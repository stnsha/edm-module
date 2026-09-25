-- Table `edm_campaign_content` (EDM module, odb database).
-- Newsletter body: html (sent) + editor_json (EmailBuilder.js block tree).
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_campaign_content`;
SET FOREIGN_KEY_CHECKS = 1;

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
