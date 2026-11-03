-- Migration 2026_11_03_100100_add_owner_columns_to_edm_contacts_table
-- EDM module - contact ownership (Services/AccessScope):
--   owner_id       staff id of the owner; NULL = superadmin
--   department_id  owner's staff.department; NULL = superadmin
-- Every existing contact was handled by superadmin, so the NULL defaults keep
-- them superadmin-owned. Checks the schema first: safe to run again.

SET NAMES utf8mb4;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_contacts' AND COLUMN_NAME = 'owner_id') = 0,
    'ALTER TABLE `edm_contacts`
        ADD COLUMN `owner_id` int unsigned DEFAULT NULL COMMENT ''staff id; NULL = superadmin'' AFTER `subscribed_at`,
        ADD COLUMN `department_id` int unsigned DEFAULT NULL COMMENT ''owner staff.department; NULL = superadmin'' AFTER `owner_id`,
        ADD KEY `edm_contacts_owner_id_index` (`owner_id`),
        ADD KEY `edm_contacts_department_id_index` (`department_id`)',
    'SELECT ''edm_contacts.owner_id already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
