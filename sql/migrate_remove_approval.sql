-- EDM module - remove the Approval Centre and Automated QA
-- (a fresh install uses edm_master.sql, which no longer has these tables).
--
-- Approval now happens in the Email creator (steps 5 and 6; see
-- migrate_newsletter_approval.sql). This file:
--   1. moves every newsletter in a removed review status (2 pending
--      submission, 3 under BPT review, 4 content revision, 5 audience
--      validation) back to Draft (1), and the same for archived_from - only
--      while edm_approvals still exists, i.e. on the first run: status 2 is
--      reused afterwards as "pending approval" and must not be reset;
--   2. drops edm_approval_decisions, edm_approval_logs, edm_approval_files,
--      edm_approvals, edm_campaign_qa and edm_revisions with their rows.
-- Running it again changes nothing. Not reversible: back up first
-- (DEPLOY.md section 1). Uploaded review artwork in uploads/approvals/ is not
-- removed by this script - delete that folder by hand once it is no longer
-- needed.

SET NAMES utf8mb4;

SET @old = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edm_approvals');

SET @s = IF(@old > 0,
    'UPDATE `edm_campaigns` SET `status` = 1 WHERE `status` IN (2, 3, 4, 5)',
    'SELECT ''old approval tables already removed - statuses left alone''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF(@old > 0,
    'UPDATE `edm_campaigns` SET `archived_from` = 1 WHERE `archived_from` IN (2, 3, 4, 5)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `edm_approval_decisions`;
DROP TABLE IF EXISTS `edm_approval_logs`;
DROP TABLE IF EXISTS `edm_approval_files`;
DROP TABLE IF EXISTS `edm_approvals`;
DROP TABLE IF EXISTS `edm_campaign_qa`;
DROP TABLE IF EXISTS `edm_revisions`;
SET FOREIGN_KEY_CHECKS = 1;
