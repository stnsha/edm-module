-- EDM module - upgrade edm_workflows: the email a workflow sends
-- (DEPLOY.md section 6.2 Path B; a fresh install uses edm_master.sql).
--
-- content_type ('campaign' = a newsletter | 'template') + content_id: chosen on
-- Automation > Workflows (Email content). trigger becomes varchar(50) so it
-- can hold custom triggers, kept in the new edm_triggers table ("+ Create new
-- trigger"). Safe to run again.

SET NAMES utf8mb4;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_workflows' AND COLUMN_NAME = 'content_type') = 0,
    'ALTER TABLE `edm_workflows` ADD COLUMN `content_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `definition`',
    'SELECT ''edm_workflows.content_type already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_workflows' AND COLUMN_NAME = 'content_id') = 0,
    'ALTER TABLE `edm_workflows` ADD COLUMN `content_id` bigint unsigned DEFAULT NULL AFTER `content_type`',
    'SELECT ''edm_workflows.content_id already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- trigger: enum -> varchar(50) (custom triggers); MODIFY keeps the values.
ALTER TABLE `edm_workflows` MODIFY `trigger` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS `edm_triggers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `edm_triggers_key_index` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When to send: trigger_type ('event' | 'schedule') + timing JSON; trigger is
-- empty for a scheduled workflow.
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_workflows' AND COLUMN_NAME = 'trigger_type') = 0,
    'ALTER TABLE `edm_workflows` ADD COLUMN `trigger_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''event'' AFTER `description`',
    'SELECT ''edm_workflows.trigger_type already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_workflows' AND COLUMN_NAME = 'timing') = 0,
    'ALTER TABLE `edm_workflows` ADD COLUMN `timing` json DEFAULT NULL AFTER `trigger`',
    'SELECT ''edm_workflows.timing already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE `edm_workflows` MODIFY `trigger` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
