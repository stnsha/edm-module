-- Migration 2026_11_03_100200_add_owner_columns_to_edm_campaigns_table
-- EDM module - newsletter ownership (Services/AccessScope):
--   owner_role     creator's role at creation (0 normal, 1 superadmin, 2 BU head);
--                  default 1 = superadmin. Decides which contacts the newsletter
--                  can reach (0 = creator's own, 2 = the department's, 1 = all)
--   department_id  creator's staff.department; NULL = superadmin
-- Every existing newsletter was handled by superadmin, so the defaults keep
-- them superadmin-owned. Checks the schema first: safe to run again.

SET NAMES utf8mb4;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'owner_role') = 0,
    'ALTER TABLE `edm_campaigns`
        ADD COLUMN `owner_role` tinyint unsigned NOT NULL DEFAULT ''1'' COMMENT ''creator role: 0 normal, 1 superadmin, 2 BU head'' AFTER `requested_by_name`,
        ADD COLUMN `department_id` int unsigned DEFAULT NULL COMMENT ''creator staff.department; NULL = superadmin'' AFTER `owner_role`,
        ADD KEY `edm_campaigns_requested_by_index` (`requested_by`),
        ADD KEY `edm_campaigns_department_id_index` (`department_id`)',
    'SELECT ''edm_campaigns.owner_role already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
