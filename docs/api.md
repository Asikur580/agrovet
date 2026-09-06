# API Reference

## Conventions

| Item | Value |
| --- | --- |
| Base URL | `{APP_URL}/api` |
| Auth | `Authorization: Bearer <token>` (Laravel Sanctum) |
| Content types | `application/json`, or `multipart/form-data` for endpoints with an `image` field |
| Accept header | `Accept: application/json` (required — otherwise validation errors redirect) |

### Standard response envelope

```json
{ "status": true, "message": "…", "data": {} }
```

**Important:** most handlers catch exceptions and return `{"status": false, …}` with an HTTP
**`200`** status. Always branch on the `status` field, not on the HTTP code. Exceptions that
do return real codes are noted inline (`422`, `403`, `404`, `500`).

### Route style

Routes are **not** RESTful resources. Updates and deletes use `POST` (not `PUT`/`DELETE`) so
that `multipart/form-data` uploads work — the only `DELETE` verb in the whole API is
`DELETE /sms-templates/{id}`.

### Common query parameters (reports)

| Param | Meaning |
| --- | --- |
| `from_date`, `to_date` | Explicit range (`YYYY-MM-DD`). Takes priority. |
| `days` | Rolling window in days (30 / 45 / 60 / 90). |
| `employee_id`, `customer_id` | Optional filters, clamped to what the caller's role permits. |

---

## 1. Authentication

### `POST /login` · public

```jsonc
// request
{ "email": "user@example.com", "password": "secret123" }
```
```jsonc
// response
{
  "status": true,
  "token": "3|kZ8…",
  "message": "Login Successful",
  "user": { "id": 3, "employee_id": 5, "email": "…", "status": "active",
            "employee": { "name": "…", "designation": { "slug": "officer" } } },
  "permissions": ["Dashboard-page", "Order-create", "…"]
}
```
Failure (still HTTP 200): `{"status": false, "message": "Unauthorized"}` or
`"Account is not active. Please contact support."`

### `POST /logout` · auth
Revokes the current access token. → `{ "status": true, "message": "Logout successful" }`

---

## 2. Profile

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/profile` | Authenticated user + permissions + live metrics |
| POST | `/profile/update` | Update own employee record and email (`multipart` for image) |
| POST | `/profile/change-password` | Change own password |

**`GET /profile` →**
```jsonc
{ "status": true, "data": {
    "user": { "…": "…", "employee": { "designation": {} } },
    "permissions": ["Dashboard-page", "…"],
    "metrics": { "customers_count": 42, "subordinates_count": 0, "sales_count": 118,
                 "total_sales": "1250000.00", "credit_limit": "500000.00",
                 "credit_use": "312400.00", "credit_available": "187600.00" } } }
```

**`POST /profile/update`** — `name`*, `phone`* (unique), `email`* (unique), `territory`*,
`district`*, `national_id`, `blood_group`, `image` (jpeg/png/jpg/gif ≤ 2 MB). Errors → `422`.

**`POST /profile/change-password`** — `current_password`*, `new_password`* (min 6, requires
`new_password_confirmation`). Wrong current password → `422`.

---

## 3. Notifications

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/notifications` | All notifications for the current user |
| POST | `/notifications/{id}/read` | Mark one as read |
| POST | `/notifications/read-all` | Mark all unread as read |

Notification `data` payload: `{ "message": "…", "order_id": 12 }` or
`{ "message": "…", "invoice_id": 88 }`.

---

## 4. Designations

| Method | Endpoint | Body |
| --- | --- | --- |
| GET | `/designations` | — |
| GET | `/designationShow/{id}` | — |
| POST | `/designationStore` | `name`*, `slug` |
| POST | `/designationUpdate/{id}` | `name`*, `slug` |
| POST | `/designationDelete/{id}` | — |

`slug` is lower-cased on save and must be one of `admin` / `rsm` / `manager` / `officer`
for hierarchy logic to work.

---

## 5. Employees

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/employees` | Active employees, scoped by caller's hierarchy |
| GET | `/employeeShow/{id}` | Single employee |
| POST | `/employeeStore` | Create (multipart) |
| POST | `/employeeUpdate/{id}` | Update (multipart) |
| POST | `/employeeDelete/{id}` | Deactivate (`status = deactive`) |
| GET | `/employees/credit-report` | Credit usage report — `from_date`, `to_date` |

**Create/update body:** `employee_id`* (unique), `designation_id`*, `name`*, `phone`*
(unique), `territory`*, `district`*, `national_id`, `blood_group`, `basic_salary`, `image`.
`credit_limit` is ignored — it is always derived from customers/subordinates.

**`GET /employees/credit-report` →**
```jsonc
{ "status": true, "data": [
  { "id": 5, "employee_name": "…", "credit_limit": "500000.00", "total_purchase": "…",
    "total_payment": "…", "total_due": "…", "credit_use": "…", "credit_due": "…" } ] }
```

---

## 6. Users

| Method | Endpoint | Body |
| --- | --- | --- |
| GET | `/users` | — (all active users) |
| GET | `/userShow/{id}` | — (returns user + all permissions + user's permission IDs) |
| POST | `/userStore` | `employee_id`* (unique in users), `email`*, `password`* (min 6), `permission[]`* |
| POST | `/userUpdate/{id}` | `email`*, `password` (nullable), `permission[]`* |
| POST | `/userDelete/{id}` | — (deactivate; self-deactivation blocked) |

---

## 7. Employee Relations (hierarchy)

| Method | Endpoint | Body / Description |
| --- | --- | --- |
| GET | `/getOfficers` | Active employees with slug `officer` (`id`, `name`) |
| POST | `/getRelatedEmployees` | `employee_id`* → valid superiors for that employee |
| POST | `/relationStore` | `employee_id`*, `relation_id`* (must differ) — set/replace superior |

`relationStore` validates the pairing (Officer→Manager, Manager→RSM) and recalculates the
credit limits of both the new and the previous superior.

---

## 8. Customers

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/customers` | Hierarchy-scoped list incl. `total_purchases`, `total_payments`, `due` |
| GET | `/customerByEmployee/{employeeId}` | Customers of one officer |
| GET | `/customerShow/{id}` | Single customer |
| POST | `/customerStore` | Create (multipart) |
| POST | `/customerUpdate/{id}` | Update (multipart) |
| POST | `/customerDelete/{id}` | Delete (FK-protected) |
| POST | `/customerToggleSms/{id}` | Flip `sms_enabled` |

**Create:** `employee_id`* (must be an `officer`), `customer_name`*, `proprietor_name`*,
`phone`*, `address`*, `credit_limit`, `old_due`, `image`.
**Update:** same, but `old_due` and `credit_limit` are required.
`customer_id` is auto-generated (`RA18/0001`).

---

## 9. Brands, Categories, Suppliers

### Brands / Categories — identical shape

| Method | Endpoint | Body |
| --- | --- | --- |
| GET | `/brands` · `/categories` | — |
| GET | `/brandShow/{id}` · `/categoryShow/{id}` | — |
| POST | `/brandStore` · `/categoryStore` | `name`* (max 255) |
| POST | `/brandUpdate/{id}` · `/categoryUpdate/{id}` | `name`* |
| POST | `/brandDelete/{id}` · `/categoryDelete/{id}` | — |

### Suppliers

| Method | Endpoint |
| --- | --- |
| GET | `/suppliers` |
| GET | `/supplierShow/{id}` |
| POST | `/supplierStore` |
| POST | `/supplierUpdate/{supplier}` |
| POST | `/supplierDelete/{supplier}` |

**Body:** `proprietor_name`*, `company_name`*, `phone`*, `email` (unique), `whatsapp`,
`country`*, `address`*, `image`.

---

## 10. Products & Stock

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/products` | All products with category + brand, sorted by name |
| GET | `/productShow/{id}` | Single product |
| POST | `/productStore` | Create (multipart) |
| POST | `/productUpdate/{id}` | Update (multipart) |
| POST | `/productDelete/{id}` | Delete (FK-protected) |
| POST | `/stock_in` | Purchase-in from supplier |
| POST | `/stock_out` | Non-sale stock removal |
| GET | `/stockInOutHistory/{productId}` | Movement ledger |

**Product body:** `cat_id`*, `brand_id`*, `name`*, `pack_size`, `expire_date`, `buy_price`,
`sell_price`, `image`.

**`POST /stock_in`** — `product_id`*, `quantity`* (int ≥ 1), `buy_price`*, `supplier_id`*,
`in_out_date`* (date), `expire_date`. Increments `products.quantity`.

**`POST /stock_out`** — `product_id`*, `quantity`* (int ≥ 1), `purpose`*, `who_take`*,
`in_out_date`*. Rejects if stock is insufficient; decrements `products.quantity`.

```jsonc
// stock_in / stock_out response
{ "status": true, "message": "Stock added successfully.",
  "data": { "id": 1, "product_id": 4, "quantity": 100, "in_out": "in", "…": "…" },
  "updated_product": { "id": 4, "quantity": 340, "…": "…" } }
```

---

## 11. Orders

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/orders` | Hierarchy-scoped; filters `customer_id`, `employee_id` |
| GET | `/orderShow/{id}` | Single order with customer, employee, line items |
| POST | `/orderStore` | Create |
| POST | `/orderUpdate/{id}` | Update |
| POST | `/orderDelete/{id}` | Delete order + line items |
| POST | `/orders/{order}/status` | `status`* ∈ `active` \| `inactive` \| `pending` |

**Create/update body**
```jsonc
{
  "cust_id": 12,
  "discount": 5,                       // percent
  "order_date": "2026-09-06",
  "order_type": "credit",              // cash | credit
  "offer": "Monsoon Combo",            // optional, max 255
  "products": [
    { "product_id": 4, "quantity": 10, "unit_price": 250.00,
      "bonus_qty": 1, "price_type": "tp" }   // price_type: tp | flat
  ]
}
```
`employee_id` is taken from the authenticated user. Quantities are rounded to non-negative
integers. Credit-limit breaches → **`422`**:
`"Order exceeds employee credit limit."` / `"Order exceeds customer credit limit."`

**`GET /orders` →** flattened rows:
```jsonc
{ "status": true, "data": [
  { "id": 7, "customer_name": "…", "employee_name": "…", "employee_id": "EMP-014",
    "products": [ { "product_name": "…", "pack_size": "1L", "quantity": 10,
                    "unit_price": 250, "bonus_qty": 1, "price_type": "tp" } ],
    "order_date": "2026-09-06", "order_type": "credit", "status": "pending",
    "offer": null, "created_at": "…", "updated_at": "…" } ] }
```

---

## 12. Invoices (Sales)

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/invoices` | Hierarchy-scoped; filters `customer_id`, `employee_id` |
| GET | `/invoiceShow/{id}` | Single invoice with customer, employee, line items |
| GET | `/salesByEmployee/{employeeId}` | All invoices for one employee |
| POST | `/invoiceStore/{orderId?}` | Create — with `orderId`, converts and **deletes** that order |
| POST | `/invoiceUpdate/{id}` | Update (restores + re-applies stock) |
| POST | `/invoiceDelete/{id}` | Delete and restore stock |
| POST | `/invoice/{id}/mark-printed` | Set `is_printed = true`, `printed_at = now()` |
| POST | `/invoice/{id}/update-offer` | `offer` (nullable, max 255) |

**Create/update body**
```jsonc
{
  "cust_id": 12,
  "total_item": 3,
  "total_price": 2500.00,
  "discount": 100.00,
  "less": 0.50,
  "grand_total": 2400.00,     // recomputed server-side
  "paid": 1000.00,
  "due": 1400.00,             // recomputed server-side
  "sale_date": "2026-09-06",
  "sale_type": "credit",      // cash | credit
  "offer": "Monsoon Combo",
  "products": [
    { "product_id": 4, "quantity": 10, "unit_price": 250.00,
      "bonus_qty": 1, "price_type": "tp", "due_quantity": 0 }   // due_quantity: update only
  ]
}
```

* `grand_total = round(total_price − discount − less)` with **`.50` down / `.51` up**;
  `due = grand_total − paid`. Client-supplied values are overwritten.
* `paid > grand_total` → `{"status": false, "message": "Paid amount cannot be greater than the grand total."}`
* Employee-limit breach → `{"status": false, "message": "Invoice exceeds employee credit limit."}` (HTTP 200)
* Customer-limit breach (credit sales) → **`422`** `"Invoice exceeds customer credit limit."`
* Stock decrements by `quantity + bonus_qty`.

**Success →**
```jsonc
{ "status": true, "message": "Invoice and products created successfully",
  "data": { "invoice": { "id": 88, "invoiceId": "RAINVO-07", "grand_total": 2400, "due": 1400, "…": "…" },
            "products": [ { "invoice_id": 88, "product_id": 4, "quantity": 10, "…": "…" } ] } }
```

Side effects on create: database notifications to employee + manager + all admins, email to
the manager, and an SMS to the customer when `sms_enabled`.

---

## 13. Payments

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/payments` | All payments |
| GET | `/paymentShow/{id}` | Single payment |
| GET | `/custPaymentHistory/{custId}` | Customer's payment history (with collecting employee) |
| GET | `/supplierPaymentHistory/{supplierId}` | Supplier payment history |
| POST | `/paymentStore` | Create |
| POST | `/paymentUpdate/{id}` | Update |
| POST | `/paymentDelete/{id}` | Delete |

**Body:** `employee_id`, `cust_id`, `supplier_id` (all nullable — set the pair that applies),
`amount`*, `payment_method`* ∈ `cash` \| `check`, `payment_date`*.

Creating a payment with `cust_id` sends the customer an SMS with the amount received and
their recomputed due (when `sms_enabled`).

---

## 14. Costs & Cost Categories

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/costs` | All costs |
| GET | `/costShow/{id}` | Single cost |
| GET | `/officeCost` | Costs with no employee, joined to cost category |
| GET | `/employeeCost/{employeeId}` | Costs for one employee |
| POST | `/costStore` | Create |
| POST | `/costUpdate/{id}` | Update |
| POST | `/costDelete/{id}` | Delete |

**Body:** `cost_cat_id`, `employee_cost_cat_id`, `employee_id` (all nullable),
`amount`* (≥ 0), `cost_date`*.

### Category CRUD (identical shape, body `name`*)

| Office cost categories | Employee cost categories |
| --- | --- |
| `GET /costCategories` | `GET /employeeCostCategories` |
| `GET /costCategoryShow/{id}` | `GET /employeeCostCategoryShow/{id}` |
| `POST /costCategoryStore` | `POST /employeeCostCategoryStore` |
| `POST /costCategoryUpdate/{id}` | `POST /employeeCostCategoryUpdate/{id}` |
| `POST /costCategoryDelete/{id}` | `POST /employeeCostCategoryDelete/{id}` |

---

## 15. Salaries

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/salaries` | All salary records |
| GET | `/salaryShow/{id}` | Single record |
| GET | `/employeeSalary/{employeeId}` | Chronological salary ledger for one employee |
| POST | `/salaryStore` | `employee_id`*, `paid_amount`* (≥ 0), `month_year`* (`YYYY-MM`) |
| POST | `/salaryUpdate/{id}` | `paid_amount`* (≥ 0) |
| POST | `/salaryDelete/{id}` | Delete |

`basic_salary`, `advance_amount` and `due_amount` are computed from the employee's basic
salary plus carried-forward dues/advances — never supplied by the client. Paying twice in
one month accumulates onto the existing row.

---

## 16. Roles & Permissions

### Permissions

| Method | Endpoint | Body |
| --- | --- | --- |
| GET | `/permissions` | — (excludes `Developer`) |
| GET | `/permissionShow/{id}` | — |
| POST | `/permissionStore` | `name`* (unique) |
| POST | `/permissionUpdate/{id}` | `name`* (unique) |
| POST | `/permissionDelete/{id}` | — |

### Roles

| Method | Endpoint | Body |
| --- | --- | --- |
| GET | `/roles` | — |
| GET | `/roleShow/{id}` | — |
| POST | `/roleStore` | `name`* (unique) |
| POST | `/roleUpdate/{id}` | `name`* (unique) |
| POST | `/roleDelete/{id}` | — |
| GET | `/roles/{roleId}/give-permissions` | Role + all permissions + current assignments |
| POST | `/roles/{roleId}/give-permissions` | `permission[]`* — syncs to the role |

> Both `give-permissions` handlers additionally sync the submitted permissions onto the
> hard-coded `User::findOrFail(3)`. Roles are unused in production; permissions are assigned
> directly to users through `/userStore` and `/userUpdate/{id}`.

---

## 17. Reports — authenticated

| Method | Endpoint | Query params | Returns |
| --- | --- | --- | --- |
| GET | `/dashboard-report` | — | `total_employee`, `total_customer`, `total_supplier`, `total_order` |
| GET | `/cashCreditSale` | `from_date`,`to_date`,`days` | `cash_total`, `credit_total` |
| GET | `/dueInvoice` | `from_date`,`to_date`,`days`,`customer_id` | Unpaid invoices + `days_since_sale` |
| GET | `/low_stock_alerts` | `threshold` (default 10) | `low_stock_alerts[]` |
| GET | `/productReport/{id}` | — | Product details + line-level sale history with apportioned discount/less |
| GET | `/customerReport/{id}` | — | Customer + invoices + `total_purchases`/`total_payments`/`total_due` |
| GET | `/employeeReport/{id}` | — | Employee + own or subordinates' invoices (hierarchy-aware) |
| GET | `/supplierReport/{id}` | — | Supplier + `total_purchase`, `total_payment`, `net_due` |
| GET | `/brandReport/{id}` | `from_date`,`to_date`,`days` | Brand sales + product breakdown |
| GET | `/categoryReport/{id}` | `from_date`,`to_date`,`days` | Category sales + product breakdown |
| GET | `/customerWiseSalesReport` | `from_date`,`to_date`,`days` | Per-customer invoices/purchases/payments/due |
| GET | `/productWiseSalesReport` | `from_date`,`to_date`,`days` | Per-product qty, gross, discount, less, net |
| GET | `/categoryWiseSalesReport` | `from_date`,`to_date`,`days` | Per-category totals |
| GET | `/product-wise-sales` | `employee_id`,`from_date`,`to_date`,`days` | Role-scoped product sales with cash/credit split |
| GET | `/payment-history-by-role` | `employee_id`,`from_date`,`to_date`,`days` | Collections per employee, role-scoped |
| GET | `/customer-sales-report` | `from_date`,`to_date` | Same handler as `/sales-report` |
| GET | `/profit-loss-report` | `from_date`,`to_date`,`days` | See below |
| GET | `/next-payable-month-salary` | — | Projected next payable month + amount per employee |

**`GET /profit-loss-report` →** (note: this endpoint returns a bare object, not the standard envelope)
```jsonc
{ "Product Sales": 1250000, "Product Buy Price": 890000, "Salary Cost": 120000,
  "Employee Add Cost": 34000, "Office Cost": 51000, "Net Profit / Loss": 155000 }
```

**`GET /product-wise-sales` →**
```jsonc
{ "status": true, "data": [
  { "id": 4, "product_name": "…", "pack_size": "1L", "total_invoice": 31,
    "total_quantity": 420, "total_item_amount": 105000, "total_item_discount": 3200,
    "total_item_less": 40, "total_amount": 101760,
    "total_cash_sale": 41000, "total_credit_sale": 60760 } ] }
```

---

## 18. Reports — ⚠️ currently unauthenticated

These are registered **outside** the `auth:sanctum` group and are publicly accessible.

| Method | Endpoint | Query params | Returns |
| --- | --- | --- | --- |
| GET | `/sales-report` | `from_date`,`to_date` | `total_sales` + breakdowns by product, category, customer, employee |
| GET | `/customer-payment-report` | `from_date`,`to_date` | Payments received, outstanding dues, per-customer paid vs due |
| GET | `/inventory-report` | `from_date`,`to_date` | Stock levels, in/out movements, low-stock alerts |
| GET | `/employee-report` | `from_date`,`to_date` | Salary details, sales performance, due vs paid |
| GET | `/expense-report` | `from_date`,`to_date` | Total operational cost, cost by category, salaries paid |
| GET | `/cashflow-report` | `from_date`,`to_date` | Inflows vs outflows, net cash flow |
| GET | `/account-report` | `from_date`,`to_date` | Receivables, payables, per-customer/supplier balances |
| GET | `/product-profitability-report` | `from_date`,`to_date` | Per-product revenue, COGS, profit, margin % |
| GET | `/supplier-report` | `from_date`,`to_date` | Per-supplier purchases, payments, net due |
| GET | `/customer-report/{customerId}` | — | Customer name, purchases, payments, due |

These return bare objects (no `status`/`message` envelope), e.g.:
```jsonc
{ "cash_inflows": 980000,
  "cash_outflows": { "supplier_payments": 400000, "salaries_paid": 120000,
                     "business_expenses": 85000, "total_outflows": 605000 },
  "net_cash_flow": 375000 }
```

---

## 19. SMS

| Method | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/send-custom-sms` | auth + `throttle:3,1` | Broadcast — **admin designation only** |
| GET | `/sms-history` | auth | Paginated log; filters `customer_id`, `status`, `from_date`, `to_date`, `per_page` |
| GET | `/sms-summary` | auth | `total_sms`, `sent_count`, `failed_count` |
| GET | `/sms-balance` | auth | Live gateway balance |
| POST | `/customerToggleSms/{id}` | auth | Flip a customer's `sms_enabled` |

**`POST /send-custom-sms`**
```jsonc
// request — omit customer_ids to target every SMS-enabled customer
{ "customer_ids": [3, 7, 12], "message": "Eid offer: 10% off all feed products." }
```
```jsonc
// response
{ "status": true, "message": "SMS sending process completed.",
  "data": { "total_customers": 3, "success_count": 3, "fail_count": 0 } }
```
Non-admin caller → **`403`**. No matching customers → **`404`**.

### SMS Templates — ⚠️ currently unauthenticated

| Method | Endpoint | Body |
| --- | --- | --- |
| GET | `/sms-templates` | — |
| POST | `/sms-templates` | `name`*, `content`*, `status` (bool, default `true`) → **201** |
| GET | `/sms-templates/{id}` | — (404 if missing) |
| POST | `/sms-templates/{id}` | `name`*, `content`*, `status` |
| DELETE | `/sms-templates/{id}` | — |

---

## 20. Non-API routes

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/` | Laravel welcome view (`routes/web.php`) |
| GET | `/up` | Laravel 11 health check |

---

## 21. Generating a Postman collection

`andreaselia/laravel-api-to-postman` is installed:

```bash
php artisan export:postman
# → storage/app/postman/{timestamp}_{app}_collection.json
```

Configure base URL, auth token and filename in `config/api-postman.php`.
