# Backend Architecture

## 1. Shape of the Application

Radiant Agrovet's backend is a **headless Laravel 11 API**. There are no Blade views beyond
the default welcome page — every feature is exposed as JSON under `/api` and consumed by the
React SPA in `agrovet-frontend/`.

```
┌──────────────────┐   HTTPS / JSON    ┌──────────────────────────────────────┐
│  React SPA       │ ───────────────►  │  Laravel 11                          │
│  (Vite, Antd)    │  Bearer token     │  routes/api.php → Controllers        │
│                  │ ◄───────────────  │      → Eloquent Models → MySQL       │
└──────────────────┘                   │      → SmsService  → MiMSMS gateway  │
                                       │      → Mail        → SMTP            │
                                       │      → Notifications → DB table      │
                                       └──────────────────────────────────────┘
```

Laravel 11's slim skeleton is used as-is: `bootstrap/app.php` registers only the default
`web`, `api`, `console` route files and the `/up` health endpoint. **No custom middleware,
exception handlers, service providers, events, listeners, jobs, policies or form requests
have been added** — the `withMiddleware()` and `withExceptions()` closures are empty and
`AppServiceProvider` is a stub.

---

## 2. Request Lifecycle (MVC flow)

```
1. HTTP request  ─►  public/index.php  ─►  bootstrap/app.php
2. Router matches routes/api.php  (prefix /api, middleware group "api")
3. Route middleware:  auth:sanctum   (and throttle:3,1 on /send-custom-sms)
4. Controller method
      a. $request->validate([...])           inline validation rules
      b. read $request->user()->employee     resolve designation slug
      c. hierarchy scoping / business checks (credit limits, stock, etc.)
      d. DB::beginTransaction()              on multi-table writes
      e. Eloquent model calls                create / update / sum / with()
      f. DB::commit()
      g. side effects                        notify(), Mail::send(), SmsService
5. return response()->json(['status' => …, 'message' => …, 'data' => …])
```

### Layers actually present

| Layer | Location | Notes |
| --- | --- | --- |
| Routing | `routes/api.php` | Single file, ~150 routes, verb-in-path naming (`/productStore`, `/productUpdate/{id}`) |
| Controllers | `app/Http/Controllers/*` (24) | Hold **all** business logic — validation, authorization, queries, side effects |
| Models | `app/Models/*` (21) | Eloquent with `$fillable`, relationships, one domain method (`Employee::recalculateCreditLimit()`) |
| Service | `app/Services/SmsService.php` | The only service class; wraps the MiMSMS HTTP API and writes `sms_histories` |
| Notifications | `app/Notifications/*` (2) | `database` channel only |
| Mail | `app/Mail/*` (2) | Markdown mailables, sent synchronously |
| Persistence | `database/migrations` (31), `seeders`, `factories` | MySQL; string-typed date columns |

### Layers **not** present

* No repository layer — controllers query Eloquent and the query builder directly.
* No Form Request classes — validation is inline `$request->validate()`.
* No API Resources / Transformers — responses are hand-built arrays or raw models.
* No Policies / Gates — authorization is `if ($slug === 'admin') …` inside controllers.
* No Events / Listeners / Jobs — notifications, mail and SMS run inline in the request.
* No custom middleware.

---

## 3. Authentication & Guards

### Sanctum personal access tokens

* `POST /api/login` verifies credentials with `Auth::attempt()` (session guard `web` under
  the hood) and then issues a token via `$user->createToken('auth_token')`.
* Every protected route is wrapped in `Route::middleware(['auth:sanctum'])`.
* The client sends `Authorization: Bearer <token>`; Sanctum resolves the `User` from
  `personal_access_tokens` and populates `$request->user()`.
* `POST /api/logout` revokes only the current token (`currentAccessToken()->delete()`).
* Tokens have no expiry configured (`config/sanctum.php` default `expiration => null`).

### Guard configuration (`config/auth.php`)

| Guard | Driver | Used for |
| --- | --- | --- |
| `web` | session | `Auth::attempt()` during login only |
| `api` | sanctum | defined, unused |
| `sanctum` | sanctum | the `auth:sanctum` middleware; also the `guard_name` on every Spatie permission |

The `User` model uses `HasApiTokens` (Sanctum), `HasRoles` (Spatie), and `Notifiable`.

---

## 4. Authorization Model

Two mechanisms coexist and serve different purposes.

### 4.1 Designation hierarchy — enforced by the backend

```
employees.designation_id ─► designations.slug ∈ { admin, rsm, manager, officer }
relations(employee_id = subordinate, relation_id = superior)   ← the org tree
```

Inside a controller the pattern is always the same:

```php
$slug = $request->user()->employee->designation->slug;

if ($slug == 'admin') {
    // unrestricted
} elseif ($slug == 'officer') {
    $q->where('employee_id', $me);
} elseif ($slug == 'manager') {
    $officerIds = Relation::where('relation_id', $me)->pluck('employee_id');
    $q->whereIn('employee_id', $officerIds);
} elseif ($slug == 'rsm') {
    $managerIds = Relation::where('relation_id', $me)->pluck('employee_id');
    $officerIds = Relation::whereIn('relation_id', $managerIds)->pluck('employee_id');
    $q->whereIn('employee_id', $managerIds->merge($officerIds));
}
```

This block is duplicated in `OrderController`, `InvoiceController`, `CustomerController`,
`EmployeeController` and two `ReportController` methods. It is the **only** server-side
access control beyond "is authenticated" — a natural refactor target is a scope such as
`Invoice::visibleTo($user)` or a `HierarchyResolver` service.

### 4.2 Spatie permissions — enforced by the frontend

* ~110 named permissions (`Product-page`, `Sale-create`, `Customer-print`, …) attached
  **directly to users** via `syncPermissions()`; the `roles` table is empty in production.
* Returned by `/login` and `/profile`; the SPA hides routes, menu items and buttons
  accordingly.
* **No route uses `permission:` or `role:` middleware**, so any authenticated token can call
  any authenticated endpoint regardless of its permission set. The backend trusts the client
  for UI-level gating.

---

## 5. Middleware in Use

| Middleware | Where | Purpose |
| --- | --- | --- |
| `api` group (framework default) | all of `routes/api.php` | Stateless, `throttle:api`, `SubstituteBindings` |
| `auth:sanctum` | the main route group | Rejects requests without a valid bearer token |
| `throttle:3,1` | `POST /send-custom-sms` | Max 3 broadcast SMS requests per minute per user/IP |

Routes registered **outside** the `auth:sanctum` group (10 financial reports and the 5
`/sms-templates` routes) run with only the `api` group middleware and are therefore public —
see [`overview.md` §6](./overview.md#6-known-issues-worth-flagging).

---

## 6. Cross-Cutting Conventions

### Response envelope

```php
return response()->json(['status' => true, 'message' => '…', 'data' => $payload]);
```

* `status` is the success flag the client branches on.
* Errors are usually returned with HTTP **200** and `status: false`; a minority of handlers
  return `422` / `403` / `404` / `500`.
* A few report endpoints (`/profit-loss-report`, the public `/…-report` family) return bare
  objects without the envelope.

### Error handling

Each controller method wraps its body in `try { … } catch (Exception $e)` and returns
`$e->getMessage()` in an `error` key. Since Laravel's own exception handler is untouched,
validation failures thrown by `$request->validate()` inside the `try` are caught as generic
exceptions and surfaced as `"Something went wrong"` + the validator message string, rather
than the standard `422` `{ errors: {...} }` shape.

### Transactions

`DB::beginTransaction()` / `commit()` / `rollBack()` guard every multi-table write: orders,
invoices, payments, salaries, users, profile updates, stock movements. Notifications, mail and
SMS are dispatched **after** `commit()` so that a failing side effect cannot roll back the
business record.

### File uploads

Images go to the `public` disk under `employees/`, `products/`, `customers/`, `suppliers/`
via `storeAs()`, with the previous file deleted on replace. The stored relative path is served
through `storage/` (requires `php artisan storage:link`) and the SPA prefixes it with
`VITE_IMG_URL`.

### Money and quantities

* `OrderController::calculateGrandTotal()` and `InvoiceController::calculateGrandTotal()`
  implement the business rounding rule (`.50` → down, `.51` → up).
* `normalizeProductQuantities()` in both controllers coerces `quantity`, `bonus_qty` and
  `due_quantity` to non-negative integers.

### Side-effect fan-out (orders & invoices)

```
commit()
  ├─ employee->user->notify(...)          database notification
  ├─ manager->user->notify(...)           via relations
  ├─ each admin user->notify(...)
  ├─ Mail::to(managerEmail)->send(...)    synchronous SMTP
  └─ SmsService->sendSms(customer)        invoices only, if sms_enabled
```

All of this runs inline in the HTTP request. Moving it behind `ShouldQueue` (the `jobs`
table and `database` queue driver are already migrated) would remove SMTP/SMS latency from
the API response.

---

## 7. External Integrations

| Integration | Entry point | Config | Notes |
| --- | --- | --- | --- |
| MiMSMS (SMS) | `App\Services\SmsService` | `services.sms.*` ← `SMS_API_URL`, `SMS_USERNAME`, `SMS_API_KEY`, `SMS_SENDER_ID` | JSON POST; balance check derives its URL from the send URL; every attempt logged to `sms_histories` |
| SMTP mail | `App\Mail\*` | `MAIL_*` | Manager notified on order/invoice creation |
| Postman export | `andreaselia/laravel-api-to-postman` | `config/api-postman.php` | `php artisan export:postman` |

---

## 8. Directory Reference

```
agrovet/
├── app/
│   ├── Http/Controllers/         BrandController … UserController (24)
│   ├── Models/                   Brand … User (21)
│   ├── Services/SmsService.php
│   ├── Notifications/            InvoiceNotification, OrderNotification
│   ├── Mail/                     InvoiceCreatedMail, OrderCreatedMail
│   └── Providers/AppServiceProvider.php   (empty)
├── bootstrap/app.php             default Laravel 11 wiring, nothing custom
├── config/
│   ├── auth.php                  web / api / sanctum guards
│   ├── sanctum.php
│   ├── permission.php            Spatie
│   ├── services.php              sms.* block
│   └── api-postman.php
├── database/
│   ├── migrations/               31 files (schema in database.md)
│   ├── seeders/                  DatabaseSeeder + 16 entity seeders (Faker)
│   └── factories/
├── resources/views/
│   ├── welcome.blade.php
│   └── emails/{orders,invoices}/created.blade.php
├── routes/
│   ├── api.php                   entire API surface
│   ├── web.php                   "/" only
│   └── console.php
└── storage/app/public/           uploaded images (symlinked to public/storage)
```

---

## 9. Suggested Evolution Path

Ordered by risk reduction per effort — none of these are implemented today.

1. **Move the 15 public routes into the `auth:sanctum` group.**
2. **Extract hierarchy scoping** into a reusable query scope or service and apply it
   consistently (payments, salaries and costs listings are currently unscoped).
3. **Replace the hard-coded `User::findOrFail(3)`** in `RoleController` with
   `$request->user()`.
4. Add `permission:` middleware (Spatie ships it) to write endpoints so backend and UI gating
   agree.
5. Queue notifications, mail and SMS.
6. Introduce Form Requests and API Resources to standardise validation errors and response
   shapes; return real HTTP status codes.
7. Convert string date columns to `DATE`/`DATETIME`.
