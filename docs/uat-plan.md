# UAT plan — Radiant Agrovet v2

Acceptance testing on **migrated data**, by the people who will use the system. Not a
demonstration: the point is to find what is wrong while it is still cheap to fix.

Companion documents: [`runbook.md`](./runbook.md) (operating it),
[`rebuild-plan.md`](./rebuild-plan.md) §11 (the parity list this checks against),
[`workplan.md`](./workplan.md) P26.

---

## 1. Before the session

| # | Step | Done by |
| --- | --- | --- |
| 1 | Fresh copy of production loaded into the legacy database | Dev A |
| 2 | `php artisan legacy:migrate` then `legacy:reconcile` — **green** | Dev A |
| 3 | Uploads copied across (`storage/app/public`) so photos appear | Dev A |
| 4 | Each tester's own account confirmed to log in with their **existing password** | Dev B |
| 5 | Queue worker and scheduler running on the UAT box | Dev B |
| 6 | This document printed, one copy per tester | Dev B |

Testers use their **own** accounts. The whole point is that an officer sees an officer's
system, and half the defects worth finding are permission-shaped.

**Who is needed:** 3–4 marketing officers, 1 area manager, the accountant, the administrator.
Half a day, in the same room, with both developers present and a laptop each.

---

## 2. How to record a defect

One line per problem, on the sheet or in the shared file. Anything a tester had to ask about
counts as a defect.

```
#   Who found it   Screen              What happened            What should happen        Severity
1   Rahim (off.)   Customers → new     Phone rejected           01712-345678 should work   P2
```

| Severity | Meaning | Fixed |
| --- | --- | --- |
| **P0** | Money is wrong, or the work cannot be done at all | Before cut-over, no exceptions |
| **P1** | A daily task is blocked and there is no way round it | Before cut-over |
| **P2** | Awkward, slow, or confusing; there is a way round it | Before cut-over if cheap, else first week |
| **P3** | Cosmetic, or a nice-to-have | Backlog |

**Exit:** no P0 or P1 open, and every tester signs §8.

---

## 2a. Decisions the company must take before cut-over

These are not defects. They are facts about the data that only become visible once the rules
are enforced, and each needs an answer from the business rather than from a developer.

| # | Fact | Why it matters on day one | Decision needed |
| --- | --- | --- | --- |
| 1 | **193 of 663 customers owe more than their credit limit** (`/customers?credit=over`) | The old system never enforced a limit. v2 does, so those shops cannot take a **credit** order until the debt drops or the limit rises. Cash sales are unaffected | Raise the limits, write off, or accept that those shops are cash-only until they pay down |
| 2 | **10 shops are with a caretaker manager** because their officer has left | Somebody is holding them, but nobody is visiting them | Reassign each to a working officer (Customers → Reassign) |
| 3 | **14 employees appear twice** — an old deactivated record and a current one | Their sales history is split across two names in reports | Merge or leave; if leaving, agree which record is "the" person |
| 4 | **4 customer phone numbers cannot be dialled** by the SMS gateway | Those shops silently get no reminders | Correct the numbers |
| 5 | **4 employees report to nobody** (Head Office) | Their work is invisible in any manager's figures | Give each a manager, or confirm they belong at Head Office |
| 6 | **Order approval is on by default**, and escalates after 24 hours | Officers cannot invoice without a manager's yes | Confirm, or change it in Settings → Approvals |

---

## 3. Officer (marketing executive)

The officer's day: visit shops, take orders, collect money.

| # | Do this | Expect |
| --- | --- | --- |
| 1 | Log in with your own password | The dashboard, showing **your** figures only |
| 2 | Open **Customers** | Only your own shops. Count them; is any missing? |
| 3 | Open one of your customers | Due, credit limit and credit available. **Check the due against the old system** |
| 4 | Look at "Opening balance" on a customer who owed money before | The same figure the old system carried as old due |
| 5 | Create a new customer | Code assigned automatically; phone accepted as you normally type it |
| 6 | Take an order for a customer with credit left | Saved as **pending**, waiting for your manager |
| 7 | Take an order for a customer who is over their limit | Refused, with a message naming the limit |
| 8 | Try to open another officer's customer by changing the address bar | Refused (403) |
| 9 | Record a payment from a shop | Appears on the customer, due drops by that amount |
| 10 | Open **Sales** | Only invoices for your own customers |
| 11 | Print an invoice | Company name, address, your name as the officer, the lines and the total |

---

## 4. Area manager

The manager's day: approve, chase, and cover for absences.

| # | Do this | Expect |
| --- | --- | --- |
| 1 | Log in | Dashboard covering **your whole team**, not just you |
| 2 | Open **Orders → Approvals** | The orders your officers submitted, oldest first |
| 3 | Approve one | It becomes approved; the officer is notified (bell) |
| 4 | Reject one, with a reason | Rejected, reason visible to the officer |
| 5 | Approve an order that is over the customer's limit | Refused unless you hold "approve over limit" — check with the administrator whether you should |
| 6 | Open **Customers** | Every shop of every officer under you |
| 7 | Find a shop marked **caretaker** | You are holding it because its officer has left. Reassign it to one of your officers |
| 7a | Filter **Customers → Over their credit limit** | The shops in your team that cannot take a credit order today (§2a #1) |
| 8 | Open **Reports → Employee performance** | One row per officer: sales, collected, what their customers owe |
| 9 | Open **Reports → Due invoices** | Every unpaid invoice, aged. Cross-check the oldest against the old system |
| 10 | Try to open a customer from another manager's team | Refused (403) |

---

## 5. Accountant

The accountant's day: money in, money out, and whether the books agree.

| # | Do this | Expect |
| --- | --- | --- |
| 1 | Open **Payments** | Every receipt, company-wide, with who collected it |
| 2 | Record a supplier payment | Appears against the supplier |
| 3 | Open **Reports → Collections** | Totals by collector; compare a month with the old system |
| 4 | Open **Reports → Profit and loss** and **Cash flow** | Figures that reconcile with sales and expenses |
| 5 | Open **Expenses**, add an office expense | Category list offers office categories only |
| 6 | Add an expense charged to an employee | Category list offers employee categories only |
| 7 | Open **Payroll**, post a salary for last month | Basic, advance and carried due behave as expected — **check one employee's history against the old system** |
| 8 | Open **Reports → Due invoices**, export it | The file opens in Excel with the same figures |
| 9 | Try to approve an order | The button is not there |

---

## 6. Administrator

| # | Do this | Expect |
| --- | --- | --- |
| 1 | Open **Users** | Every account, with role, extra permissions and denials |
| 2 | Pick one officer and compare their permissions with the old system | The same things they could do before; nothing new they should not have |
| 3 | Grant one extra permission, log in as that user (or ask them) | It takes effect on their next click |
| 4 | Open **Employees**, start a **handover** for somebody who has left | The wizard lists their customers, team and open orders |
| 5 | Complete the handover | The employee is deactivated; everything of theirs has a new owner |
| 6 | Open **Settings** | Company name and address as they should print |
| 7 | Change the company address, print an invoice | The new address is on it |
| 8 | Open **Settings → Numbering**, try to lower the next invoice number | Refused, naming the current counter |
| 9 | Open **Settings → Backups** | Last night's archive, with its size and time |
| 10 | Open **SMS → Broadcast**, choose "customers who owe" | A recipient count **before** anything is sent |
| 11 | Send a broadcast to two customers you have agreed with | Two messages in the history with the gateway's answer |
| 12 | Open **Activity log** | Everything above, with your name against it |

---

## 7. Whole-company checks

Done once, by a developer with a tester watching, because these are the ones that decide
whether the migration is trustworthy.

| # | Check | How |
| --- | --- | --- |
| 1 | Every customer's due matches the old system | `php artisan legacy:reconcile` — "Customer dues agree" |
| 2 | Twenty invoices, printed side by side with the old system | Pick them at random, including one from each officer |
| 3 | The three biggest debtors | Compare due, credit limit and last payment |
| 4 | One officer's full customer list | Count and names, against the old system |
| 5 | Stock for ten products | v2 quantity is the sum of its ledger; the old cached number may differ and the report says why |
| 6 | A month of sales per officer | `Reports → Employee performance`, one month, against the old system |
| 7 | The invoice numbers | Every one unique; the old number still shown on the invoice |

---

## 8. Sign-off

The system is accepted for cut-over when each name below has walked their section and no P0
or P1 defect is open.

| Role | Name | Date | Signature | Defects raised |
| --- | --- | --- | --- | --- |
| Officer | | | | |
| Officer | | | | |
| Officer | | | | |
| Area manager | | | | |
| Accountant | | | | |
| Administrator | | | | |
| Developer A | | | | |
| Developer B | | | | |

---

## 9. Training

Half a day, after sign-off and before cut-over, in two sessions.

**Officers and managers (90 minutes).** Log in; the dashboard; find a customer; check a due;
take an order; what "pending" means and who approves it; record a payment; print an invoice;
what to do when a customer is over their limit.

**Accountant and administrator (90 minutes).** Payments and allocation; expenses and the two
category scopes; payroll; the report catalogue and exports; users and permissions; handovers;
settings; backups; the activity log.

Record both sessions. The recordings, plus this document and the runbook, are what a new
member of staff is handed in six months' time.
