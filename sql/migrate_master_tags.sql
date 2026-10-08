-- EDM module - newsletter tags (DEPLOY.md section 6.2 Path B; a fresh install
-- uses edm_master.sql). Creates edm_master_tags and adds edm_campaigns.tag_ids
-- (JSON list of tag ids). Safe to run again.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `edm_master_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_master_tags_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'tag_ids') = 0,
    'ALTER TABLE `edm_campaigns` ADD COLUMN `tag_ids` json DEFAULT NULL AFTER `preheader`',
    'SELECT ''edm_campaigns.tag_ids already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
