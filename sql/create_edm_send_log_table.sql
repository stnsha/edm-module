-- Table `edm_send_log` (EDM module, odb database).
-- Per-recipient send history (frequency caps + SES delivery state). Written by the
-- send pipeline (app/Services/Ses/CampaignSender), updated by SES events.
-- status: 1=sent, 2=delivered, 3=bounced, 4=complained, 5=failed, 6=skipped (error says why).
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_send_log`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_send_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `member_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
