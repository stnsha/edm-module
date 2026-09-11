-- Table `edm_list_members` for the EDM module. Data owned by edm-api.
-- Canonical DDL; mirrors edm-api/database/migrations/2026_09_08_000004_create_edm_list_members_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_list_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `list_id` bigint unsigned NOT NULL,
  `member_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('subscribed','unsubscribed','bounced') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subscribed',
  `subscribed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `edm_list_members_list_id_member_code_unique` (`list_id`,`member_code`),
  KEY `edm_list_members_member_code_index` (`member_code`),
  CONSTRAINT `edm_list_members_list_id_foreign` FOREIGN KEY (`list_id`) REFERENCES `edm_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
