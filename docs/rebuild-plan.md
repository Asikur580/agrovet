# Rebuild Plan — Radiant Agrovet v2 (Laravel + Inertia + React)

This plan replaces the current two-repo system (Laravel 11 JSON API + React SPA) with a
**single Inertia monolith**. It carries every business rule that works today, fixes the
problems documented in the reviews, and ends with a one-time migration of the live data.

**Companion design documents** (this plan references them; they are the source of detail):

| Document | Covers |
| --- | --- |
| [`database-review.md`](./database-review.md) | Why the schema changes; column-level redesign, indexes |
| [`permissions-design.md`](./permissions-design.md) | Per-feature permission catalogue (143 codes), role + user-level assignment with allow/deny |
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
| 10 | Invoice PDF | **Server-side** (`laravel-dompdf`; Browsershot if Bangla text must render) | client-side `@react-pdf` | §3.7 |
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
* Business rules: hierarchy scoping; cascading credit limits; employee and customer credit
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

### 3.2 Roles & permissions — [`permissions-design.md`](./permissions-design.md)
* **143 permissions** in 24 modules, codes in `config/permissions.php`, synced by
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
* **Credit limit**: `RecalculateCreditLimit` cascades upward; nightly `credit:rebuild`.
* **Leaving / promotion / leave = handover wizard**: customers, subordinates, open orders
  reassigned (or parked with a **caretaker manager** / **Head Office**) before deactivation;
  clearance checklist; final settlement; `hierarchy:check` nightly; reactivation.
* *Credit report* (*/employees/credit-report*) → one grouped query.

### 3.4 Customers & suppliers
* Customers (*/customers\**): code from `document_sequences`, owner must be an **officer**
  (or caretaker manager), E.164 phone, `credit_limit` (separate permission), opening balance
  as an **opening-balance invoice**, `sms_enabled`, `archived_at`, `customer_assignments`
  history, bulk **reassign** tool. Show page tabs: details, invoices, payments, SMS log.
* Suppliers (*/suppliers\**): details, purchases, payments, balance.

### 3.5 Catalogue & inventory
* Brands, categories with sales drill-down. Products: prices (`buy`, TP, `flat`) behind
  `products.set-prices`, `quantity` cached, expiry, low-stock threshold, archive.
* **`stock_movements`** ledger — `purchase` (*/stock_in*), `adjustment` / `transfer`
  (*/stock_out*, employee FK instead of free text), `sale`, `sale_reversal`, `return`.
  `PostStockMovement` is the only writer of `products.quantity`; `stock:rebuild` reconciles.

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

### 3.8 Payments
* One `payments` table: `direction in|out`, morph counterparty (customer / supplier /
  employee), `collected_by`, method, reference, date. `payment_allocations` settle invoices
  oldest-first or explicitly; unallocated = advance.
* Customer due = `Σ open invoices − Σ allocations` — one accessor everywhere. SMS on customer
  payment (queued).

### 3.9 Expenses & payroll
* `expense_categories(scope office|employee)` merges the two legacy tables; expenses with
  receipt attachment.
* Salaries: unique (employee, month); carry-forward in `PostSalary`; final settlement from
  handover; "next payable" computed on the payroll index.

### 3.10 Reports & dashboard
12 report classes replace 28 controller methods (`SalesSummary`, `SalesByCustomer`,
`SalesByProduct`, `SalesByCategoryBrand`, `DueInvoices` (ageing), `CustomerStatement`,
`Collections`, `EmployeePerformance`, `SupplierLedger`, `Inventory`, `ProfitLoss` +
`CashFlow`, `ProductProfitability`) — each with date range / days, hierarchy scoping, Excel
export, print. Dashboard widgets via Inertia **deferred props**, cached 5 min.

### 3.11 Notifications
Database + Reverb (`private-user.{id}`); bell with unread count from shared props;
paginated dropdown; prunable (read > 30 d, unread > 90 d).

### 3.12 SMS
`SmsGateway` interface (`MimSms`, `Log` driver); credentials in Settings; templates with
placeholders; broadcast to selected / all / filtered customers, **queued in chunks**, rate
limited; history with gateway response, summary, balance.

### 3.13 Settings
Company profile & logo, numbering (prefixes, next numbers), low-stock threshold, order
approval switches, SMS gateway, mail test, licence status, backup download.

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
| `orders`, `order_lines` | `status pending/approved/rejected/cancelled/invoiced`, `submitted_at`, `approved_by/at`, `rejected_by/at`, `rejection_reason`, `approval_note`, `approved_over_limit`, `invoice_id` |
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
| `FormField*` | shadcn inputs bound to `useForm`; `MoneyInput` (2 dp, no float maths), `DatePicker`, `MonthPicker`, `ImageUpload` |
| `Combobox` pickers | Async customer / product / employee search (session-protected JSON lookups — the only JSON endpoints) |
| `LineItemsEditor` | Shared by Order and Invoice forms; live totals mirror server rounding |
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
| `sms_templates`, `sms_histories` | `sms_templates`, `sms_messages` | |
| `orders` (3 open rows) | recreated by hand as `pending` | |
| `notifications`, `personal_access_tokens` | — | not migrated |

**Reconciliation report** (all green before cut-over): per-customer due (legacy formula vs
v2 derived, ≤ 0.01), per-product stock (`quantity` vs `Σ qty_delta`), counts and sums per
table, invoices per employee per month, 20 printed invoices spot-checked, every
`hierarchy:check` invariant.

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
| Scheduler | prune notifications, `credit:rebuild`, `stock:reconcile`, `hierarchy:check`, order escalation, backups |
| Backups | nightly DB + uploads off-box (`spatie/laravel-backup`) |
| Deploy | Forge/Ploi or `deploy.sh`: `composer install --no-dev`, `npm ci && npm run build`, `migrate --force`, `permissions:sync`, `optimize`, restart workers/Reverb |
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
| `SystemSecurity` client socket | 🔁 | server middleware |
| ToDo app, theme toggler, Sales(backup) | ❌ | dead code |
| **New:** handover wizard, approvals inbox, audit log, settings, activity history | ➕ | from the design docs |
