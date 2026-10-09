-- EDM module - master schema for the odb database.
-- Drops every edm_* table, then recreates them all in dependency order
-- (development: all edm_* rows are lost). Per-table copies live next to this file.
-- staff.edm (role tier) is a separate one-off change: add_staff_edm_column.sql.
-- Datetime columns hold Asia/Kuala_Lumpur local time. Every table has
-- created_at / updated_at / deleted_at; deleted_at is a soft delete (NULL = active)
-- and business-key uniqueness is enforced by the app against active rows.

SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_calendar_slots`;
DROP TABLE IF EXISTS `edm_autoresponders`;
DROP TABLE IF EXISTS `edm_workflows`;
DROP TABLE IF EXISTS `edm_triggers`;
DROP TABLE IF EXISTS `edm_assets`;
DROP TABLE IF EXISTS `edm_templates`;
DROP TABLE IF EXISTS `edm_campaign_content`;
DROP TABLE IF EXISTS `edm_campaigns`;
DROP TABLE IF EXISTS `edm_master_tags`;
DROP TABLE IF EXISTS `edm_ses_events`;
DROP TABLE IF EXISTS `edm_send_log`;
DROP TABLE IF EXISTS `edm_suppressions`;
DROP TABLE IF EXISTS `edm_settings`;
DROP TABLE IF EXISTS `edm_sending_domains`;
DROP TABLE IF EXISTS `edm_member_tags`;
DROP TABLE IF EXISTS `edm_segments`;
DROP TABLE IF EXISTS `edm_contacts`;
-- Replaced by edm_contacts (one masterlist); dropped so an older database is cleaned up.
DROP TABLE IF EXISTS `edm_list_members`;
DROP TABLE IF EXISTS `edm_lists`;
DROP TABLE IF EXISTS `edm_tags`;
DROP TABLE IF EXISTS `edm_custom_fields`;
DROP TABLE IF EXISTS `edm_senders`;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------
-- edm_senders: From-addresses. status: 1=pending, 2=verified, 3=failed.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_senders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `verified_at` datetime NULL DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_senders_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_custom_fields: Contact field definitions; each key is a {{key}} personalisation variable.
-- category: spec section 6 filter category grouping the field in the segment builder; NULL = Custom fields.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_custom_fields` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('text','number','date','boolean','select','multiselect') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `options` json DEFAULT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_custom_fields_key_index` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_tags: Contact tags.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_tags_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_contacts: The contact masterlist: one row per email address. status: 1=subscribed, 2=unsubscribed, 3=bounced.
-- fields: Contacts > Custom fields values keyed by field key (JSON).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fields` json DEFAULT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `subscribed_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_contacts_email_index` (`email`),
  KEY `edm_contacts_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_segments: Saved AND/OR segment definitions (definition = {match, groups}); applied to the contact masterlist.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_segments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `definition` json NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_member_tags: Tag assignments per contact email address.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_member_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_id` bigint unsigned NOT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_member_tags_email_tag_id_index` (`email`,`tag_id`),
  KEY `edm_member_tags_tag_id_foreign` (`tag_id`),
  CONSTRAINT `edm_member_tags_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `edm_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_sending_domains: Sending domains. dkim/spf/dmarc_status: 1=pending, 2=verified, 3=failed.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_sending_domains` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dkim_status` tinyint unsigned NOT NULL DEFAULT '1',
  `spf_status` tinyint unsigned NOT NULL DEFAULT '1',
  `dmarc_status` tinyint unsigned NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_sending_domains_domain_index` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_settings: Grouped key/value settings (general, integrations).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_settings_group_key_index` (`group`,`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_suppressions: Suppression list (never email these addresses).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_suppressions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` enum('unsubscribed','hard_bounce','soft_bounce','spam_complaint','inactive','manual') COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_suppressions_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_send_log: Per-recipient send history (frequency caps + SES delivery state). Written by the
-- send pipeline (app/Services/Ses/CampaignSender), updated by SES events.
-- status: 1=sent, 2=delivered, 3=bounced, 4=complained, 5=failed, 6=skipped (error says why).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_send_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` datetime NOT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `ses_message_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivered_at` datetime NULL DEFAULT NULL,
  `opened_at` datetime NULL DEFAULT NULL,
  `clicked_at` datetime NULL DEFAULT NULL,
  `bounced_at` datetime NULL DEFAULT NULL,
  `complained_at` datetime NULL DEFAULT NULL,
  `unsubscribed_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_send_log_email_sent_at_index` (`email`,`sent_at`),
  KEY `edm_send_log_campaign_id_index` (`campaign_id`),
  KEY `edm_send_log_ses_message_id_index` (`ses_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_ses_events: Raw Amazon SES events received through SNS (public/ses-webhook.php).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_ses_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ses_message_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `occurred_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_ses_events_ses_message_id_index` (`ses_message_id`),
  KEY `edm_ses_events_event_type_index` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_master_tags: Master list of newsletter tags (edm_campaigns.tag_ids).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_master_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_master_tags_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_campaigns: Newsletters. status: 1=draft, 6=scheduled, 7=sending, 8=completed, 9=archived (2-5 unused, from the removed approval chain).
-- audience: JSON { include: { segments: [id], contacts: [id] }, exclude: { segments: [id], contacts: [id] } };
--   NULL / empty include = every subscribed contact (edm_contacts), minus the excluded ones.
-- archived_from: the status before archiving (9); unarchiving restores it.
-- tag_ids: JSON list of edm_master_tags ids (newsletter tags, optional).
-- send_now: 1 = send as soon as it is scheduled, 0 = at scheduled_at. Every send waits
--   60 seconds after its time so it can be recalled (CampaignSender::RECALL_SECONDS).
-- track_opens / track_clicks: SES open / click tracking; utm_enabled + utm (JSON source, medium,
--   campaign, content): UTM parameters added to every link when sent.
-- sender_id: From address; reply_to_id: Reply-to address (NULL = replies go to the sender). Both edm_senders.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_b` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preheader` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tag_ids` json DEFAULT NULL,
  `sender_id` bigint unsigned DEFAULT NULL,
  `reply_to_id` bigint unsigned DEFAULT NULL,
  `audience` json DEFAULT NULL,
  `send_now` tinyint(1) NOT NULL DEFAULT '0',
  `track_opens` tinyint(1) NOT NULL DEFAULT '1',
  `track_clicks` tinyint(1) NOT NULL DEFAULT '1',
  `utm_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `utm` json DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `archived_from` tinyint unsigned DEFAULT NULL,
  `scheduled_at` datetime NULL DEFAULT NULL,
  `requested_by` int unsigned DEFAULT NULL,
  `requested_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_campaigns_status_index` (`status`),
  KEY `edm_campaigns_scheduled_at_index` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_campaign_content: Newsletter body: html (sent) + editor_json (EmailBuilder.js block tree).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_campaign_content` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `html` longtext COLLATE utf8mb4_unicode_ci,
  `editor_json` json DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_campaign_content_campaign_id_index` (`campaign_id`),
  CONSTRAINT `edm_campaign_content_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `edm_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_templates: Template library: html (rendered email) + editor_json (EmailBuilder.js block tree).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `html` longtext COLLATE utf8mb4_unicode_ci,
  `editor_json` json DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_assets: Files library (uploaded images and external URLs).
-- ----------------------------------------------------------------------
CREATE TABLE `edm_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned DEFAULT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `uploaded_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_triggers: Custom workflow triggers (built-ins live in code,
-- Workflow::BUILT_IN_TRIGGERS); key is what edm_workflows.trigger stores.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_triggers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_triggers_key_index` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_workflows: Automation workflows. status: 1=draft, 2=active, 3=paused.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trigger_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'event',
  `trigger` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timing` json DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `definition` json DEFAULT NULL,
  `content_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_id` bigint unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_autoresponders: Autoresponders. status: 1=draft, 2=active, 3=paused.
-- workflow_id: the journey (edm_workflows) this timed email is a step of; offset_days from its trigger.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_autoresponders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workflow_id` bigint unsigned DEFAULT NULL,
  `offset_days` int NOT NULL DEFAULT '0',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_autoresponders_workflow_id_foreign` (`workflow_id`),
  CONSTRAINT `edm_autoresponders_workflow_id_foreign` FOREIGN KEY (`workflow_id`) REFERENCES `edm_workflows` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- edm_calendar_slots: Newsletter calendar slots.
-- ----------------------------------------------------------------------
CREATE TABLE `edm_calendar_slots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slot_date` date NOT NULL,
  `slot_label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_calendar_slots_slot_date_index` (`slot_date`),
  KEY `edm_calendar_slots_campaign_id_foreign` (`campaign_id`),
  CONSTRAINT `edm_calendar_slots_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `edm_campaigns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
