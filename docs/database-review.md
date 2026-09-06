# Database Design Review — Radiant Agrovet

**Scope:** the 31 migrations in `database/migrations`, the Eloquent models, every query in
`app/Http/Controllers`, and the production snapshot `gldqpoea_radian_agrovet.sql`
(phpMyAdmin data-only export, 11 Feb 2026, MariaDB 11.4).

**Method:** DDL is taken from the migrations. The dump contains no `CREATE TABLE` statements,
so production indexes cannot be read directly — but the dump's `INSERT` column lists and row
data were used to verify what the schema *actually* holds, find constraint violations, and
measure volumes. Every number below was computed from that snapshot.

---

## Part 1 — Current Design Overview

### 1.1 Volumes (Feb 2026 snapshot)

| Table | Rows | Notes |
| --- | ---: | --- |
| `invoices` | 1,092 | 12 Sep 2025 → 10 Feb 2026 (~220 / month); 455 cash, 637 credit |
| `invoice_products` | 2,541 | ~2.3 lines per invoice; 1,287 `tp`, 1,254 `flat` |
| `customers` | 521 | |
| `stock_in_outs` | 293 | 193 in, 100 out |
| `products` | 99 | |
| `employees` / `users` | 55 / 33 | 32 active, 23 deactivated employees |
| `relations` | 27 | |
| `payments` | 61 | all `cash`, all customer + employee |
| `orders` / `order_products` | 3 / 4 | orders are deleted on conversion to invoice |
| `costs` / `salaries` | 2 / 1 | barely used |
| `notifications` | **5,629** | 5,490 (97 %) unread; user #1 alone holds 2,168; ~1.4 MB of JSON |
| `personal_access_tokens` | **4,652** | user #1 has 1,794; **0** rows have `expires_at` |
| `permissions` / `model_has_permissions` | 99 / 1,663 | |
| `migrations` | 23 | last recorded: `2025_01_20_…create_salaries_table` |

This is a **small** database (≈ 17 k business rows). Partitioning, sharding and read
replicas are not justified today; the problems are about **correctness, drift and unbounded
growth in two infrastructure tables**, not raw scale.

### 1.2 Table structure (from migrations)

| Table | PK | Foreign keys (all `restrictOnDelete` unless noted) | Unique | Notable types |
| --- | --- | --- | --- | --- |
| `designations` | id | — | `name`, `slug` | |
| `employees` | id | `designation_id` | `employee_id` (business code) | `credit_limit`, `basic_salary` **double**; `created_by` **int, no FK**; `status` enum(`active`,`deactive`) |
| `users` | id | `employee_id` | `email` (nullable!) | no `remember_token`, no `email_verified_at` |
| `relations` | id | `employee_id`, `relation_id` → employees; `created_by` → users | `employee_id` | self-referencing org tree |
| `customers` | id | `employee_id` | `customer_id` (business code) | `credit_limit`, `purchase`, `payment`, `old_due` **decimal(15,2)**; `sms_enabled` bool |
| `suppliers` | id | — | — | `email` nullable |
| `brands`, `categories` | id | — | — | `name` only |
| `products` | id | `cat_id`, `brand_id` (`cascadeOnUpdate`) | — | `buy_price`, `sell_price` **double**; `quantity` int; `expire_date` **string** |
| `stock_in_outs` | id | `product_id`, `supplier_id` (nullable) | — | `in_out` **string** (not enum); `buy_price` double nullable; `who_take` free text; `in_out_date` **string** |
| `orders` | id | `cust_id`, `employee_id` | — | `discount` double(8,2) **= percent**; `order_type` enum nullable; `status` enum; `order_date` **string**; `offer` string |
| `order_products` | id | `order_id`, `product_id` | — | `unit_price` double; `price_type` enum(`tp`,`flat`) |
| `invoices` | id | `cust_id`, `employee_id` | `invoiceId` | 7 money columns **double**; `discount` **= amount**; `sale_date` **string**; `is_printed`, `printed_at`, `offer` |
| `invoice_products` | id | `invoice_id`, `product_id` | — | `unit_price` double; `due_quantity`, `bonus_qty` int |
| `payments` | id | `cust_id`, `employee_id`, `supplier_id` — **all nullable** | — | `amount` double; `payment_method` enum(`cash`,`check`); `payment_date` **string** |
| `cost_categories`, `employee_cost_categories` | id | — | — | two identical tables |
| `costs` | id | `cost_cat_id`, `employee_cost_cat_id`, `employee_id` — **all nullable** | — | `amount` double; `cost_date` **string** |
| `salaries` | id | `employee_id` | **none** (no unique on employee+month) | 4 money columns double; `month_year` **string** `YYYY-MM` |
| `notifications` | uuid | morph `notifiable` | — | `data` text |
| `sms_histories` | id | `customer_id` (**`ON DELETE SET NULL`**) | — | indexes on `customer_id`, `status`, `sent_at` — the only table with deliberate secondary indexes |
| `sms_templates` | id | — | — | |
| Spatie `permissions`, `roles`, `model_has_*`, `role_has_permissions` | | standard | | `roles` unused |
| Sanctum `personal_access_tokens` | id | morph `tokenable` | `token` | `expires_at` never set |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | | framework | | |

**Indexes present (by construction):** primary keys, the `unique()` columns above, MySQL's
automatic index on every FK column, `morphs()` composite indexes, and the three explicit
indexes on `sms_histories`. **No other secondary index exists** — none on any date column,
status column, phone, or the `(employee_id, sale_date)` combinations the reports filter on.

### 1.3 Relationships

| Type | Relationship | Implementation |
| --- | --- | --- |
| 1 : 1 | Employee ↔ User | `users.employee_id` FK — **not unique at DB level** (uniqueness only via validation rule) |
| 1 : 1 | Employee → superior | `relations.employee_id` unique, `relation_id` = superior |
| 1 : N | Designation → Employees | FK |
| 1 : N | Employee (officer) → Customers | FK; the "officer only" rule lives in the controller |
| 1 : N | Employee → Orders, Invoices, Salaries, Costs, Payments | FK |
| 1 : N | Customer → Orders, Invoices, Payments, SmsHistories | FK |
| 1 : N | Category / Brand → Products | FK |
| 1 : N | Supplier → StockInOuts, Payments | FK |
| 1 : N | Product → StockInOuts, OrderProducts, InvoiceProducts | FK |
| N : N | Order ↔ Product via `order_products` (with `quantity`, `unit_price`, `bonus_qty`, `price_type`) | pivot |
| N : N | Invoice ↔ Product via `invoice_products` (+ `due_quantity`) | pivot |
| N : N | User ↔ Permission via `model_has_permissions` | Spatie |
| polymorphic | User → Notifications, PersonalAccessTokens | morphs |
| **pseudo-polymorphic** | Payment → Customer *or* Supplier *or* Employee | three nullable FKs, no constraint on the combination |
| **pseudo-polymorphic** | Cost → CostCategory *or* EmployeeCostCategory | two nullable FKs |

### 1.4 What is in good shape

Being fair about it, several things are right and worth keeping:

* **Referential integrity holds.** Eight orphan checks across the snapshot
  (invoices→customers/employees, invoice_products→products, customers→employees,
  payments→employees, relations→employees/users, stock_in_outs→suppliers) found **0 orphans**.
* **`restrictOnDelete` everywhere** prevents silent data loss; `sms_histories` correctly uses
  `SET NULL`.
* **String date columns are consistently ISO `YYYY-MM-DD`** in production (1,092/1,092
  `sale_date`, 293/293 `in_out_date`, 61/61 `payment_date`, 99/99 `expire_date`), so a
  migration to real `DATE` columns is mechanical and safe.
* **Stock reconciles.** `products.quantity` = Σ stock-in − Σ stock-out − Σ (sold + bonus)
  for 98 of 99 products (product #2 is off by 10 units).
* Line-item **price snapshots** (`unit_price` on pivots) preserve history when list prices change.
* Passwords are bcrypt, Sanctum tokens are SHA-256 hashed, `national_id` is at least nullable.

---

## Part 2 — Problems Found

Ordered by severity. Each item states the evidence.

### 🔴 P0 — Data integrity

#### 2.1 `invoices.invoiceId` is not unique — 318 invoices share "RAINVO-01"

* Migration: `$table->string('invoiceId')->unique();`
* Production: **27 distinct values across 1,092 rows**. `RAINVO-01` ×318, `RAINVO-02` ×197,
  `RAINVO-03` ×144 …
* Cause: `InvoiceController::store()` derives the next number from *the customer's* latest
  invoice, so every customer restarts at 01, while the column is meant to be global. The
  unique index must have been dropped in production to let inserts through.
* Impact: the number printed on customer invoices is not an identifier. Notifications
  ("Your invoice RAINVO-01 has been created") are ambiguous. Any external reference
  (bank reconciliation, tax audit) is unusable.

#### 2.2 Two sources of truth for what a customer owes — and they disagree

`invoices.paid` / `invoices.due` are stored **and** the `payments` table is a ledger. Nothing
keeps them in sync:

* **259 invoices** have `due = 0` while `paid = 0` and `grand_total > 0` — something zeroed
  `due` without recording `paid` (the now-commented-out block in `PaymentController` that
  adjusted invoice dues).
* Invoice #1003: `grand_total = 6518.4, paid = 0, due = 0.0000000000027284841053188`.
  Invoice #1070: `due = 0.0000000000018189894035459`. These are IEEE-754 residues from
  `due -= amount` on a `DOUBLE` column.
* Reports split by which source they read: `dueInvoice`, `account-report`,
  `customer-payment-report` use `invoices.due`; `customerReport`, `CustomerController@index`
  and the credit-limit checks use `old_due + Σ grand_total − Σ payments`. **They return
  different numbers for the same customer.**

#### 2.3 Money stored as `DOUBLE`

Every monetary column except the four on `customers` (added later as `decimal(15,2)`) is
`double`: `invoices.*`, `payments.amount`, `products.*_price`, `salaries.*`, `costs.amount`,
`employees.credit_limit`, `order_products.unit_price`, `invoice_products.unit_price`.

* Invoice #487: `total_price = 4812.5, discount = 144.375, grand_total = 4668.125` — three
  decimal places of currency.
* The two `1e-12` residues above.
* Sums across thousands of rows accumulate error; `SUM(grand_total)` in reports will not
  equal the printed invoices to the paisa.

#### 2.4 Production schema is managed by hand — migrations no longer describe it

The `migrations` table stops at `2025_01_20_062003_create_salaries_table` (23 rows), yet:

| Evidence in the dump | Migration that should have recorded it |
| --- | --- |
| `notifications` table exists with 5,629 rows | `2025_10_01_…create_notifications_table` — **not recorded** |
| `invoices` has `is_printed`, `printed_at` | `2025_12_01_…add_print_fields` — **not recorded** |
| `customers` has `old_due` but **not** `credit_limit`, `purchase`, `payment` | `2026_01_29_…add_purchase_and_payment` — half applied, not recorded |
| `products` has **`flat_price`** | **no migration exists at all** (model and controller use it) |
| `designations.slug` has 4 rows `manager`, 4 rows `officer` | migration declares `slug` **unique** — constraint absent |
| `invoices.invoiceId` duplicated (2.1) | migration declares **unique** — constraint absent |

Consequences: `php artisan migrate` on production will fail (`notifications` already exists);
a fresh environment built from migrations will lack `flat_price` and will *enforce* the two
unique constraints production has abandoned; nobody can be sure which indexes production has.

#### 2.5 Business-rule uniqueness not enforced

| Rule assumed by the code | DB constraint | Risk |
| --- | --- | --- |
| One salary row per employee per month (`SalaryController` does find-then-update) | none | concurrent posts → two rows for the same month; carry-forward maths double-counts |
| One user per employee (`unique:users,employee_id` validation rule) | none | two logins for one employee |
| `designations.slug` is a role key (`admin`/`rsm`/`manager`/`officer`) | unique dropped in prod | fine as data, but the column is doing two jobs — see 2.9 |

### 🟠 P1 — Normalization, redundancy, modelling

#### 2.6 `payments` and `costs` are "pick-one-of-three-nullable-FKs" tables

`payments (cust_id NULL, employee_id NULL, supplier_id NULL)` — a row with all three NULL, or
customer **and** supplier set, is accepted. `PaymentController` validates each column
individually, never the combination. `employee_id` is semantically overloaded: for customer
payments it is *the collector*; for a standalone row it is *the payee*. The same shape
repeats in `costs (cost_cat_id, employee_cost_cat_id, employee_id)`. This is a classic
1NF/3NF smell: the meaning of a column depends on which sibling column is filled.

#### 2.7 Duplicate tables and dead columns

* `cost_categories` and `employee_cost_categories` are structurally identical (`id`, `name`).
* `customers.purchase` and `customers.payment` (migration 2026-01-29) are **never written**
  by any controller (0/521 non-zero in the snapshot).
* `invoices.total_item` is `COUNT(invoice_products)`.
* `products.flat_price` exists in production but not in migrations (2.4).

#### 2.8 The stock ledger is split across two tables

Manual movements go to `stock_in_outs`; sales decrement `products.quantity` directly and leave
**no ledger row**. Reconstructing stock therefore requires joining `stock_in_outs` **and**
`invoice_products` (+ bonus). Invoice *updates* increment/decrement in place, so an audit of
"why is quantity X" is impossible from the ledger alone. The one mismatched product (#2,
−10 units) cannot be explained from the data.

#### 2.9 Roles are encoded in a display slug; hierarchy lives in a separate table

* `designations.slug` is simultaneously a URL-ish slug and the authorization level. Four
  designations map to `manager`, four to `officer`. The intended rule (`officer→manager→rsm`)
  is enforced in `RelationController`, not by the schema.
* `relations(employee_id UNIQUE, relation_id)` is exactly a nullable self-FK
  `employees.manager_id` wearing a second table. Every request resolves the tree with 1–2
  extra `pluck()` queries; RSM resolution is two round-trips.

#### 2.10 Cached aggregates without a recompute path

* `employees.credit_limit` is a materialised roll-up (Σ customers / Σ subordinates) recomputed
  by `Employee::recalculateCreditLimit()` on *some* events. There is no command to rebuild it,
  and in the Feb-2026 snapshot it still holds hand-entered values (14 × 500,000; 7 × 300,000).
* `products.quantity` (see 2.8).

#### 2.11 Historical cost is not captured

`invoice_products` snapshots `unit_price` but not the product's `buy_price` at sale time.
`generateProductProfitabilityReport` multiplies historical quantities by the **current**
`products.buy_price`, so margins rewrite themselves whenever a purchase price changes.

#### 2.12 Same column name, different meaning

* `orders.discount` is a **percentage**; `invoices.discount` is an **amount** (the frontend
  converts). Both are just `discount`.
* `customers.customer_id` is a *string business code* (`RA18/0001`), while
  `sms_histories.customer_id` is an *integer FK*. Likewise `employees.employee_id` (code) vs
  `users.employee_id` / `customers.employee_id` (FK). Every join has to be double-checked.
* FK columns are `cust_id` but the table is `customers`; PK column `invoiceId` is camelCase
  in an otherwise snake_case schema; `stock_in_outs.in_out`; `status` value `deactive`.

#### 2.13 Dates and periods as strings

`sale_date`, `order_date`, `payment_date`, `in_out_date`, `cost_date`, `expire_date`,
`month_year` are `VARCHAR`. Production values are clean ISO strings, so range filters
*happen* to work lexicographically, but: no type validation (`'2026-13-45'` inserts fine),
`Carbon::parse(...)->startOfDay()` bounds are compared as strings, `DATE_FORMAT`/`YEAR()`
need `STR_TO_DATE` (already used in `SalaryController`), and no index-friendly range scans.

#### 2.14 Nullable overuse

`payments` ×3 FKs, `costs` ×3 FKs, `orders.order_type`, `users.email` (login requires it),
`users.password`, `products.expire_date/buy_price/sell_price`, `stock_in_outs.buy_price`
(nullable even for `in` rows). Nullable-by-default hides which combinations are valid.

### 🟡 P2 — Performance & growth

#### 2.15 Two infrastructure tables grow without bound

* **`personal_access_tokens`: 4,652 rows, 0 expiring.** Every login mints a token; logout
  revokes only the current one; `sanctum:prune-expired` cannot prune rows with
  `expires_at = NULL`. User #1 carries 1,794 live tokens — each a valid credential.
* **`notifications`: 5,629 rows, 97 % unread**, and `NotificationController@index` returns
  `$user->notifications` — *all of them* — while the SPA polls it **every 60 s per user**.
  For user #1 that is 2,168 rows (~0.5 MB of JSON) per minute. There is no pagination, no
  `unreadNotifications` short-cut, no pruning.

#### 2.16 Missing indexes on every filter column

Report and list queries filter on columns with no index: `invoices.sale_date`,
`invoices.sale_type`, `invoices.due > 0`, `payments.payment_date`, `stock_in_outs.in_out_date`,
`costs.cost_date`, `orders.status`, `customers.phone`, `products.quantity` (low-stock),
`salaries.month_year`. Composite `(employee_id, sale_date)` / `(cust_id, sale_date)` would
serve the hierarchy-scoped reports. Fine at 1 k rows; a full scan at 100 k.

#### 2.17 N+1 and unpaginated endpoints (from controller code)

| Endpoint | Pattern | Cost today |
| --- | --- | --- |
| `GET /employees/credit-report` | loops every employee, 4 aggregate queries each | 55 × 4 ≈ 220 queries |
| `GET /next-payable-month-salary` | 2 queries per employee | ≈ 110 queries |
| `GET /invoices`, `/orders`, `/customers`, `/dueInvoice`, `/payments` | no pagination; full result + nested products serialised | 1,092 invoices × lines on every `/sales` visit |
| `GET /customer-payment-report` | `Customer::with('invoices')->get()` then sums in PHP | loads every invoice in range into memory |
| `GET /notifications` | see 2.15 | |
| hierarchy resolution | 1–2 `pluck()` per request, uncached | small, but repeated on every scoped endpoint |

### 🟡 P2 — Security, audit, lifecycle

#### 2.18 No audit trail on financial records

Invoices, invoice lines, payments, stock movements, salaries and costs have **no
`created_by` / `updated_by`** and **no soft deletes**. `InvoiceController::destroy()` and
`PaymentController::destroy()` hard-delete money records; order → invoice conversion
hard-deletes the order, so the invoice cannot be traced back to the request that produced it.
The only provenance columns in the schema are `employees.created_by` (no FK) and
`relations.created_by`.

#### 2.19 Deactivation is a status flag with no consequences

23 of 55 employees are `deactive`; **4 of them still own 10 customers**, which keeps those
customers invisible to any active officer's scoped list and keeps the deactivated officer's
`credit_limit` in the manager roll-up. Customers, products and suppliers have no status at
all — and because every FK is `restrict`, they also cannot be deleted once referenced, so the
UI's "delete" buttons fail with a constraint message.

#### 2.20 Sensitive data

* A **full production dump** (customer names, phones, addresses, employee NIDs and phone
  numbers, password hashes) is committed at `agrovet/gldqpoea_radian_agrovet.sql`.
* `employees.national_id` is plaintext.
* 1,794 non-expiring bearer tokens for one user (2.15) is a credential-sprawl issue as much
  as a storage one.

#### 2.21 Data quality (symptoms of missing constraints)

* Customer phones in three formats — 411 `01738-737595`, 99 `01738737595`, 2 `+8801…` —
  and ≈ 23 phone values shared by more than one customer. SMS normalises at send time, but
  lookups and duplicate detection cannot.
* `customer_id` codes with inconsistent padding (`RA18/0001` … `RA18/000448`), so
  lexicographic ordering of the code is broken.
* Two `stock_in_outs` "out" rows with `who_take` free text like `Hasan Al Mahmud (Mojnu)` —
  the taker should be an employee FK.

---

## Part 3 — Recommended Improvements

### 3.1 Priority roadmap

| Priority | Change | Fixes |
| --- | --- | --- |
| **P0** | Reconcile migrations with production (3.2) | 2.4 |
| **P0** | Global invoice numbering + unique index (3.3) | 2.1 |
| **P0** | `DOUBLE` → `DECIMAL(15,2)` on all money (3.4) | 2.3 |
| **P0** | Remove the dump from the repo; rotate exposed secrets | 2.20 |
| **P1** | Payment allocations; drop `invoices.paid/due` as source of truth (3.5) | 2.2, 2.6 |
| **P1** | Unified `stock_movements` ledger (3.6) | 2.8, 2.10 |
| **P1** | Indexing pass (3.8) + prune tokens/notifications (3.9) | 2.15, 2.16 |
| **P1** | Missing unique constraints (3.7) | 2.5 |
| **P2** | `employees.manager_id` + `designations.level`; drop `relations` (3.10) | 2.9 |
| **P2** | Soft deletes + `created_by/updated_by` + activity log (3.11) | 2.18, 2.19 |
| **P2** | Real `DATE` columns, naming clean-up, merge cost categories (3.12) | 2.7, 2.12, 2.13, 2.14 |
| **P3** | Redis for hierarchy/dashboard cache and queues; replica only if needed (Part 4) | 2.17 |

### 3.2 Reconcile migrations with production

1. Export production DDL (`mysqldump --no-data`) and diff it against `php artisan migrate`
   on a clean database.
2. Write **idempotent** catch-up migrations for every manual change:

```php
// 2026_xx_xx_add_flat_price_to_products.php
public function up(): void
{
    if (! Schema::hasColumn('products', 'flat_price')) {
        Schema::table('products', fn (Blueprint $t) =>
            $t->decimal('flat_price', 15, 2)->default(0)->after('sell_price'));
    }
}
```

3. Insert the missing rows into `migrations` for changes that already exist
   (`notifications`, print fields) so `migrate:status` is truthful.
4. From then on: **no phpMyAdmin schema edits**; every change is a migration, and CI runs
   `migrate --pretend` against a production-schema copy.

### 3.3 Invoice numbering

**Before** (`InvoiceController::store`):

```php
$latest = Invoice::where('cust_id', $custId)->latest('id')->first();   // per-customer!
$n = $latest ? (int) preg_replace('/\D/', '', $latest->invoiceId) + 1 : 1;
$validated['invoiceId'] = 'RAINVO-' . str_pad($n, 2, '0', STR_PAD_LEFT);
```

**After** — a sequence table, locked inside the existing transaction:

```php
Schema::create('document_sequences', function (Blueprint $t) {
    $t->string('key')->primary();            // 'invoice', 'customer', 'order'
    $t->unsignedBigInteger('next')->default(1);
});

// inside DB::transaction():
$seq = DB::table('document_sequences')->where('key', 'invoice')->lockForUpdate()->first();
DB::table('document_sequences')->where('key', 'invoice')->increment('next');
$invoice->invoice_no = sprintf('RAINVO-%06d', $seq->next);      // RAINVO-000001
```

```php
Schema::table('invoices', function (Blueprint $t) {
    $t->renameColumn('invoiceId', 'invoice_no');
    $t->unique('invoice_no');
});
```

Back-fill existing rows with `RAINVO-%06d` from `id` order before adding the unique index.
If a per-customer running number is wanted on the print-out, store it as a separate
`customer_seq` column — never as the identifier.

### 3.4 Money columns

```php
// one migration, every money column
$table->decimal('grand_total', 15, 2)->change();   // invoices: total_price, discount, less, grand_total, paid, due
$table->decimal('amount', 15, 2)->change();        // payments, costs
$table->decimal('unit_price', 15, 2)->change();    // order_products, invoice_products
$table->decimal('buy_price', 15, 2)->change();     // products (+ sell_price, flat_price), stock_in_outs
$table->decimal('credit_limit', 15, 2)->change();  // employees (+ basic_salary)
$table->decimal('basic_salary', 15, 2)->change();  // salaries (+ paid_amount, advance_amount, due_amount)
```

Add `'grand_total' => 'decimal:2'` casts on the models and keep the `.50↓/.51↑` rounding
rule, but apply it once with `bcround`/`round($x, 2)` before persisting. Clean the three
polluted rows (#487, #1003, #1070) in the same migration.

### 3.5 Payments: allocate to invoices, stop storing `paid`/`due`

**Before:** `payments(cust_id?, employee_id?, supplier_id?, amount)` + `invoices.paid/due`
maintained by hand.

**After:**

```php
Schema::create('payments', function (Blueprint $t) {
    $t->id();
    $t->enum('direction', ['in', 'out']);                       // received / paid
    $t->morphs('counterparty');                                  // Customer | Supplier | Employee
    $t->foreignId('collected_by')->nullable()->constrained('employees');
    $t->decimal('amount', 15, 2);
    $t->enum('method', ['cash', 'cheque', 'bank', 'mobile']);
    $t->string('reference')->nullable();                         // cheque no / trx id
    $t->date('paid_on');
    $t->foreignId('created_by')->constrained('users');
    $t->timestamps();
    $t->softDeletes();
    $t->index(['counterparty_type', 'counterparty_id', 'paid_on']);
    $t->index('paid_on');
});

Schema::create('payment_allocations', function (Blueprint $t) {
    $t->id();
    $t->foreignId('payment_id')->constrained()->cascadeOnDelete();
    $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
    $t->decimal('amount', 15, 2);
    $t->unique(['payment_id', 'invoice_id']);
});
```

* `invoices.paid` and `invoices.due` become **derived**:
  `due = grand_total − Σ payment_allocations.amount`. Expose them as an Eloquent accessor or a
  `withSum('allocations as paid', 'amount')` — one definition, every report agrees.
* Unallocated cash (advance) is simply a payment whose allocations sum to less than `amount`.
* `customers.old_due` stays as an opening-balance invoice (`type = 'opening'`) so it flows
  through the same allocation logic instead of being a special case in every formula.

### 3.6 One stock ledger

```php
Schema::create('stock_movements', function (Blueprint $t) {
    $t->id();
    $t->foreignId('product_id')->constrained()->restrictOnDelete();
    $t->integer('qty_delta');                                    // +in / −out, bonus included
    $t->enum('type', ['purchase', 'sale', 'sale_reversal', 'return', 'adjustment', 'transfer']);
    $t->nullableMorphs('reference');                             // Invoice | StockIn | Order …
    $t->decimal('unit_cost', 15, 2)->nullable();                 // for purchases → COGS
    $t->foreignId('supplier_id')->nullable()->constrained();
    $t->foreignId('employee_id')->nullable()->constrained();     // replaces who_take free text
    $t->string('note')->nullable();
    $t->date('moved_on');
    $t->foreignId('created_by')->constrained('users');
    $t->timestamps();
    $t->index(['product_id', 'moved_on']);
    $t->index(['reference_type', 'reference_id']);
});
```

* `InvoiceController::store()` writes one `sale` movement per line (`qty_delta =
  -(quantity + bonus_qty)`); update writes a `sale_reversal` + new `sale` instead of
  increment/decrement in place; delete writes a reversal.
* `products.quantity` stays as a cache; add `php artisan stock:rebuild` that recomputes it
  from `SUM(qty_delta)` and reports drift (product #2 today).
* Add `invoice_products.cost_price` (snapshot of `products.buy_price` at sale) so
  profitability reports are stable (2.11).

### 3.7 Constraints the business rules already assume

```php
Schema::table('salaries', fn (Blueprint $t) => $t->unique(['employee_id', 'month_year']));
Schema::table('users',    fn (Blueprint $t) => $t->unique('employee_id'));
Schema::table('users',    fn (Blueprint $t) => $t->string('email')->nullable(false)->change());
Schema::table('employees',fn (Blueprint $t) => $t->foreign('created_by')->references('id')->on('users'));
Schema::table('invoices', fn (Blueprint $t) => $t->unique('invoice_no'));      // after 3.3
```

For `payments`/`costs` (if not restructured per 3.5): add a `CHECK` (MariaDB 10.2+/MySQL 8.0.16+):

```sql
ALTER TABLE payments ADD CONSTRAINT chk_payments_one_party
  CHECK ((cust_id IS NOT NULL) + (supplier_id IS NOT NULL) = 1);
```

### 3.8 Indexing strategy

```php
// invoices — every report filters by date and scopes by employee/customer
$t->index('sale_date');
$t->index(['employee_id', 'sale_date']);
$t->index(['cust_id', 'sale_date']);
$t->index(['sale_type', 'sale_date']);

// payments
$t->index('payment_date');
$t->index(['cust_id', 'payment_date']);
$t->index(['supplier_id', 'payment_date']);

// stock_in_outs / stock_movements
$t->index(['product_id', 'in_out_date']);
$t->index('in_out_date');

// orders — credit checks sum pending orders per employee/customer
$t->index(['employee_id', 'status']);
$t->index(['cust_id', 'status']);

// costs, salaries
$t->index('cost_date');
$t->index(['employee_id', 'cost_date']);
$t->index('month_year');

// customers, products
$t->index('phone');                 // consider unique after normalising to E.164
$t->index('quantity');              // low-stock scan

// notifications — polled every minute
$t->index(['notifiable_type', 'notifiable_id', 'read_at']);
```

Rules of thumb applied: index every column that appears in `WHERE`/`ORDER BY` of a list or
report; lead composite indexes with the equality column (`employee_id`) and end with the
range column (`sale_date`); don't index low-cardinality columns alone (`sale_type`) unless
combined with a range.

### 3.9 Stop unbounded growth

```php
// config/sanctum.php
'expiration' => 60 * 24 * 30,       // 30 days

// UserController::login — one active token per device is enough
$user->tokens()->where('name', 'auth_token')->delete();   // or keep last N
$token = $user->createToken('auth_token')->plainTextToken;

// routes/console.php
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('model:prune', ['--model' => [DatabaseNotification::class]])->daily();
```

```php
// App\Models\Notification (extends DatabaseNotification) — prune read > 30 d, unread > 90 d
public function prunable(): Builder
{
    return static::where(fn ($q) => $q->whereNotNull('read_at')->where('read_at', '<', now()->subDays(30)))
                 ->orWhere('created_at', '<', now()->subDays(90));
}
```

And `NotificationController@index` should return `->latest()->limit(50)` plus an
`unread_count`, not the whole table.

### 3.10 Hierarchy and roles

**Before:** `designations.slug` doubles as role; `relations` table; 1–2 extra queries per request.

**After:**

```php
Schema::table('designations', fn (Blueprint $t) =>
    $t->enum('level', ['admin', 'rsm', 'manager', 'officer'])->after('slug')->index());

Schema::table('employees', function (Blueprint $t) {
    $t->foreignId('manager_id')->nullable()->after('designation_id')
      ->constrained('employees')->restrictOnDelete();
});
// migrate: UPDATE employees e JOIN relations r ON r.employee_id = e.id SET e.manager_id = r.relation_id;
// then drop relations
```

Resolve an RSM's whole subtree in **one** query with a recursive CTE (MariaDB 10.2+/MySQL 8):

```php
$ids = DB::select(<<<SQL
    WITH RECURSIVE tree AS (
        SELECT id FROM employees WHERE id = ?
        UNION ALL
        SELECT e.id FROM employees e JOIN tree t ON e.manager_id = t.id
    ) SELECT id FROM tree
SQL, [$employeeId]);
```

Wrap it in a scope — `Invoice::visibleTo($user)` — and delete the five copies of the
`if ($slug == 'admin') … elseif …` block. If relation *history* matters (who reported to whom
last quarter), keep a `reporting_lines(employee_id, manager_id, valid_from, valid_to)` table
instead of the current one-row-per-employee `relations`.

### 3.11 Audit and lifecycle

* Add `softDeletes()` + `created_by` / `updated_by` (FK → users) to `invoices`,
  `invoice_products`, `payments`, `stock_movements`, `salaries`, `costs`, `customers`,
  `products`, `suppliers`, `orders`.
* Keep `orders` after conversion; add `invoices.order_id` (nullable FK) and set
  `orders.status = 'invoiced'` instead of deleting.
* Add `status`/`deactivated_at` to customers, products, suppliers so "delete" in the UI
  becomes "archive" and the `restrictOnDelete` errors disappear.
* Rename employee/user status value `deactive` → `inactive`; better, store
  `deactivated_at timestamp NULL`.
* Business rule: deactivating an officer must reassign their customers (a DB trigger is
  overkill — do it in a service with a transaction) — 10 customers are orphaned today.
* For a full change history with almost no code, `spatie/laravel-activitylog` on the
  financial models.

### 3.12 Types, naming and small clean-ups

| Change | Why |
| --- | --- |
| `sale_date`, `order_date`, `payment_date`, `in_out_date`, `cost_date`, `expire_date` → `date` | production values are already ISO; `->date()->change()` works in place |
| `salaries.month_year` → `salary_month date` (first of month) | range queries, `DATE_FORMAT`, unique index |
| `orders.discount` → `discount_percent`; `invoices.discount` → `discount_amount` | same word, two meanings |
| `customers.customer_id` → `customer_code`; `employees.employee_id` → `employee_code` | stop colliding with FK naming |
| `invoices.invoiceId` → `invoice_no`; `cust_id` → `customer_id` on `orders`, `invoices`, `payments` | snake_case, Laravel conventions, `belongsTo` defaults work |
| `stock_in_outs.in_out` string → `enum('in','out')` (or replace per 3.6) | closed set |
| drop `customers.purchase`, `customers.payment`, `invoices.total_item` | never written / derivable |
| merge `cost_categories` + `employee_cost_categories` → `expense_categories(name, scope enum('office','employee','both'))` | one table, one FK on `costs` |
| `users`: add `remember_token`, `email_verified_at` or remove them from the model's `$hidden`/casts | model references columns that do not exist |
| normalise `customers.phone` to E.164 on write (`88017…`), then index / unique | SMS gateway already needs it; ≈ 23 duplicates today |
| `customer_code` padding fixed at 6 (`RA18/000001`) and generated from `document_sequences` | `RA18/0001` vs `RA18/000448` |
| `employees.national_id` → `encrypted` cast | PII at rest |

### 3.13 Laravel / MySQL best-practice checklist

- [ ] All schema changes via migrations; `migrate:status` clean in every environment.
- [ ] `DECIMAL` for money, `DATE`/`DATETIME` for dates, `ENUM` or lookup tables for closed sets.
- [ ] Every FK indexed (automatic) **plus** an index on each filter/sort column.
- [ ] Unique indexes for every "must be one" rule the code relies on.
- [ ] Derived numbers (`due`, `quantity`, `credit_limit`) either purely computed or cached
      **with** a rebuild command and a nightly reconciliation check.
- [ ] Money mutations append to a ledger (`payment_allocations`, `stock_movements`); never
      `increment()`/`decrement()` a balance as the only record.
- [ ] Soft deletes + author columns on financial tables; hard delete only for lookups.
- [ ] Paginate every list endpoint; cap notification payloads; prune tokens and notifications.
- [ ] `utf8mb4_unicode_ci` throughout (Bangla names/addresses); confirm production collation.
- [ ] Nightly `mysqldump --single-transaction` to off-box storage; **no dumps in git**.

---

## Part 4 — Advanced

### 4.1 Proposed ER diagram (text)

```
designations ──< employees >── employees (manager_id, self)
                    │  │  │
                    │  │  └──< users >──< model_has_permissions >── permissions
                    │  │
                    │  └──< customers ──< invoices ──< invoice_products >── products >── categories
                    │          │            │  ▲                              │            brands
                    │          │            │  └── invoices.order_id ── orders ──< order_products
                    │          │            │
                    │          │            └──< payment_allocations >── payments (counterparty morph:
                    │          │                                            customers | suppliers | employees;
                    │          └──< sms_histories                          collected_by → employees)
                    │
                    ├──< salaries         (unique employee_id + salary_month)
                    ├──< costs ── expense_categories
                    └──< stock_movements >── products
                              (reference morph → invoices | purchases; supplier_id; unit_cost)

document_sequences (key, next)        ← invoice_no, customer_code, order_no
notifications / personal_access_tokens (pruned)   sms_templates
```

### 4.2 Caching strategy (Redis)

At today's volume Redis buys more as a **queue** than as a cache; both are cheap to adopt
because `jobs`/`cache` tables already exist and the code uses the facades.

```dotenv
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

| What to cache | Key | TTL / invalidation |
| --- | --- | --- |
| Subordinate-ID set per employee (hierarchy) | `hierarchy:{employee_id}` | 1 h; flush on `employees.manager_id` change |
| `/dashboard-report`, `/profit-loss-report?range`, `/cashCreditSale?range` | `report:{name}:{from}:{to}` | 5–10 min, `Cache::tags(['reports'])->flush()` after invoice/payment writes |
| Product catalogue for the order/sales pickers | `products:all` | flush on product/stock change |
| Unread-notification count (for the 60 s poll) | `notif:unread:{user_id}` | increment on notify, reset on read |
| Permission set per user | `perms:{user_id}` | Spatie already caches roles/permissions (`config/permission.php` → `cache.store = redis`) |

Move `Mail::send`, `notify()` and `SmsService::sendSms()` behind `ShouldQueue` jobs so the
invoice request no longer waits on SMTP and the SMS gateway; add `Horizon` if the SMS
broadcast volume grows.

### 4.3 Read/write separation

**Not recommended yet.** With ~1 k invoices/month a single MariaDB instance with the indexes
from 3.8 will stay well under 10 ms for every query in this codebase. Revisit when:

* the report endpoints exceed ~200 ms after indexing, or
* the business runs multiple depots/regions and the dashboard is hit continuously.

When that day comes, Laravel needs only configuration:

```php
// config/database.php
'mysql' => [
    'read'  => ['host' => ['10.0.0.2', '10.0.0.3']],
    'write' => ['host' => ['10.0.0.1']],
    'sticky' => true,        // read your own writes inside a request
    // …
],
```

and route heavy reads explicitly: `Invoice::on('mysql::read')->…` inside `ReportController`.
Because the current write path depends on read-then-check (credit limits, stock), keep
`sticky => true` and leave the transactional controllers on the writer.

### 4.4 Partitioning

Not needed. `invoices` would reach ~1 M rows in ~80 years at current volume. If
`notifications`/`sms_histories` ever grow past a few million, prune (3.9) before partitioning;
range-partition `sms_histories` by `sent_at` month only if retention rules require keeping
years of history online.

---

## Appendix — Evidence commands

All figures were produced from the data-only dump with `awk` over the `INSERT` tuples; the
scripts are reproducible against a restored copy with plain SQL, e.g.:

```sql
SELECT invoiceId, COUNT(*) FROM invoices GROUP BY invoiceId HAVING COUNT(*) > 1 ORDER BY 2 DESC;
SELECT COUNT(*) FROM invoices WHERE ABS((grand_total - paid) - due) > 0.01;
SELECT tokenable_id, COUNT(*) FROM personal_access_tokens GROUP BY 1 ORDER BY 2 DESC LIMIT 5;
SELECT notifiable_id, COUNT(*) FROM notifications WHERE read_at IS NULL GROUP BY 1 ORDER BY 2 DESC LIMIT 5;
SELECT slug, COUNT(*) FROM designations GROUP BY slug HAVING COUNT(*) > 1;
SELECT e.id, COUNT(c.id) FROM employees e JOIN customers c ON c.employee_id = e.id
 WHERE e.status = 'deactive' GROUP BY e.id;
SELECT p.id, p.quantity,
       COALESCE(SUM(CASE WHEN s.in_out='in' THEN s.quantity ELSE -s.quantity END),0)
     - COALESCE((SELECT SUM(ip.quantity+ip.bonus_qty) FROM invoice_products ip WHERE ip.product_id=p.id),0) AS expected
  FROM products p LEFT JOIN stock_in_outs s ON s.product_id = p.id
 GROUP BY p.id, p.quantity HAVING p.quantity <> expected;
```
