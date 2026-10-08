# EDM Module - Production Deployment

Step-by-step guide to bring the production server (`C:\xampp\htdocs\odb\edm`)
up to date with `main` from `https://github.com/stnsha/edm-module.git`, from
`git pull` to a working send queue. Follow the sections in order. Every SQL
file referenced here is safe to run more than once unless stated otherwise.

All commands are for the Windows server, in a Command Prompt
(`cmd.exe`) opened as Administrator.

---

## 0. Prerequisites (check once per server)

| Item | Requirement | How to check |
| ---- | ----------- | ------------ |
| PHP | 8.1 or newer (the code, AWS SDK and PhpSpreadsheet need it) | `C:\xampp\php\php.exe -v` |
| PHP extensions | `curl`, `fileinfo`, `gd`, `mbstring`, `mysqli`, `openssl`, `zip` enabled in `C:\xampp\php\php.ini` (remove the leading `;`), plus the built-ins `ctype dom filter iconv json libxml pcre simplexml xml xmlreader xmlwriter zlib` | `C:\xampp\php\php.exe -m` |
| Composer | Installed, on PATH | `composer --version` |
| Database | MySQL 8 or MariaDB 10.4+ (`odb` database) | phpMyAdmin |
| Apache | `AllowOverride All` for `htdocs` (the module's `.htaccess` files deny `.env`, `vendor/`, `sql/`, `cron/`, `logs/`) | Open `https://<host>/odb/edm/.env` - it must be refused (403/404) |
| Public URL | The module reachable from the internet over HTTPS (images in emails, unsubscribe links, SES webhook) | Open `https://<host>/odb/edm/public/unsubscribe.php` from a phone on mobile data - it should show "invalid link" |

After changing `php.ini`, restart Apache from the XAMPP Control Panel.

---

## 1. Back up first

```bat
mkdir C:\edm-backup

rem Whole odb database (prompts for the MySQL password)
C:\xampp\mysql\bin\mysqldump.exe -u root -p --single-transaction --routines odb > C:\edm-backup\edm-before-deploy.sql
```

Or in phpMyAdmin: select the `odb` database -> Export -> Custom -> tick every
`edm_*` table and `staff` -> Go, and save the file as
`C:\edm-backup\edm-before-deploy.sql`.

Also copy these (they are not in git):

```bat
copy C:\xampp\htdocs\odb\edm\.env C:\edm-backup\.env
xcopy /E /I /Y C:\xampp\htdocs\odb\edm\uploads C:\edm-backup\uploads
```

---

## 2. Pull the code

```bat
cd /d C:\xampp\htdocs\odb\edm
git status
git pull origin main
```

- `git status` must show a clean tree before pulling. If it lists changed
  files on the server, keep them aside with `git stash` (or discard with
  `git checkout -- <file>` if they are not needed), then pull.
- Do not edit code on the server; change it locally, push, then pull here.

---

## 3. PHP dependencies

`vendor/` is not in git. Install exactly the versions in `composer.lock`:

```bat
cd /d C:\xampp\htdocs\odb\edm
composer install --no-dev --optimize-autoloader
```

If Composer reports a missing extension (for example `ext-gd` or `ext-zip`),
enable it in `C:\xampp\php\php.ini` (section 0), restart Apache and run the
command again.

---

## 4. Folders that must be writable

`uploads/` and `logs/` are git-ignored (only `uploads/.htaccess` is tracked).
Create them if missing; the Apache user and the account that runs the
scheduler need write access.

```bat
cd /d C:\xampp\htdocs\odb\edm
if not exist uploads\assets mkdir uploads\assets
if not exist uploads\approvals mkdir uploads\approvals
if not exist logs mkdir logs
```

Restore files from the backup if this is a fresh clone:
`xcopy /E /I /Y C:\edm-backup\uploads C:\xampp\htdocs\odb\edm\uploads`.

---

## 5. Environment file (`edm\.env`)

`.env` is not in git. Create it from the template the first time
(`copy .env.example .env`), otherwise compare your existing file with
`.env.example` and add any missing key.

| Key | Production value |
| --- | ---------------- |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | IAM user key pair with SES send permissions. Leave both empty to use the AWS default credential chain (`C:\Users\<scheduler account>\.aws\credentials` of the account that runs the scheduler, and of the Apache service account). |
| `AWS_REGION` | `ap-southeast-1` |
| `SES_CONFIGURATION_SET` | `alpro-marketing` (must publish send, delivery, bounce, complaint, open and click events to the SNS topic below) |
| `SES_SNS_TOPIC_ARN` | ARN of the SNS topic subscribed to the webhook (section 8) |
| `SES_MAX_SEND_RATE` | Empty (uses the account rate), or a lower emails-per-second cap |
| `SES_DAILY_LIMIT` | Empty, or a cap below the SES 24-hour quota |
| `EDM_PUBLIC_URL` | Public HTTPS base URL of the module, no trailing slash, e.g. `https://odb.alpropharmacy.com/odb/edm`. Used for unsubscribe links and for the address of uploaded images. **Required** - if empty, unsubscribe links in sent emails are broken. |
| `EDM_APP_KEY` | Random secret, generated **once** per server: `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"`. Never change it afterwards - it would invalidate every unsubscribe link already sent. |
| `EDM_SERVICE_EMAIL` / `EDM_SERVICE_PASSWORD` | Legacy (old edm-api). Not used; leave empty. |

Images inserted into designs before `EDM_PUBLIC_URL` was set keep their old
address. Re-insert them after setting it.

---

## 6. Database

The module uses odb's existing connection (`odb\common\index_adv.php`) and
the `odb` database. Run SQL either in phpMyAdmin (database `odb` -> SQL tab,
or Import for a file) or with the command line client:

```bat
cd /d C:\xampp\htdocs\odb\edm
C:\xampp\mysql\bin\mysql.exe -u root -p odb < sql\<file>.sql
```

### 6.1 `staff.edm` role column

Check whether it exists:

```sql
SHOW COLUMNS FROM staff LIKE 'edm';
```

Only if no row is returned, run `sql/add_staff_edm_column.sql` (it is a plain
`ALTER TABLE` and fails if the column already exists). Then give yourself the
superadmin role: `UPDATE staff SET edm = 1 WHERE username = '<your username>';`

### 6.2 EDM tables - choose one path

First run the read-only check. It lists every table / column the new code
needs that the database does not have yet:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p odb < sql\check_schema.sql
```

**Path A - no EDM data to keep (first install, or only test data on the
server).** Rebuild everything from scratch. This **deletes all rows in every
`edm_*` table** (newsletters, contacts, approvals, send history):

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p odb < sql\edm_master.sql
```

**Path B - keep existing EDM data.** Two steps, both safe to run more than
once.

Step 1 - contact lists to one masterlist. Contact lists are gone: every
contact now lives once in `edm_contacts`, and a newsletter goes to every
subscribed contact, narrowed by its segment. If the database still has
`edm_lists` / `edm_list_members` (check_schema lists the `edm_contacts`
table as missing), run:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p odb < sql\migrate_lists_to_masterlist.sql
```

It copies every contact once per email address (an unsubscribed or bounced
status on any list wins), then removes the list columns from segments,
autoresponders and newsletters and drops the two list tables. Review the
newsletters that are not yet sent afterwards: one that was sent to a single
list now goes to every subscribed contact unless it has a segment.

Step 2 - Email creator wizard columns on `edm_campaigns` (recipients with
include / exclude, send now, open / click / UTM tracking). Run once; it copies
each newsletter's segment into the new recipients and drops `segment_id`:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p odb < sql\migrate_campaign_wizard.sql
```

Also run `sql\migrate_master_tags.sql` (newsletter tags: creates
`edm_master_tags`, adds `edm_campaigns.tag_ids`; safe to run again) and
`sql\migrate_workflow_content.sql` (workflow newsletter / template, custom
triggers, and when to send: `trigger_type` + `timing`) (adds `edm_workflows.content_type`
/ `content_id` - the newsletter or template a workflow sends; safe to run again).

Optional: to honour "Open rate off" in a newsletter's Tracking step, create a
second SES configuration set (a copy of `SES_CONFIGURATION_SET` whose event
destination publishes every event except Open) and put its name in
`SES_CONFIGURATION_SET_NO_OPENS` in `.env`. Without it opens stay tracked.

Step 3 - if `check_schema.sql` still lists columns from the table below, run
the upgrade SQL that follows (it adds each column only when missing):

| Column | Added for |
| ------ | --------- |
| `edm_custom_fields.category` | Field categories (Demographic, Location, ...) in the segment builder |
| `edm_autoresponders.workflow_id` | Journey steps linked to their journey |
| `edm_custom_fields.type` = `multiselect` | Multi-select custom fields (not listed by check_schema - run the ALTER below regardless) |
| `edm_campaigns.reply_to_id` | Newsletter Reply-to chosen from Settings > Senders (also drops `edm_senders.reply_to`) |

```sql
-- edm_custom_fields.category (segment builder field groups)
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_custom_fields' AND COLUMN_NAME = 'category') = 0,
    'ALTER TABLE `edm_custom_fields` ADD COLUMN `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `options`',
    'SELECT ''edm_custom_fields.category already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- edm_autoresponders.workflow_id (journey steps belong to a journey)
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_autoresponders' AND COLUMN_NAME = 'workflow_id') = 0,
    'ALTER TABLE `edm_autoresponders` ADD COLUMN `workflow_id` bigint unsigned DEFAULT NULL AFTER `name`, ADD KEY `edm_autoresponders_workflow_id_foreign` (`workflow_id`), ADD CONSTRAINT `edm_autoresponders_workflow_id_foreign` FOREIGN KEY (`workflow_id`) REFERENCES `edm_workflows` (`id`) ON DELETE SET NULL',
    'SELECT ''edm_autoresponders.workflow_id already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- edm_campaigns.reply_to_id (campaign Reply-to picked from Settings > Senders)
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'reply_to_id') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `reply_to_id` bigint unsigned DEFAULT NULL AFTER `sender_id`',
    'SELECT ''edm_campaigns.reply_to_id already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- edm_custom_fields.type gains 'multiselect' (several options per contact).
-- Safe to run again: MODIFY only widens the list of allowed values.
ALTER TABLE `edm_custom_fields` MODIFY `type` enum('text','number','date','boolean','select','multiselect') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text';

-- edm_senders.reply_to removed (Reply-to is chosen per campaign now)
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_senders' AND COLUMN_NAME = 'reply_to') > 0,
    'ALTER TABLE `edm_senders` DROP COLUMN `reply_to`',
    'SELECT ''edm_senders.reply_to already gone''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
```

If `check_schema.sql` lists **whole tables or other columns** as missing,
production is older than this guide covers. Either take Path A (after
exporting any data you need), or recreate only the missing tables with their
`sql/create_edm_<table>_table.sql` file - note each of those files starts
with `DROP TABLE IF EXISTS`, so run it only for a table that is missing or
empty.

Run `check_schema.sql` again. **It must return no rows except possibly
`staff.edm` before 6.1 was done.** Do not continue until it is empty.

### 6.3 Seed data (spec-driven setup)

```bat
C:\xampp\mysql\bin\mysql.exe -u root -p odb < sql\edm_seed.sql
```

Adds what would otherwise be created by hand, skipping anything that already
exists (it never overwrites edits made in the app):

- 29 contact custom fields with categories (spec 6 filters, 13.1 warehouse
  fields, 9 personalisation variables such as `points_balance`,
  `voucher_code`, `nearest_outlet`)
- 14 pre-built segments (VIP, Loyal, At risk, Dormant, High spenders, ...)
- 5 journeys from spec 8 (Welcome, Birthday, Re-engagement, Cart
  abandonment, Bounce recovery) as drafts, with their 12 email steps
- 2 starter templates (Basic newsletter, Birthday voucher)

**Optional, test servers only:** `sql\edm_seed_uat.sql` adds 40 contacts
on the Amazon SES mailbox simulator
(`success+uatNN@simulator.amazonses.com` - accepted, never bounce, no
reputation impact). Do not load it on a server that sends to real customers
unless you delete those contacts afterwards.

---

## 7. Send queue scheduler

`cron\edm-send.bat` runs one send pass (about 50 seconds) and the automated QA
checks. It must run **every minute**.

PHP selection: the script uses the first PHP **8.1 or newer** it finds, in
this order: the `EDM_PHP` environment variable, `C:\xampp\php\php.exe`,
Laragon, then `php` on PATH. An older PHP is skipped (an old PHP would make
every run fail with exit code 255). To force a specific one, set a system
environment variable `EDM_PHP` = full path of `php.exe`.

**Option 1 - Windows Task Scheduler** (as Administrator):

```bat
schtasks /Create /TN "EDM send queue" /SC MINUTE /MO 1 /RU SYSTEM /TR "\"C:\xampp\htdocs\odb\edm\cron\edm-send.bat\""
schtasks /Query /TN "EDM send queue"
```

With `/RU SYSTEM` and the AWS default credential chain, put the credentials
in the `.env` key pair instead (SYSTEM has no `~\.aws` profile).

**Option 2 - System Scheduler:** New event -> Run Application ->
Application `C:\xampp\htdocs\odb\edm\cron\edm-send.bat`, Schedule: every 1
minute, State: Hidden (no console window every minute).

**Verify after two minutes** - these files appear in `edm\logs\`:

| File | Content |
| ---- | ------- |
| `cron.log` | Everything PHP printed, including a fatal error that stops a run before it can log. Check this first when nothing happens. |
| `ses-send.log` | Send runs: "Newsletter #N started sending", "Sent N email(s)", "completed" |
| `qa.log` | Automated QA runs |

A scheduler result of exit code 255 means PHP crashed - the reason is at the
end of `logs\cron.log`.

---

## 8. Amazon SES

1. **Sending domain** - Settings > Sending domains: add the company domain
   (e.g. `alpropharmacy.com`), publish the DKIM CNAME records, SPF and DMARC
   in DNS, then check status until Verified.
2. **Sender** - Settings > Senders: use an address on that domain (e.g.
   `noreply@alpropharmacy.com`). **Do not use a Gmail / personal address**:
   SES is not authorised for gmail.com, so Gmail shows "couldn't verify this
   sender" and bulk mail goes to spam.
3. **Configuration set** `alpro-marketing` - event destination to the SNS
   topic for Send, Delivery, Bounce, Complaint, Open, Click (open / click
   tracking enabled).
4. **SNS subscription** - HTTPS subscription of the topic to
   `<EDM_PUBLIC_URL>/public/ses-webhook.php`. The webhook confirms the
   subscription itself and only trusts messages signed for
   `SES_SNS_TOPIC_ARN`.
5. **Production access** - while the SES account is in the sandbox, it only
   sends to verified addresses (Settings > Integrations shows the quota and
   sandbox state). Request production access in the AWS console before real
   newsletters.

---

## 9. Users and roles

Settings > Users & permissions (superadmin only) - give each user a role
(stored in `staff.edm`):

| Role | Value | Can |
| ---- | ----- | --- |
| Superadmin | 1 | Everything, every approval stage |
| Admin | 2 | Build newsletters; **audience validation** (approval step 4, until a BI role exists) |
| BPT team | 3 | Build; **BPT review** (steps 2-3) and **final approval / scheduling** (step 6) |
| Management | 4 | Read only |

`dev-switch-role.php` (the DEV ROLE bar) only works on localhost and is
inert on production.

---

## 10. Smoke test after deploy

1. Open `https://<host>/odb/edm/` - the dashboard loads without PHP warnings.
2. Contacts > Custom fields - 29 fields with categories. Contacts > Segments -
   the pre-built segments show contact counts (0 is fine with no contacts).
3. Files - upload an image; its URL must start with `EDM_PUBLIC_URL`, not
   `localhost`.
4. Create a newsletter with a segment that matches only test contacts ->
   Submit -> Approval > Raise review ->
   BPT approve -> Audience validation -> wait for Automated QA -> Final
   approval with a send time 3-5 minutes ahead.
5. At that time `logs\ses-send.log` shows the newsletter starting and
   completing; the newsletter status becomes Completed; the test inbox
   receives the email with images and a working Unsubscribe link.
6. Click Unsubscribe in that email - the page loads over the public URL and
   the address appears in Suppression Centre.

---

## 11. Rollback

```bat
cd /d C:\xampp\htdocs\odb\edm
git log --oneline -5
git checkout <previous commit hash> -- .
composer install --no-dev --optimize-autoloader
```

Then restore the database from `C:\edm-backup\edm-before-deploy.sql`
(phpMyAdmin -> Import) and `.env` / `uploads` from `C:\edm-backup`. Stop the
scheduler task while restoring so no newsletter sends half-way.

---

## Appendix - what this release changes

**Schema** (section 6.2): contact lists replaced by one masterlist
(`edm_contacts`; `edm_lists`, `edm_list_members` and every `list_id` /
`all_lists` column removed), `edm_custom_fields.category`,
`edm_autoresponders.workflow_id`.

**New files**: `sql/edm_seed.sql`, `sql/edm_seed_uat.sql`,
`sql/check_schema.sql`, `sql/migrate_lists_to_masterlist.sql`,
`sql/edm_truncate.sql`, `app/Services/CampaignAudience.php`,
`app/Services/ScheduleReadiness.php`, this guide.

**Behaviour**:

- Approval chain completed end to end: raise review -> BPT review ->
  audience validation (new) -> automated QA -> final approval with send date
  (new) -> Scheduled. Final approval is the only way to schedule; the
  newsletter is then locked. Newsletters list has a new Archive action for
  completed newsletters.
- One contact masterlist instead of contact lists: Contacts > All contacts,
  import straight into it, newsletters go to every subscribed contact or to a
  segment.
- Segments: AND / OR across condition groups; new date conditions (in the
  last N days, more than N days ago, in month, age between); engagement
  conditions (opened / clicked in the last N days, in a newsletter, ever /
  never); fields grouped by category.
- Contact import accepts dates as `YYYY-MM-DD`, `D/M/YYYY` (Excel CSV) and
  Excel date cells.
- Merge tags from the spec (`{{FirstName}}`, `{{LastName}}`,
  `{{MembershipType}}`, `{{PointsBalance}}`, `{{VoucherCode}}`,
  `{{NearestOutlet}}`, `{{UnsubscribeLink}}`, ...) resolve to the matching
  fields.
- Uploaded image URLs use `EDM_PUBLIC_URL`.
- `cron\edm-send.bat` picks PHP 8.1+ only and logs PHP output to
  `logs\cron.log`.
