-- Table `edm_sending_domains` for the EDM module. Data owned by edm-api.
-- Canonical DDL; mirrors edm-api/database/migrations/2026_09_08_000007_create_edm_sending_domains_table.php.
-- Applied to the edm-api database (local: edm_local).

CREATE TABLE `edm_sending_domains` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dkim_status` enum('pending','verified','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `spf_status` enum('pending','verified','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `dmarc_status` enum('pending','verified','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `edm_sending_domains_domain_unique` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
