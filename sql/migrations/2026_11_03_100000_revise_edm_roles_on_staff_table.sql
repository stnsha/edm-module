-- Migration 2026_11_03_100000_revise_edm_roles_on_staff_table
-- EDM module - revised roles (staff.edm):
--   0 = normal user        own newsletters, own contacts, own calendar / dashboard
--   1 = superadmin         everything
--   2 = business unit head newsletters, contacts, calendar and dashboard of their
--                          department (staff.department); approves its newsletters
-- Roles 3 (bpt team) and 4 (management) are removed; staff who had them become
-- normal users. Every logged-in odb staff can open EDM (0 included).
-- Safe to run again. Back up first (DEPLOY.md section 1).

SET NAMES utf8mb4;

UPDATE `staff` SET `edm` = 0 WHERE `edm` IN (3, 4);

ALTER TABLE `staff`
    MODIFY COLUMN `edm` INT NOT NULL DEFAULT 0
    COMMENT '0 = normal user, 1 = superadmin, 2 = business unit head';
