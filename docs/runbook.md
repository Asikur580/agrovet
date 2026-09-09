# Runbook — Radiant Agrovet v2

What to do, in order, when deploying, when something breaks, and when data has to come back.
Written for whoever is on the server at the time, not for whoever wrote the code.

Companion documents: [`setup.md`](./setup.md) (first install), [`uat-plan.md`](./uat-plan.md)
(acceptance testing), [`rebuild-plan.md`](./rebuild-plan.md) §7–§8 (migration and operations).

---

## 1. What is running

| Piece | Command | If it is not running |
| --- | --- | --- |
| Web | PHP-FPM behind Nginx | Nothing works |
| Queue worker | `php artisan queue:work` under Supervisor | Mail, SMS, notifications and backups queue up and never happen |
| Scheduler | `* * * * * php artisan schedule:run` | Nightly stock reconcile, low-stock digest, escalations, hierarchy check and backups never run |
| Websockets | `php artisan reverb:start` | The bell updates on the next page load instead of live — everything else is fine |

Check all four in one go:

```bash
php artisan about                 # framework, cache, queue, database
php artisan queue:monitor default # queue depth
php artisan schedule:list         # what runs and when
php artisan licence:show          # non-zero exit means the app is blocked
```

---

## 2. Deploy

```bash
php artisan down --render="errors::503" --retry=60

git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan permissions:sync --roles     # new permission codes; safe to re-run
php artisan reports:flush                # a code change is not a write
php artisan optimize                     # config, routes, views, events
php artisan licence:show                 # stops the deploy if the licence is blocked

sudo supervisorctl restart agrovet-worker:*
sudo supervisorctl restart agrovet-reverb:*

php artisan up
```

**Before pressing enter on the first line:** take a backup (§4). Every deploy that touches
migrations is a deploy that can need §3.

**After `php artisan up`:** open the dashboard, one customer, one invoice and one report. If
the dashboard shows figures and an invoice prints, the deploy is good.

---

## 3. Rollback

Roll back in the reverse order of the deploy, and only ever forward on data.

**Code only** (no migration ran):

```bash
php artisan down
git checkout <previous tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize
sudo supervisorctl restart agrovet-worker:* agrovet-reverb:*
php artisan up
```

**A migration ran and the release is bad.** Do not run `migrate:rollback` on production
unless the migration in question is one of the reversible ones and nothing has been written
since. The safe path is: restore last night's backup (§4), then deploy the previous tag.
Anything entered between the backup and the failure has to be re-entered — which is why the
first thing to establish is **when** the bad release went out and **what** was entered after
it (`/activity-log`, filtered by date, answers this).

**The licence screen is blocking everybody.** `php artisan licence:show --fresh`. If the key
has expired, paste a current one at `/system/licence`; if the vendor cannot be reached, set
`LICENCE_ENFORCE=false` in `.env` and `php artisan config:clear` to reopen the system, then
put it back once a key is in.

---

## 4. Backups

Written nightly to the private `backups` disk: the database and everything under
`storage/app/public` (uploads). `backup:clean` runs at 02:30, `backup:run` at 03:00.

```bash
php artisan backup:run              # database + uploads, now
php artisan backup:run --only-db    # database only, before a risky change
php artisan backup:list             # what exists and how old it is
```

The archives are also reachable at **Settings → Backups**, which is the same list with a
download button behind the `system.backups` permission.

**`mysqldump` must be on the PATH.** It is on a normal Linux server; on Windows it is not.
Set `DB_DUMP_BINARY_PATH` to the directory holding it, or the nightly backup fails and
nobody notices until the day it is needed.

**Copy the archives off the box.** A backup that lives only on the server it backs up is not
a backup. Either add an off-site disk to `config/backup.php` (`destination.disks`) or rsync
the folder nightly.

### Restoring

```bash
# 1. Take a backup of what is there now, however broken it looks.
php artisan backup:run --only-db

# 2. Unpack the archive you want.
unzip 2026-09-08-03-00-00.zip -d /tmp/restore

# 3. Load it into a scratch database first, never straight over production.
mysql -u root -e "create database agrovet_restore"
mysql -u root agrovet_restore < /tmp/restore/db-dumps/mysql-agrovet.sql

# 4. Check it is the night you think it is.
mysql -u root agrovet_restore -e "select count(*) from invoices, (select max(sale_date) from invoices) x"

# 5. Swap: point .env at the restored database, or rename the two.
php artisan config:clear && php artisan optimize
```

Then, whatever was restored:

```bash
php artisan stock:rebuild      # cached quantities from the ledger
php artisan reports:flush
php artisan hierarchy:check    # exits non-zero if the tree is broken
```

**This has been rehearsed** (P26): a database backup of the migrated data restored into a
scratch database and matched the original exactly — 663 customers, 2,694 invoices, 6,054
lines, 1,045 payments, 2,163 allocations, 33,762,558.86 billed, 18,844,605.60 received.
Rehearse it again after any change to the backup configuration.

---

## 5. When something is wrong

| Symptom | First thing to check |
| --- | --- |
| Every page redirects to the licence screen | `php artisan licence:show` — expired or missing key (§3) |
| A user says "I can't see any customers" | Their hierarchy: `php artisan hierarchy:check`. An officer sees their own customers; a customer whose owner has left is with a caretaker |
| A user says "the button isn't there" | Their permissions: `/users/{id}` shows role, allows and denies. A **deny** beats a role |
| Notifications are not arriving | The queue worker. `php artisan queue:monitor default`; failed jobs in `php artisan queue:failed` |
| SMS is not going out | `/sms` shows the gateway's own answer per message. No credentials on the box → the settings screen says so |
| A product's stock looks wrong | `php artisan stock:reconcile` — it reports drift and exits non-zero; `stock:rebuild` fixes the cache from the ledger |
| A credit limit looks wrong | It is typed, not calculated: an employee's on their edit form, a customer's on theirs. `/activity-log` says who last changed it |
| Reports show yesterday's numbers | `php artisan reports:flush`. Writes invalidate the cache; a deploy does not |
| A page is slow | `php artisan pail` while reloading it; the query log shows what it is doing |

**Logs.** `storage/logs/laravel.log`, or `php artisan pail` to watch live. Failed queue jobs
are rows in `failed_jobs`: `php artisan queue:retry all` after fixing the cause.

**The audit trail.** `/activity-log` records who changed what and when, including settings,
permissions, handovers and SMS broadcasts. It is the first place to look when a figure
changed and nobody knows why.

---

## 6. Daily and weekly

| When | What | Where |
| --- | --- | --- |
| Every morning | Low-stock digest arrives 07:00 | Mail |
| Every morning | Orders waiting for approval | Dashboard card |
| Every morning | Last night's backup exists | Settings → Backups |
| Weekly | `hierarchy:check` findings — caretakers, teams without a manager | Dashboard / `php artisan hierarchy:check` |
| Weekly | Failed SMS | `/sms`, status filter "failed" |
| Monthly | Payroll posted, dues carried | `/salaries` |
| Quarterly | Restore a backup into a scratch database (§4) | Nobody finds out a backup is broken at the moment they need it |

---

## 7. Cut-over night (P27)

The order, once agreed with the staff:

1. Legacy goes read-only. Nobody types anything into it after this point.
2. `php artisan backup:run` on v2 — the empty-but-configured state to come back to.
3. `php artisan legacy:migrate` (12 seconds on the current dump; allow for growth).
4. `php artisan legacy:reconcile` — **it must exit zero.** If it does not, stop; the notes
   say what differs.
5. Spot-check by hand: three customers' dues, three printed invoices, one officer's list.
6. Switch DNS / the web root to v2.
7. Legacy stays read-only for 30 days, then is archived and decommissioned.

If step 4 or 5 fails: the migration is idempotent and the legacy system is untouched, so the
answer is always "fix, re-run, re-reconcile" — never "carry on and correct it later".
