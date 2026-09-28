-- Table `edm_ses_events` (EDM module, odb database).
-- Raw Amazon SES events received through SNS (public/ses-webhook.php).
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_ses_events`;
SET FOREIGN_KEY_CHECKS = 1;

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
