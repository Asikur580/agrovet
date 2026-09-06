| [`pdf-engine-spike.md`](./pdf-engine-spike.md) | P05 spike: dompdf vs Chrome/Browsershot with Bangla text — evidence and decision |
# Radiant Agrovet — Project Overview

## 1. What This Project Is

**Radiant Agrovet** is a distribution / field-sales ERP for an agro-veterinary products
business (Bangladesh — currency `TK`, phone numbers normalised to `88…`).

It is a **headless Laravel 11 REST API** consumed by a separate React SPA. The backend
manages the full trade cycle:

```
Supplier → Stock In → Product Inventory
                          ↓
   Field Officer → Order (pending) → Invoice (sale) → Payment collection
                          ↓
   Credit limits, Commissions/Costs, Salaries, Reports, SMS alerts
```

The distinguishing feature is a **3-tier field sales hierarchy with cascading credit
limits** — every order and invoice is validated against both the *employee's* and the
*customer's* available credit before it is allowed.

---

## 2. Repository Layout

| Path | Purpose |
| --- | --- |
| `agrovet/` | Laravel 11 backend (this documentation's subject) |
| `agrovet-frontend/` | React 18 + Vite + Ant Design / MUI SPA client |
| `docs/` | This documentation |
| `gldqpoea_radian_agrovet.sql` | Production MySQL dump (~7 MB) |

### Backend structure

```
agrovet/
├── app/
│   ├── Http/Controllers/     24 controllers (all API, no web controllers)
│   ├── Models/               21 Eloquent models
│   ├── Services/SmsService   MiMSMS gateway integration
│   ├── Notifications/        InvoiceNotification, OrderNotification (database channel)
│   ├── Mail/                 InvoiceCreatedMail, OrderCreatedMail (markdown mailables)
│   └── Providers/
├── routes/
│   ├── api.php               ~150 endpoints (the entire application surface)
│   └── web.php               single `/` welcome view
├── database/
│   ├── migrations/           31 migrations
│   ├── seeders/              17 seeders (factory-driven demo data)
│   └── factories/
└── config/                   incl. permission.php, sanctum.php, api-postman.php
```

---

## 3. Technology Stack

| Layer | Choice |
| --- | --- |
| Language / Runtime | PHP `^8.2` |
| Framework | Laravel `^11.9` |
| API authentication | Laravel Sanctum `^4.0` (personal access tokens) |
| Authorization | spatie/laravel-permission `^6.10` (guard: `sanctum`) |
| Database | MySQL (production dump provided); SQLite default in `.env.example` |
| Queue / Cache / Session | `database` driver |
| Notifications | Laravel database notifications + SMTP mail |
| SMS | MiMSMS HTTP API via `App\Services\SmsService` |
| API docs tooling | `andreaselia/laravel-api-to-postman` (`php artisan export:postman`) |
| Dev tooling | Pint, Pail, Sail, PHPUnit 11, Collision, Faker |
| Frontend (separate) | React 18, Vite, Ant Design, MUI, Chart.js, react-to-print, axios |

---

## 4. Architectural Notes

* **Controller-centric.** Business logic lives directly in controllers; there is one
  service class (`SmsService`) and no repository/action layer. `ReportController` is the
  largest unit (~1,650 lines, 28 report methods).
* **Uniform JSON envelope.** Nearly every endpoint returns
  `{ "status": bool, "message": string, "data": mixed }`.
* **Verb-in-path routing.** Routes are not RESTful resources — they use explicit action
  names (`/productStore`, `/productUpdate/{id}`, `/productDelete/{id}`) and **`POST` is
  used for updates and deletes** so that `multipart/form-data` file uploads work.
* **Role logic is data-driven, not middleware-driven.** Access scoping is decided inside
  each controller by reading `$user->employee->designation->slug`
  (`admin` / `rsm` / `manager` / `officer`). No custom middleware exists; the only route
  middleware in use are `auth:sanctum` and `throttle:3,1` on custom SMS.
* **Spatie permissions are used as direct user permissions**, not through roles. The
  `roles` table is empty in production — `UserController::store` calls
  `syncPermissions()` on the user. The ~110 permissions are UI-page/action gates
  (`Product-page`, `Sale-create`, `Customer-print`, `Sms-template-page`, …) enforced by
  the frontend, not by backend middleware.
* **Money rounding is a deliberate business rule.** `.50` rounds *down*, `.51` rounds
  *up* — implemented as `calculateGrandTotal()` in both `OrderController` and
  `InvoiceController`.
* **Dates are stored as strings** (`sale_date`, `order_date`, `cost_date`,
  `in_out_date`, `payment_date`, `month_year`), not as date columns.

---

## 5. Key Business Rules (at a glance)

1. **Cascading credit limits** — an Officer's limit is the sum of their customers' limits;
   a Manager's is the sum of their Officers'; an RSM's is the sum of their Managers'.
   Recalculation cascades upward automatically (`Employee::recalculateCreditLimit()`).
2. **Dual credit gate** — creating/updating an order or invoice checks the *employee*
   credit ceiling always, and the *customer* credit ceiling when the sale is on credit.
3. **Customer due** = `old_due + Σ invoice.grand_total − Σ payments.amount`.
4. **Order → Invoice conversion** deletes the source order (`POST /invoiceStore/{orderId}`).
5. **Stock is decremented by `quantity + bonus_qty`** on invoicing and restored on
   invoice update/delete.
6. **Only `officer`-designated employees may own customers.**
7. **Automatic customer SMS** on invoice creation and on payment receipt, gated by
   `customers.sms_enabled`.
8. **ID generation** — customers `RA18/0001`, invoices `RAINVO-01` (per-customer sequence).

---

## 6. Known Issues Worth Flagging

These are observations from the code, not changes made:

| Issue | Detail |
| --- | --- |
| **Unauthenticated endpoints** | 10 financial report routes (`/sales-report`, `/cashflow-report`, `/account-report`, `/expense-report`, `/supplier-report`, `/inventory-report`, `/employee-report`, `/customer-payment-report`, `/product-profitability-report`, `/customer-report/{id}`) and all 5 `/sms-templates` routes sit **outside** the `auth:sanctum` group and are publicly readable/writable. |
| **Hard-coded user ID** | `RoleController::addPermissionToRole()` and `givePermissionToRole()` both call `User::findOrFail(3)` and sync permissions onto that fixed user. |
| **Missing SMS config** | `SMS_API_URL`, `SMS_USERNAME`, `SMS_API_KEY`, `SMS_SENDER_ID` are read by `SmsService` but are **absent from both `.env` and `.env.example`**, so SMS currently short-circuits to `failed`. |
| **Errors return HTTP 200** | Most `catch` blocks return `{"status": false}` with a `200` status code; clients must inspect the body. |
| **Duplicate route** | `product-wise-sales` is registered twice in the authenticated group (second registration wins). |
| **Stock check disabled** | The insufficient-stock guard in `InvoiceController::store()` is commented out — invoices can drive stock negative. |
| **Secrets in repo** | `.env` and a full production SQL dump are committed alongside the code. |
| **Stray files** | `error_log` files are checked in across `app/`, `routes/`, `database/`; a 36 MB `agrovet.zip` sits in the project root. |
| **`update()` breaks order pivot** | `OrderController::update()` uses `products()->detach()/attach()`, which drops the `bonus_qty`/`price_type` written by `store()` unless all pivot fields are supplied (they are, but `due_quantity` handling differs from `store`). |

---

## 7. Documentation Index

| File | Contents |
| --- | --- |
| [`overview.md`](./overview.md) | This document |
| [`features.md`](./features.md) | Feature-by-feature functional breakdown |
| [`api.md`](./api.md) | Full endpoint reference with request/response shapes |
| [`database.md`](./database.md) | Tables, columns, relationships, ER description |
| [`architecture.md`](./architecture.md) | Request flow, layering, middleware & guards, conventions |
| [`database-review.md`](./database-review.md) | Deep schema review against the production snapshot: problems found, evidence, redesign, indexing, roadmap |
| [`rebuild-plan.md`](./rebuild-plan.md) | v2 rebuild plan on Laravel + Inertia + React: decisions, architecture, module design, phases, legacy data migration, cut-over |
| [`permissions-design.md`](./permissions-design.md) | v2 access control: per-feature permission catalogue (137 codes), role + user-level assignment with allow/deny overrides, schema, code, admin UI, legacy mapping |
| [`order-approval-design.md`](./order-approval-design.md) | v2 order workflow: officer submits → direct manager approves (or admin with permission); state machine, policy, credit-limit re-checks, settings, approvals inbox, notifications |
| [`workplan.md`](./workplan.md) | v2 delivery: 27 phases of 2–5 days in 6 stages, effort per phase, two developer tracks, week-by-week calendar, dependencies, definition of done, product-owner inputs |
| [`offboarding-design.md`](./offboarding-design.md) | v2 hierarchy lifecycle: what happens when an RSM / manager / officer resigns, is promoted or goes on leave — handover workflow, caretaker & Head Office fallbacks, ownership history, scoping rules, invariants |
| [`setup.md`](./setup.md) | Local install, configuration, seeding, deployment |

Frontend documentation lives separately in [`../../agrovet-frontend/docs/`](../../agrovet-frontend/docs/overview.md).
