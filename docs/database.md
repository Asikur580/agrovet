# Database Schema

**Engine:** MySQL (production) · **Migrations:** 31 files in `agrovet/database/migrations`
**Deletion policy:** almost every foreign key uses `restrictOnDelete` — parent rows cannot be
removed while children reference them. Controllers trap MySQL error `1451` and return a
friendly message.

---

## 1. Table Catalogue

### Organisation & Access

| Table | Purpose | Key columns |
| --- | --- | --- |
| `designations` | Job titles; `slug` drives the access hierarchy | `name` (uniq), `slug` (uniq) |
| `employees` | Staff master — the true actor in all business flows | `employee_id` (uniq), `designation_id` FK, `name`, `phone`, `territory`, `district`, `national_id`, `blood_group`, `image`, `credit_limit`, `basic_salary`, `created_by`, `status` (`active`/`deactive`) |
| `users` | Login accounts, 1-to-1 with an employee | `employee_id` FK, `email` (uniq, nullable), `password`, `status` |
| `relations` | Employee → superior edges (the org tree) | `employee_id` FK **uniq**, `relation_id` FK, `created_by` FK→users |
| `permissions` | Spatie permissions (guard `sanctum`) | `name`, `guard_name` |
| `roles` | Spatie roles — **empty in production** | `name`, `guard_name` |
| `model_has_permissions` | Direct user↔permission grants (the mechanism actually used) | morph + `permission_id` |
| `model_has_roles`, `role_has_permissions` | Spatie pivots — unused | |
| `personal_access_tokens` | Sanctum bearer tokens | morph `tokenable`, `token` (uniq), `abilities`, `expires_at` |

> `relations.employee_id` is **unique** — an employee has at most one superior, but a superior
> can have many subordinates.

### Catalogue & Inventory

| Table | Purpose | Key columns |
| --- | --- | --- |
| `brands` | Product brands | `name` |
| `categories` | Product categories | `name` |
| `suppliers` | Vendors | `proprietor_name`, `company_name`, `phone`, `email`, `whatsapp`, `country`, `address`, `image` |
| `products` | Product master + live stock | `cat_id` FK, `brand_id` FK, `name`, `pack_size`, `expire_date`, `buy_price`, `sell_price`, `quantity`, `image` |
| `stock_in_outs` | Stock movement ledger | `product_id` FK, `quantity`, `buy_price`, `in_out` (`in`/`out`), `purpose`, `supplier_id` FK (nullable), `who_take`, `in_out_date` |

`products.quantity` is the authoritative on-hand figure, mutated by stock-in, stock-out and
invoicing. `stock_in_outs` records only manual movements — **sales do not write a row here**.

### Customers & Sales

| Table | Purpose | Key columns |
| --- | --- | --- |
| `customers` | Retailers/shops owned by an Officer | `employee_id` FK, `customer_name`, `customer_id` (uniq, `RA18/0001`), `proprietor_name`, `phone`, `address`, `image`, `credit_limit`, `purchase`, `payment`, `old_due`, `sms_enabled` |
| `orders` | Sales requests awaiting fulfilment | `cust_id` FK, `employee_id` FK, `discount` (%), `order_type` (`cash`/`credit`), `offer`, `order_date`, `status` (`pending`/`active`/`inactive`) |
| `order_products` | Order line items | `order_id` FK, `product_id` FK, `quantity`, `unit_price`, `bonus_qty`, `price_type` (`tp`/`flat`) |
| `invoices` | Completed sales | `invoiceId` (uniq, `RAINVO-01`), `cust_id` FK, `employee_id` FK, `total_item`, `total_price`, `discount`, `less`, `grand_total`, `paid`, `due`, `sale_date`, `sale_type` (`cash`/`credit`), `offer`, `is_printed`, `printed_at` |
| `invoice_products` | Invoice line items | `invoice_id` FK, `product_id` FK, `quantity`, `unit_price`, `due_quantity`, `bonus_qty`, `price_type` |

### Money

| Table | Purpose | Key columns |
| --- | --- | --- |
| `payments` | Unified cash ledger (customer receipts, supplier payments, employee payments) | `cust_id` FK (nullable), `employee_id` FK (nullable), `supplier_id` FK (nullable), `amount`, `payment_method` (`cash`/`check`), `payment_date` |
| `cost_categories` | Office expense categories | `name` |
| `employee_cost_categories` | Employee expense categories | `name` |
| `costs` | Expense entries | `cost_cat_id` FK (nullable), `employee_cost_cat_id` FK (nullable), `employee_id` FK (nullable), `amount`, `cost_date` |
| `salaries` | Monthly payroll with carry-forward | `employee_id` FK, `month_year` (`YYYY-MM`), `basic_salary`, `paid_amount`, `advance_amount`, `due_amount` |

`costs` discriminates on `employee_id`: **NULL → office cost** (use `cost_cat_id`),
**set → employee cost** (use `employee_cost_cat_id`).

### Communication & Infrastructure

| Table | Purpose | Key columns |
| --- | --- | --- |
| `notifications` | Laravel database notifications | `id` (UUID PK), `type`, `notifiable_type`/`notifiable_id`, `data`, `read_at` |
| `sms_histories` | Every SMS attempt | `customer_id` FK (`ON DELETE SET NULL`), `phone`, `message`, `status` (`sent`/`failed`), `sent_at` — indexed on `customer_id`, `status`, `sent_at` |
| `sms_templates` | Reusable message bodies | `name`, `content`, `status` (bool) |
| `cache`, `cache_locks` | Cache driver | |
| `jobs`, `job_batches`, `failed_jobs` | Queue driver | |

---

## 2. Entity Relationships

### Textual ER map

```
Designation 1───∞ Employee
Employee    1───1 User                       (users.employee_id, unique in practice)
Employee    1───∞ Customer                   (only 'officer' employees may own customers)
Employee    1───∞ Order / Invoice / Salary / Cost / Payment
Employee    1───1 Relation (as subordinate)  ─┐  self-referencing hierarchy
Employee    1───∞ Relation (as superior)     ─┘  relations.relation_id → employees.id

Category    1───∞ Product
Brand       1───∞ Product
Supplier    1───∞ StockInOut
Supplier    1───∞ Payment
Product     1───∞ StockInOut
Product     1───∞ OrderProduct  ∞───1 Order      (Order ⇄ Product many-to-many via pivot)
Product     1───∞ InvoiceProduct ∞───1 Invoice   (Invoice ⇄ Product many-to-many via pivot)

Customer    1───∞ Order
Customer    1───∞ Invoice
Customer    1───∞ Payment
Customer    1───∞ SmsHistory

CostCategory         1───∞ Cost
EmployeeCostCategory 1───∞ Cost

User        1───∞ Notification (morph: notifiable)
User        1───∞ PersonalAccessToken (morph: tokenable)
User        ∞───∞ Permission (model_has_permissions)
```

### The self-referencing hierarchy

`relations` is the only self-join in the schema and the backbone of all access scoping:

```
             ┌──────────────┐
             │  employees   │
             └──────┬───────┘
        employee_id │      │ relation_id
         (subordinate)     (superior)
             ┌──────▼──────▼┐
             │  relations   │
             └──────────────┘

RSM ── relations ──> Managers ── relations ──> Officers ── customers
```

`Employee::subordinates()` is a `hasManyThrough` across `relations`; direct superiors are
looked up as `Relation::where('employee_id', $id)->value('relation_id')`.

### Order → Invoice lifecycle

```
orders (pending) ──POST /invoiceStore/{orderId}──> invoices
   │                                                  │
   └── order_products ──(deleted with the order)      └── invoice_products
                                                          │
                                                          └── products.quantity −= (qty + bonus)
```

The order and its line items are **hard-deleted** on conversion; there is no
`orders.invoice_id` link, so the audit trail from invoice back to order is not preserved.

---

## 3. Derived Values & Business Formulas

These are computed at query time — they are **not** stored (with the exception of
`employees.credit_limit`, which is materialised).

| Value | Formula | Where |
| --- | --- | --- |
| Customer due | `old_due + Σ invoices.grand_total − Σ payments.amount` | `CustomerController@index`, order/invoice credit checks |
| Customer credit usage | `customer due + Σ pending-order value + new document total` | `OrderController`, `InvoiceController` |
| Employee credit usage | `Σ invoices.due + Σ pending-order value + new document total` | `OrderController`, `InvoiceController` |
| Officer credit limit | `Σ customers.credit_limit` | `Employee::recalculateCreditLimit()` (**stored**) |
| Manager / RSM credit limit | `Σ subordinates.credit_limit` | `Employee::recalculateCreditLimit()` (**stored**) |
| Invoice grand total | `round(total_price − discount − less)`, `.50` down / `.51` up | `InvoiceController::calculateGrandTotal()` |
| Invoice due | `grand_total − paid` | `InvoiceController` |
| Pending order value | `Σ order_products.quantity × unit_price` where `orders.status = 'pending'` | credit checks |
| Per-line net revenue | `(line_amount / invoice.total_price) × invoice.grand_total` | product/category/brand reports |
| Supplier net due | `Σ (stock_in_outs.quantity × buy_price) − Σ payments.amount` | `supplierReport` |
| Adjusted salary | `(basic_salary + Σ previous due) − Σ previous advance` | `SalaryController` |
| COGS (P&L) | `Σ stock_in_outs.quantity × buy_price` where `in_out = 'in'` | `generateProfitLossReport` |

---

## 4. Generated Identifiers

| Entity | Format | Logic |
| --- | --- | --- |
| Customer | `RA18/0001` | Digits after `/` on the highest-`id` customer, incremented, padded to 4 |
| Invoice | `RAINVO-01` | Digits of the latest invoice **for that customer**, incremented, padded to 2 |
| Employee | Manual | Supplied by the user, unique |

> The invoice sequence is derived per-customer but the column is globally unique — with more
> than one customer this can collide once numbers overlap. Worth reviewing before scaling.

---

## 5. Schema Observations

| Observation | Impact |
| --- | --- |
| **Dates stored as `VARCHAR`** — `sale_date`, `order_date`, `cost_date`, `in_out_date`, `payment_date`, `month_year` | Range queries rely on lexicographic comparison; `Carbon::parse(...)->startOfDay()` bounds are compared against strings. Works for `YYYY-MM-DD`, fragile for any other format. |
| **`Product::$fillable` includes `flat_price`** but no such column exists | Value is silently dropped on write. |
| **`customers.purchase` / `customers.payment`** exist but are never written by any controller | Dead columns; totals are always recomputed. |
| **`Supplier::$fillable` includes `balance`, `due`** — no such columns | Silently dropped. |
| **`Supplier::invoices()`** uses `supplier_id` on `invoices` — no such column | Relationship will error if called. |
| **`Cost::category()`** references `costCategory_id` — no such column | Use `costCategory()` instead. |
| **`InvoiceProduct::invoice_products()` / `OrderProduct::order_products()`** are self-referencing stubs | Unused; harmless. |
| **No `orders.invoice_id`** | Order→invoice conversion destroys the link. |
| **`invoices` has no index on `cust_id`/`employee_id`/`sale_date`** beyond the FK indexes | Report queries scan broadly as data grows. |
| **`users.email` is nullable but login requires it** | Employee accounts without email cannot authenticate. |

---

## 6. Restoring the Production Dump

A full MySQL dump ships with the repo (two identical copies):

```
Radiant Agrovet/gldqpoea_radian_agrovet.sql          (~7 MB)
Radiant Agrovet/agrovet/gldqpoea_radian_agrovet.sql  (~3 MB)
```

```bash
mysql -u root -p -e "CREATE DATABASE agrovet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p agrovet < "gldqpoea_radian_agrovet.sql"
```

The dump contains real business data (employees, customers, invoices, permissions) — treat it
as confidential and prefer `php artisan migrate --seed` for local development.
