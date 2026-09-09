# Rebuild Plan — Radiant Agrovet v2 (Laravel + Inertia + React)

This plan replaces the current two-repo system (Laravel 11 JSON API + React SPA) with a
**single Inertia monolith**. It carries every business rule that works today, fixes the
problems documented in the reviews, and ends with a one-time migration of the live data.

**Companion design documents** (this plan references them; they are the source of detail):

| Document | Covers |
| --- | --- |
| [`database-review.md`](./database-review.md) | Why the schema changes; column-level redesign, indexes |
| [`permissions-design.md`](./permissions-design.md) | Per-feature permission catalogue (137 codes), role + user-level assignment with allow/deny |
| [`offboarding-design.md`](./offboarding-design.md) | What happens when an RSM / manager / officer resigns, is promoted, goes on leave |
| [`order-approval-design.md`](./order-approval-design.md) | Officer submits → direct manager approves (or admin with permission); credit re-checks |
| [`workplan.md`](./workplan.md) | **27 small phases, week-by-week calendar, two developer tracks, exit criteria** |

---

## 0. Decisions

### 0.1 Confirmed

| # | Decision | Chosen |
| --- | --- | --- |
| 1 | App shape | Inertia monolith, session auth, no separate API (add a thin Sanctum API later only if a native mobile app is needed) |
| 2 | Permissions | Every feature has its own permission; assignable per **role** and per **user** (allow + deny overrides) |
| 3 | Order flow | Officer submits → **direct manager** approves; **admin** approves only if holding `orders.approve`; RSM ✗ by default |
| 4 | Leaving / promotion | A **handover workflow** must complete (customers, subordinates, open orders reassigned or parked) before deactivation; history kept |
| 5 | Invoice visibility | Follows **customer ownership** (collections); sales attribution stays with the original seller |

### 0.2 Recommended (proceed unless you object)

| # | Decision | Recommended | Alternative | Affects |
| --- | --- | --- | --- | --- |
| 6 | Language | **TypeScript** (starter-kit default) | JavaScript | §5 |
| 7 | Database | **New clean schema + one-time ETL** — the only cheap moment to fix invoice numbering, the dual ledger, `DOUBLE` money, schema drift | Reuse current schema | §4, §7 |
| 8 | UI kit | **Tailwind 4 + shadcn/ui** (official starter kit) | Ant Design — pick **one** | §5 |
| 9 | Realtime | **Laravel Reverb** replaces the 60-second poll | Keep polling | §3.11 |
| 10 | Invoice PDF | **dompdf for English-only documents; Browsershot the moment Bangla must print** — settled by the P05 spike (`pdf-engine-spike.md`): dompdf mis-shapes Bangla, Chrome renders it correctly | client-side `@react-pdf` | §3.7 |
| 11 | Queue | `database` driver now; Redis + Horizon when SMS volume grows | Redis day one | §8 |
| 12 | Offline | **Online-first**; old `localStorage` fallback dropped | Separate offline order-entry PWA in v2.1 if field officers truly need it | §10 |
| 13 | Licence check | Server-side middleware with cached result | Drop | §3.13 |
| 14 | Team & timeline | **2 developers, 14 weeks** to cut-over (see `workplan.md`) | 3 devs ≈ 10 weeks; 1 dev ≈ 24 weeks | §6 |

### 0.3 Open (small, decide during Stage A)

| # | Question | Default in the docs |
| --- | --- | --- |
| 15 | Keep `details` as a separate permission from `view` (legacy had `-page` and `-details-page`)? | Separate (≈10 extra codes) |
| 16 | May customers held by a **caretaker manager** receive credit orders? | Yes, with the manager's own approval |
| 17 | Should RSMs be able to approve orders of their subtree? | No by default; role matrix + `allow_upline` setting can enable |
| 18 | Customer SMS on order approval ("your order is confirmed")? | Template exists, off by default |

---

## 1. Why rebuild, and what to keep

### Fix (from the reviews)

| Problem today | v2 answer |
| --- | --- |
| Permission checks only in the browser; 15 public routes | Policies + `can:` on every route; UI reads the same permission list |
| Invoice numbers not unique (318 × `RAINVO-01`) | `document_sequences` + unique `invoice_no` |
| `invoices.paid/due` vs `payments` ledger disagree | `payment_allocations`; `due` is always derived |
| Money as `DOUBLE`, dates as strings | `DECIMAL(15,2)`, `DATE` |
| Schema edited by hand in phpMyAdmin | Migrations only; CI runs `migrate --pretend` |
| Hierarchy `if/elseif` copied into 6 controllers | `employees.manager_id` + one `visibleTo($user)` scope |
| Deactivated employees still own customers (4 → 10 customers) | Handover workflow + nightly `hierarchy:check` |
| Anyone can flip an order's status | State machine + `OrderPolicy::approve` + audit |
| 1,300-line `CommonModal`, three cart-modal variants | Typed page components + shared `Form`/`DataTable`/`LineItemsEditor` kit |
| Stock changed by `decrement()` with no ledger row | `stock_movements` ledger; `products.quantity` rebuildable |
| Tokens (4,652) and notifications (5,629) never pruned | Session auth; notifications paginated + pruned |
| Mail/SMS sent inside the request | Events → queued listeners |

### Keep

* Screen set and navigation; same flows (order → approval → invoice → payment).
* Business rules: hierarchy scoping; employee and customer credit
  gates; `.50↓ / .51↑` rounding; TP vs flat pricing; bonus quantity; order → invoice;
  SMS on invoice and payment; `sms_enabled`; print tracking; cash/credit sale types.
* Customer code `RA18/…` and invoice prefix `RAINVO-` (now padded and unique).
* Dashboard widgets and the report catalogue (consolidated, §3.10).

---

## 2. Target architecture

```
Browser ──HTTPS/session──►  Laravel 12 (+ Inertia 2)  ──►  MySQL 8 / MariaDB 11
   │                            │        │                      ▲
   React 19 + TS (Vite)         │        └── queue workers ─────┘  (mail, SMS, notifications)
   Tailwind 4 + shadcn/ui       └── Reverb (WebSockets) ─► browser notifications
```

**Base:** `laravel new agrovet --react --pest` (official starter kit: Inertia 2, React 19,
TypeScript, Tailwind 4, shadcn/ui, session auth). One repository, one deploy.

### Backend layering

| Layer | Location | Rule |
| --- | --- | --- |
| Routes | `routes/web.php` → `routes/modules/*.php` | `Route::resource` + `->can(Perm::…)` on every route |
| Controllers | `app/Http/Controllers/{Module}/` | Thin: Form Request → Action → `Inertia::render` / redirect |
| Form Requests | `app/Http/Requests/{Module}/` | All validation; `authorize()` → Policies |
| Policies | `app/Policies/` | Permission **and** hierarchy row scope |
| Actions | `app/Actions/{Module}/` | One business operation each, transactional, unit-tested |
| Query scopes | `scopeVisibleTo(User)` on models | The **only** place hierarchy scoping exists |
| Reports | `app/Reports/` | Query classes returning typed arrays; cached |
| Services | `app/Services/` | `SmsGateway`, `DocumentNumber`, `Money`, `CreditGuard` |
| Events / Listeners / Jobs | `app/Events`, `app/Listeners`, `app/Jobs` | Side effects off the request path |
| Shared props | `HandleInertiaRequests::share()` | `auth.user`, `auth.roles`, `auth.permissions`, `flash`, `pendingApprovals`, `notifications.unread` |

### Frontend layering

```
resources/js/
├── app.tsx                      Inertia bootstrap, Ziggy
├── layouts/AppLayout.tsx        sidebar + topbar + flash + bell + approvals badge
├── layouts/PrintLayout.tsx
├── pages/{module}/{Index,Create,Edit,Show}.tsx
├── components/ui/               shadcn primitives
├── components/app/              DataTable, FormField, MoneyInput, DatePicker, Combobox,
│                                LineItemsEditor, PermissionMatrix, ConfirmDialog, Can,
│                                PageHeader, StatCard, Chart, Timeline
├── hooks/                       useCan, useFlash, useDebouncedSearch
├── lib/                         money.ts (mirrors Money::roundBusiness), dates.ts
└── types/                       generated: permissions.ts, models.d.ts
```

Server-driven lists everywhere: paginated, filtered, sorted by the controller
(`spatie/laravel-query-builder`), filters kept in the URL, partial reloads.

### Packages

| Purpose | Package |
| --- | --- |
| Roles & permissions | `spatie/laravel-permission` (+ our `user_denied_permissions`) |
| Index filtering/sorting | `spatie/laravel-query-builder` |
| Audit trail | `spatie/laravel-activitylog` |
| Route names in JS | `tightenco/ziggy` |
| PDF | `barryvdh/laravel-dompdf` (or `spatie/laravel-pdf` + Browsershot) |
| Excel export | `spatie/simple-excel` |
| Realtime | `laravel/reverb` + `laravel-echo` |
| Backups | `spatie/laravel-backup` |
| Queues (later) | `laravel/horizon` |
| Static analysis / style | `larastan/larastan`, `laravel/pint`, ESLint + Prettier |
| Tests | Pest 3, factories |

---

## 3. Module design

Old endpoints in *italics* for traceability.

### 3.1 Auth & session
Starter-kit login/logout/password reset/profile (*/login, /logout, /profile\**). No
self-registration. `users` 1:1 `employees` (unique FK). Deactivated user → login blocked,
sessions invalidated (`EnsureUserIsActive`).

Built in P09, with four decisions worth recording:

* **Deactivating ends live sessions immediately** (`UserSessions::purge`, database session
  driver) instead of waiting for the account's next request; `EnsureUserIsActive` stays as
  the backstop. Changing a password does the same to every *other* session and clears
  `remember_token`.
* **Accounts cannot delete themselves.** The starter kit shipped a "delete account" card and
  it is removed: deleting would orphan the audit trail and every `created_by` column, and it
  routes around the last-super-admin guard. Administrators deactivate instead
  (`users.delete`), which is reversible.
* **The profile page carries the legacy metrics block** (customers, team, sales count and
  total, credit limit / used / available) plus the employee's own contact details — the two
  things legacy `POST /api/profile/update` wrote. Sales count on `invoices.employee_id`
  ("who sold"); credit on current customer ownership, so the numbers agree with the credit
  guard and survive a handover. Designation, manager, credit limit and roles are not
  editable here; they belong to an administrator.
* `users.last_login_at` is stamped by a `Login` listener, which is what the "Last login"
  column on the user list reads.

### 3.2 Roles & permissions — [`permissions-design.md`](./permissions-design.md)
* **137 permissions** in 26 modules, codes in `config/permissions.php`, synced by
  `permissions:sync`, typed constants in PHP (`Perm::…`) and TypeScript.
* Roles (`super-admin`, `admin`, `rsm`, `manager`, `officer`, `accountant`, custom) carry a
  matrix; users inherit and get **allow** / **deny** overrides:
  `effective = (roles ∪ allows) − denies`; `super-admin` via `Gate::before`.
* Admin UI: role matrix, user Access tab (tri-state), effective preview, catalogue, audit log.
  Changes apply on the user's next request.
* Backend enforces on every route; Policies add row scope; `<Can>` / `useCan()` only hide.
* Legacy per-user permissions migrate one-to-one via the mapping table.

### 3.3 Organisation — [`offboarding-design.md`](./offboarding-design.md)
* **Designations**: `name`, `level enum(admin,rsm,manager,officer)`.
* **Employees** (*/employees\**): code, designation, `manager_id` (nullable = Head Office),
  contacts, territory/district, encrypted NID, photo, basic salary, derived `credit_limit`,
  lifecycle fields. `reporting_lines` keeps who-reported-to-whom history.
* `AssignManager` enforces officer→manager→rsm and the cycle guard.
* **Credit limit**: set on the employee form and on the customer, never derived. Legacy had
  the field on both forms *and* `recalculateCreditLimit()` overwriting the employee one from
  the sum of their customers, so a number somebody entered survived until the next customer
  edit. v2 keeps the fields and drops the overwriting: what is entered is the ceiling until
  somebody edits it. The exposure still moves with the customers — a handover changes what a
  person is carrying, never what they are allowed to carry.
* **Leaving / promotion / leave = handover wizard**: customers, subordinates, open orders
  reassigned (or parked with a **caretaker manager** / **Head Office**) before deactivation;
  clearance checklist; final settlement; `hierarchy:check` nightly; reactivation.

Built in P19. Five decisions:

* **A plan is not a move.** Starting a handover changes nothing: the employee keeps their
  team, their customers and their login while an administrator works out where everything
  goes. `CompleteHandover` is the only thing that writes, and it writes in one transaction —
  either every customer, report and open order lands where the plan says and the account
  closes, or nothing happens at all.
* **The completion guard re-reads the database, it does not trust the plan.** A leaver keeps
  selling through their notice period, so every visit to the wizard re-syncs what actually
  depends on them (`SyncHandoverItems`) and the transaction refuses to deactivate anybody who
  still owns something. This is the invariant legacy never had.
* **Head Office is an escape valve, and only a handover may use it.** `AssignManager` still
  refuses to leave an officer without a manager, because their manager approves their orders;
  `AssignManager::toHeadOffice()` deliberately bypasses that rule when a manager resigns with
  no successor, and `hierarchy:check` plus a dashboard widget nag until it is fixed
  (offboarding-design §6).
* **Coming back is not the reverse of leaving.** Reactivation needs a manager and restores the
  login, but the customers stay with whoever has been serving them. The one shortcut reads the
  completed handover and returns only the customers still sitting with the person who took
  them — anything that has moved on since was somebody's decision.
* **Credit is simulated before it is changed.** Step 4 shows the limit of the leaver, every
  successor and every manager above them, before and after, computed without writing anything
  (`CreditPreview`). An administrator sees a manager's limit double before agreeing to it.
* *Credit report* (*/employees/credit-report*) → one grouped query (correlated sub-selects,
  hierarchy-scoped, optional date window). **Built in P07 and it deliberately differs from the
  legacy report in two ways**, so that it can never contradict what `CreditGuard` blocks at
  order/invoice time:
  * usage is keyed on **current customer ownership**, not `invoices.employee_id`, so a handover
    moves the exposure with the customers (offboarding-design §4); sales *performance* reports
    keep using `invoices.employee_id`, because "who sold" never changes;
  * only **open** (pending/approved) orders count toward usage — legacy summed every credit
    order regardless of status and so double counted anything already invoiced.

  On the demo seed, where nothing has been handed over, the purchases column matches the legacy
  formula employee for employee (asserted in `CreditReportTest`).

### 3.4 Customers & suppliers
* Customers (*/customers\**): code from `document_sequences`, owner must be an **officer**
  (or caretaker manager), E.164 phone, `credit_limit` (separate permission), opening balance
  as an **opening-balance invoice**, `sms_enabled`, `archived_at`, `customer_assignments`
  history, bulk **reassign** tool. Show page tabs: details, invoices, payments, SMS log.
* Suppliers (*/suppliers\**): details, purchases, payments, balance.

Suppliers built in P11. Three notes:

* **The balance is derived, never stored**: purchases come from the stock ledger
  (Σ qty × unit_cost of their `purchase` movements) and payments from what was sent to them,
  so the figure cannot drift from the movements behind it (database-review §2.8). Archiving
  is refused while anything is still payable.
* **Supplier phone numbers are free text**, unlike customers'. The table has a `country`
  column and suppliers can be abroad, so the Bangladeshi mobile pattern would reject valid
  numbers.
* `suppliers.export` was **added to the permission catalogue** in this phase: the workplan
  asks for an export and no code existed for it (permissions-design §8 makes adding the code
  part of the definition of done). The accountant role picks it up through its `suppliers.*`
  wildcard, taking the catalogue to 138 permissions.

Customers built in P10. Five decisions worth recording:

* **Phone numbers are normalised on the way in** (`App\Support\Phone`): anything a user
  types — `01712-345678`, `+880 1712 345678`, `8801712345678` — is stored as
  `8801712345678`, and uniqueness is checked against that value, so the same number cannot
  be entered twice in two different shapes. Only real operator prefixes (013–019) are
  accepted; the seed factory was corrected to match.
* **The opening balance is an invoice**, type `opening`, with no lines. Legacy kept it in
  `customers.old_due`, a column the ledger knew nothing about, so a statement never
  reconciled with the invoices behind it (database-review §2.5). As an invoice it is
  counted by the same query as everything else and a payment can be allocated against it.
* **Ownership moves only through `ReassignCustomer`**, which writes the
  `customer_assignments` row; the exposure moves with the customer, the ceilings do not move.
  Several moves on the same day collapse to one open row rather than leaving rows that end
  before they start; each move is still recorded in the activity log.
* **A manager may own a customer only as a caretaker** (offboarding-design §6). The flag is
  stored on the assignment row, shown as a badge, and is the only way the owner-must-be-an-
  officer rule relaxes.
* **Customers are archived, never deleted**, and archiving is refused while orders are open.
  The credit limit and the SMS switch each sit behind their own permission
  (`customers.set-credit-limit`, `customers.toggle-sms`), so an officer can create a shop
  but not decide how much credit it gets.

### 3.5 Catalogue & inventory
* Brands, categories with sales drill-down. Products: prices (`buy`, TP, `flat`) behind
  `products.set-prices`, `quantity` cached, expiry, low-stock threshold, archive.
* **`stock_movements`** ledger — `purchase` (*/stock_in*), `adjustment` / `transfer`
  (*/stock_out*, employee FK instead of free text), `sale`, `sale_reversal`, `return`.
  `PostStockMovement` is the only writer of `products.quantity`; `stock:rebuild` reconciles.

Products built in P12. Four decisions:

* **`quantity` is never editable.** There is no quantity field on the form, and SaveProduct
  strips one if it arrives anyway. Stock only moves through the ledger (P13), which is what
  keeps the cached figure and the movements behind it in agreement (database-review §2.8).
  A new product starts at zero and is stocked by a purchase.
* **`flat_price` is a real column now.** Legacy accepted it as mass-assignable with no column
  behind it, so every value was silently discarded (features.md §5).
* **The three prices are one permission, and it reaches every surface.** Without
  `products.set-prices` the form omits them, the request rejects them, the show page hides
  them, the print sheet drops its price columns and the CSV export leaves them out — so a
  catalogue export cannot leak the buy price to someone who may not see it.
* **Archiving is blocked by open orders, not by stock on hand.** A discontinued item often
  has a few units left; an open order line, on the other hand, could no longer be invoiced.

The ledger was built in P13. Six decisions:

* **One action writes stock, and it locks the row.** `PostStockMovement` creates the movement
  and increments `products.quantity` inside a transaction, after `lockForUpdate` on the
  product, so two people selling the last unit cannot both pass the guard. Legacy moved stock
  from three places — a manual table, invoice creation, and invoice edits that incremented in
  place — which is how a quantity became unexplainable (database-review §2.8).
* **Sign and type have to agree.** A purchase is positive and needs both a supplier and a unit
  cost, a sale or a transfer is negative, and a zero movement is rejected outright. A purchase
  without cost and supplier would leave the supplier balance and the stock value guessing.
* **Nothing goes below zero.** The insufficient-stock guard names the product, what it holds
  and what the movement wants: "Not enough stock: Aromec 3% has 3, this adjustment needs 5."
  The message lands on the quantity field of whichever form posted it.
* **Movements are append-only.** There is no edit and no delete route. A mistake is corrected
  by a further movement with a reason, which is why the reason is required on every manual
  stock-out and why the ledger keeps `created_by`.
* **The cache is checked nightly, and the check is a page too.** `stock:reconcile` compares
  every cached quantity against the sum of its movements and exits non-zero on drift;
  `stock:rebuild` sets the cache back to the ledger. `/stock/reconcile` shows the same report
  to anyone with `stock.view`, so the legacy −10 product would now be visible the same day.
* **Two permissions, one form.** `stock.out` covers transfers and returns, `stock.adjust`
  covers correcting the count. Both reach `/stock/out`; the form request picks the permission
  from the movement type, so an adjuster cannot post a transfer.

Low stock is a page (`/stock/low-stock`, `stock.low-stock`) listing everything at or below its
own threshold with the cost to restock, plus `stock:low-stock-digest`, a 07:00 database and
mail digest sent only when something is actually low, and only to active users who hold the
permission.

### 3.6 Orders — [`order-approval-design.md`](./order-approval-design.md)
* Officer creates (`pending`); lines `{product, qty, bonus, price_type, unit_price}`,
  discount %, offer, cash/credit; `CreditGuard` blocks over-limit submissions.
* **Direct manager** (or admin with `orders.approve`) approves / rejects / unapproves;
  `CreditGuard` re-runs at approval; breach needs `orders.approve-over-limit` + reason.
* `approved` orders are locked for the officer and are the only ones that can be invoiced;
  `invoiced` / `cancelled` are terminal — **orders are never deleted**.
* Approvals inbox with credit columns and bulk approve; pending-approvals badge; escalation
  after 24 h; settings switches (approval required, upline, auto-approve cash, escalation).
* Every transition stamped and logged with a credit snapshot.

#### The shared line editor (built in P14)

`App\Support\Sales\DocumentTotals` is the only place order and invoice money is added up,
and `resources/js/lib/totals.ts` mirrors it. `tests/fixtures/document-totals.json` is one
table of cases that both the Pest test and the vitest test run, so the two can never drift
apart quietly. Five decisions:

* **The browser computes the same numbers as the server, not similar ones.** Legacy added
  totals up in the invoice controller, again in the order controller and again in each
  report, so a report could disagree with the invoice it summarised (features.md §11).
* **Bonus units are free goods.** A line is worth `unit_price × quantity`; bonus moves stock
  and never money.
* **Discount arrives two ways.** Orders carry a percentage (`orders.discount_percent`),
  invoices carry an amount plus a "less"; both end at
  `roundBusiness(subtotal − discount − less)`, the .50↓ / .51↑ rule the business has always
  used. The rounding is shown on the form rather than hidden.
* **Discount and less are apportioned per line, and the remainder is placed, not dropped.**
  Each share is proportional to the line amount, and the last paisa lands on the largest
  line, so product-wise and category-wise revenue reconciles to the grand total exactly.
* **A product appears once per document.** `order_lines` and `invoice_lines` are unique per
  product, so the editor flags a repeat instead of letting the insert fail.

The editor renders hidden inputs, so it drops into an Inertia `<Form>` with no extra wiring,
and `/dev/kit` shows its totals beside the server's answer for the same payload — the P14
exit criterion, visible in a browser.

#### The approval workflow (built in P15)

Built to `order-approval-design.md`; §10's table is now `tests/Feature/Orders/OrderApprovalTest.php`.
Six decisions worth keeping:

* **The check that legacy never had.** `OrderPolicy` decides every transition: admin level
  anywhere, otherwise the officer's **direct** manager, plus the upline only when
  `orders.approval.allow_upline` is on — and never the order's own officer, whatever
  permissions they hold. Legacy flipped `pending ⇄ active` on a route that trusted the
  caller and only hid the button in the UI.
* **The credit gates run twice, and the second run is the one that counts.** They block a
  submission outright, and they run again at approval because payments and other orders move
  the position in between. A breach then needs `orders.approve-over-limit` **and** a reason,
  which is stored on the order and shown in its history.
* **Every decision is a record.** Each transition writes an activity-log entry with the
  reason and a snapshot of both credit positions, so a dispute months later can show what
  the approver was looking at.
* **Orders are never deleted.** `cancelled` and `invoiced` are terminal, a rejected order is
  edited and resubmitted (the edit *is* the resubmission), and an approved order is frozen
  until somebody un-approves it with a reason.
* **The bell stays quiet.** Only the direct manager is notified, or the admins when the
  officer reports to Head Office. Anything still undecided after
  `orders.approval.escalate_after_hours` is escalated once — `orders.escalate` runs hourly
  and stamps `orders.escalated_at`, a column added in P15 for exactly that.
* **Settings decide how much ceremony there is.** `orders.approval.required` off creates
  orders already approved, `auto_approve_cash` skips the queue for cash orders inside every
  limit, and credit orders always wait for a person. `App\Support\Settings` reads the table
  with a cache; the screen that writes it is P24.

Two defects the browser drive caught, both worth remembering: a page that eager-loads a
relation with a **column subset** (`employee:id,name`) hands the policy a null `manager_id`
and the credit guard a null `credit_limit`. The policy and `DecideOrder::recheckCredit` now
read those rows whole, and a regression test pins it.

### 3.7 Sales / invoices
* `CreateInvoice` (transaction): `invoice_no` from `document_sequences`, totals recomputed
  with `Money::roundBusiness()`, `CreditGuard`, one `stock_movements(sale)` per line with
  `cost_price` snapshot, optional immediate payment + allocation, link/close the order.
* `UpdateInvoice` posts reversal + new movements; `DeleteInvoice` = soft delete + reversal,
  blocked if payments are allocated.
* Events → notifications, manager mail, **customer SMS (queued)**.
* Print view (Blade + `PrintLayout`), PDF, mark-printed, update-offer, mobile-width print.
* Visibility by **customer ownership** (see offboarding §4); "sales by employee" reports use
  `invoices.employee_id`.

#### Invoices (built in P16)

* **One transaction writes the whole sale.** Number, totals, both credit gates, one
  `stock_movements(sale)` per line, the cash taken at the door, and closing the order it came
  from. Legacy did the same work in three places, each able to fail alone, which is how an
  invoice could exist with no stock movement behind it (database-review §2.8).
* **Two snapshots, never recomputed.** `invoice_lines.cost_price` freezes what the goods cost
  on the day, so a margin report is not rewritten by tomorrow's buy price; `invoices.employee_id`
  freezes who sold it, while visibility follows the customer's current owner
  (offboarding-design §4).
* **Editing is a reversal plus a fresh posting.** `UpdateInvoice` posts `sale_reversal` rows
  for the old lines and `sale` rows for the new ones, so `stock:reconcile` can still explain
  every unit. Legacy incremented `products.quantity` in place.
* **Deleting is soft, and blocked by money.** The stock comes back, the row and its number
  stay, and an invoice with payments allocated to it cannot be deleted — unpicking an
  allocation is a payment decision (P17). An order whose invoice is deleted goes back to
  `approved`.
* **Bonus units move stock but never money.** The line is worth `unit_price × quantity`; the
  movement is `quantity + bonus_qty`.
* **Due is still never stored.** `App\Support\Invoices\InvoiceDue` computes it as
  grand total minus allocations, for one invoice, for a filter, and for the list summary —
  opening balances included, which is what holding them as invoices bought us
  (database-review §2.5).
* **Conversion keeps the order.** `ConvertOrderToInvoice` copies the lines, the offer and the
  order's officer, then marks the order `invoiced`; legacy deleted the order and its lines,
  so nobody could compare what was ordered with what was delivered.
* **Print is Chrome, PDF is dompdf.** Both read one array from
  `App\Support\Invoices\InvoiceDocument`, so the paper and the file can never disagree.
  The PDF is English-only by design (pdf-engine-spike.md); the screen print renders Bangla
  correctly because it is a browser.

Two defects the drive caught, both worth carrying forward:

* **A `date` cast serialises as an ISO timestamp**, which `<input type="date">` rejects
  outright — the field simply renders empty. Every DATE column is now cast `date:Y-m-d`
  (invoices, orders, employees, products, payments, salaries, expenses, stock movements and
  the two history tables), so a date is a day everywhere it crosses the wire.
* **A guard that only throws is invisible.** Deleting a paid invoice returned a validation
  error the show page never rendered, so the dialog closed and looked like it had worked.
  The delete now reports the guard's message as a toast.

### 3.8 Payments
* One `payments` table: `direction in|out`, morph counterparty (customer / supplier /
  employee), `collected_by`, method, reference, date. `payment_allocations` settle invoices
  oldest-first or explicitly; unallocated = advance.
* Customer due = `Σ open invoices − Σ allocations` — one accessor everywhere. SMS on customer
  payment (queued).

#### Payments (built in P17)

* **One ledger, one meaning per column.** Legacy's `payments` had three nullable foreign
  keys, so a row could name a customer *and* a supplier, or neither, and `employee_id` meant
  "collector" or "payee" depending on its siblings (database-review §2.6). The counterparty
  is now a morph — exactly one party — and `collected_by` only ever means who took the money.
* **Every taka can be traced, or deliberately not.** `payment_allocations` settle invoices
  oldest first by default, which is how a collector applies cash; an explicit map overrides
  that; anything left over stays as an advance and is reported as `unapplied`.
* **Nothing about a due is stored.** `App\Support\Payments\CustomerBalance` is the single
  calculation: `billed − received`, floored at zero. The customer page, the credit gates,
  the payment screens and the print history all read it, so the three disagreeing answers of
  the legacy system (database-review §2.5, §2.9) cannot come back.
* **Money in hand frees credit immediately.** An unapplied advance still reduces what the
  customer owes, so a customer who pays before ordering is not blocked by their own cash.
* **Deleting a payment gives the money back.** The allocations go first, so the invoices it
  settled owe again, then the row is soft-deleted with its number intact.
* **Allocation guards are per invoice and per payment.** Nothing can take more than an
  invoice still owes, or more in total than the payment is worth, and money can only be
  pointed at invoices belonging to the same customer.

### 3.9 Expenses & payroll
* `expense_categories(scope office|employee)` merges the two legacy tables; expenses with
  receipt attachment.
* Salaries: unique (employee, month); carry-forward in `PostSalary`; final settlement from
  handover; "next payable" computed on the payroll index.

Payroll was built in P21. **The formula is legacy's, the carry-forward is not.** Four decisions:

* **A row's `due`/`advance` is the position it carries out of that month**, and the next month
  reads only that row. Legacy summed the due and advance of *every* earlier row
  (`SalaryController@store`), so a due that had already been settled kept inflating every
  month after it: Jan short 400 → Feb pays 1400 and settles it → Mar is charged the same 400
  again. The same bug credited a recovered advance twice. With one row in the whole Feb-2026
  snapshot, nothing in production depends on the old behaviour.
* **Every later month is re-derived on any write** (`RebuildSalaryLedger`). Correcting,
  back-dating or deleting a month rewrites the chain below it, so the ledger cannot disagree
  with itself — legacy computed a row once and never revisited it.
* **The month's basic salary is a snapshot.** A raise changes what the *next* month costs; it
  does not rewrite a month already paid. `UpdateSalary` can still correct it by hand.
* **Nothing may be posted for a month that has not started, or after the month somebody
  left.** "Next payable" is clamped to the current month, and when that lands on a
  part-paid month the figure offered is what is still outstanding on that row rather than
  another whole month's salary.

`CompleteHandover` now opens the leaver's final settlement (`CreateFinalSettlement`) with the
clearance figures in the note — step 7 of offboarding-design §3.2.

Expenses were built in P20 (payroll is P21). Four decisions:

* **`employee_id` is the only discriminator.** NULL means the office paid it, a value means
  it is charged to that person. Legacy needed two category tables and three nullable foreign
  keys — `cost_cat_id`, `employee_cost_cat_id`, `employee_id` — to say the same thing
  (database-review §2.3). The office/employee "view" on the list is one `WHERE` on that column.
* **A category carries a scope, and it is enforced on both sides.** An office category cannot
  be charged to a person and a field category cannot be booked against the office
  (`ResolveExpenseCategory`); the form only offers what fits, and the server checks again,
  including when an edit flips a row from one side to the other. Narrowing the scope of a
  category rows already rely on is refused, and says how many are in the way.
* **Office rows are company-wide, claims follow the subtree.** `Expense::scopeVisibleTo`
  shows every office row to anybody holding `expenses.view`, but a claim only to the chain
  above that employee — the same two-key thinking as offboarding-design §4.
* **A deleted expense keeps its receipt.** The row is soft-deleted and the file stays: a
  claim that was withdrawn is still evidence of what somebody handed in. Deleting a category
  counts soft-deleted expenses too, so a restore can never land on a missing category.

### 3.10 Reports & dashboard
12 report classes replace 28 controller methods (`SalesSummary`, `SalesByCustomer`,
`SalesByProduct`, `SalesByCategoryBrand`, `DueInvoices` (ageing), `CustomerStatement`,
`Collections`, `EmployeePerformance`, `SupplierLedger`, `Inventory`, `ProfitLoss` +
`CashFlow`, `ProductProfitability`) — each with date range / days, hierarchy scoping, Excel
export, print. Dashboard widgets via Inertia **deferred props**, cached 5 min.

Built in P22. Five decisions:

* **Every report answers the same four questions** — columns, rows, totals, headline figures
  (`App\Support\Reports\Report`). That one interface is why twelve reports need one page,
  one CSV writer and one print view; legacy's twenty-eight methods each carried their own
  copy of the date handling and their own table markup.
* **Apportioned figures are reconciled to the documents.** Splitting an invoice's discount
  across its lines drifts by paisa, so `BaseReport::reconcile()` puts the difference on the
  largest row — the same rule `DocumentTotals` uses inside an invoice. Product-wise revenue
  therefore adds up to the sales figure exactly, which legacy's never did.
* **Cost of goods is the price captured at sale time** (`invoice_lines.cost_price`), not the
  value of stock bought in the same window. Legacy used the latter, so a month with a big
  delivery showed a loss and the month that sold it showed a fortune.
* **Two keys again, as in offboarding-design §4.** Sales are keyed on `invoices.employee_id`
  (who sold it, never changes); dues and collections follow current customer ownership. The
  employee report shows both columns side by side for exactly that reason.
* **The cache is a version number, not tags.** The store is `database`, which has no tags, so
  `ReportCache` versions every key and any write to an invoice, payment, expense, salary or
  stock movement bumps it (`AppServiceProvider::configureReportCache`). A deploy is not a
  write, so `php artisan reports:flush` belongs in the deploy script.

The exit criterion — every report reconciling with the invoice and payment sums on the demo
seed — is asserted in `tests/Feature/Reports/ReportTest.php`.

### 3.11 Notifications
Database + Reverb (`private-App.Models.User.{id}`); bell with unread count from shared props;
dropdown of the latest twenty; prunable (read > 30 d, unread > 90 d).

Built in P18. Four decisions:

* **The bell is written inline, everything that leaves the building is queued.** Every
  notification is `ShouldQueue`, but `viaConnections()` keeps `database` and `broadcast` on
  `sync` and sends `mail` to the queue. A notification nobody can see until a worker runs is
  not a notification; an invoice that waits on SMTP is the legacy bug being replaced.
* **Broadcasting needs a worker.** `ShouldBroadcast` events are queued by Laravel, so the
  live push arrives once `queue:work` is running — without it the bell still updates on the
  next page visit. Both `reverb:start` and a worker belong in the deployment (§8).
* **`App\Models\Notification` subclasses Laravel's `DatabaseNotification` for one reason:
  pruning.** The framework's model never expires, and `model:prune` runs nightly at 02:15.
* **The channel authorises the owner and nobody else**, and a deactivated account is refused
  at the door before it ever reaches the channel.

### 3.12 SMS
`SmsGateway` interface (`MimSms`, `Log` driver); credentials in the environment, never in the
database (the settings screen only says whether the box has them); templates with
placeholders; broadcast to selected / all / filtered customers, **queued in chunks**, rate
limited; history with gateway response, summary, balance.

The gateway, the templates and the two automatic messages were built in P18; the broadcast
screens, the template editor and the history came in P23. Four decisions from P18:

* **`log` is the default driver.** It writes the message to the log and reports success, so
  development, tests and staging need no credentials — and nobody texts a real shopkeeper
  from a seeded database by accident.
* **A missing credential is a failed row, not an exception.** Legacy let a gateway problem
  surface in the middle of writing an invoice; here the send is a queued job whose result is
  stored on the `sms_messages` row, and the sale is never affected.
* **The two hard-coded legacy messages are templates now** (`invoice_created`,
  `payment_received`), rendered by `SmsTemplateRenderer` with `{placeholders}` filled from
  the document. An unfilled placeholder is dropped rather than texted as literal braces.
* **Only the template text is cached, never the model.** A cached Eloquent model came back
  as `__PHP_Incomplete_Class` and broke invoice creation with a 500 — caught by the browser
  drive, fixed by caching the string.

And four more from P23:

* **The audience is a value object, and it is the guard.** `SmsAudience` applies the three
  rules legacy's broadcast endpoint had none of: SMS switched on, a number the gateway can
  actually dial, and inside the sender's own hierarchy. A "filtered" audience that narrows
  nothing counts as everybody, so it needs `sms.send-all` like the explicit choice does.
* **The count is shown before the send, not after.** The broadcast screen asks the server how
  many customers would really be texted and puts that number on the button. On the demo data
  it reads 29 of 40 — the eleven with unusable numbers are excluded, which is exactly the
  thing an operator needs to see before pressing send.
* **Nothing is sent from the web request.** The audience is split into chunks of 200 and
  handed to the queue (`SendSmsChunk`), which writes the rows and queues one `SendSms` each.
  Legacy looped over every customer inside the request and timed out. Personalisation
  (`{customer_name}`, `{customer_code}`, `{due}`) happens in the chunk, where reading a
  balance per customer is affordable.
* **A system template may be reworded, never deleted.** `invoice_created` and
  `payment_received` are looked up by key when an invoice or a payment is written, so the
  policy refuses to delete a keyed row and the editor says to switch it off instead. Every
  save forgets the renderer's ten-minute cache, or a corrected message would keep going out
  wrong.

### 3.13 Settings, licence and backups
Company profile & logo, numbering (prefixes, next numbers), low-stock threshold, order
approval switches, SMS gateway, mail test, licence status, backup download. Six decisions
from P24:

* **A setting is only a setting if something reads it.** Every key on the screen has a
  consumer: the company block is what `App\Support\CompanyProfile` puts on every printed
  document (it was `config('app.name')`, so changing the letterhead meant editing `.env` and
  redeploying), the threshold is the default a new product starts with, the approval switches
  are read by `SaveOrder`, `OrderApprovers` and `InvoicePolicy`. Keys nobody reads were not
  added.
* **Only a known key can be written.** `SaveSettings::KEYS` is the whole list, with the type
  each value is read as. A crafted request cannot invent `licence.key` or `app.debug` and
  have the application later believe it.
* **Numbering saves on its own, and only goes up.** A counter that has issued a number never
  issues it again, so `UpdateNumbering` refuses a lower `next` and says what the counter is
  already at. Legacy reset invoice numbers every financial year, which is precisely how two
  invoices came to share one number (database-review §2.1). Raising it is allowed — that is
  what the ETL does after importing legacy documents.
* **Credentials stay in the environment.** The SMS page says which gateway is configured and
  whether this box has an API key; it never shows the key and never stores one in the
  database. The mail test sends to the signed-in user's own address, so the company's server
  cannot be used to mail a stranger.
* **The licence is checked on the server, offline, and it forgives.** A key is a small JSON
  payload signed with Ed25519; the installation only ever verifies, so a licence cannot be
  minted on the customer's box and no network call is needed. A fresh install runs for
  `trial_days` (ETL, UAT and training happen there) and an expired key keeps working for
  `grace_days` with a banner — a renewal late in the post must not stop the sales day. Only
  the signature is cached; the dates are compared every request, so a licence expires on the
  day it expires. When it does block, everything redirects to the licence screen, which any
  signed-in user may read, so "why has the system stopped?" is answered on screen.
* **A backup is the whole company in one file.** It goes to a private disk, is queued rather
  than run in the request, and is served only through the controller behind `system.backups`,
  and only for a name that is really in the list. `Pulse` was left out: the box runs one
  application and Flare/Sentry already covers what it would show.

---

## 4. Data model (final table list)

| Table | Notes |
| --- | --- |
| `users`, `password_reset_tokens`, `sessions` | starter kit; `users.employee_id` unique |
| `roles`, `permissions` (+ `module`, `label`, `sort`), `model_has_*`, `role_has_permissions`, **`user_denied_permissions`** | Spatie + deny table |
| `designations` | `+ level` |
| `employees` | `+ manager_id` (nullable = Head Office), `deactivated_at`, `deactivation_reason`, `last_working_day`, `successor_id`, encrypted `national_id`, soft deletes |
| `reporting_lines`, `customer_assignments` | history with `valid_from/valid_to`, `is_caretaker` |
| `handovers`, `handover_items` | offboarding / promotion / leave workflow |
| `customers` | `customer_code`, E.164 `phone`, `credit_limit`, `sms_enabled`, `archived_at` |
| `suppliers` | `+ archived_at` |
| `brands`, `categories` | |
| `products` | `+ flat_price`, `low_stock_threshold`, `archived_at`; `quantity` cached |
| `stock_movements` | unified ledger (replaces `stock_in_outs`) |
| `orders`, `order_lines` | `order_no` unique, `status pending/approved/rejected/cancelled/invoiced`, `submitted_at`, `escalated_at`, `approved_by/at`, `rejected_by/at`, `rejection_reason`, `approval_note`, `approved_over_limit`, `cancelled_at`; the invoice is reached via the unique `invoices.order_id` (no circular FK) |
| `invoices`, `invoice_lines` | `invoice_no` unique, `order_id`, `type sale|opening`, `cost_price` on lines, soft deletes |
| `payments`, `payment_allocations` | direction + morph counterparty |
| `expense_categories`, `expenses` | merged |
| `salaries` | unique (employee, month) |
| `sms_templates`, `sms_messages` | |
| `notifications`, `activity_log`, `document_sequences`, `settings` | |
| `jobs`, `failed_jobs`, `cache` | framework |

All money `DECIMAL(15,2)`, all dates `DATE`/`DATETIME`, `created_by`/`updated_by` on
transactional tables, indexes per `database-review.md` §3.8.

---

## 5. Frontend kit (built in Stage A, reused by every module)

| Piece | Behaviour |
| --- | --- |
| `AppLayout` | Sidebar from `nav.ts` filtered by `useCan`; topbar with bell, approvals badge, user menu; flash toasts |
| `DataTable` | TanStack Table, server-side pagination/sort/filter via URL, column visibility, row actions, export |
| `Field` + inputs | shadcn inputs wired for `<Form>` and `useForm`; `MoneyInput` (2 dp, no float maths), `DateInput`/`MonthInput` (native `date`/`month` inputs — reliable on field phones, no calendar library), `SelectField`, `ImageUpload` |
| `Combobox` pickers | Async customer / product / employee / supplier search (session-protected JSON lookups — the only JSON endpoints), wrapped as `ProductPicker`, `CustomerPicker`, `EmployeePicker`, `SupplierPicker` so no page writes its own fetch |
| `LineItemsEditor` + `TotalsPanel` | Shared by Order and Invoice forms; every figure comes from `lib/totals.ts`, the mirror of `App\Support\Sales\DocumentTotals` |
| `PermissionMatrix` | Role matrix and user override (tri-state) |
| `Can`, `useCan` | Permission gate from shared props |
| `Timeline` | Order / handover history |
| `PrintLayout` | Invoices and reports |
| Charts | Recharts |

---

## 6. Delivery

Detailed in [`workplan.md`](./workplan.md): **27 phases of 2–5 days**, grouped in 6 stages,
two parallel developer tracks, ~86 developer-days of build plus review/buffer → **14 calendar
weeks** for two developers.

| Stage | Weeks | Phases | Milestone demo |
| --- | --- | --- | --- |
| A – Foundation | 1–2 | P01–P05 | Login, layout, permissions kernel, Brands end-to-end |
| B – Organisation & access | 3–4 | P06–P09 | Employees + hierarchy + credit limits, roles/users matrix |
| C – Partners & catalogue | 5–6 | P10–P13 | Customers, suppliers, products, stock ledger |
| D – Orders, sales, money | 7–10 | P14–P19 | Order → approval → invoice → payment, notifications, SMS events, handover wizard |
| E – Expenses, payroll, reports, SMS, settings | 11–12 | P20–P24 | Full feature parity |
| F – Migration & cut-over | 13–14 | P25–P27 | Legacy data migrated and reconciled; go-live |

---

## 7. Legacy data migration (Stage F)

`php artisan legacy:migrate` reading a `legacy` DB connection, idempotent, run repeatedly
against a copy before the real cut-over.

| Legacy | v2 | Transform |
| --- | --- | --- |
| `designations` | `designations` | `level` from `slug` |
| `employees` + `relations` | `employees`, `reporting_lines` | `manager_id = relations.relation_id`; `status='deactive'` → `deactivated_at`; open history rows back-filled |
| `users` + `model_has_permissions` | `users`, roles, allows, denies | role from designation level; legacy names → new codes (mapping table); `allow = mapped − role`, `deny = role − mapped` |
| `customers` | `customers`, `customer_assignments`, opening invoices | code re-padded; phone → E.164; `old_due > 0` → `invoices(type=opening)`; customers of deactivated officers → **caretaker manager** (listed for admin) |
| `products` | `products` | incl. `flat_price` |
| `stock_in_outs` | `stock_movements` | `in` → `purchase`; `out` → `adjustment` |
| `invoices` + `invoice_products` | `invoices`, `invoice_lines`, `stock_movements(sale)` | new `invoice_no` chronological; `legacy_invoice_no`, `legacy_id` kept; `cost_price` from last purchase before `sale_date` |
| `payments` (customer) | `payments(in)` + allocations | FIFO against that customer's invoices by date |
| `payments` (supplier) | `payments(out)` | |
| `costs` + both category tables | `expenses`, `expense_categories` | merged with `scope` |
| `salaries` | `salaries` | `month_year` → `DATE` |
| `sms_templates` | `sms_templates` | no `sms_histories` table exists in the dump |
| `orders` (2 open rows) | `orders`, `order_lines` | only `pending`/`active` come across, as `pending`; closed ones stay behind |
| `notifications`, `personal_access_tokens` | — | not migrated |

**Reconciliation report** — `php artisan legacy:reconcile`, exits non-zero on any failure:
counts and money per table, per-customer due (legacy `old_due + Σ invoices − Σ payments` vs
v2 `billed − received`, ≤ 0.01), per-product stock (`quantity` vs `Σ qty_delta`), invoices
per officer per month, invoice-number uniqueness, and every `hierarchy:check` invariant.
Three outcomes: **ok**, **note** (an expected difference, explained) and **fail**.

Nine decisions from P25, taken against the real 2,544-invoice dump:

* **`legacy_id` is what makes a re-run safe.** Every mapper finds its row again by the legacy
  key (or a natural one where legacy had none — `orders` and `stock_movements` needed a
  column adding), so the ETL updates instead of duplicating and a run that dies at invoice
  2,000 is simply started again. Three dry runs and a cut-over are then the same command.
  Verified: the second run leaves every count and every invoice number identical.
* **Numbering is assigned once and never revisited.** Legacy reset `invoiceId` each financial
  year, so 2,544 invoices shared 64 numbers — "RAINVO-01" names sixty different documents.
  Openings and sales are sorted by date and numbered from the global sequence in one pass;
  an invoice that already has a number keeps it, so a second run cannot renumber the book.
  The old string stays in `legacy_invoice_no` for the customer holding a paper copy.
* **Money is compared row by row, not with `SUM()`.** Legacy stored money in `double`, and
  six invoices carry half a paisa (4,668.125). `DECIMAL(15,2)` cannot hold that, so the book
  moves by three paisa in total — reported as a note rather than hidden, because "the totals
  differ" needs an answer before anybody signs off. (`App\Support\Money` also learned to
  accept `2.0E-9`, which is what a `double` column hands you.)
* **Legacy's own arithmetic is not corrected.** Thirteen invoices were billed something other
  than `total_price − discount − less`. The amount the customer was actually asked to pay is
  what migrates; the report lists which ones so somebody can look.
* **Nobody loses access, and nobody is locked out of the new half.** A user's legacy page
  list becomes `allow = mapped − role defaults` and `deny = (role defaults ∩ what legacy
  could grant) − mapped`. The intersection matters: settings, backups, exports, approvals and
  offboarding have no legacy counterpart, so nobody could have held them — denying them would
  have left the administrator unable to open the settings screen on day one. They follow the
  role instead. Password hashes are copied as they are, so nobody resets forty accounts on
  cut-over night.
* **A customer nobody can see is the thing to prevent.** Twenty-three of sixty employees are
  `deactive` in legacy while their shops still point at them. Each such customer goes to the
  nearest **active** manager above the departed officer, flagged `is_caretaker`; when a whole
  line has left, to the most senior active person. The history is written as two rows — the
  original ownership closed on the officer's last working day, the caretaker's open from it —
  so the caretaker shows its true age on the hierarchy report instead of looking as though
  somebody has held the shop since it opened.
* **The stock ledger gains the half legacy never had.** `stock_in_outs` held purchases and
  odds and ends; sales decremented `products.quantity` in place. Every sale line now writes
  the movement that took the goods out, **bonus units included**, with `cost_price` from the
  last purchase before that sale — which is what makes profit reportable at all. The cached
  quantity is then rebuilt from the ledger rather than copied.
* **Everything derived is derived, not copied.** `employees.credit_limit` and
  `products.quantity` is recomputed at the end of the run (`stock:rebuild`) so the number
  comes out of the ledger instead of out of whatever legacy last wrote. `credit_limit` is
  copied as it stands — it is typed in both systems.
* **The migration is not written to the audit trail.** Forty thousand rows arriving is not
  something a person did, and it would bury the entries that are.

**What the dry run found in the data** (all reported, none blocking): 14 employees are
duplicated — the same person entered twice, once deactivated — and share a phone number, so
the second record keeps the number exactly as it was typed rather than having a suffix
appended to a working number; 4 customer numbers no gateway can dial; 4 employees report to
nobody; 10 shops are with a caretaker awaiting reassignment. A full run over the real dump
takes **12 seconds**.

**Cut-over evening:** legacy read-only → final ETL → reconciliation → switch → legacy kept
read-only 30 days → decommission.

---

## 8. Environment & operations

| Concern | v2 |
| --- | --- |
| Local dev | Laravel Herd or Sail; `composer dev` runs server, Vite, queue, Reverb |
| Config | `.env` never committed; runtime settings in `settings` table |
| Queue | `database` + `queue:work` under Supervisor; Redis/Horizon when broadcasts grow |
| Realtime | Reverb behind Nginx |
| Scheduler | `stock:reconcile` 01:45, `model:prune` 02:15 (notifications), `stock:low-stock-digest` 07:00, `orders:escalate` hourly, `hierarchy:check` 02:00 (exits non-zero on a broken invariant), `backup:clean` 02:30, `backup:run` 03:00 |
| Workers | `queue:work` (mail, SMS, broadcast events, `backup:run`) and `reverb:start` (websocket). Without the worker the bell updates on the next page visit instead of live; without Reverb it still updates, just never pushes. |
| Backups | nightly DB + `storage/app/public` to the private `backups` disk (`spatie/laravel-backup`), then copied off-box. `mysqldump` is a separate program: on Windows, and on any box where it is not on the PATH, set `DB_DUMP_BINARY_PATH` or the nightly backup fails silently until somebody looks. |
| Licence | signed key in `settings` (`licence.key`) or `LICENCE_KEY`; verified offline against the public key in `config/licence.php`. `licence:show` exits non-zero when the installation is blocked — put it in the deploy script. |
| Deploy | Forge/Ploi or `deploy.sh`: `composer install --no-dev`, `npm ci && npm run build`, `migrate --force`, `permissions:sync`, `reports:flush`, `licence:show`, `optimize`, restart workers/Reverb |
| Monitoring | Pulse (optional), Flare/Sentry |
| Security | HTTPS, `Secure`/`HttpOnly`/`SameSite` cookies, login + SMS rate limits, encrypted NID, **no dumps in git** |

---

## 9. Quality gates

* Pest tests per Action: credit guard (both gates × both sale types), rounding table,
  invoice numbering under concurrency, stock posting/reversal, payment allocation FIFO, salary
  carry-forward, hierarchy visibility per level, **order approval matrix**, **handover
  scenarios**, deny-override semantics.
* Generated route × role policy matrix test; `permissions:sync` idempotence.
* Report snapshot tests on a seeded dataset.
* Larastan level 6+, TypeScript `strict`, ESLint; CI on every PR with `migrate:fresh --seed`.

---

## 10. Risks & mitigations

| Risk | Mitigation |
| --- | --- |
| ETL gets a customer's due wrong | Reconciliation report + parallel-run week; legacy read-only 30 days |
| Field officers relied on offline mode | Confirm need (§0 #12); v2.1 offline module if real |
| Approval bottleneck when a manager is unavailable | Escalation after 24 h, Head-Office fallback, admin approval, `allow_upline` switch |
| Users resist UI change | Same navigation and flows; two training sessions; screen recordings |
| Bangla text on printed invoices | Decide dompdf vs Browsershot in P05 with a real sample |
| Scope creep | Parity list (§11) is v2; everything else → v2.1 backlog |
| Rules subtly differ from legacy | Port `calculateGrandTotal` and credit formulas verbatim first, cover with tests, then refactor |
| Vendor licence dependency | Server-side check with cache + grace period |

---

## 10a. Hardening and acceptance (P26)

Measured on the **migrated** database — 663 customers, 2,694 invoices, 6,054 lines, 1,045
payments — because that is the only volume that tells the truth. Six decisions:

* **A report is built once.** A report page asks for rows, totals and a summary, and the last
  two are made of the first, so every report was being computed three times. `BaseReport::rows()`
  is now final and memoised and each report implements `build()`. Due invoices went from
  **65,835 queries and 3.2 s to 10 queries and 0.8 s**.
* **A permission check must not touch the cache store.** `deniedPermissionNames()` read the
  cache on every `can()`, and a list calls `can()` once per row — with the database cache
  driver that was a SQL query per check, 400 on a report page. It is held for the request now;
  the shared cache still holds it between requests.
* **A loaded aggregate can be null.** `withSum('allocations as allocated')` yields `null`, not
  zero, on an invoice nobody has paid anything towards, so reading the value instead of testing
  the key sent the due calculation back to the database per row. The key is tested now.
* **Ask once for everybody.** The employee report asked `CustomerBalance::dueFor()` per row.
  `dueByOwner()` answers for every officer in two queries: 86 queries down to 14.
* **Two indexes were missing where a unique key looked like one.** `payment_allocations` is
  unique on `(payment_id, invoice_id)` but every due asks by `invoice_id`; `invoice_lines` is
  unique on `(invoice_id, product_id)` but every product report asks by `product_id`. A
  composite index does not serve a lookup on its second column.
* **Scripts get a nonce; styles keep `'unsafe-inline'`.** `SecurityHeaders` sets a
  Content-Security-Policy with a per-request nonce, plus `nosniff`, `DENY`, a referrer policy,
  a permissions policy and HSTS over HTTPS only. Locking scripts down is what stops a customer
  name from becoming a script; pretending to lock styles down would only break the UI kit,
  which sets element styles directly.

**After the pass, every page is 5–17 queries.** The slowest screen is due invoices at ~800 ms
for 1,396 unpaid invoices; every other page is under 350 ms.

**Verified rather than asserted:** a database backup of the migrated data was restored into a
scratch database and matched the original exactly (663 customers, 2,694 invoices, 6,054 lines,
1,045 payments, 2,163 allocations, 33,762,558.86 billed, 18,844,605.60 received). The
procedure is [`runbook.md`](./runbook.md) §4.

**Acceptance** is [`uat-plan.md`](./uat-plan.md): a script per role against migrated data, a
defect sheet with severities, six decisions the company has to take before cut-over (§2a), and
a sign-off table. Each role's script was dry-run against the migrated data first, so the
testers meet a system that works rather than one that 500s on the second screen.

---

## 11. Feature-parity checklist

| Legacy | v2 | Note |
| --- | --- | --- |
| Login, logout, profile, change password | ✅ | starter kit |
| Signup page | ❌ | never worked |
| Dashboard + 9 widgets | ✅ | deferred props, cached |
| Designations, Employees, relations, credit report | 🔁 | `manager_id`, handover wizard, one query |
| Users + permission tree | 🔁 | roles + per-feature permissions + user overrides |
| Customers (+ details, payments, SMS toggle) | 🔁 | E.164, opening invoice, ownership history, reassign |
| Suppliers (+ details, payments) | ✅ | |
| Brands, Categories (+ drill-down) | ✅ | |
| Products, stock in/out, ledger, low stock | 🔁 | `stock_movements` |
| Orders create/list/edit/approve/convert | 🔁 | state machine, manager/admin approval, never deleted |
| Sales create/list/edit/delete, offer, printed | ✅ | soft delete + reversal |
| Invoice PDF / mobile invoice | 🔁 | server-side PDF + responsive print |
| Sales report, payment-history report | ✅ | report classes |
| Cost categories ×2, office/employee costs | 🔁 | merged categories |
| Salaries (unrouted), Roles (unrouted) | ✅ | routed |
| SMS anyone/everyone/templates/history/balance | ✅ | queued, templated |
| Notifications (poll + sound) | 🔁 | Reverb push |
| Offline `localStorage` cache | ❌ | §0 #12 |
| `SystemSecurity` client socket | 🔁 | `EnsureLicenceIsValid`: signed key, verified on the server, cache + grace (§3.13) |
| ToDo app, theme toggler, Sales(backup) | ❌ | dead code |
| **New:** handover wizard, approvals inbox, audit log, settings, activity history | ➕ | from the design docs |
