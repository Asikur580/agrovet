# Hierarchy Lifecycle & Offboarding Design — Radiant Agrovet v2

Companion to [`rebuild-plan.md`](./rebuild-plan.md) §3.3 and
[`permissions-design.md`](./permissions-design.md).

**The problem.** The hierarchy is `RSM → Manager → Officer → Customer`. When anyone in that
chain resigns, is terminated, is promoted, transfers, or goes on long leave, everything below
them must end up with a valid, active owner — and history must stay intact. The legacy
system only flips `status = 'deactive'`; in the Feb-2026 snapshot **4 deactivated employees
still own 10 customers** that no active officer can see, and their credit limits still roll
up into their old managers.

**Principle:** *an employee is never "deleted" and never simply "switched off". Leaving is a
workflow — a **handover** — that must complete before the account is deactivated.*

---

## 1. What depends on an employee

| Dependency | Officer | Manager | RSM | What must happen |
| --- | :-: | :-: | :-: | --- |
| Subordinates (`employees.manager_id`) | – | officers | managers | reassigned to a successor of the same level, or to Head Office |
| Customers (`customers.employee_id`) | ✅ | – | – | reassigned to an active officer (or caretaker) |
| Open orders (`pending` / `approved`, not invoiced) | ✅ | (rare) | – | reassigned or cancelled |
| Orders awaiting *their* approval | – | ✅ | – | nothing to move — approval is by role + scope, so the new manager sees them |
| Receivables on customers they served | ✅ | – | – | follow the **customer** to the successor (§4) |
| Credit-limit roll-ups | ✅ | ✅ | ✅ | recalculated for the old chain and the new chain |
| Notification routing (manager mail/notify) | – | ✅ | – | resolved from `manager_id` at send time — automatic after reassignment |
| Login, sessions, permissions | ✅ | ✅ | ✅ | user deactivated, sessions invalidated; roles/permissions kept for audit |
| Payroll: last salary, advances, dues | ✅ | ✅ | ✅ | final settlement entry in payroll |
| History: invoices, payments, stock, costs, salaries | ✅ | ✅ | ✅ | **never reassigned** — historical attribution is immutable |

---

## 2. Data model additions

```php
// employees — lifecycle
$t->timestamp('deactivated_at')->nullable();
$t->enum('deactivation_reason', ['resigned','terminated','transferred','retired','promoted','leave','other'])->nullable();
$t->date('last_working_day')->nullable();
$t->foreignId('successor_id')->nullable()->constrained('employees');   // informational

// reporting_lines — who reported to whom, when (current pointer stays on employees.manager_id)
Schema::create('reporting_lines', function (Blueprint $t) {
    $t->id();
    $t->foreignId('employee_id')->constrained('employees');
    $t->foreignId('manager_id')->nullable()->constrained('employees');   // NULL = Head Office
    $t->date('valid_from');
    $t->date('valid_to')->nullable();                                     // NULL = current
    $t->string('reason', 40)->nullable();                                 // hired, handover, promotion, restructure
    $t->foreignId('created_by')->constrained('users');
    $t->timestamps();
    $t->index(['employee_id', 'valid_to']);
});

// customer_assignments — who owned the customer, when (current pointer stays on customers.employee_id)
Schema::create('customer_assignments', function (Blueprint $t) {
    $t->id();
    $t->foreignId('customer_id')->constrained();
    $t->foreignId('employee_id')->constrained('employees');
    $t->boolean('is_caretaker')->default(false);                          // manager holding temporarily
    $t->date('valid_from');
    $t->date('valid_to')->nullable();
    $t->string('reason', 40)->nullable();                                 // created, handover, territory-change
    $t->foreignId('created_by')->constrained('users');
    $t->timestamps();
    $t->index(['customer_id', 'valid_to']);
    $t->index(['employee_id', 'valid_to']);
});

// handovers — the workflow record
Schema::create('handovers', function (Blueprint $t) {
    $t->id();
    $t->foreignId('employee_id')->constrained('employees');               // the person leaving / changing
    $t->enum('trigger', ['resignation','termination','transfer','promotion','leave','retirement']);
    $t->enum('status', ['draft','in_progress','completed','cancelled'])->default('draft');
    $t->date('last_working_day');
    $t->text('notes')->nullable();
    $t->foreignId('initiated_by')->constrained('users');
    $t->timestamp('completed_at')->nullable();
    $t->timestamps();
});

Schema::create('handover_items', function (Blueprint $t) {
    $t->id();
    $t->foreignId('handover_id')->constrained()->cascadeOnDelete();
    $t->enum('type', ['customer','subordinate','order','cash','asset','settlement']);
    $t->nullableMorphs('subject');                                        // Customer | Employee | Order
    $t->foreignId('to_employee_id')->nullable()->constrained('employees');
    $t->enum('status', ['pending','done','skipped'])->default('pending');
    $t->string('note')->nullable();
    $t->timestamps();
});
```

`employees.manager_id` becomes **nullable**: `NULL` means "reports to Head Office" — used
for RSMs and for any level when no successor exists yet (§6).

---

## 3. The handover workflow

Permission: `employees.offboard` (start, edit, complete a handover), `customers.reassign`
(bulk customer transfer outside of offboarding), `employees.reactivate`.

```
Admin/HR → Employee page → "Start handover"
   │
   ├─ Step 1  Basics        trigger, last working day, notes
   ├─ Step 2  Handover      tabs generated from §1 for this employee's level:
   │                        Customers | Team | Open orders   → choose successors (bulk or per row)
   ├─ Step 3  Clearance     cash-in-hand / undeposited collections, salary advance balance,
   │                        company assets (checklist)
   └─ Step 4  Review        credit-limit before/after for the affected chain, item summary
                            → [Complete handover]  = one DB transaction (§3.2)
```

Until Step 4 completes the employee stays **active but flagged `offboarding`** (badge in
lists). Optional guard: while flagged, the employee cannot create new **credit** orders.

### 3.1 Successor rules (validated in the Form Request)

| Moving | Successor must be | Also |
| --- | --- | --- |
| Customers of an officer | active, level `officer` — or a manager as **caretaker** (§6) | may be split across several officers (per row, or "all customers in territory X → Y") |
| Officers of a manager | active, level `manager`, or **Head Office** (`NULL`) | not the leaver; not in the leaver's own subtree (cycle guard) |
| Managers of an RSM | active, level `rsm`, or **Head Office** | cycle guard |
| Open orders of an officer | the officer who now owns that order's customer (default) | or *cancel* with reason |

### 3.2 `CompleteHandover` action (single transaction)

```php
DB::transaction(function () use ($handover) {
    $leaver = $handover->employee;
    $today  = $handover->last_working_day;

    // 1. Customers → successors (writes history)
    foreach ($handover->items->where('type', 'customer') as $item) {
        ReassignCustomer::run($item->subject, $item->to_employee, $today, 'handover', $item->to_employee->isManager());
    }
    // 2. Subordinates → successor / Head Office (writes history)
    foreach ($handover->items->where('type', 'subordinate') as $item) {
        AssignManager::run($item->subject, $item->to_employee /* nullable */, $today, 'handover');
    }
    // 3. Open orders → follow customer, or cancel
    foreach ($handover->items->where('type', 'order') as $item) {
        $item->to_employee ? ReassignOrder::run($item->subject, $item->to_employee)
                           : CancelOrder::run($item->subject, 'handover');
    }
    // 4. Hard guard — nothing may remain
    abort_if($leaver->activeSubordinates()->exists()
          || $leaver->customers()->exists()
          || $leaver->openOrders()->exists(), 422, 'Handover incomplete');

    // 5. Deactivate
    $leaver->forceFill([
        'deactivated_at' => now(), 'deactivation_reason' => $handover->trigger,
        'last_working_day' => $today, 'manager_id' => null,
    ])->save();
    $leaver->closeCurrentReportingLine($today);
    DeactivateUser::run($leaver->user);            // disable login, delete sessions, revoke tokens

    // 6. Credit limits — old chain and every new chain
    RecalculateCreditLimit::forChainOf($leaver->previousManagerId());
    foreach ($handover->successors() as $s) RecalculateCreditLimit::forChainOf($s->id);

    // 7. Payroll final settlement placeholder
    CreateFinalSettlement::run($leaver, $today);

    $handover->update(['status' => 'completed', 'completed_at' => now()]);
});

event(new HandoverCompleted($handover));   // notify successors + their managers; activity log
```

`ReassignCustomer` closes the open `customer_assignments` row (`valid_to = today − 1`),
opens a new one, updates `customers.employee_id`, and logs. `AssignManager` does the same on
`reporting_lines` + `employees.manager_id`. Both are the **only** writers of those pointers,
so history is always complete.

---

## 4. Scoping after a handover — two keys, not one

This is the subtle part and the reason legacy reports would break after a transfer.

| Question | Key | Mutable? |
| --- | --- | --- |
| *Who sold this?* (performance, targets, commission history) | `invoices.employee_id` | **No** — set at sale, never changed |
| *Who is responsible for this customer now?* (collections, visibility, credit exposure) | `customers.employee_id` (+ `customer_assignments` for a date) | **Yes** — moves on handover |

Therefore in v2:

* `Invoice::visibleTo($user)` = invoices whose **customer** is owned by an employee in the
  user's subtree — **not** invoices whose `employee_id` is in the subtree. The successor sees
  the transferred customers' old invoices and can collect their dues; the leaver's history
  still shows who sold.
* **Sales reports "by employee"** use `invoices.employee_id`.
* **Due / collections / credit-usage reports "by employee"** use current customer ownership.
* **Period-accurate attribution** (e.g. "Officer X's customers' sales in October, when X
  owned them") joins `customer_assignments` on `sale_date BETWEEN valid_from AND valid_to`.
* Employee credit usage = Σ open dues of **currently owned** customers + own pending orders.
  After handover the leaver's usage is 0; the successor's usage *and* limit both grow by the
  transferred customers' figures, so the position stays consistent. Step 4 of the wizard shows
  the before/after for the whole chain.

---

## 5. Who leaves × what happens

### Officer leaves
1. Customers → one or several active officers (usually under the same manager), bulk by
   territory. Each transfer is a `customer_assignments` row.
2. Open orders → follow their customer's new officer, or cancel.
3. Receivables follow the customers (§4). A "Transferred customers with dues" report is
   generated for the successor.
4. Credit: old manager's roll-up drops the leaver; successor's limit and usage rise;
   successor's manager/RSM roll-ups recalc.
5. Clearance: cash-in-hand, advances, assets. Final settlement in payroll.
6. Deactivate user; keep employee row and all history.

### Manager leaves
1. Officers → another manager (same RSM by default) or **Head Office** if none.
2. Customers are untouched (they belong to officers).
3. Orders awaiting approval: nothing to move; the new manager (or admin) sees them via scope.
4. Notifications/emails for "manager" resolve from `manager_id` at send time → automatic.
5. Credit roll-ups: leaver → 0; new manager += Σ officers' limits; if the RSM changed,
   both RSMs recalc.
6. Deactivate.

### RSM leaves
1. Managers → another RSM or **Head Office**.
2. Everything below is untouched.
3. Roll-ups recalc; deactivate.

### Promotion (officer → manager, manager → RSM)
Same wizard with `trigger = promotion`, but the employee is **not** deactivated:
1. Change designation level; set the promotee's own `manager_id` (e.g. to the RSM);
   write a `reporting_lines` row.
2. Promotee's customers → other officers (managers don't own customers).
3. Then the *leaving* manager's officers → the promotee (a second handover, or the same
   wizard if the promotion replaces a leaver — the UI offers "promote X and hand over to X").
4. Roles: swap `officer` → `manager` role; overrides reviewed.

### Long leave (maternity, medical)
Use the wizard with `trigger = leave`, successors flagged **caretaker** (`is_caretaker`) and
an `expected_return` note; the account is deactivated for the duration. Return = §7
reactivation, which offers "restore caretaker assignments to me" as a one-click reverse.
*(v2.1 could add `acting_until` for automatic reversal.)*

---

## 6. When there is no successor yet

Resignations happen before replacements are hired. Two safety valves — neither leaves a
dangling owner:

| Situation | Handling |
| --- | --- |
| No manager/RSM available for subordinates | assign to **Head Office** (`manager_id = NULL`). Admin/accountant-level users approve orders and receive the manager notifications. A dashboard widget "Teams without a manager" nags until fixed. |
| No officer available for customers | the **manager becomes caretaker** (`customer_assignments.is_caretaker = true`; the owner-must-be-officer rule is relaxed only for caretaking). Caretaker customers show a badge, appear in the manager's own scope, and in a "Customers awaiting an officer" widget. New **credit** orders for caretaker customers require the manager's own approval. |

Both states are visible on the dashboard and blocked from silently persisting: a nightly
`hierarchy:check` command reports every customer owned by an inactive employee, every active
employee whose manager is inactive, every caretaker assignment older than N days, and every
Head-Office team.

---

## 7. Reactivation (rejoining)

`employees.reactivate`: clears `deactivated_at`, requires a new `manager_id` (or Head
Office), re-enables the user (new password reset link), restores the role (overrides start
empty). Customers and subordinates are **not** returned automatically — the admin uses the
reassignment tool, with a shortcut "return the customers X handed over on {date}" that reads
`customer_assignments`.

---

## 8. Invariants (enforced in actions, verified nightly)

1. An active customer's owner is an active employee (officer, or manager-as-caretaker).
2. An active employee's `manager_id` is NULL or an active employee of the correct higher level.
3. No cycles in `manager_id`.
4. A deactivated employee owns no customers, has no active subordinates, has no open orders.
5. `customer_assignments` and `reporting_lines` have exactly one open row (`valid_to IS NULL`)
   per customer / employee that is active, and none for a deactivated employee.
6. Historical `invoices.employee_id`, `payments.collected_by`, `stock_movements.employee_id`
   never change.

The DB enforces what it can (FKs, `manager_id` nullable, unique open-row via a generated
column or application check); the rest lives in the Actions and the nightly check.

---

## 9. Permission catalogue additions

Add to `permissions-design.md` §2, module **Organisation**:

| Code | Unlocks |
| --- | --- |
| `employees.offboard` | Start / edit / complete / cancel a handover |
| `employees.reactivate` | Reactivate a deactivated employee |
| `customers.reassign` | Bulk customer transfer outside of a handover (territory changes) |

Default matrix: `admin` ✅ all three; `rsm` ✅ `customers.reassign` within scope; others ✗.

---

## 10. Legacy data fix during migration

The ETL must not carry the broken state forward:

* For the 4 deactivated employees owning 10 customers: the migration report lists them; the
  admin assigns successors in the wizard on day one, or the ETL assigns their **manager as
  caretaker** so invariant 1 holds immediately.
* `reporting_lines` and `customer_assignments` are back-filled with one open row each from
  current pointers (`valid_from` = employee/customer `created_at`).
* Deactivated employees with `manager_id` pointing to other deactivated employees are set to
  NULL.

---

## 10a. What the build settled (P19)

The design above is what was built; five details were only decided at the keyboard.

| Question the design left open | How P19 answered it |
| --- | --- |
| When is a plan row "decided"? | An item leaves `pending` and carries the answer: a **customer** or **team member** row is `done` with a successor (a team row `done` with no successor means Head Office), an **order** row is `done` with a successor or `skipped` to cancel, and a **clearance** row is `done` or `skipped`. `CompleteHandover` refuses while anything is still `pending`. |
| Where do promotion details live? | Two nullable columns on `handovers` (`to_designation_id`, `to_manager_id`) plus `expected_return_on` for long leave, and an `amount` on `handover_items` for the clearance figures. |
| May an officer be parked at Head Office? | Not through the reporting-line dialog — `AssignManager` refuses, because a manager approves an officer's orders. Only `AssignManager::toHeadOffice()`, reached from a handover, may do it, and §8's check reports the team until somebody fixes it. |
| What happens to a customer with no history rows (ETL, or a row written before the action existed)? | `ReassignCustomer` back-fills one closed row for the previous owner the first time the customer moves, so invariant 5 holds from then on. It does not back-fill when the owner is not changing — that call is the one opening the history. |
| Does the leaver keep an open reporting line? | No. Completion detaches them (`manager_id = null`, open line closed on the last working day) so their limit stops rolling up into their old manager's, which is exactly the legacy bug in §10. |

The nine scenarios in §11 are covered by `tests/Feature/Handovers/HandoverTest.php`, and the
`hierarchy:check` command reports each invariant with a non-zero exit code.

---

## 11. Tests

| Scenario | Assert |
| --- | --- |
| Officer handover splits customers to two officers | ownership, history rows, open orders follow, credit roll-ups for both chains, leaver deactivated, user cannot log in |
| Manager handover with no successor | officers → Head Office, orders still approvable by admin, widget shows the team |
| Officer handover with no officer available | manager caretaker, badge, credit order needs manager approval |
| Attempt to complete with one customer unassigned | 422, nothing changed (transaction) |
| Successor inside leaver's subtree | validation error (cycle) |
| Invoice visibility after transfer | successor sees old invoices of transferred customers; leaver's sales report unchanged |
| Promotion officer → manager | level changed, own customers moved, inherits leaver's officers, roles swapped |
| Reactivation | requires manager, user restored, "return handed-over customers" works |
| Nightly `hierarchy:check` on seeded broken data | reports every invariant violation |
