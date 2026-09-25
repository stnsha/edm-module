-- Table `edm_calendar_slots` (EDM module, odb database).
-- Campaign calendar slots.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_calendar_slots`;
SET FOREIGN_KEY_CHECKS = 1;

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
