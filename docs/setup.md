# Setup & Deployment Guide

## 1. Requirements

| Component | Version |
| --- | --- |
| PHP | `8.2` or higher |
| Composer | 2.x |
| MySQL | 5.7+ / 8.x (or MariaDB 10.4+) |
| Node.js | 18+ (only for asset building / the frontend) |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`, `gd` |

---

## 2. Backend Installation

```bash
cd "Radiant Agrovet/agrovet"

# 1. Install PHP dependencies
composer install

# 2. Create the environment file
cp .env.example .env

# 3. Generate the application key
php artisan key:generate

# 4. Configure .env  (see §3 below), then:

# 5. Create the schema
php artisan migrate

# 6. Optional — populate demo data via factories
php artisan db:seed

# 7. Expose uploaded images (required for products/customers/employees/suppliers)
php artisan storage:link

# 8. Run
php artisan serve      # http://127.0.0.1:8000
```

### One-command dev loop

`composer.json` defines a `dev` script running server, queue worker, log tailer and Vite
concurrently:

```bash
composer run dev
```

---

## 3. Environment Configuration

`.env.example` ships with `DB_CONNECTION=sqlite`. **Switch it to MySQL** — the reports use
MySQL-specific SQL (`STR_TO_DATE`, `IF()`), so SQLite will not work.

### Database

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=agrovet
DB_USERNAME=root
DB_PASSWORD=
```

### Application

```dotenv
APP_NAME="Radiant Agrovet"
APP_ENV=local            # production on the server
APP_DEBUG=true           # ALWAYS false in production
APP_TIMEZONE=Asia/Dhaka  # currently UTC; set to your operating timezone
APP_URL=http://localhost:8000
```

### Mail (order/invoice notifications to managers)

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.yourhost.com
MAIL_PORT=587
MAIL_USERNAME=noreply@yourdomain.com
MAIL_PASSWORD=•••
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Set `MAIL_MAILER=log` locally to write mails to `storage/logs/laravel.log` instead of sending.

### SMS gateway (MiMSMS) — ⚠️ missing from the committed `.env`

`App\Services\SmsService` reads these four keys through `config/services.php`, but **none of
them are present in `.env` or `.env.example`**. Until they are added, every SMS is logged as
`failed` in `sms_histories` (the request itself still succeeds).

```dotenv
SMS_API_URL=https://api.mimsms.com/api/SmsSending/SMS
SMS_USERNAME=your_mimsms_username
SMS_API_KEY=your_mimsms_api_key
SMS_SENDER_ID=your_approved_sender_id
```

> Balance checking derives its URL from `SMS_API_URL` by replacing `/api/V2/SendSMS` with
> `/api/V2/BalanceCheck`, so keep the API URL in the form your account documentation specifies.

### Queue, cache, session

All default to the `database` driver, which requires the `jobs`, `cache` and `sessions`
tables created by the migrations. To process queued work:

```bash
php artisan queue:work
```

> Notifications and mails are currently dispatched **synchronously** inside request handlers,
> so a queue worker is not strictly required — but it is recommended, since a slow SMTP or SMS
> endpoint will otherwise block the API response.

---

## 4. Seeding Data

### Option A — factory demo data

```bash
php artisan migrate:fresh --seed
```

`DatabaseSeeder` runs 16 seeders (designations, employees, users, customers, brands,
categories, suppliers, products, stock, orders, invoices, cost categories, payments, costs,
salaries). All use Faker factories, so **designation slugs will be random** — the
role-hierarchy features will not behave sensibly until you fix them:

```sql
UPDATE designations SET slug = 'admin'   WHERE id = 1;
UPDATE designations SET slug = 'rsm'     WHERE id = 2;
UPDATE designations SET slug = 'manager' WHERE id = 3;
UPDATE designations SET slug = 'officer' WHERE id = 4;
```

Seeders create no permissions — add them manually or import the production dump.

### Option B — restore the production dump (real data)

```bash
mysql -u root -p -e "CREATE DATABASE agrovet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p agrovet < "../gldqpoea_radian_agrovet.sql"
```

This gives you the correct designations (`admin`/`rsm`/`manager`/`officer`), all ~110
permissions and real transactional history. **It contains confidential business data** — do
not use it in shared or public environments.

### Creating a first admin manually

```bash
php artisan tinker
```
```php
$d = \App\Models\Designation::create(['name' => 'Admin', 'slug' => 'admin']);
$e = \App\Models\Employee::create([
    'employee_id' => 'ADMIN-001', 'designation_id' => $d->id, 'name' => 'System Admin',
    'phone' => '01700000000', 'territory' => 'HQ', 'district' => 'Dhaka',
    'credit_limit' => 0, 'basic_salary' => 0, 'created_by' => 1, 'status' => 'active',
]);
$u = \App\Models\User::create([
    'employee_id' => $e->id, 'email' => 'admin@example.com',
    'password' => bcrypt('secret123'), 'status' => 'active',
]);
// Grant every permission (create them first, or import the dump)
$u->syncPermissions(\Spatie\Permission\Models\Permission::pluck('name'));
```

---

## 5. Verifying the Install

```bash
# Health check
curl http://127.0.0.1:8000/up

# Login
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"secret123"}'

# Authenticated call
curl http://127.0.0.1:8000/api/dashboard-report \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

---

## 6. Frontend (React SPA)

```bash
cd "Radiant Agrovet/agrovet-frontend"
npm install
npm run dev        # Vite dev server
npm run build      # production bundle → dist/
```

Point the client's API base URL at your backend (check `src/` for the axios base
configuration). If the SPA runs on a different origin, configure CORS — Laravel 11 uses
`config/cors.php`; publish it if absent:

```bash
php artisan config:publish cors
```

---

## 7. Production Deployment

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

**Web server:** point the document root at `agrovet/public`, never at the project root.

**Permissions:**
```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

**Scheduler / queue (recommended):**
```
* * * * * cd /path/to/agrovet && php artisan schedule:run >> /dev/null 2>&1
```
Run `php artisan queue:work` under Supervisor or systemd.

### Pre-deployment checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `APP_KEY` generated and unique per environment
- [ ] `SMS_*` keys added (see §3)
- [ ] `APP_TIMEZONE` set to the operating timezone (currently `UTC`)
- [ ] **`.env` removed from version control** and rotated — the committed file contains live credentials
- [ ] **Production SQL dumps removed from the repo** (`gldqpoea_radian_agrovet.sql`, both copies)
- [ ] `agrovet.zip` (36 MB) and the stray `error_log` files removed
- [ ] HTTPS enforced; Sanctum token expiry reviewed in `config/sanctum.php`
- [ ] **Unauthenticated report and `sms-templates` routes moved inside the `auth:sanctum` group** (see [`api.md` §18/§19](./api.md))
- [ ] Hard-coded `User::findOrFail(3)` in `RoleController` replaced with the authenticated user
- [ ] Backups scheduled for the database and `storage/app/public`

---

## 8. Useful Commands

| Command | Purpose |
| --- | --- |
| `php artisan migrate:status` | Show applied/pending migrations |
| `php artisan migrate:fresh --seed` | Rebuild the schema with demo data (**destroys all data**) |
| `php artisan export:postman` | Generate a Postman collection from the routes |
| `php artisan route:list --path=api` | List every API route |
| `php artisan pail` | Live-tail application logs |
| `./vendor/bin/pint` | Format PHP to Laravel style |
| `php artisan test` | Run the PHPUnit suite |
| `php artisan optimize:clear` | Clear config, route, view and event caches |

---

## 9. Troubleshooting

| Symptom | Cause / Fix |
| --- | --- |
| `Unauthenticated.` on every call | Missing `Authorization: Bearer …` header, or `Accept: application/json` omitted |
| Login returns `Unauthorized` with correct credentials | `users.status` is not `active`, or the password was not hashed via `bcrypt` |
| `Call to a member function ... on null` in `/orders`, `/customers`, `/employees` | The logged-in user's employee has no designation, or the slug isn't `admin`/`rsm`/`manager`/`officer` |
| Uploaded images 404 | `php artisan storage:link` was not run, or `APP_URL` is wrong |
| SMS always `failed` in `sms_histories` | `SMS_*` env keys missing (see §3) |
| `SQLSTATE[42000]` on salary or report endpoints | Database is SQLite — switch to MySQL |
| `Cannot delete ... referenced by another record` | Expected: FKs are `restrictOnDelete`; remove the dependent rows first |
| Order/invoice rejected with `422` | Employee or customer credit limit exceeded — check `GET /api/employees/credit-report` |
| Employee credit limit shows `0` | It is derived: assign customers to the officer, or subordinates to the manager/RSM |
