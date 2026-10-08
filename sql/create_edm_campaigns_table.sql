-- Table `edm_campaigns` (EDM module, odb database).
-- Newsletters. status: 1=draft, 2=pending_submission, 3=under_bpt_review, 4=content_revision, 5=audience_validation, 6=scheduled, 7=sending, 8=completed, 9=archived.
-- audience: JSON { include: { segments: [id], contacts: [id] }, exclude: { segments: [id], contacts: [id] } };
--   NULL / empty include = every subscribed contact (edm_contacts), minus the excluded ones.
-- archived_from: the status before archiving (9); unarchiving restores it.
-- tag_ids: JSON list of edm_master_tags ids (newsletter tags, optional).
-- send_now: 1 = send as soon as final approval is given, 0 = at scheduled_at. Every send waits
--   60 seconds after its time so it can be recalled (CampaignSender::RECALL_SECONDS).
-- track_opens / track_clicks: SES open / click tracking; utm_enabled + utm (JSON source, medium,
--   campaign, content): UTM parameters added to every link when sent.
-- sender_id: From address; reply_to_id: Reply-to address (NULL = replies go to the sender). Both edm_senders.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_campaigns`;
SET FOREIGN_KEY_CHECKS = 1;

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
