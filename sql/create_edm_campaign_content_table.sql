-- Table `edm_campaign_content` for the EDM module. Data owned by edm-api.
-- Canonical DDL; mirrors edm-api/database/migrations/2026_09_08_000012_create_edm_campaign_content_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_campaign_content` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `html` longtext COLLATE utf8mb4_unicode_ci,
  `editor_json` json DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `edm_campaign_content_campaign_id_unique` (`campaign_id`),
  CONSTRAINT `edm_campaign_content_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `edm_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
