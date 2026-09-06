# Order Approval Design — Radiant Agrovet v2

Companion to [`rebuild-plan.md`](./rebuild-plan.md) §3.6,
[`permissions-design.md`](./permissions-design.md) and
[`offboarding-design.md`](./offboarding-design.md).

**Business rule (confirmed):**

> The officer takes the order. The officer's manager approves it. An admin can also approve
> if they hold the permission.

**Legacy behaviour being replaced:** `POST /orders/{id}/status` flips `pending ⇄ active`
with **no server-side check** of who is calling; the UI hides the button unless the
designation is `manager`/`admin`. Approval leaves no record of who approved or when.

---

## 1. State machine

```
                     officer                 manager / admin(with permission)
   ┌─────────┐  create/submit  ┌─────────┐   approve    ┌──────────┐  convert   ┌──────────┐
   │ (new)   │ ──────────────► │ pending │ ───────────► │ approved │ ─────────► │ invoiced │
   └─────────┘                 └────┬────┘              └────┬─────┘            └──────────┘
                                    │ reject                 │ unapprove (back to pending)
                                    ▼                        │ cancel
                               ┌──────────┐                  ▼
                               │ rejected │ ── officer edits & resubmits ──► pending
                               └──────────┘             ┌───────────┐
                               officer cancels pending ─►│ cancelled │
                                                         └───────────┘
```

| From | To | Who | Permission | Notes |
| --- | --- | --- | --- | --- |
| — | `pending` | officer (any user with create) | `orders.create` | creation **is** submission; no draft state |
| `pending` | `pending` (edited) | the order's officer | `orders.update` | edits allowed while pending; approver is re-notified if amount changed |
| `pending` | `approved` | **direct manager** of the order's officer; any upline with permission; admin with permission | `orders.approve` (+ scope, §2) | re-runs `CreditGuard`; over limit needs §4 |
| `pending` | `rejected` | same as approve | `orders.approve` | reason required; officer notified |
| `pending` | `cancelled` | the order's officer, or approver | `orders.cancel` | reason optional |
| `rejected` | `pending` | the order's officer | `orders.update` | edit → resubmit |
| `approved` | `pending` | approver / admin | `orders.approve` | "unapprove" with reason; used when the officer must change an approved order |
| `approved` | `cancelled` | approver / admin | `orders.cancel` | |
| `approved` | `invoiced` | whoever converts | `orders.convert` | only **approved** orders can be invoiced |
| `invoiced`, `cancelled` | — | — | — | terminal; order is never deleted |

Officers **cannot** edit or cancel an `approved` order — they ask the approver to unapprove.

---

## 2. Who may approve — permission **and** scope

```php
class OrderPolicy
{
    public function approve(User $user, Order $order): bool
    {
        if (! $user->can(Perm::ORDERS_APPROVE)) {
            return false;                                   // admin without the permission → no
        }
        if ($order->status !== 'pending') {
            return false;
        }
        $officer = $order->employee;                        // the officer who took the order

        return $user->employee->isAdminLevel()              // admin/super-admin: any order
            || $officer->manager_id === $user->employee->id // the officer's DIRECT manager
            || (config('orders.approval.allow_upline')      // optional: RSM above the manager
                && $officer->isInSubtreeOf($user->employee));
    }
}
```

| Approver | Default | How |
| --- | --- | --- |
| Officer's **direct manager** | ✅ | has `orders.approve` in the `manager` role |
| **Admin** | ✅ only if the permission is held | `admin` role has `orders.approve` by default; can be denied per user (§ permissions-design 5.2) |
| RSM (upline) | ✗ | grant `orders.approve` to the `rsm` role **and** set `allow_upline = true` in Settings |
| The officer themselves | never | policy excludes the order's own officer even if they somehow hold the permission |
| Officer reporting to **Head Office** (`manager_id = NULL`, see offboarding §6) | admin approves | the approvals inbox for admins highlights these |

Default matrix change in `permissions-design.md`: `orders.approve` → admin ✅, manager ✅,
**rsm ✗** (was ✅), officer ✗.

---

## 3. Data

```php
Schema::table('orders', function (Blueprint $t) {
    $t->enum('status', ['pending','approved','rejected','cancelled','invoiced'])->default('pending')->change();
    $t->timestamp('submitted_at')->nullable();
    $t->foreignId('approved_by')->nullable()->constrained('users');
    $t->timestamp('approved_at')->nullable();
    $t->foreignId('rejected_by')->nullable()->constrained('users');
    $t->timestamp('rejected_at')->nullable();
    $t->string('rejection_reason', 255)->nullable();
    $t->string('approval_note', 255)->nullable();
    $t->boolean('approved_over_limit')->default(false);
    $t->foreignId('invoice_id')->nullable()->constrained();        // set on convert
    $t->index(['status', 'employee_id']);
});
```

Every transition is also written to the activity log with the credit snapshot
(customer due / limit / available, officer usage / limit) at that moment, so a later dispute
can show what the approver saw.

---

## 4. Interaction with credit limits

| Moment | Check | On failure |
| --- | --- | --- |
| Officer creates/edits | `CreditGuard` — employee gate always, customer gate for credit orders | **blocked** (422) as today — the officer cannot even submit an over-limit order |
| Manager approves | `CreditGuard` **re-run** (other orders/invoices/payments may have changed the position since submission) | approval screen shows the breach; approving requires `orders.approve-over-limit` + a reason → `approved_over_limit = true` |
| Convert to invoice | `CreditGuard` re-run at invoice creation | blocked unless the invoice creator holds `orders.approve-over-limit` (same exception, same reason field) |

Credit **usage** counts orders in `pending` **and** `approved` (both are commitments);
`rejected`, `cancelled`, `invoiced` do not count (the invoice does).

New permission: `orders.approve-over-limit` — default **admin only**. Managers who should be
allowed to exceed customer limits get it per user or via the role matrix.

---

## 5. Settings (runtime, `settings` table)

| Key | Default | Effect |
| --- | --- | --- |
| `orders.approval.required` | `true` | `false` = orders skip approval and are created as `approved` (single-person businesses) |
| `orders.approval.allow_upline` | `false` | RSM/upline with the permission may approve, not only the direct manager |
| `orders.approval.auto_approve_cash` | `false` | cash orders within limits are auto-approved; credit orders still need a manager |
| `orders.approval.escalate_after_hours` | `24` | pending longer than this → notify the manager's manager and admins; `0` disables |
| `sales.require_approved_order` | `false` | `true` = officers can only create invoices from approved orders (no direct sales); admin exempt |

---

## 6. Notifications

| Event | Recipients | Channel |
| --- | --- | --- |
| `OrderSubmitted` | officer's direct manager (or admins if Head Office) | database + Reverb; manager email (queued) |
| `OrderApproved` | the officer | database + Reverb; optional customer SMS "Your order #… is confirmed" (template, off by default) |
| `OrderRejected` | the officer, with reason | database + Reverb |
| `OrderUnapproved` | the officer, with reason | database + Reverb |
| `OrderPendingEscalation` (scheduler) | manager's manager + admins | database |

Admins are **not** spammed with every submission (legacy did this); they get the daily
escalation digest and see everything in the approvals inbox.

---

## 7. UI

### Approvals inbox — `/orders/approvals` (`orders.approve`)

```
Pending approvals (7)                        [Bulk approve selected]   filter: officer ▾  customer ▾  type ▾
☐  #1742  10 Feb  Adorsho Poultry     Officer: Rahim   Credit   ৳ 32,500   Cust due 41,000 / limit 100,000 ✔   Officer 312k/500k ✔   [Approve] [Reject]
☐  #1743  10 Feb  Dulal Traders       Officer: Rahim   Credit   ৳ 18,270   Cust due 98,400 / limit 100,000 ⚠ over by 16,670          [Approve*] [Reject]
☐  #1744  11 Feb  Mim Pharmacy        Officer: Karim   Cash     ৳  8,020   —                                                        [Approve] [Reject]
* requires orders.approve-over-limit + reason
```

* Rows are scoped by the policy (manager: own officers; admin: all, Head-Office orders
  flagged).
* Reject opens a reason dialog; bulk approve skips over-limit rows and says so.
* Badge count in the sidebar from a shared prop `pendingApprovals`.

### Order details — timeline block

```
Submitted   10 Feb 22:12  by Rahim (officer)
Approved    11 Feb 09:40  by Hasan (manager)   note: "deliver Thursday"
Invoiced    12 Feb 15:02  RAINVO-000913
```

### Officer's order list

Status chips (`pending` amber, `approved` green, `rejected` red with reason tooltip,
`invoiced` grey); rejected orders have **Edit & resubmit**.

---

## 8. Conversion to invoice

* `POST /orders/{order}/invoice` → `ConvertOrderToInvoice` action: requires `orders.convert`
  and `status = approved`; copies lines, offer, customer, **`employee_id` = the order's
  officer** (sales attribution stays with the officer, whoever performs the conversion);
  posts stock movements; sets `orders.status = invoiced`, `orders.invoice_id`,
  `invoices.order_id`. The order row is **kept**.
* Partial delivery (`due_quantity` on lines, as legacy allows) stays on the invoice; the order
  itself is fully `invoiced` once an invoice exists for it.

---

## 9. Permission catalogue delta

| Code | Change |
| --- | --- |
| `orders.approve` | unchanged code; default matrix: admin ✅, manager ✅, **rsm ✗** |
| `orders.approve-over-limit` | **new** — default admin only |
| `orders.cancel` | **new** (replaces `orders.delete`, orders are never deleted) |
| `orders.convert` | unchanged |

Total catalogue: 143 codes.

---

## 10. Tests

| Scenario | Assert |
| --- | --- |
| Officer submits; direct manager approves | status, `approved_by/at`, officer notified, credit snapshot logged |
| Another manager (not the officer's) with `orders.approve` tries | 403 |
| Admin with `orders.approve` denied per user | 403; admin with it → 200 |
| RSM with permission, `allow_upline = false` | 403; `true` → 200 |
| Officer tries to approve own order | 403 even if granted the permission |
| Customer position changed after submission; manager approves without over-limit permission | 422 with breach details; with permission + reason → approved, `approved_over_limit = true` |
| Officer edits an approved order | 403; after unapprove → 200 |
| Convert a pending order | 422; approved → invoice created, order `invoiced`, row retained |
| Officer under Head Office submits | admins' inbox shows it flagged; admin approves |
| Escalation scheduler | pending > 24 h creates escalation notifications once |
