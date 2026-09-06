# Work Plan — Radiant Agrovet v2

Companion to [`rebuild-plan.md`](./rebuild-plan.md) §6. **27 phases of 2–5 days**, six
stages, two developer tracks, 14 calendar weeks.

**Assumptions:** two developers (A = backend-leaning, B = frontend-leaning; both full-stack
enough to review each other), a product owner available ~2 h/week plus one demo per week,
the §0 decisions of the rebuild plan confirmed by the end of Stage A.

**How to read a phase**

* **Effort** = developer-days of build (tests included). Calendar time is longer: reviews,
  demos and a ~35 % buffer are added at stage level.
* **Depends on** = phases that must be merged first.
* **Exit** = what must be true before the phase is closed (demoable, tested, merged to `main`).

---

## 1. Stage overview

| Stage | Weeks | Phases | Effort (dev-days) | Milestone |
| --- | --- | --- | ---: | --- |
| A – Foundation | 1–2 | P01–P05 | 14 | **M1** Login, layout, permissions kernel, Brands end-to-end |
| B – Organisation & access | 3–4 | P06–P09 | 10 | **M2** Employees, hierarchy, credit limits, roles & users |
| C – Partners & catalogue | 5–6 | P10–P13 | 12 | **M3** Customers, suppliers, products, stock ledger |
| D – Orders, sales, money | 7–10 | P14–P19 | 24 | **M4** Order → approval → invoice → payment; handover wizard |
| E – Expenses, payroll, reports, SMS, settings | 11–12 | P20–P24 | 15 | **M5** Feature parity |
| F – Migration & cut-over | 13–14 | P25–P27 | 11 | **M6** Go-live |
| | | | **86** | + reviews/buffer ≈ 14 weeks × 2 devs |

---

## 2. Phase catalogue

### Stage A — Foundation (weeks 1–2)

#### P01 · Project bootstrap — 2 days · both
* `laravel new agrovet --react --pest`; TypeScript `strict`; Tailwind 4 + shadcn/ui init.
* Pint, Larastan (level 6), ESLint + Prettier, Husky pre-commit (lint + types).
* GitHub Actions: PHP tests on MySQL service, `migrate:fresh --seed`, `npm run build`, type-check.
* `.env.example` complete (DB, mail, Reverb, SMS keys); Herd/Sail instructions in `README`.
* Branching: `main` protected, feature branches, PR template with the Definition of Done (§5).
* GitHub Projects board seeded with P01–P27 as epics.

**Exit:** CI green on an empty app; both devs can run it locally in < 15 min.

#### P02 · Schema, factories, seeders — 3 days · Dev A
* All migrations from `rebuild-plan.md` §4 and `database-review.md` §3 (money `DECIMAL`,
  `DATE`, `document_sequences`, `settings`, `stock_movements`, `payments` +
  `payment_allocations`, `reporting_lines`, `customer_assignments`, `handovers`,
  `user_denied_permissions`, order approval columns, indexes).
* Model classes with casts, relationships, soft deletes, `created_by/updated_by` trait.
* Factories for every model; `DemoSeeder` mirroring legacy shapes (1 rsm → 2 managers →
  4 officers → 40 customers, 30 products, 200 invoices, payments, expenses).

**Exit:** `migrate:fresh --seed` < 30 s; Larastan clean; ER matches the plan.

#### P03 · Domain kernel — 3 days · Dev A
* `Money` (BCMath, `roundBusiness()` `.50↓/.51↑`), `DocumentNumber` (`lockForUpdate`),
  `CreditGuard` (employee gate, customer gate on credit) — ported **verbatim** from legacy
  formulas, table-driven tests.
* `Employee::scopeVisibleTo()` with recursive CTE + per-user cache; scopes on Customer,
  Order, Invoice (by customer ownership), Payment.
* Permissions kernel: `config/permissions.php` (all 137 codes), `permissions:sync`
  (+ `--constants` → `Perm` class + `permissions.ts`), `Gate::before` for `super-admin`,
  `User::hasPermissionTo` deny override, `effectivePermissionNames()`, cache flush hooks.
* `HandleInertiaRequests` shared props (`auth`, `flash`, `pendingApprovals`, `notifications.unread`).
* Activity log installed; `EnsureUserIsActive` middleware.

**Exit:** tests for rounding, numbering under concurrency, credit gates, visibility per level,
deny-beats-role, super-admin bypass all green.

#### P04 · UI kit — 4 days · Dev B
* `AppLayout` (sidebar from `nav.ts` + `useCan`, topbar, flash toasts, bell placeholder,
  approvals badge), `PrintLayout`, `PageHeader`, `ConfirmDialog`, `EmptyState`.
* `DataTable` (TanStack, server-side pagination/sort/filter in URL, column visibility, row
  actions, export button, `router.reload({only})`).
* `FormField*` bound to `useForm`; `MoneyInput`, `DatePicker`, `MonthPicker`, `ImageUpload`,
  `Combobox` (async lookup endpoint pattern), `Can`/`useCan`, `Timeline`, `StatCard`.
* `lib/money.ts` mirroring `Money::roundBusiness` with the same test table.

**Exit:** Storybook-style demo page renders every component; a11y basics (labels, focus).

#### P05 · Reference module: Brands — 2 days · Dev B (review A)
* Index (DataTable, search, export), Create/Edit (Form Request → Action), Archive, sales
  drill-down placeholder, `BrandPolicy`, routes with `->can(Perm::…)`, feature tests, route ×
  role matrix test harness (generic, reused by all modules).
* Decide PDF engine with a real invoice sample (dompdf vs Browsershot) — spike, 0.5 day.

**Exit:** **M1 demo.** Brands is the template every CRUD module copies; §0 decisions confirmed.

---

### Stage B — Organisation & access (weeks 3–4)

#### P06 · Designations & Categories — 1.5 days · Dev B
Copy of Brands; designations carry `level`. Categories drill-down placeholder.

**Exit:** both modules pass the matrix test.

#### P07 · Employees & hierarchy — 3 days · Dev A
* CRUD with photo, encrypted NID, `manager_id` picker (filtered by level), lifecycle fields
  read-only here.
* `AssignManager` action (officer→manager→rsm, cycle guard, `reporting_lines` row).
* `RecalculateCreditLimit` (cascade) + `credit:rebuild` command; credit report as one
  grouped query; employee show page (details, team, customers, credit).

**Exit:** hierarchy tests for all four levels; credit roll-up tests; report equals legacy
formula on the demo seed.

#### P08 · Users, roles, permissions UI — 4 days · Dev B (backend bits A)
* Users CRUD (linked to employee, unique), role select, **Access tab** with tri-state
  `PermissionMatrix` (inherited / allow / deny), effective-permission preview page,
  read-only catalogue page, activity-log page.
* Roles CRUD + role matrix page with module/column select-all, "copy from role", affected
  user count.
* Lock-out guards, last-super-admin guard, cache flush + activity log on every change.

**Exit:** deny/allow scenarios green; changing a user's access is visible on their next
request; `permissions:sync` runs in CI and deploy.

#### P09 · Profile & auth polish — 1 day · Dev B
Profile page with metrics (customers, team, sales, credit), password change, login rate
limit, session invalidation on deactivation, password-reset mail.

**Exit:** **M2 demo** — create an officer, manager, RSM; assign chain; log in as each; see
scoped data and correct menus.

---

### Stage C — Partners & catalogue (weeks 5–6)

#### P10 · Customers — 4 days · Dev B (actions A)
* CRUD, code from `document_sequences`, E.164 phone normalisation, owner picker (officers
  in scope; caretaker managers allowed), `customers.set-credit-limit` gate, opening balance →
  opening-balance invoice, `sms_enabled` toggle, archive.
* `customer_assignments` written by `ReassignCustomer`; **bulk reassign tool** (by territory
  / by officer) behind `customers.reassign`.
* Show page tabs: details, invoices (placeholder), payments (placeholder), SMS log
  (placeholder), assignment history.
* Export, print.

**Exit:** scope tests (officer sees own, manager sees team, RSM subtree, admin all);
reassignment writes history and recalculates credit limits.

#### P11 · Suppliers — 2 days · Dev B
CRUD, show page (purchases, payments placeholders, balance), export.

#### P12 · Products — 3 days · Dev A
CRUD with images, prices behind `products.set-prices`, `flat_price`, expiry, low-stock
threshold, archive; product show page with ledger tab placeholder; export/print.

#### P13 · Stock movements — 3 days · Dev A
* `PostStockMovement` (only writer of `products.quantity`), forms for purchase (stock-in with
  supplier + unit cost), transfer/adjustment (employee FK, reason), ledger tab, `stock:rebuild`
  + `stock:reconcile` report, low-stock widget + daily digest job.

**Exit:** **M3 demo** — ledger reconciles in tests; product #2-style drift is detected by
`stock:reconcile`.

---

### Stage D — Orders, sales, money (weeks 7–10)

#### P14 · LineItemsEditor & pickers — 3 days · Dev B
Shared editor: product async picker, qty, bonus, TP/flat toggle, unit price, live totals via
`lib/money.ts`, discount % / amount, less; customer picker scoped; keyboard-friendly.

**Exit:** editor output matches server totals in a shared test table.

#### P15 · Orders & approval workflow — 5 days · A (backend) + B (UI)
* `CreateOrder` / `UpdateOrder` / `CancelOrder` with `CreditGuard`; state machine;
  `OrderPolicy::approve` (direct manager, admin with permission, `allow_upline` setting,
  never self); `ApproveOrder` / `RejectOrder` / `UnapproveOrder` with credit re-check,
  `orders.approve-over-limit` + reason, credit snapshot in activity log.
* Approvals inbox (`/orders/approvals`) with credit columns, bulk approve, reject dialog;
  pending badge; order show page with timeline; officer list with status chips and
  "edit & resubmit".
* Settings switches (approval required, upline, auto-approve cash, escalation hours);
  escalation scheduler; database notifications (Reverb comes in P18).

**Exit:** approval matrix tests (§10 of the approval doc) green; demo of officer → manager →
admin paths.

#### P16 · Invoices — 5 days · A (actions) + B (pages, print)
* `CreateInvoice` (numbering, rounding, `CreditGuard`, stock `sale` movements with
  `cost_price`, optional immediate payment, close order), `UpdateInvoice` (reversal + new),
  `DeleteInvoice` (soft + reversal, blocked if allocated), `ConvertOrderToInvoice`.
* Sales list (filters: customer, employee, type, date, printed), show page, print view +
  PDF, mark-printed, update-offer, export; visibility by customer ownership.

**Exit:** stock and credit tests; invoice number unique under concurrent creation; print
matches the legacy layout (sample approved by product owner).

#### P17 · Payments & allocations — 4 days · Dev A (UI B)
* `RecordCustomerPayment` (FIFO or explicit allocation), supplier payments, employee
  advances; `payment_allocations`; due accessor used by customers, invoices, reports.
* Customer/supplier payment tabs, allocation UI, payment history print, delete with guard.

**Exit:** derived due equals ledger in property-based tests; customer page shows the same
figure everywhere.

#### P18 · Notifications, Reverb, mail, SMS events — 3 days · Dev B (gateway A)
* Reverb + Echo; bell dropdown (latest 20, mark read, unread count), prunable model.
* Listeners (queued): `OrderSubmitted`, `OrderApproved/Rejected`, `InvoiceCreated`,
  `PaymentReceived` → notifications, manager mail, customer SMS.
* `SmsGateway` interface, `MimSms` driver, `Log` driver, `SmsTemplateRenderer`; the two
  hard-coded legacy messages become templates.

**Exit:** creating an invoice triggers notification (live), queued mail and SMS (Log driver)
in tests and on staging.

#### P19 · Handover wizard & lifecycle — 4 days · A (actions) + B (wizard)
* `handovers` flow: basics → handover tabs (customers / team / open orders) → clearance →
  review with credit before/after → `CompleteHandover` transaction; caretaker and Head-Office
  fallbacks; promotion variant; long-leave variant; reactivation with "return handed-over
  customers".
* `hierarchy:check` nightly + dashboard widgets ("teams without a manager", "customers
  awaiting an officer", "offboarding in progress").

**Exit:** **M4 demo** — the nine scenarios in `offboarding-design.md` §11 pass; full flow
order → approval → invoice → payment → SMS shown end-to-end.

---

### Stage E — Expenses, payroll, reports, SMS, settings (weeks 11–12)

#### P20 · Expenses — 2 days · Dev B
`expense_categories` (scope), expenses CRUD with receipt upload, office vs employee views,
print/export.

#### P21 · Payroll — 3 days · Dev A
`PostSalary` with carry-forward (ported from legacy, table-driven tests), unique
(employee, month), payroll index with "next payable", final settlement from handover, export/print.

#### P22 · Reports & dashboard — 5 days · A (queries) + B (pages, charts)
Twelve report classes with date/days filters, hierarchy scoping, Excel export, print;
dashboard with deferred props and the nine widgets; report cache with tag flush on writes.

**Exit:** snapshot tests on the demo seed; report totals reconcile with invoice/payment sums.

#### P23 · SMS module — 3 days · Dev B
Templates CRUD with placeholder preview, broadcast page (selected / all / filtered, preview
count, chunked queued send, rate limit, `sms.send-all` gate), history with filters, summary,
balance; gateway settings test button.

#### P24 · Settings, licence, backups — 2 days · Dev A
Settings page (company, numbering, thresholds, approval switches, SMS gateway, mail test),
server-side licence middleware with cache + grace, `spatie/laravel-backup` schedule,
Pulse (optional).

**Exit:** **M5 demo** — parity checklist (`rebuild-plan.md` §11) fully ticked on staging.

---

### Stage F — Migration & cut-over (weeks 13–14)

#### P25 · Legacy ETL — 4 days · Dev A (review B)
`legacy:migrate` (idempotent, per-table mappers, permission mapping table, FIFO allocations,
opening invoices, stock movements from `stock_in_outs` + invoice lines, invoice renumbering
with `legacy_invoice_no`, caretaker parking for orphaned customers, history back-fill) +
`legacy:reconcile` report. Three dry runs on fresh copies of production.

**Exit:** reconciliation report green on a dry run; run time known.

#### P26 · UAT & hardening — 4 days · both
UAT with 3–4 officers, one manager, accountant, admin on migrated data; bug-fix loop;
performance pass (query log, indexes, N+1), security pass (headers, rate limits, CSRF,
encryption, backups restore test); training videos; runbook (deploy, rollback, backup restore).

**Exit:** UAT sign-off; no P0/P1 bugs open.

#### P27 · Parallel run & cut-over — 3 days + hypercare · both
Parallel-run week (staff enter the day's work in v2 while legacy stays live; reports
compared), then cut-over evening: legacy read-only → final ETL → reconciliation → switch →
hypercare (daily check-ins for two weeks, legacy read-only for 30 days).

**Exit:** **M6 go-live**; hypercare log closed.

---

## 3. Week-by-week calendar (two tracks)

| Week | Dev A (backend-leaning) | Dev B (frontend-leaning) | Friday demo |
| --- | --- | --- | --- |
| 1 | P01 (with B) → P02 schema | P01 (with A) → P04 UI kit | CI green, layout |
| 2 | P03 kernel | P04 finish → P05 Brands | **M1** |
| 3 | P07 Employees | P06 Designations/Categories → P08 UI | hierarchy |
| 4 | P08 backend bits → P13 prep | P08 finish → P09 Profile | **M2** |
| 5 | P12 Products | P10 Customers | customers + products |
| 6 | P13 Stock movements | P10 finish → P11 Suppliers | **M3** |
| 7 | P15 Orders backend | P14 LineItemsEditor → P15 UI | order create + approve |
| 8 | P16 Invoice actions | P16 pages + print | invoice + PDF |
| 9 | P17 Payments | P18 Notifications/Reverb/SMS events | payment + live notifications |
| 10 | P19 Handover actions | P19 wizard UI | **M4** |
| 11 | P21 Payroll → P22 report queries | P20 Expenses → P22 dashboard | reports |
| 12 | P24 Settings/licence/backups | P23 SMS module | **M5** |
| 13 | P25 Legacy ETL (dry runs) | P26 UAT support, fixes, training material | reconciliation |
| 14 | P26/P27 hardening, cut-over | P26/P27 UAT, cut-over | **M6** |

Buffer is built into weeks 4, 6, 10, 12 (each track has ~1 day slack) and week 14.

---

## 4. Dependencies

```
P01 ─► P02 ─► P03 ─► P05 ─► P06, P07, P10, P11, P12
       P01 ─► P04 ─┘
P07 ─► P08 ─► P09
P10 ─► P14 ─► P15 ─► P16 ─► P17 ─► P22
P12 ─► P13 ─► P16
P15, P16, P17 ─► P18 ─► P23
P07, P10, P15 ─► P19
P07 ─► P21 ;  P10 ─► P20 ;  P03 ─► P24
P16, P17, P13, P08 ─► P25 ─► P26 ─► P27
```

Critical path: **P01 → P02 → P03 → P05 → P10 → P14 → P15 → P16 → P17 → P25 → P26 → P27**.
Anything that slips on this path moves the go-live date; other phases have slack.

---

## 5. Definition of Done (every phase)

- [ ] Form Requests validate; Actions are transactional; Policies cover permission + scope.
- [ ] Every new route has `->can(Perm::…)`; permission codes added to `config/permissions.php`
      and the default matrix; `permissions:sync` run.
- [ ] Menu items and buttons wrapped in `<Can>`.
- [ ] Pest feature tests for the happy path, the forbidden path, and each business rule;
      route × role matrix test updated.
- [ ] Larastan, Pint, ESLint, `tsc` clean; CI green.
- [ ] Activity log on create/update/delete of business records.
- [ ] Demo data in `DemoSeeder` covers the feature.
- [ ] Reviewed by the other developer; merged to `main`; deployed to staging.
- [ ] Parity checklist row updated in `rebuild-plan.md` §11.

---

## 6. Working rhythm

| When | What |
| --- | --- |
| Daily, 15 min | stand-up: yesterday / today / blocked |
| Monday | pick the week's phases from the board; split A/B tasks |
| Friday | demo on **staging** to the product owner; decisions recorded in `rebuild-plan.md` §0 |
| Every merge | auto-deploy to staging |
| End of stage | milestone review: parity checklist, risk list, next-stage scope |

Tooling: GitHub Projects board (epics = phases, issues = tasks), PR template with the DoD,
staging server with a copy of migrated legacy data from Stage F onward (anonymised until UAT).

---

## 7. What the product owner must provide, and when

| By | Item | Needed for |
| --- | --- | --- |
| Week 1 | Decisions §0.2 / §0.3 of the rebuild plan; a real printed invoice sample; company logo | P03, P05 |
| Week 2 | List of roles actually used and who is `super-admin`; confirmation of the default role matrix | P08 |
| Week 4 | Territory / district list; customer code and invoice prefix confirmation | P10, P16 |
| Week 6 | SMS gateway credentials (test account) and sender ID; sample SMS wording | P18, P23 |
| Week 7 | Order approval settings (upline? auto-approve cash? escalation hours?) | P15 |
| Week 9 | Payroll rules review (carry-forward examples with numbers) | P21 |
| Week 11 | Read-only copy of the production DB (fresh) for ETL dry runs | P25 |
| Week 12 | UAT users (3–4 officers, 1 manager, accountant, admin) and a training slot | P26 |
| Week 13 | Cut-over date and freeze window agreed with staff | P27 |

---

## 8. If the team changes

| Team | Effect |
| --- | --- |
| 3 developers | Third dev takes the UI of P10/P11/P12 in Stage C and P20/P23 in Stage E; ~10 weeks |
| 1 developer | Run phases sequentially in stage order; ~24 weeks; keep P05 as the template to stay fast |
| Part-time product owner | Batch decisions per stage; risk of rework in P15/P19 (rules-heavy) |
