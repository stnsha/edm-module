-- Table `edm_sending_domains` (EDM module, odb database).
-- Sending domains. dkim/spf/dmarc_status: 1=pending, 2=verified, 3=failed.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_sending_domains`;
SET FOREIGN_KEY_CHECKS = 1;

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
