-- Table `edm_member_tags` for the EDM module. Data owned by edm-api.
-- Canonical DDL; mirrors edm-api/database/migrations/2026_09_08_000006_create_edm_member_tags_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_member_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `member_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `edm_member_tags_member_code_tag_id_unique` (`member_code`,`tag_id`),
  KEY `edm_member_tags_tag_id_foreign` (`tag_id`),
  CONSTRAINT `edm_member_tags_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `edm_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
