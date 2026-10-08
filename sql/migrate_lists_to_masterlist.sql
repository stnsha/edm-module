-- EDM module - upgrade a database that still has contact lists to the single
-- contact masterlist (edm_contacts). For DEPLOY.md section 6.2 Path B only;
-- a fresh install uses edm_master.sql instead.
--
-- 1. Creates edm_contacts and copies every active edm_list_members row into it,
--    one row per email address (lower-cased, trimmed): name,
--    custom fields, source and subscribed_at come from the oldest row for that
--    address; status is the most restrictive one found (3 bounced >
--    2 unsubscribed > 1 subscribed), so an unsubscribe is never lost.
-- 2. Drops list_id from edm_segments and edm_autoresponders (with their foreign
--    keys) and list_id / all_lists from edm_campaigns. Newsletters then go to
--    every subscribed contact, narrowed by their segment.
-- 3. Drops edm_list_members and edm_lists.
--
-- Back up first (DEPLOY.md section 1). Every step checks the current schema,
-- so running the file again changes nothing. Step 1 copies only when
-- edm_contacts is empty and edm_list_members still exists.

SET NAMES utf8mb4;

-- 1. edm_contacts --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edm_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fields` json DEFAULT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `subscribed_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_contacts_email_index` (`email`),
  KEY `edm_contacts_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @copy = (SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_list_members') > 0
        AND (SELECT COUNT(*) FROM `edm_contacts`) = 0;
SET @s = IF(@copy,
    'INSERT INTO `edm_contacts` (`email`, `name`, `fields`, `source`, `status`, `subscribed_at`, `created_at`, `updated_at`)
     SELECT LOWER(TRIM(m.`email`)), m.`name`, m.`fields`, m.`source`, x.`status`, m.`subscribed_at`, m.`created_at`, NOW()
       FROM `edm_list_members` m
       JOIN (SELECT MIN(`id`) AS id, MAX(`status`) AS status FROM `edm_list_members`
              WHERE `deleted_at` IS NULL AND TRIM(`email`) <> ''''
              GROUP BY LOWER(TRIM(`email`))) x ON x.`id` = m.`id`
      ORDER BY m.`id`',
    'SELECT ''edm_contacts: nothing to copy''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. list columns --------------------------------------------------------

-- edm_segments.list_id
SET @s = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_segments' AND CONSTRAINT_NAME = 'edm_segments_list_id_foreign') > 0,
    'ALTER TABLE `edm_segments` DROP FOREIGN KEY `edm_segments_list_id_foreign`',
    'SELECT ''edm_segments: no list foreign key''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_segments' AND INDEX_NAME = 'edm_segments_list_id_foreign') > 0,
    'ALTER TABLE `edm_segments` DROP INDEX `edm_segments_list_id_foreign`',
    'SELECT ''edm_segments: no list index''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_segments' AND COLUMN_NAME = 'list_id') > 0,
    'ALTER TABLE `edm_segments` DROP COLUMN `list_id`',
    'SELECT ''edm_segments.list_id already gone''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- edm_autoresponders.list_id
SET @s = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_autoresponders' AND CONSTRAINT_NAME = 'edm_autoresponders_list_id_foreign') > 0,
    'ALTER TABLE `edm_autoresponders` DROP FOREIGN KEY `edm_autoresponders_list_id_foreign`',
    'SELECT ''edm_autoresponders: no list foreign key''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_autoresponders' AND INDEX_NAME = 'edm_autoresponders_list_id_foreign') > 0,
    'ALTER TABLE `edm_autoresponders` DROP INDEX `edm_autoresponders_list_id_foreign`',
    'SELECT ''edm_autoresponders: no list index''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_autoresponders' AND COLUMN_NAME = 'list_id') > 0,
    'ALTER TABLE `edm_autoresponders` DROP COLUMN `list_id`',
    'SELECT ''edm_autoresponders.list_id already gone''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- edm_campaigns.list_id, edm_campaigns.all_lists
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'all_lists') > 0,
    'ALTER TABLE `edm_campaigns` DROP COLUMN `all_lists`',
    'SELECT ''edm_campaigns.all_lists already gone''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'list_id') > 0,
    'ALTER TABLE `edm_campaigns` DROP COLUMN `list_id`',
    'SELECT ''edm_campaigns.list_id already gone''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. list tables ---------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_list_members`;
DROP TABLE IF EXISTS `edm_lists`;
SET FOREIGN_KEY_CHECKS = 1;
