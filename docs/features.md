# Feature Reference

Every feature below is implemented in `agrovet/app/Http/Controllers`. Route paths are
relative to the `/api` prefix.

---

## 1. Authentication

**Controller:** `UserController` · **Package:** Laravel Sanctum

| Capability | Detail |
| --- | --- |
| Login | `POST /api/login` — email + password, validated with `Auth::attempt()`. Public route. |
| Active-account gate | Users with `status != 'active'` are rejected after password check. |
| Token issue | `createToken('auth_token')` → plain-text Sanctum bearer token. |
| Login payload | Returns token, user (with `employee.designation` eager-loaded) and the user's **direct** permission names. |
| Logout | `POST /api/logout` — deletes the current access token only. |
| Password change | `POST /api/profile/change-password` — verifies `current_password`, requires `new_password` confirmed, min 6. |

**Guard configuration:** `config/auth.php` defines `web` (session), `api` (sanctum) and
`sanctum` guards, all backed by the `users` provider. Spatie permissions are stored under
the `sanctum` guard name.

> **Note:** login failures return HTTP `200` with `{"status": false, "message": "Unauthorized"}`.

---

## 2. Authorization — Designations & Permissions

Two independent mechanisms operate side by side.

### 2.1 Designation hierarchy (the real access control)

`employees.designation_id → designations.slug` drives all data scoping. Four slugs are in
use in production data (`admin`, `rsm`, `manager`, `officer`), mapped from many human-readable
designation names (e.g. *Sr. Manager*, *Area Manager*, *Territory Manager* → `manager`;
*Marketing Executive*, *Territory Executive* → `officer`).

```
admin   → sees everything
  rsm   → sees their Managers + those Managers' Officers
  manager → sees their Officers
    officer → sees only their own records
```

The hierarchy is materialised in the `relations` table
(`employee_id` = subordinate, `relation_id` = superior) and re-resolved per request inside
each controller. Endpoints implementing this scoping:

* `OrderController@index`
* `InvoiceController@index`
* `CustomerController@index`
* `EmployeeController@index`
* `ReportController@productWiseSalesByRole`
* `ReportController@paymentHistoryByRole`

Relation integrity is enforced in `RelationController@store`: an Officer's superior **must**
be a Manager, a Manager's superior **must** be an RSM.

### 2.2 Spatie permissions (UI gating)

* ~110 permission records, all on guard `sanctum`, named after frontend pages and actions:
  `Dashboard-page`, `Product-create`, `Sale-invoice`, `Customer-print`, `Sms-template-page`, …
* Assigned **directly to users** (`model_has_permissions`); the `roles` table is empty in the
  production dump.
* Returned at login and via `GET /api/profile`, then enforced by the React client.
* Full CRUD exists for both `Permission` and `Role` (`PermissionController`, `RoleController`),
  including `role/{id}/give-permissions`.
* The permission `Developer` is filtered out of every listing.

> No `can:` / `role:` middleware is applied to any route — permissions are advisory to the client.

---

## 3. User & Employee Management

### Employees — `EmployeeController`

* CRUD with `employee_id` and `phone` uniqueness, image upload to `storage/app/public/employees`.
* Fields: designation, name, phone, territory, district, national ID, blood group, basic salary.
* **Soft deactivation** — `destroy` sets `status = 'deactive'` rather than deleting.
* `credit_limit` is **never set manually** — always derived (see §4).
* `GET /api/employees/credit-report` — per-employee credit limit, purchases, payments, due,
  credit used and credit remaining, with optional `from_date`/`to_date`.
* Self-deactivation is blocked.

### Users — `UserController`

* A `User` is a login account bound 1-to-1 to an `Employee`.
* Create/update accepts a `permission[]` array, synced via `syncPermissions()`.
* `GET /api/userShow/{id}` returns the user plus all assignable permissions and the user's
  current permission IDs — designed to render a permission checkbox matrix.
* Deactivation instead of deletion; self-deactivation blocked.

### Profile — `ProfileController`

`GET /api/profile` returns the authenticated user, their permissions and a live **metrics**
block computed on the fly:

| Metric | Formula |
| --- | --- |
| `customers_count` | employee's customers |
| `subordinates_count` | rows in `relations` where `relation_id` = employee |
| `sales_count` / `total_sales` | count / Σ `grand_total` of the employee's invoices |
| `credit_limit` | `employees.credit_limit` |
| `credit_use` | (Σ invoice `grand_total` − Σ payments) + Σ pending **credit** order value |
| `credit_available` | `credit_limit − credit_use` |

`POST /api/profile/update` updates the linked employee record (name, phone, territory,
district, NID, blood group, image) and the user's email in one transaction.

### Designations — `DesignationController`

Simple CRUD over `name` + `slug`; the slug is lower-cased on save and is what the hierarchy
logic keys on.

---

## 4. Credit Limit Engine  *(core Agrovet business logic)*

Implemented in `Employee::recalculateCreditLimit()`.

```
Officer.credit_limit  = Σ credit_limit of their Customers
Manager.credit_limit  = Σ credit_limit of their Officers
RSM.credit_limit      = Σ credit_limit of their Managers
```

After computing its own value the method walks **up** the `relations` chain and recalculates
each superior recursively. It is triggered whenever the underlying figures can change:

* Customer created / updated / deleted (`CustomerController`) — including re-pointing a
  customer to a different officer, which recalculates both the old and new officer.
* Employee-to-superior relation created or changed (`RelationController@store`) — recalculates
  both the new and the previous superior.

### Enforcement points

Both `OrderController` and `InvoiceController` gate writes on two independent ceilings:

**Employee ceiling (always checked):**
```
Σ invoice.due  +  Σ pending order value  +  new document total   ≤  employee.credit_limit
```

**Customer ceiling (checked only when `order_type` / `sale_type` = `credit`):**
```
(customer.old_due + Σ invoice.grand_total − Σ payments)
      + Σ pending order value  +  new document total            ≤  customer.credit_limit
```

Breaches return HTTP `422` and abort the transaction.

---

## 5. Product, Category, Brand & Inventory

### Catalogue

| Entity | Controller | Fields |
| --- | --- | --- |
| Brand | `BrandController` | `name` |
| Category | `CategoryController` | `name` |
| Product | `ProductController` | category, brand, name, pack size, expiry, buy price, sell price, quantity, image |
| Supplier | `SupplierController` | proprietor, company, phone, email, WhatsApp, country, address, image |

Products and suppliers support image upload with old-file cleanup. Delete handlers trap MySQL
error `1451` (FK constraint) and return a friendly "referenced by another record" message.

> `flat_price` is accepted and mass-assignable on `Product` but has **no database column**
> (the migration defines only `buy_price` / `sell_price`), so it is silently discarded.

### Stock movement — `stock_in_outs`

* `POST /api/stock_in` — records a purchase from a supplier (`product_id`, `quantity`,
  `buy_price`, `supplier_id`, `in_out_date`, optional `expire_date`) and **increments**
  `products.quantity`. Expiry date is updated on the product when supplied.
* `POST /api/stock_out` — non-sale removal (`purpose`, `who_take`) with an
  insufficient-stock guard; **decrements** `products.quantity`.
* `GET /api/stockInOutHistory/{productId}` — joined ledger with supplier names, newest first.
* Sales also move stock: invoicing decrements `quantity + bonus_qty`; invoice update/delete
  restores it.
* `GET /api/low_stock_alerts?threshold=10` — products at or below a threshold.

---

## 6. Order Management

**Controller:** `OrderController` · Tables `orders` + `order_products`

* An order is a field-officer sales request awaiting fulfilment.
* Line items carry `quantity`, `unit_price`, `bonus_qty` (free goods) and
  `price_type` ∈ {`tp`, `flat`} — trade price vs flat price.
* Quantities are normalised to non-negative integers before saving
  (`normalizeProductQuantities()`).
* `order_type` ∈ {`cash`, `credit`}; `status` ∈ {`pending`, `active`, `inactive`}, default
  `pending`. Only `pending` orders count toward credit usage.
* `offer` — free-text promotional label carried on the order and copied onto the invoice at
  conversion time.
* The order's employee is taken from the authenticated user, not the request body.
* Listing is hierarchy-scoped and additionally filterable by `customer_id` / `employee_id`.
* `POST /api/orders/{order}/status` toggles status and reports the transition.
* Deleting an order deletes its line items first.

### On order creation the system also

1. Notifies the creating employee (database notification).
2. Notifies the employee's Manager (via `relations`).
3. Notifies every `admin`-designated employee.
4. Emails the Manager an `OrderCreatedMail`.

---

## 7. Sales / Invoicing

**Controller:** `InvoiceController` · Tables `invoices` + `invoice_products`

| Aspect | Behaviour |
| --- | --- |
| Invoice number | `RAINVO-` + zero-padded sequence derived from the **latest invoice of that customer** |
| Totals | `grand_total = round(total_price − discount − less)` using the `.50↓ / .51↑` rule; `due = grand_total − paid` — both **recomputed server-side**, ignoring client values |
| Overpayment | `paid > grand_total` is rejected |
| Credit gates | Employee ceiling always; customer ceiling when `sale_type = credit` |
| Stock | Decrements `quantity + bonus_qty` per line on create; restores + re-applies on update; fully restores on delete |
| Order conversion | `POST /api/invoiceStore/{orderId}` copies the order's `employee_id` and `offer`, then **deletes the order and its line items** |
| Printing | `is_printed` / `printed_at`, set via `POST /api/invoice/{id}/mark-printed` |
| Offer | Editable post-hoc via `POST /api/invoice/{id}/update-offer` |
| Listing | Hierarchy-scoped, filterable by `customer_id` / `employee_id`, flattened into a print-friendly shape |
| `salesByEmployee/{id}` | All invoices for one employee with customer and line detail |

### On invoice creation the system also

1. Notifies the invoice's employee, their Manager, and all admins (database notifications).
2. Emails the Manager an `InvoiceCreatedMail` (links to `https://ra.s3cbd.com/invoice/{id}`).
3. **Sends the customer an SMS** with the invoice ID and bill total, if the customer has a
   phone and `sms_enabled = true`.

---

## 8. Customer Management

**Controller:** `CustomerController`

* Auto-generated customer code `RA18/0001`, `RA18/0002`, …
* **Only employees with designation slug `officer` may be assigned as a customer's owner** —
  enforced on both create and update.
* Financial fields: `credit_limit`, `old_due` (opening balance), plus denormalised
  `purchase` / `payment` columns.
* `GET /api/customers` returns each customer with `total_purchases`, `total_payments` and a
  computed `due = old_due + total_purchases − total_payments`, scoped by hierarchy.
* `GET /api/customerByEmployee/{employeeId}` — customer list for one officer.
* Image upload with old-file cleanup; delete traps FK violations.
* Any create/update/delete recalculates the owning officer's credit limit (and cascades up).
* `POST /api/customerToggleSms/{id}` flips `sms_enabled`.

---

## 9. Payments

**Controller:** `PaymentController` · Table `payments`

A single polymorphic-by-nullable-FK ledger serving three flows:

| `cust_id` | `supplier_id` | `employee_id` | Meaning |
| --- | --- | --- | --- |
| set | – | set | Customer payment collected by an employee (receivable) |
| – | set | – | Payment made to a supplier (payable) |
| – | – | set | Employee-directed payment |

* `payment_method` ∈ {`cash`, `check`}; `payment_date` stored as a string.
* `GET /api/custPaymentHistory/{custId}` — joined with customer + collecting employee.
* `GET /api/supplierPaymentHistory/{supplierId}` — joined with supplier.
* **Customer payments trigger an SMS** confirming the amount received and the customer's
  recomputed outstanding due (when `sms_enabled`).

> There is no card/wallet/online payment gateway in this system — "payment integration"
> here means manual cash/cheque recording. The only external integration is SMS.

---

## 10. Expenses (Costs) & Payroll

### Costs — `CostController`

`costs` rows are either **office costs** (`employee_id` NULL, categorised by `cost_cat_id`)
or **employee costs** (`employee_id` set, categorised by `employee_cost_cat_id`).

* `GET /api/officeCost` — office-only costs with category names.
* `GET /api/employeeCost/{employeeId}` — per-employee cost history.
* Two independent category tables: `CostCategoryController` and
  `EmployeeCostCategoryController` (both simple CRUD).

### Salaries — `SalaryController`

Monthly payroll keyed by `employee_id` + `month_year` (`YYYY-MM`), with carry-forward:

```
adjusted_salary = (basic_salary + Σ previous due) − Σ previous advance
paid ≥ adjusted  →  advance = paid − adjusted,  due = 0
paid <  adjusted →  due     = adjusted − paid,  advance = 0
```

Paying twice in the same month **accumulates** onto the existing row rather than creating a
second one. `GET /api/employeeSalary/{employeeId}` returns the chronological ledger;
`GET /api/next-payable-month-salary` projects each employee's next payable month and amount.

---

## 11. Reports & Analytics

`ReportController` exposes 28 report methods. Most accept either an explicit
`from_date` + `to_date` pair **or** a `days` window (30/45/60/90), with the explicit range
taking priority.

### Sales

| Endpoint | Output |
| --- | --- |
| `customerWiseSalesReport` | Per customer: invoice count, old due, purchases, payments, due |
| `productWiseSalesReport` | Per product: invoice count, qty (incl. bonus), gross, apportioned discount/less, net |
| `categoryWiseSalesReport` | Same aggregation rolled up by category |
| `product-wise-sales` (`productWiseSalesByRole`) | Product-wise sales scoped to the caller's hierarchy, split into cash vs credit totals |
| `cashCreditSale` | Cash vs credit revenue split |
| `brandReport/{id}`, `categoryReport/{id}` | Drill-down for one brand/category |
| `productReport/{id}` | Line-level sales history for a single product |

Discount and "less" are **apportioned per line** as
`(line_amount / invoice.total_price) × invoice.discount` so that per-product net revenue
reconciles to the invoice grand total.

### Financial

| Endpoint | Output |
| --- | --- |
| `profit-loss-report` | Revenue, COGS (stock-in value), salaries, employee costs, office costs, net profit |
| `cashflow-report` | Inflows (payments received) vs outflows (supplier payments + salaries + costs) |
| `account-report` | Receivables vs payables, customer-wise dues, supplier-wise payables |
| `expense-report` | Total operational cost, cost by category, salaries paid |
| `product-profitability-report` | Per-product revenue, COGS, profit and margin % |
| `dueInvoice` | Unpaid invoices with `days_since_sale`, filterable by customer/date/window |

### Operational

| Endpoint | Output |
| --- | --- |
| `dashboard-report` | Active employees, customers, suppliers, orders — headline counters |
| `inventory-report` | Current stock levels, in/out movement per product, low-stock list |
| `low_stock_alerts` | Products at/below a configurable threshold (default 10) |
| `employee-report` / `employeeReport/{id}` | Salary details plus sales performance; hierarchy-aware (a Manager's report aggregates their Officers' invoices) |
| `payment-history-by-role` | Collections per employee, scoped to the caller's hierarchy |
| `supplierReport/{id}` / `supplier-report` | Purchases vs payments and net due per supplier |
| `customerReport/{id}`, `customer-report/{id}` | Customer statement: invoices, purchases, payments, due |

---

## 12. Notifications

**Channel:** `database` only (no broadcast/push).

* `OrderNotification` — payload `{ message, order_id }`
* `InvoiceNotification` — payload `{ message, invoice_id }`

Fan-out on every order and invoice: **creator → their Manager → all admins**.

| Endpoint | Action |
| --- | --- |
| `GET /api/notifications` | All notifications for the authenticated user |
| `POST /api/notifications/{id}/read` | Mark one read |
| `POST /api/notifications/read-all` | Mark all unread as read |

### Email

Markdown mailables sent synchronously to the Manager's user email:
`OrderCreatedMail` (`emails.orders.created`) and `InvoiceCreatedMail`
(`emails.invoices.created`).

---

## 13. SMS Integration

**Service:** `App\Services\SmsService` → **MiMSMS** HTTP API.

* Config keys (`config/services.php` → `services.sms.*`): `SMS_API_URL`, `SMS_USERNAME`,
  `SMS_API_KEY`, `SMS_SENDER_ID`.
* Phone numbers are normalised to Bangladesh format (`88` prefix, non-digits stripped).
* Every send — success or failure — is written to `sms_histories` with status
  `sent` / `failed`.
* Missing credentials are logged and recorded as `failed`; the caller is never interrupted.

| Trigger | Message |
| --- | --- |
| Invoice created | Invoice ID + total bill |
| Customer payment recorded | Amount received + recomputed current due |
| Manual broadcast | `POST /api/send-custom-sms` — **admin-designation only**, rate-limited to 3/min, targets specific `customer_ids[]` or every SMS-enabled customer |

**Supporting endpoints:** `GET /api/sms-history` (paginated + filterable),
`GET /api/sms-summary` (sent/failed counts), `GET /api/sms-balance` (live gateway balance),
`POST /api/customerToggleSms/{id}`, and full CRUD for reusable `sms-templates`.

> `SMS_*` keys are absent from the committed `.env`/`.env.example` — see
> [`setup.md`](./setup.md).

---

## 14. Cross-Cutting Conventions

* **Response envelope:** `{ status, message, data }` on nearly every endpoint.
* **File uploads:** `public` disk, subfolders `employees/`, `products/`, `customers/`,
  `suppliers/`; old files deleted on replace; requires `php artisan storage:link`.
* **Transactions:** `DB::beginTransaction()` wraps order, invoice, payment, salary, user and
  profile writes.
* **Soft deactivation:** users and employees use `status = 'deactive'`; all other entities
  hard-delete with FK protection (`restrictOnDelete`).
* **Health check:** `GET /up` (Laravel 11 built-in).
* **Postman export:** `php artisan export:postman` generates a collection from the route table.
