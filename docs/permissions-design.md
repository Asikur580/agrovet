# Permissions Design — Radiant Agrovet v2

Companion to [`rebuild-plan.md`](./rebuild-plan.md) §3.2.

**Requirements**

1. **Every feature has its own permission** — every screen, every button, every report,
   every export. Nothing is reachable without a named permission.
2. Permissions can be assigned **per role** *and* **per user**. A user inherits from their
   role(s) and can additionally be granted extra permissions or have inherited ones revoked.
3. The backend enforces; the UI only hides. Same permission list drives both.
4. Permission codes live in code (one source of truth), are synced to the DB, and are
   type-checked in PHP and TypeScript.

---

## 1. Model

```
effective(user) = ( ⋃ permissions of user's roles  ∪  user's direct ALLOWs )  −  user's DENYs
super-admin      = everything, always (Gate::before), cannot be denied
```

| Concept | Storage | Notes |
| --- | --- | --- |
| Permission | `permissions` (Spatie) + columns `module`, `label`, `sort` | seeded from `config/permissions.php`, never created from the UI |
| Role | `roles` (Spatie) | `super-admin`, `admin`, `rsm`, `manager`, `officer`, `accountant` seeded; more can be created in the UI |
| Role → permissions | `role_has_permissions` (Spatie) | edited on the **Role matrix** page |
| User → roles | `model_has_roles` (Spatie) | one primary role recommended, several allowed |
| User → extra ALLOW | `model_has_permissions` (Spatie direct permissions) | edited on the **User overrides** page |
| User → DENY | **`user_denied_permissions`** (new) | revokes a permission the role would grant |

**Data scope is a separate axis.** Permissions answer *"may this user do X?"*; the
hierarchy scope (`visibleTo($user)`: admin → rsm → manager → officer) answers *"on which
rows?"*. An officer with `invoices.update` can still only update invoices in their own scope.
Both checks always apply; Policies combine them.

Two permission vocabularies are used consistently everywhere:

| Standard action | Meaning |
| --- | --- |
| `view` | open the list / index page |
| `details` | open a single record's page |
| `create` | create button + store route |
| `update` | edit button + update route |
| `delete` | delete / archive / deactivate |
| `print` | print views (list and details) |
| `export` | Excel/CSV export |

plus module-specific actions (`orders.approve`, `stock.in`, `sms.send-all`, …).

---

## 2. Permission catalogue

Code = `module.action`. "Replaces" lists the legacy permission name(s) so the migration
loses nothing. **143 permissions** in 24 modules.

### Dashboard & account

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `dashboard.view` | Dashboard page, counters, sales widgets | Dashboard-page |
| `dashboard.financial` | Profit/loss, cash-flow, credit-limit widgets | (part of Dashboard-page) |
| `profile.view` | Own profile page | Profile-page |
| `profile.update` | Edit own profile, change password | Profile-page |
| `notifications.view` | Bell, notification list | (implicit) |

### Organisation

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `designations.view / create / update / delete` | Designations CRUD | Designation-page, -create, -edit, -delete |
| `employees.view` | Employee list | Employee-page |
| `employees.details` | Employee details page | Employee-details-page |
| `employees.create / update` | Create / edit employee | Employee-create, Employee-edit |
| `employees.delete` | Deactivate (with customer reassignment) | Employee-delete |
| `employees.print` | Print employee list/details | Employee-details-print |
| `employees.export` | Excel export | — |
| `employees.assign-manager` | Set reporting line | Employee-relation-create |
| `employees.credit-report` | Credit usage report | (part of Employee-page) |
| `employees.offboard` | Start / edit / complete / cancel a handover (see `offboarding-design.md`) | — |
| `employees.reactivate` | Reactivate a deactivated employee | — |
| `employee-costs.view / create / update / delete / print` | Employee cost history + entry | Employee-cost-history, Employee-cost-create, Employee-cost-print |
| `salaries.view / create / update / delete / print` | Payroll page, salary posting | Employee-salary-history, Employee-salary-create, Employee-salary-print |
| `salaries.export` | Payroll export | — |

### Access control

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `users.view / create / update / delete` | User accounts CRUD | User-page, User-create, User-edit, User-delete |
| `users.manage-permissions` | Edit a user's roles and overrides | (part of User-page) |
| `roles.view / create / update / delete` | Roles list and permission matrix | Permission-page, Permission-create, Permission-edit, Permission-delete |
| `permissions.view` | Read-only permission catalogue | Permission-page |
| `activity-log.view` | Audit trail page | — |

### Customers

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `customers.view / details / create / update / delete` | Customer CRUD + details | Customer-page, Customer-details-page, Customer-create, -edit, -delete |
| `customers.print` | Print list / details | Customer-print, Customer-details-print |
| `customers.export` | Excel export | — |
| `customers.set-credit-limit` | Edit `credit_limit` / opening balance | (part of Customer-edit) |
| `customers.toggle-sms` | Enable/disable SMS per customer | (part of Customer-page) |
| `customers.reassign` | Bulk transfer of customers between officers (territory changes) | — |
| `customer-payments.view / create / update / delete / print` | Payment history and recording | Customer-payment-history, Customer-payment-create, Customer-payment-print |

### Suppliers

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `suppliers.view / details / create / update / delete / print` | Supplier CRUD | Supplier-page, Supplier-details-page, Supplier-create, -edit, -delete, Supplier-details-print |
| `supplier-payments.view / create / update / delete / print` | Supplier payments | Supplier-payment-history, Supplier-payment-create, Supplier-payment-print |

### Catalogue

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `brands.view / details / create / update / delete / print` | Brands + sales drill-down | Brand-page, Brand-details-page, Brand-create, -edit, -delete, Brand-details-print |
| `categories.view / details / create / update / delete / print` | Categories + drill-down | Category-page, Category-details-page, Category-create, -edit, -delete, Category-details-print |
| `products.view / details / create / update / delete` | Product CRUD | Product-page, Product-details-page, Product-create, -edit, -delete |
| `products.print` | Print product list / details | Product-print, Product-details-print |
| `products.export` | Excel export | — |
| `products.set-prices` | Edit buy / TP / flat prices | Product settings |

### Inventory

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `stock.view` | Stock ledger on product page, inventory report | (part of Product-details-page) |
| `stock.in` | Purchase / stock-in entry | Product-in |
| `stock.out` | Stock-out / transfer entry | Product-out |
| `stock.adjust` | Manual adjustment (± with reason) | — |
| `stock.low-stock` | Low-stock widget & digest | (part of Dashboard-page) |

### Orders

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `orders.view / details` | Orders list / details | Order-page |
| `orders.create` | Order create page + store | Order-create-page, Order-create |
| `orders.update` | Edit while pending / rejected | Order-edit |
| `orders.cancel` | Cancel (orders are never deleted) | Order-delete |
| `orders.approve` | Approve / reject / un-approve orders of officers in scope — direct manager by default, admin if granted (see `order-approval-design.md`) | — |
| `orders.approve-over-limit` | Approve or invoice an order that breaches a credit limit, with reason | — |
| `orders.convert` | Convert order to invoice | Sale-from-order |
| `orders.print` | Print order | — |

### Sales / invoices

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `invoices.view / details` | Sales list / details | Sale-page |
| `invoices.create` | Sales create page + store | Sale-create-page, Sale-create |
| `invoices.update / delete` | Edit / delete (soft) | Sale-edit, Sale-delete |
| `invoices.print` | Invoice print / PDF, mark printed | Sale-invoice |
| `invoices.update-offer` | Edit offer text after creation | (part of Sale-edit) |
| `invoices.export` | Excel export | — |

### Expenses

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `expense-categories.view / create / update / delete` | Categories (office + employee scope) | Cost-categories-page/-create/-edit/-delete, Employee-cost-category-page/-create/-edit/-delete |
| `expenses.view / create / update / delete / print` | Office expenses | Office-cost-page, Office-cost-create, -edit, -delete, Office-cost-print |
| `expenses.export` | Excel export | — |

### Reports

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `reports.sales` | Sales summary / by customer / product / category / brand | (Sale-page) |
| `reports.collections` | Payment history by employee | (Sale-page) |
| `reports.customers` | Due invoices, ageing, statements | (Dashboard-page) |
| `reports.inventory` | Stock levels, movements, valuation | — |
| `reports.employees` | Performance, credit | (Employee-page) |
| `reports.suppliers` | Supplier ledger | (Supplier-details-page) |
| `reports.financial` | Profit/loss, cash flow, accounts, product profitability | (Dashboard-page) |
| `reports.export` | Export any report | — |

### SMS

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `sms.history` | Delivery log, summary, balance | Sms |
| `sms.send-selected` | Send to chosen customers | Sms-send-anyone-page |
| `sms.send-all` | Broadcast to all SMS-enabled customers | Sms-send-everyone-page |
| `sms-templates.view / create / update / delete` | Template CRUD | Sms-template-page |

### Settings

| Code | Unlocks | Replaces |
| --- | --- | --- |
| `settings.view / update` | Company, numbering, SMS gateway, thresholds | — |

Legacy `Developer` → the `super-admin` **role** (not a permission).

---

## 3. Default role matrix (editable in the UI)

`✅` granted by default. `super-admin` is omitted — it has everything via `Gate::before`.

| Module / action | admin | accountant | rsm | manager | officer |
| --- | :-: | :-: | :-: | :-: | :-: |
| dashboard.view | ✅ | ✅ | ✅ | ✅ | ✅ |
| dashboard.financial | ✅ | ✅ | ✅ | | |
| profile.\*, notifications.view | ✅ | ✅ | ✅ | ✅ | ✅ |
| designations.\* | ✅ | | | | |
| employees.view / details | ✅ | ✅ | ✅ | ✅ | |
| employees.create / update / delete / assign-manager | ✅ | | | | |
| employees.credit-report, employees.print/export | ✅ | ✅ | ✅ | ✅ | |
| employees.offboard, employees.reactivate | ✅ | | | | |
| customers.reassign | ✅ | | ✅ | | |
| employee-costs.\*, salaries.\* | ✅ | ✅ | | | |
| users.\*, roles.\*, permissions.view, activity-log.view | ✅ | | | | |
| customers.view / details / print | ✅ | ✅ | ✅ | ✅ | ✅ |
| customers.create / update | ✅ | | | ✅ | ✅ |
| customers.delete, customers.set-credit-limit | ✅ | | ✅ | ✅ | |
| customers.toggle-sms, customers.export | ✅ | ✅ | ✅ | ✅ | |
| customer-payments.view / print | ✅ | ✅ | ✅ | ✅ | ✅ |
| customer-payments.create | ✅ | ✅ | | ✅ | ✅ |
| customer-payments.update / delete | ✅ | ✅ | | | |
| suppliers.\*, supplier-payments.\* | ✅ | ✅ | | | |
| brands.\*, categories.\* (view/details) | ✅ | ✅ | ✅ | ✅ | ✅ |
| brands / categories create / update / delete / print | ✅ | | | | |
| products.view / details / print | ✅ | ✅ | ✅ | ✅ | ✅ |
| products.create / update / delete / set-prices / export | ✅ | | | | |
| stock.view, stock.low-stock | ✅ | ✅ | ✅ | ✅ | |
| stock.in / out / adjust | ✅ | | | | |
| orders.view / details / print | ✅ | | ✅ | ✅ | ✅ |
| orders.create / update | ✅ | | | | ✅ |
| orders.cancel | ✅ | | | ✅ | ✅ (own pending) |
| orders.approve | ✅ | | | ✅ | |
| orders.approve-over-limit | ✅ | | | | |
| orders.convert | ✅ | | ✅ | ✅ | ✅ |
| invoices.view / details / print | ✅ | ✅ | ✅ | ✅ | ✅ |
| invoices.create | ✅ | | | ✅ | ✅ |
| invoices.update / update-offer | ✅ | | | ✅ | |
| invoices.delete | ✅ | | | | |
| invoices.export | ✅ | ✅ | ✅ | ✅ | |
| expense-categories.\*, expenses.\* | ✅ | ✅ | | | |
| reports.sales / collections / customers / employees | ✅ | ✅ | ✅ | ✅ | |
| reports.inventory / suppliers / financial | ✅ | ✅ | ✅ | | |
| reports.export | ✅ | ✅ | ✅ | ✅ | |
| sms.history, sms-templates.view | ✅ | | ✅ | ✅ | |
| sms.send-selected | ✅ | | ✅ | ✅ | |
| sms.send-all, sms-templates.create / update / delete | ✅ | | | | |
| settings.\* | ✅ | | | | |

Officers additionally get row-level restriction to their own customers by the scope.

---

## 4. Backend implementation

### 4.1 Source of truth — `config/permissions.php`

```php
return [
    'customers' => [
        'label' => 'Customers',
        'permissions' => [
            'view'             => 'View customer list',
            'details'          => 'Open customer details',
            'create'           => 'Create customers',
            'update'           => 'Edit customers',
            'delete'           => 'Archive customers',
            'print'            => 'Print customers',
            'export'           => 'Export customers',
            'set-credit-limit' => 'Set credit limit / opening balance',
            'toggle-sms'       => 'Enable or disable SMS per customer',
        ],
    ],
    'customer-payments' => [ /* … */ ],
    // … one block per module in §2
];
```

`php artisan permissions:sync` (runs on every deploy) upserts `permissions`
(`name`, `guard_name`, `module`, `label`, `sort`), removes codes no longer in config
(after confirming), and seeds the default role matrix on first run. **Permissions are never
created from the UI** — adding a feature means adding a code here and a `can:` on its route.

### 4.2 Typed constants — no string typos

```php
// app/Support/Perm.php — generated from config by `permissions:sync --constants`
final class Perm
{
    public const CUSTOMERS_VIEW   = 'customers.view';
    public const CUSTOMERS_CREATE = 'customers.create';
    public const ORDERS_APPROVE   = 'orders.approve';
    // …
}
```

```ts
// resources/js/types/permissions.ts — generated by the same command
export const Perm = {
  CUSTOMERS_VIEW: 'customers.view',
  ORDERS_APPROVE: 'orders.approve',
  // …
} as const;
export type Permission = (typeof Perm)[keyof typeof Perm];
```

### 4.3 Schema additions

```php
Schema::table('permissions', function (Blueprint $t) {
    $t->string('module', 40)->index()->after('guard_name');
    $t->string('label', 120)->after('module');
    $t->unsignedSmallInteger('sort')->default(0)->after('label');
});

Schema::create('user_denied_permissions', function (Blueprint $t) {
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
    $t->foreignId('created_by')->nullable()->constrained('users');
    $t->timestamp('created_at')->useCurrent();
    $t->primary(['user_id', 'permission_id']);
});
```

### 4.4 User model — inherit, allow, deny

```php
class User extends Authenticatable
{
    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }

    public function deniedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_denied_permissions')
                    ->withPivot('created_by', 'created_at');
    }

    /** DENY beats everything except super-admin (handled by Gate::before). */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $name = $permission instanceof Permission ? $permission->name : (string) $permission;

        if ($this->deniedPermissionNames()->contains($name)) {
            return false;
        }
        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    public function deniedPermissionNames(): Collection
    {
        return Cache::remember("user:{$this->id}:denied", 3600,
            fn () => $this->deniedPermissions()->pluck('name'));
    }

    /** What the UI receives: roles ∪ direct − denied. */
    public function effectivePermissionNames(): Collection
    {
        return $this->getAllPermissions()->pluck('name')
                    ->diff($this->deniedPermissionNames())->values();
    }
}
```

Because Spatie's own `Gate::before` calls `$user->checkPermissionTo()` → `hasPermissionTo()`,
this single override covers `can()`, `@can`, the `can:` middleware, Policies and
`Gate::authorize`.

```php
// AppServiceProvider::boot()
Gate::before(fn (User $user) => $user->hasRole('super-admin') ? true : null);
```

### 4.5 Routes and policies

```php
Route::middleware(['auth'])->group(function () {
    Route::get('customers',              [CustomerController::class, 'index'])->can(Perm::CUSTOMERS_VIEW);
    Route::get('customers/{customer}',   [CustomerController::class, 'show'])->can(Perm::CUSTOMERS_DETAILS, 'customer');
    Route::post('customers',             [CustomerController::class, 'store'])->can(Perm::CUSTOMERS_CREATE);
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->can(Perm::CUSTOMERS_UPDATE, 'customer');
    Route::post('orders/{order}/approve',[OrderApprovalController::class, 'store'])->can(Perm::ORDERS_APPROVE, 'order');
});
```

Policies add the **row scope** on top of the permission:

```php
class CustomerPolicy
{
    public function update(User $user, Customer $customer): bool
    {
        return $user->can(Perm::CUSTOMERS_UPDATE)
            && Customer::visibleTo($user)->whereKey($customer->id)->exists();
    }
}
```

A CI test walks every named route with a `can:` middleware and asserts 403 for a user
without that permission and 200/302 for one with it (§7).

### 4.6 Sharing with Inertia

```php
// HandleInertiaRequests::share()
'auth' => [
    'user'        => $request->user()?->only('id', 'name', 'email'),
    'roles'       => $request->user()?->getRoleNames(),
    'permissions' => $request->user()?->effectivePermissionNames(),
],
```

Computed per request, so a change made by an admin takes effect on the affected user's
**next request** — no re-login (the legacy app required one).

### 4.7 Cache invalidation

Any write to roles, role permissions, user roles, user allows or user denies calls:

```php
app(PermissionRegistrar::class)->forgetCachedPermissions();   // Spatie
Cache::forget("user:{$userId}:denied");                        // ours
activity()->causedBy(auth()->user())->performedOn($target)->withProperties($diff)->log('permissions.changed');
```

### 4.8 Safeguards

| Rule | Where |
| --- | --- |
| Cannot remove the last `super-admin` | `UpdateUserRoles` action |
| An admin cannot deny themselves `users.manage-permissions` / `roles.update` (lock-out) | Form Request `authorize()` |
| Optional: a user may only grant permissions they hold themselves (delegation cap) | `UpdateUserPermissions` action, config flag |
| Deactivated user: sessions invalidated, no permission evaluation | `EnsureUserIsActive` middleware |
| Every change logged with before/after diff | activity log |

---

## 5. Admin UI

### 5.1 Roles — `/roles`, `/roles/{role}/edit`

```
Role: Manager                                   Users with this role: 6      [Copy from role ▾]  [Save]
Search permissions… ________

Module            View  Details  Create  Update  Delete  Print  Export   Extra
─────────────────────────────────────────────────────────────────────────────────────────
▣ Customers        ☑      ☑       ☑       ☑       ☑      ☑      ☑     ☑ set-credit-limit  ☑ toggle-sms
▣ Customer pay.    ☑      –       ☑       ☐       ☐      ☑      –
▣ Orders           ☑      ☑       ☐       ☐       ☑      ☑      –     ☑ approve  ☑ convert
▣ Invoices         ☑      ☑       ☑       ☑       ☐      ☑      ☑     ☐ update-offer
▣ Reports          –      –       –       –       –      –      ☑     ☑ sales ☑ collections ☑ customers ☑ employees ☐ inventory ☐ suppliers ☐ financial
…
```

* Row checkbox `▣` toggles the whole module; column header toggles the whole column.
* `–` = action not applicable to that module.
* Saving shows "This affects 6 users"; change is logged.

### 5.2 User — `/users/{user}/edit` → "Access" tab

```
Roles:  [Manager ×] [+ add role]                 Effective permissions: 61     [Preview]
Overrides                                             Legend: ● inherited  ✚ extra allow  ⛔ denied
Module            View       Details    Create     Update     Delete     Print      Extra
──────────────────────────────────────────────────────────────────────────────────────────
Customers         ●          ●          ●          ●          ⛔ denied  ●          ● set-credit-limit
Invoices          ●          ●          ●          ✚ allow…   ○          ●          ○ update-offer
SMS               ○ history  –          –          –          –          –          ✚ send-selected
…
[Reset overrides]   [Copy overrides from user ▾]                                      [Save]
```

Each cell is tri-state relative to the role: **inherited** (grey ●, from role), **allow** (✚,
stored in `model_has_permissions`), **deny** (⛔, stored in `user_denied_permissions`),
**none** (○). Clicking cycles none → allow (if not inherited) or inherited → deny.

### 5.3 Preview — `/users/{user}/permissions`

Read-only effective list grouped by module, each line tagged `via Manager`, `direct`, or
`denied`. Used by support to answer "why can't I see X?".

### 5.4 Catalogue — `/permissions`

Read-only table from `config/permissions.php` with, per permission, which roles hold it and
how many users have it as an override.

### 5.5 Shared component

`<PermissionMatrix mode="role" | "user" value={…} onChange={…} catalogue={…} inherited={…} />`
— one React component drives 5.1 and 5.2; the page passes the catalogue (from config via
props) so columns and modules are never hard-coded in the frontend.

Page props (TypeScript):

```ts
interface PermissionCatalogue { module: string; label: string; permissions: { code: string; action: string; label: string }[] }[]
interface RoleEditProps  { role: Role; catalogue: PermissionCatalogue; granted: string[]; userCount: number }
interface UserAccessProps{ user: User; roles: Role[]; catalogue: PermissionCatalogue;
                           inherited: string[]; allowed: string[]; denied: string[] }
```

### 5.6 Frontend gating

```tsx
<Can permission={Perm.CUSTOMERS_CREATE}><Button>New customer</Button></Can>
const canApprove = useCan(Perm.ORDERS_APPROVE);
```

`nav.ts` lists each menu item with its `permission`; `AppLayout` filters. The backend still
returns 403 if the UI is bypassed.

---

## 6. Migrating legacy access (ETL step)

Legacy stores **only per-user direct permissions** (99 UI-page codes, `roles` table empty).
The migration reproduces each user's exact access while introducing roles:

1. Map each legacy name → new code(s) using the "Replaces" column in §2 (a PHP array in the
   ETL command; `Developer` → role `super-admin`).
2. Assign role from the employee's designation level (`admin`, `rsm`, `manager`, `officer`).
3. `allow = mapped − rolePermissions`, `deny = rolePermissions − mapped` → write as overrides.
4. Print a per-user report (role, +allows, −denies) for admins to review and simplify.

Result: day-one effective permissions equal legacy exactly; admins then clean up by editing
roles instead of 33 users individually.

---

## 7. Tests

| Test | Ensures |
| --- | --- |
| `permissions:sync` idempotent; config ⇄ DB match; no orphan codes | catalogue integrity |
| Every route with `can:` × each seeded role → expected 200/302 or 403 (generated matrix) | backend enforcement |
| Deny override beats role grant; allow override adds; super-admin cannot be denied | model semantics |
| Officer with `invoices.update` cannot update another officer's invoice | permission + scope both apply |
| Lock-out guards; last super-admin guard | safety |
| Shared `auth.permissions` reflects a change on the next request | UI freshness |

---

## 8. Definition of done (per feature, every phase)

- [ ] Permission code added to `config/permissions.php` and default matrix.
- [ ] Route has `->can(Perm::…)`; Policy covers row scope where relevant.
- [ ] Menu item / button wrapped in `<Can>`.
- [ ] Matrix test updated; `permissions:sync` run in CI.
