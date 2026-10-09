# EDM Platform - Build Spec (internal)

Condensed from `SES_EDM_Frontend_Specification v1.0` (19 May 2026, Marketing / CRM
Team). Amazon SES is the delivery engine only; this app owns all newsletter logic,
audience selection, scheduling, and reporting. The spec's Approval Centre /
8-step approval chain and Automated QA are **not used** (removed from the
module): a newsletter is scheduled straight from the Email creator.

## Architecture

```
POS / Membership  ->  Customer Data Warehouse  ->  CRM Segmentation
  ->  Automation Engine  ->  Amazon SES (send)  ->  Reporting (SES events via SNS/SQS -> BI DB)
```

- SES API: newsletter dispatch (`cron/send.php` -> `app/Services/Ses/CampaignSender`) +
  SES event webhook (`public/ses-webhook.php`, SNS HTTPS). Built; see CLAUDE.md "Amazon SES".
- Customer Data Warehouse: external, owned by BI. Source for all segmentation and
  personalisation. This app queries it, does not own it.
- Reporting: consume SES delivery events (SNS/SQS), store in BI database. Currently
  stored in `edm_ses_events` (raw) + `edm_send_log` (per recipient) in the odb database.

## Access model

`staff.edm` is the access tier only. Newsletter ownership is separate.

| edm | Tier        | Spec role(s)                        |
| --- | ----------- | ---------------------------------- |
| 0   | none        | -                                 |
| 1   | superadmin  | BI / Digital, IT / Developer      |
| 2   | admin       | (reserved)                        |
| 3   | bpt team    | Promotion / BPT                   |
| 4   | management  | Management (read-only)            |

"Project PIC / Requester" is not a tier. Any staff with `edm` in (1,2,3) can raise
a newsletter; management (4) is read-only. Ownership is tracked per newsletter in
`campaigns.requested_by` / `requested_by_name` (snapshot), same split as the atem
module's `issuer_staff_id` vs `staff.atem` grade. Owner-only actions (edit own
draft, view own status) are gated by `requested_by === staff_id`
with superadmin / BPT override.

## Modules -> pages (one page, one folder)

| Module              | Folder            | Phase | Menu gate            |
| ------------------- | ----------------- | ----- | ------------------- |
| Dashboard (incl. Reporting Dashboard) | `dashboard/` | 1 | edm >= 1     |
| Newsletter Management  | `newsletters/`    | 1     | edm in (1,2,3)      |
| Email Builder       | `email-builder/`  | 1     | edm in (1,2,3)      |
| Audience Builder    | `audience/`       | 1     | edm in (1,2,3)      |
| Newsletter Calendar   | `calendar/`       | 1     | edm >= 1            |
| Suppression Centre  | `suppression/`    | 1     | edm in (1,2,3)      |
| Settings            | `settings/`       | 1     | edm == 1 / superadmin |
| Automation Builder  | `automation/`     | 2     | edm in (1,2,3)      |
| Template Library    | `templates/`      | 2     | edm in (1,2,3)      |
| Asset Library       | `assets/`         | 2     | edm in (1,2,3)      |

Phase 1 folders are scaffolded as empty-card stubs plus an `api.php` router
skeleton. Real screens and endpoints are filled in per feature; every endpoint
change goes through the module's MVC layer (see CLAUDE.md "MVC layer").

## Newsletter status flow

`draft` (1) -> `pending_approval` (2) -> `scheduled` (6) -> `sending` (7) ->
`completed` (8), and `archived` (9) from draft or completed. The spec's other
review statuses (3 under_bpt_review, 4 content_revision, 5
audience_validation) are not used; their integer values stay reserved.

Submit for review (Email creator step 5, `Campaign::submit()`) records who
submitted it and their department, runs the readiness checks (complete
newsletter, verified sender, usable recipients, future send date) and locks
the newsletter. Approval (step 6): the BPT team or a superadmin, never the
submitter, approves (`Campaign::approve()` -> scheduled at the requested
time) or rejects with a reason (-> draft); the submitter can withdraw.
Recall returns a scheduled newsletter to draft. The SES queue starts it at
the scheduled datetime (after the one-minute recall window); the reporting
dashboard reads live delivery data.

## Suppression rules (hard, no manual override, applied at QUEUE time)

| Rule                     | Threshold      | Behaviour                                  |
| ------------------------ | -------------- | ---------------------------------------- |
| Max sends / customer / month | 8            | hard cap before queue                   |
| Max sends / week         | 2              | rolling 7-day window per customer        |
| Max sends / day          | 1              | rolling 24-hour window per customer      |
| Unsubscribe              | immediate      | auto-suppress on unsubscribe click       |
| Hard bounce              | permanent      | blacklist, never retried                 |
| Soft bounce              | retry 3 days   | resend once after 3 days, else inactive  |
| Spam complaint           | permanent      | auto-blacklist (protect SES reputation)  |
| Inactive email           | tagged         | flagged for review; reduced freq / suppress |

Suppression Centre UI lets BPT / CRM view lists, review flagged records, and run
recovery actions (e.g. re-validate an address).

## Audience Builder filters (AND/OR condition groups)

Demographic (gender, age range, race, nationality, preferred language);
Location (state, city, create outlet, last visit outlet);
Membership (type, personal/corporate, client sales category);
Purchase (product category, brand, frequency, last purchase date, total spend);
Engagement (score, open/click history, dormant flag);
RFM / LOFRA (VIP, Loyal, Potential Loyalist, New, At Risk, Dormant | Loyal, Fresh,
Risk, Hopper, Average);
Suppression (unsubscribed, bounced, spam complaint, inactive);
Advanced (multi condition groups, pre-built segments, abandoned cart).

LOFRA recalculated monthly. RFM uses a rolling 12-month purchase window. All source
data comes from the Customer Data Warehouse.

## Dynamic content variables

`{{FirstName}}` `{{LastName}}` `{{MembershipType}}`
`{{PointsBalance}}` `{{PointsExpiry}}` `{{VoucherCode}}` `{{NearestOutlet}}`
`{{UnsubscribeLink}}` (mandatory footer).

## Reporting KPIs (per newsletter, per segment, platform level)

Delivery rate >= 98%; open rate; CTR; CTOR; bounce rate < 2%; unsubscribe < 0.5%;
spam complaint < 0.08%; revenue attributed (UTM / order tracking); conversion rate;
segment performance. Visualisations: open/click trend, click heatmap, bounce trend,
complaint trend, segment comparison, journey performance. Export PDF / Excel / CSV.
Bot filtering toggleable per report.

## Automation journeys (Phase 2)

Welcome (new member: D0 welcome, D3 benefits, D7 product rec, D14 reminder);
Birthday (DOB trigger: -7 voucher, day-of greeting, +7 reminder);
Re-engagement (90 days no activity: D90 reminder, D120 voucher, D180 win-back);
Cart Abandonment (cart inactive 7 days: D7 reminder, D14 incentive);
Bounce Recovery (soft bounce: wait 3 days, retry, mark inactive on failure).
Visual builder: drag-and-drop steps, delay nodes, if/else on open/click/purchase,
exit conditions, per-journey analytics.

## Phase plan

- Phase 1 Foundation: Newsletter Management, Email Builder, Audience Builder,
  Scheduling, SES API integration, core Reporting, Contact Segmentation,
  Suppression Centre.
- Phase 2 Automation: visual Automation Builder, Welcome / Birthday /
  Re-engagement / Cart-Abandonment journeys, advanced
  reporting, bounce recovery.
- Phase 3 CRM Intelligence: AI send-time optimisation, AI segmentation, predictive
  analytics, product recommendation engine, AI-assisted email generation.

## Open questions (from spec section 14 - unresolved)

Authentication: SSO vs standalone (current: the odb session, resolved by `Edm\Core\Auth`).
Hosting: spec prefers AWS-native; current is Laragon / XAMPP on-prem.
Data refresh: real-time CDC from POS / membership vs scheduled batch.
RFM recalculation: automated monthly job vs manual BI trigger.
A/B testing: subject-line-only vs full content for Phase 1.
AI provider: in-house LLM vs third-party API.
Multi-language UI: required or English only.
Archival retention period for completed newsletters and email content.
SES sending domains: count in scope; DKIM / SPF per domain.

## GetResponse parity (IA + terminology)

The marketing / CRM team is moving off GetResponse (`app.getresponse.com`);
GetResponse is being cancelled, not integrated. This module is its replacement.
To keep the switch familiar, the navigation grouping, screen labels, and the
newsletter creation flow mirror GetResponse. Folder names on disk stay
spec-aligned (code identity, drives navbar active state); the display label is
decoupled via the `$edm_nav` map in `navbar.php`.

### Navigation map

| Nav group / item        | Folder / file                     | Spec module          | Gate  |
| ----------------------- | --------------------------------- | -------------------- | ----- |
| Dashboard               | `dashboard/index.php`             | Dashboard + Reporting Dashboard | view  |
| Contacts > All contacts | `audience/index.php`              | Audience Builder     | build |
| (no menu entry; via All contacts > Import contacts, keeps All contacts active) | `audience/import.php` | Audience Builder | build |
| Contacts > Segments     | `audience/segments.php`           | Audience Builder     | build |
| Contacts > Custom fields | `audience/fields.php`            | Audience Builder     | build |
| (not in menu) Tags       | `audience/tags.php`             | Audience Builder     | build |
| Contacts > Exclusion lists | `suppression/index.php`         | Suppression Centre   | build |
| Newsletters > All newsletters | `newsletters/index.php`    | Newsletter Management  | build |
| (no menu entry; via Newsletters > Design, keeps All newsletters active) | `email-builder/index.php` | Email Builder | build |
| Automation > Workflows   | `automation/index.php`           | Automation Builder   | build |
| Automation > Autoresponders | `automation/autoresponders.php` | Automation Builder | build |
| Calendar                | `calendar/index.php`             | Newsletter Calendar    | view  |
| Newsletters > Templates | `templates/index.php`           | Template Library     | build |
| (no menu entry; via Templates > Edit / Design template) | `templates/edit.php` | Template Library | build |
| Newsletters > Reports   | `newsletters/report.php`        | Reporting            | build |
| Settings > Senders      | `settings/senders.php`         | Settings             | super |
| Settings > Sending domains | `settings/domains.php`       | Settings             | super |
| Settings > Users & permissions | `settings/users.php`     | Settings             | super |
| Settings > Integrations & API | `settings/integrations.php` | Settings           | super |

Gates: `build` = edm in (1,2,3); `view` = edm >= 1; `super` = edm == 1.

### Terminology lock (GetResponse word -> our word)

| GetResponse                | This module        |
| -------------------------- | ------------------ |
| Campaign (legacy = a list) | (none - one contact masterlist) |
| Newsletter                 | Newsletter         |
| Autoresponder              | Autoresponder      |
| Marketing Automation       | Workflow           |
| Segment                    | Segment            |
| Suppression list / Blacklist | Exclusion list |
| From field                 | Sender             |
| Statistics                 | Dashboard          |
| File manager               | Files              |
| Message template           | Template           |

Never use the bare word "Campaign" for the audience container - that is
GetResponse's legacy meaning and the top source of confusion. There are no
contact lists: every contact is on one masterlist (Contacts > All contacts),
and a newsletter's audience is all subscribed contacts or a Segment.

### Newsletter creation flow (GetResponse 3-step wizard shape)

1. Setup - name, subject + A/B subject variant, sender (`settings/senders.php`),
   reply-to, recipient Segment (none = all subscribed contacts), UTM auto-tag. Creates newsletter at
   status `draft`.
2. Design - template picker -> Email creator (drag-drop + HTML) -> desktop /
   mobile preview -> spam score -> dynamic variable insertion -> test send.
3. Summary - review + readiness checks, Submitted by + Department. Requester
   clicks **Submit for review** -> status `pending_approval`; the BPT team
   approves in the Approval step -> `scheduled`.

### Intentional divergences from GetResponse

- One-minute recall window after the send time (Recall - don't send it).
- Hard suppression caps (8/month, 2/week, 1/day) enforced at queue time - shown
  as a visible pre-send check.
- LOFRA / RFM segment filters sourced from the Customer Data Warehouse.
- `staff.edm` tier RBAC (5 spec roles mapped onto 4 tiers, see "Access model").

## Data model

All `edm_*` data lives in the odb MySQL database and is read / written by the
module's own MVC layer (`edm/app/`, see CLAUDE.md) through odb's `$conn`. Each
page's `api.php` dispatches to a controller; there is no separate API service.

Schema: one SQL file per table in `edm/sql/` plus `edm/sql/edm_master.sql`
(every table, dependency order, re-runnable). Every table has `created_at`,
`updated_at` and `deleted_at` (soft delete).

Ownership boundaries:

- Customer Data Warehouse (external, not ours): all contact PII, purchase
  history, LOFRA / RFM scores. The module queries it, never owns it.
- The odb MySQL (`$conn` from `common/index_adv.php`): all `edm_*` tables below
  plus `staff.edm` (the access tier). SES delivery-event ingestion (SNS / SQS)
  will write here too.

| Module      | Tables                                                                                                                                          | Phase |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ----- |
| Contacts    | `edm_contacts` (the masterlist, one row per email), `edm_segments` (filter JSON), `edm_tags`, `edm_member_tags`, `edm_custom_fields`               | 1     |
| Newsletters | `edm_campaigns` (status enum, `requested_by` / `requested_by_name` snapshot), `edm_campaign_content` (html + editor JSON + version), `edm_campaign_ab_variants`, `edm_campaign_recipients` (resolved snapshot), `edm_campaign_status_log` | 1 |
| Suppression | `edm_suppressions` (email, reason enum, source), `edm_send_log` (drives 8/month, 2/week, 1/day rolling caps)                                     | 1     |
| Calendar    | `edm_calendar_slots` (slot allocation + conflict detection)                                                                                      | 1     |
| Settings    | `edm_senders` (from-field + verified flag), `edm_sending_domains` (dkim / spf status), `edm_settings` (kv)                                        | 1     |
| Templates   | `edm_templates`, `edm_template_content`                                                                                                          | 2     |
| Files       | `edm_assets`                                                                                                                                     | 2     |
| Automation  | `edm_workflows`, `edm_workflow_nodes`, `edm_workflow_runs`, `edm_autoresponders`                                                                 | 2     |
