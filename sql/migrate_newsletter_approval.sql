-- EDM module - newsletter approval in the Email creator (steps 5 and 6)
-- (a fresh install uses edm_master.sql).
--
-- Adds to edm_campaigns: submitted_by, submitted_by_name, submitted_department,
-- submitted_at (who submitted it for review and their department) and
-- reviewed_by, reviewed_by_name, reviewed_at, review_comment (the approval
-- decision; review_comment = rejection reason). Status 2 is reused as
-- "pending approval". Every step checks the current schema, so running the
-- file again changes nothing. Back up first (DEPLOY.md section 1).

SET NAMES utf8mb4;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'submitted_by') = 0,
    'ALTER TABLE `edm_campaigns`
        ADD COLUMN `submitted_by` int unsigned DEFAULT NULL AFTER `requested_by_name`,
        ADD COLUMN `submitted_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `submitted_by`,
        ADD COLUMN `submitted_department` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `submitted_by_name`,
        ADD COLUMN `submitted_at` datetime NULL DEFAULT NULL AFTER `submitted_department`',
    'SELECT ''edm_campaigns.submitted_* already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_campaigns' AND COLUMN_NAME = 'reviewed_by') = 0,
    'ALTER TABLE `edm_campaigns`
        ADD COLUMN `reviewed_by` int unsigned DEFAULT NULL AFTER `submitted_at`,
        ADD COLUMN `reviewed_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `reviewed_by`,
        ADD COLUMN `reviewed_at` datetime NULL DEFAULT NULL AFTER `reviewed_by_name`,
        ADD COLUMN `review_comment` text COLLATE utf8mb4_unicode_ci AFTER `reviewed_at`',
    'SELECT ''edm_campaigns.reviewed_* already there''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
