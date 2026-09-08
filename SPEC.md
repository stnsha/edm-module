# EDM Platform - Build Spec (internal)

Condensed from `SES_EDM_Frontend_Specification v1.0` (19 May 2026, Marketing / CRM
Team). Amazon SES is the delivery engine only; this app owns all campaign logic,
audience selection, scheduling, approval, and reporting.

## Architecture

```
POS / Membership  ->  Customer Data Warehouse  ->  CRM Segmentation
  ->  Automation Engine  ->  Amazon SES (send)  ->  Reporting (SES events via SNS/SQS -> BI DB)
```

- SES API: campaign dispatch + bounce/complaint webhook receiver (edm-api).
- Customer Data Warehouse: external, owned by BI. Source for all segmentation and
  personalisation. This app queries it, does not own it.
- Reporting: consume SES delivery events (SNS/SQS), store in BI database.

## Access model

`staff.edm` is the access tier only. Campaign ownership is separate.

| edm | Tier        | Spec role(s)                        |
| --- | ----------- | ---------------------------------- |
| 0   | none        | -                                 |
| 1   | superadmin  | BI / Digital, IT / Developer      |
| 2   | admin       | (reserved)                        |
| 3   | bpt team    | Promotion / BPT                   |
| 4   | management  | Management (read-only)            |

"Project PIC / Requester" is not a tier. Any staff with `edm` in (1,2,3) can raise
a campaign; management (4) is read-only. Ownership is tracked per campaign in
`campaigns.requested_by` / `requested_by_name` (snapshot), same split as the atem
module's `issuer_staff_id` vs `staff.atem` grade. Owner-only actions (edit own
draft, resubmit revision, view own status) are gated by `requested_by === staff_id`
with superadmin / BPT override.

## Modules -> pages (one page, one folder)

| Module              | Folder            | Phase | Menu gate            |
| ------------------- | ----------------- | ----- | ------------------- |
| Dashboard           | `dashboard/`      | 1     | edm >= 1            |
| Campaign Management  | `campaign/`       | 1     | edm in (1,2,3)      |
| Email Builder       | `email-builder/`  | 1     | edm in (1,2,3)      |
| Audience Builder    | `audience/`       | 1     | edm in (1,2,3)      |
| Campaign Calendar   | `calendar/`       | 1     | edm >= 1            |
| Reporting Dashboard  | `reporting/`      | 1     | edm >= 1            |
| Suppression Centre  | `suppression/`    | 1     | edm in (1,2,3)      |
| Settings            | `settings/`       | 1     | edm == 1 / superadmin |
| Automation Builder  | `automation/`     | 2     | edm in (1,2,3)      |
| Approval Centre     | `approval/`       | 2     | edm in (1,2,3)      |
| Template Library    | `templates/`      | 2     | edm in (1,2,3)      |
| Asset Library       | `assets/`         | 2     | edm in (1,2,3)      |

Phase 1 folders are scaffolded as empty-card stubs plus an `api.php` router
skeleton. Real screens and endpoints are filled in per feature; every endpoint
change is mirrored in `edm-api` (see CLAUDE.md "API coupling").

## Campaign status flow (9)

`draft` -> `pending_submission` -> `under_bpt_review` -> `content_revision`
-> `audience_validation` -> `scheduled` -> `sending` -> `completed` -> `archived`

The system enforces valid transitions. Owner of each state per spec section 5.1
(Draft/Pending/Revision = PIC; Under Review/Scheduled = BPT; Audience Validation =
BI/CRM; Sending/Completed/Archived = system).

## Approval chain (8 steps, spec 5.2)

1. PIC submits request (objective, audience brief, artwork, copy).
2. BPT reviews content, artwork, CTA, grammar, compliance, objective.
3. BPT checks calendar for a free slot; conflict detection runs.
4. BI / CRM validates audience segment (LOFRA + suppression filters).
5. Automated QA runs (see below).
6. Final approval; campaign locked.
7. SES queue triggered at scheduled datetime.
8. Reporting dashboard updated with live delivery data.

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
Membership (type, member code, personal/corporate, client sales category);
Purchase (product category, brand, frequency, last purchase date, total spend);
Engagement (score, open/click history, dormant flag);
RFM / LOFRA (VIP, Loyal, Potential Loyalist, New, At Risk, Dormant | Loyal, Fresh,
Risk, Hopper, Average);
Suppression (unsubscribed, bounced, spam complaint, inactive);
Advanced (multi condition groups, pre-built segments, abandoned cart).

LOFRA recalculated monthly. RFM uses a rolling 12-month purchase window. All source
data comes from the Customer Data Warehouse.

## Dynamic content variables

`{{FirstName}}` `{{LastName}}` `{{MemberCode}}` `{{MembershipType}}`
`{{PointsBalance}}` `{{PointsExpiry}}` `{{VoucherCode}}` `{{NearestOutlet}}`
`{{UnsubscribeLink}}` (mandatory footer). QA blocks any unresolved / blank variable.

## Automated QA checklist (runs at Scheduled; all must pass before SES queue)

Broken link check; image load check; spam score below threshold; mobile responsive
render; unsubscribe footer present; subject line <= 60 chars; UTM parameters on all
links; every `{{variable}}` resolved; high-bounce-rate alert; SES sending quota
verified.

## Reporting KPIs (per campaign, per segment, platform level)

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

- Phase 1 Foundation: Campaign Management, Email Builder, Audience Builder,
  Scheduling, SES API integration, core Reporting, Contact Segmentation,
  Suppression Centre.
- Phase 2 Automation: visual Automation Builder, Welcome / Birthday /
  Re-engagement / Cart-Abandonment journeys, Approval workflow, advanced
  reporting, bounce recovery.
- Phase 3 CRM Intelligence: AI send-time optimisation, AI segmentation, predictive
  analytics, product recommendation engine, AI-assisted email generation.

## Open questions (from spec section 14 - unresolved)

Authentication: SSO vs standalone (current: standalone JWT via odb session).
Hosting: spec prefers AWS-native; current is Laragon / XAMPP on-prem.
Data refresh: real-time CDC from POS / membership vs scheduled batch.
RFM recalculation: automated monthly job vs manual BI trigger.
A/B testing: subject-line-only vs full content for Phase 1.
AI provider: in-house LLM vs third-party API.
Multi-language UI: required or English only.
Archival retention period for completed campaigns and email content.
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
| Dashboard               | `dashboard/index.php`             | Dashboard            | view  |
| Contacts > Lists        | `audience/index.php`              | Audience Builder     | build |
| Contacts > Segments     | `audience/segments.php`           | Audience Builder     | build |
| Contacts > Custom fields | `audience/fields.php`            | Audience Builder     | build |
| Contacts > Tags & scoring | `audience/tags.php`             | Audience Builder     | build |
| Contacts > Suppression lists | `suppression/index.php`       | Suppression Centre   | build |
| Email marketing > Newsletters | `campaign/index.php`         | Campaign Management  | build |
| Email marketing > Email creator | `email-builder/index.php`  | Email Builder        | build |
| Automation > Workflows   | `automation/index.php`           | Automation Builder   | build |
| Automation > Autoresponders | `automation/autoresponders.php` | Automation Builder | build |
| Calendar                | `calendar/index.php`             | Campaign Calendar    | view  |
| Statistics              | `reporting/index.php`            | Reporting Dashboard  | view  |
| Templates               | `templates/index.php`           | Template Library     | build |
| Files                   | `assets/index.php`              | Asset Library        | build |
| Approval                | `approval/index.php`           | Approval Centre      | build |
| Settings > Senders      | `settings/senders.php`         | Settings             | super |
| Settings > Sending domains | `settings/domains.php`       | Settings             | super |
| Settings > Users & permissions | `settings/users.php`     | Settings             | super |
| Settings > Integrations & API | `settings/integrations.php` | Settings           | super |
| Settings > General      | `settings/index.php`           | Settings             | super |

Gates: `build` = edm in (1,2,3); `view` = edm >= 1; `super` = edm == 1.

### Terminology lock (GetResponse word -> our word)

| GetResponse                | This module        |
| -------------------------- | ------------------ |
| Campaign (legacy = a list) | List               |
| Newsletter                 | Newsletter         |
| Autoresponder              | Autoresponder      |
| Marketing Automation       | Workflow           |
| Segment                    | Segment            |
| Suppression list / Blacklist | Suppression list |
| From field                 | Sender             |
| Statistics                 | Statistics         |
| File manager               | Files              |
| Message template           | Template           |

Never use the bare word "Campaign" for the audience container - that is
GetResponse's legacy meaning and the top source of confusion. The container is a
"List".

### Newsletter creation flow (GetResponse 3-step wizard shape)

1. Setup - name, subject + A/B subject variant, sender (`settings/senders.php`),
   reply-to, recipient Lists / Segments, UTM auto-tag. Creates campaign at
   status `draft`.
2. Design - template picker -> Email creator (drag-drop + HTML) -> desktop /
   mobile preview -> spam score -> dynamic variable insertion -> test send.
3. Summary - review + pre-submit checks. Requester clicks **Submit for review**
   (never a bare "Send"); GPT precheck gates this -> status `pending_submission`.
   BPT / BI / QA proceed per section 5. Automated QA checklist runs at
   `scheduled` before the SES queue.

### Intentional divergences from GetResponse

- Approval Centre + 8-step chain + 9-status flow (GetResponse sends immediately).
- Requester UI says "Submit for review", not "Send".
- Hard suppression caps (8/month, 2/week, 1/day) enforced at queue time - shown
  as a visible pre-send check.
- LOFRA / RFM segment filters sourced from the Customer Data Warehouse.
- GPT precheck step before submission.
- Automated QA checklist at the Scheduled stage.
- `staff.edm` tier RBAC (5 spec roles mapped onto 4 tiers, see "Access model").

## Data model

Schema is inventoried here; each table gets its own SQL file in `sql/`, written
when its screen is built (never one combined dump).

Ownership boundaries:

- Customer Data Warehouse (external, not ours): all contact PII, purchase
  history, LOFRA / RFM scores. This module queries it, never owns it.
- `edm-api` (Laravel, BI DB): SES delivery-event ingestion via SNS / SQS.
- This module's DB (odb MySQL, `mysqli` / `$conn` from `common/index_adv.php`):
  everything below.

| Module      | Tables                                                                                                                                          | Phase |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ----- |
| Contacts    | `edm_lists`, `edm_list_members` (by member code), `edm_segments` (filter JSON), `edm_tags`, `edm_member_tags`, `edm_custom_fields`               | 1     |
| Newsletters | `edm_campaigns` (9-status enum, `requested_by` / `requested_by_name` snapshot), `edm_campaign_content` (html + editor JSON + version), `edm_campaign_ab_variants`, `edm_campaign_recipients` (resolved snapshot), `edm_campaign_status_log` | 1 |
| Suppression | `edm_suppressions` (email, reason enum, source), `edm_send_log` (drives 8/month, 2/week, 1/day rolling caps)                                     | 1     |
| Calendar    | `edm_calendar_slots` (slot allocation + conflict detection)                                                                                      | 1     |
| Settings    | `edm_senders` (from-field + verified flag), `edm_sending_domains` (dkim / spf status), `edm_settings` (kv)                                        | 1     |
| QA          | `edm_qa_checks` (campaign_id, check_type, status, detail)                                                                                         | 1     |
| Templates   | `edm_templates`, `edm_template_content`                                                                                                          | 2     |
| Files       | `edm_assets`                                                                                                                                     | 2     |
| Automation  | `edm_workflows`, `edm_workflow_nodes`, `edm_workflow_runs`, `edm_autoresponders`                                                                 | 2     |
| Approval    | `edm_approvals` (step 1-8, reviewer, decision, comment), `edm_revisions`                                                                          | 2     |
