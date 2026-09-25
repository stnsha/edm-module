-- Table `edm_workflows` (EDM module, odb database).
-- Automation workflows. status: 1=draft, 2=active, 3=paused.
-- Drops and recreates the table (development: existing rows are lost).
-- Datetime columns hold Asia/Kuala_Lumpur local time. deleted_at = soft delete (NULL = active).

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_workflows`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `edm_workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trigger` enum('new_member','birthday','inactivity','cart_abandonment','soft_bounce','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `definition` json DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
