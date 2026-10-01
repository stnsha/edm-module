-- EDM module seed data (odb database), from the SES EDM Frontend Feature Specification.
-- Run after edm_master.sql (or on an existing database): every insert is skipped when
-- the row already exists (matched by key / name among active rows), so the file is
-- safe to run again and never overwrites what has been edited in the app.
--
--   custom fields  spec 6 filter fields, 13.1 warehouse fields and 9 personalisation
--                  variables ({{PointsBalance}} = points_balance: variables match
--                  custom field keys ignoring case and underscores)
--   segments       spec 6 pre-built segments
--   workflows      spec 8 journeys (draft), with their email steps as autoresponders
--   templates      starter layouts using the spec 9 variables
--
-- Not seeded: I/C numbers (spec 13.1 identity) - personal identifiers stay in the
-- warehouse, not in the marketing tool. Test contacts: edm_seed_uat.sql.
-- Datetime columns hold Asia/Kuala_Lumpur local time.

SET NAMES utf8mb4;
SET @now = NOW();

-- ----------------------------------------------------------------------
-- Custom fields
-- ----------------------------------------------------------------------
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'gender', 'Gender', 'select', '["F","M"]', 'Demographic', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'gender' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'birthday', 'Birthday', 'date', NULL, 'Demographic', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'birthday' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'race', 'Race', 'select', '["Malay","Chinese","Indian","Bumiputera Sabah","Bumiputera Sarawak","Others"]', 'Demographic', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'race' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'nationality', 'Nationality', 'select', '["Malaysian","Singaporean","Indonesian","Others"]', 'Demographic', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'nationality' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'preferred_language', 'Preferred Language', 'select', '["Bahasa Malaysia","English","Chinese","Tamil"]', 'Demographic', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'preferred_language' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'state', 'State', 'select', '["Johor","Kedah","Kelantan","Melaka","Negeri Sembilan","Pahang","Perak","Perlis","Pulau Pinang","Sabah","Sarawak","Selangor","Terengganu","W.P. Kuala Lumpur","W.P. Labuan","W.P. Putrajaya"]', 'Location', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'state' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'city', 'City', 'text', NULL, 'Location', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'city' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'create_outlet', 'Create Outlet', 'text', NULL, 'Location', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'create_outlet' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'last_visit_outlet', 'Last Visit Outlet', 'text', NULL, 'Location', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'last_visit_outlet' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'nearest_outlet', 'Nearest Outlet', 'text', NULL, 'Location', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'nearest_outlet' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'membership_type', 'Membership Type', 'select', '["Standard","Silver","Gold","Platinum"]', 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'membership_type' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'member_category', 'Member Category', 'select', '["Personal","Corporate"]', 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'member_category' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'client_sales_category', 'Client Sales Category', 'select', '["Retail","Wholesale","Online","Staff"]', 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'client_sales_category' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'client_interest_group', 'Client Interest Group', 'text', NULL, 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'client_interest_group' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'group_outlet', 'Group / Outlet', 'text', NULL, 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'group_outlet' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'mobile', 'Mobile', 'text', NULL, 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'mobile' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'points_balance', 'Points Balance', 'number', NULL, 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'points_balance' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'points_expiry', 'Points Expiry', 'date', NULL, 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'points_expiry' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'voucher_code', 'Voucher Code', 'text', NULL, 'Membership', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'voucher_code' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'product_category', 'Product Category', 'text', NULL, 'Purchase', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'product_category' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'product_brand', 'Product Brand', 'text', NULL, 'Purchase', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'product_brand' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'purchase_frequency', 'Purchase Frequency', 'number', NULL, 'Purchase', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'purchase_frequency' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'last_purchase_date', 'Last Purchase Date', 'date', NULL, 'Purchase', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'last_purchase_date' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'total_spending', 'Total Spending', 'number', NULL, 'Purchase', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'total_spending' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'engagement_score', 'Engagement Score', 'number', NULL, 'Engagement', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'engagement_score' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'dormant', 'Dormant', 'boolean', NULL, 'Engagement', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'dormant' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'rfm_segment', 'RFM Segment', 'select', '["VIP","Loyal","Potential Loyalist","New","At Risk","Dormant"]', 'RFM / LOFRA', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'rfm_segment' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'rfm_score', 'RFM Score', 'number', NULL, 'RFM / LOFRA', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'rfm_score' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'lofra', 'LOFRA', 'select', '["Loyal","Fresh","Risk","Hopper","Average"]', 'RFM / LOFRA', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'lofra' AND `deleted_at` IS NULL);
-- Fields created before categories existed get theirs (never overwrites a chosen one).
UPDATE `edm_custom_fields` SET `category` = 'Demographic', `updated_at` = @now WHERE `key` = 'gender' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Demographic', `updated_at` = @now WHERE `key` = 'birthday' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Demographic', `updated_at` = @now WHERE `key` = 'race' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Demographic', `updated_at` = @now WHERE `key` = 'nationality' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Demographic', `updated_at` = @now WHERE `key` = 'preferred_language' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Location', `updated_at` = @now WHERE `key` = 'state' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Location', `updated_at` = @now WHERE `key` = 'city' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Location', `updated_at` = @now WHERE `key` = 'create_outlet' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Location', `updated_at` = @now WHERE `key` = 'last_visit_outlet' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Location', `updated_at` = @now WHERE `key` = 'nearest_outlet' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'membership_type' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'member_category' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'client_sales_category' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'client_interest_group' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'group_outlet' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'mobile' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'points_balance' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'points_expiry' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Membership', `updated_at` = @now WHERE `key` = 'voucher_code' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Purchase', `updated_at` = @now WHERE `key` = 'product_category' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Purchase', `updated_at` = @now WHERE `key` = 'product_brand' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Purchase', `updated_at` = @now WHERE `key` = 'purchase_frequency' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Purchase', `updated_at` = @now WHERE `key` = 'last_purchase_date' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Purchase', `updated_at` = @now WHERE `key` = 'total_spending' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Engagement', `updated_at` = @now WHERE `key` = 'engagement_score' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'Engagement', `updated_at` = @now WHERE `key` = 'dormant' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'RFM / LOFRA', `updated_at` = @now WHERE `key` = 'rfm_segment' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'RFM / LOFRA', `updated_at` = @now WHERE `key` = 'rfm_score' AND `category` IS NULL AND `deleted_at` IS NULL;
UPDATE `edm_custom_fields` SET `category` = 'RFM / LOFRA', `updated_at` = @now WHERE `key` = 'lofra' AND `category` IS NULL AND `deleted_at` IS NULL;

-- ----------------------------------------------------------------------
-- Pre-built segments (all lists)
-- ----------------------------------------------------------------------
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'VIP customers', 'RFM segment VIP.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:rfm_segment","op":"is","value":"VIP"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'VIP customers' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Loyal (LOFRA)', 'LOFRA segment Loyal.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:lofra","op":"is","value":"Loyal"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Loyal (LOFRA)' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'At risk', 'RFM At Risk or LOFRA Risk - win-back candidates.', '{"match":"all","groups":[{"match":"any","rules":[{"field":"field:rfm_segment","op":"is","value":"At Risk"},{"field":"field:lofra","op":"is","value":"Risk"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'At risk' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Dormant members', 'Dormant flag set.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:dormant","op":"is_true","value":""}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Dormant members' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Gold and Platinum members', 'Membership type Gold or Platinum.', '{"match":"all","groups":[{"match":"any","rules":[{"field":"field:membership_type","op":"is","value":"Gold"},{"field":"field:membership_type","op":"is","value":"Platinum"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Gold and Platinum members' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'High spenders', 'Total spending above RM 2,000.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:total_spending","op":"gt","value":"2000"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'High spenders' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Recent buyers (30 days)', 'Last purchase in the last 30 days.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:last_purchase_date","op":"in_last_days","value":"30"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Recent buyers (30 days)' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Lapsed buyers (180 days)', 'No purchase for more than 180 days.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:last_purchase_date","op":"older_than_days","value":"180"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Lapsed buyers (180 days)' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'New subscribers (30 days)', 'Subscribed in the last 30 days.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"subscribed_at","op":"in_last_days","value":"30"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'New subscribers (30 days)' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Engaged (opened 30 days)', 'Opened an email in the last 30 days.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"activity:opened","op":"within_days","value":"30"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Engaged (opened 30 days)' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Never opened', 'Has never opened an email.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"activity:opened","op":"never","value":""}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Never opened' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Klang Valley', 'State Selangor, Kuala Lumpur or Putrajaya.', '{"match":"all","groups":[{"match":"any","rules":[{"field":"field:state","op":"is","value":"Selangor"},{"field":"field:state","op":"is","value":"W.P. Kuala Lumpur"},{"field":"field:state","op":"is","value":"W.P. Putrajaya"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Klang Valley' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Young adults (18-35)', 'Age 18 to 35 from Birthday.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:birthday","op":"age_between","value":"18-35"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Young adults (18-35)' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `list_id`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Corporate members', 'Member category Corporate.', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:member_category","op":"is","value":"Corporate"}]}]}', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Corporate members' AND `deleted_at` IS NULL);

-- ----------------------------------------------------------------------
-- Journeys (spec 8) - draft; email steps as autoresponders (workflow_id)
-- ----------------------------------------------------------------------
INSERT INTO `edm_workflows` (`name`, `description`, `trigger`, `status`, `definition`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Welcome journey', 'New member registration: welcome, benefits, product recommendation, reminder.', 'new_member', 1, '{"entry":"New member registration","exit":"Unsubscribed or 14 days after joining","steps":[{"offset_days":0,"type":"email","name":"Welcome"},{"offset_days":3,"type":"email","name":"Benefits"},{"offset_days":7,"type":"email","name":"Product recommendation"},{"offset_days":14,"type":"email","name":"Reminder"}]}', 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_workflows` WHERE `name` = 'Welcome journey' AND `deleted_at` IS NULL);
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Welcome', w.`id`, NULL, 0, 'Welcome to the family, {{FirstName}}!', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Welcome journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Welcome' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Benefits', w.`id`, NULL, 3, 'Your {{MembershipType}} member benefits', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Welcome journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Benefits' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Product recommendation', w.`id`, NULL, 7, 'Picked for you, {{FirstName}}', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Welcome journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Product recommendation' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Reminder', w.`id`, NULL, 14, 'Don''t forget your member perks', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Welcome journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Reminder' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_workflows` (`name`, `description`, `trigger`, `status`, `definition`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Birthday journey', 'Date of birth match: voucher 7 days before, greeting on the day, reminder 7 days after.', 'birthday', 1, '{"entry":"Birthday (DOB) match","exit":"Voucher redeemed or 7 days after the birthday","steps":[{"offset_days":-7,"type":"email","name":"Birthday voucher"},{"offset_days":0,"type":"email","name":"Birthday greeting"},{"offset_days":7,"type":"email","name":"Voucher reminder"}]}', 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_workflows` WHERE `name` = 'Birthday journey' AND `deleted_at` IS NULL);
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Birthday voucher', w.`id`, NULL, -7, 'An early birthday gift for you, {{FirstName}}', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Birthday journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Birthday voucher' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Birthday greeting', w.`id`, NULL, 0, 'Happy birthday, {{FirstName}}!', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Birthday journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Birthday greeting' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Voucher reminder', w.`id`, NULL, 7, 'Your birthday voucher {{VoucherCode}} is still waiting', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Birthday journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Voucher reminder' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_workflows` (`name`, `description`, `trigger`, `status`, `definition`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Re-engagement journey', 'No activity for 90 days: reminder, voucher at 120 days, win-back at 180 days.', 'inactivity', 1, '{"entry":"No purchase or engagement for 90 days","inactive_days":90,"exit":"Purchase or email click","steps":[{"offset_days":90,"type":"email","name":"Reminder"},{"offset_days":120,"type":"email","name":"Comeback voucher"},{"offset_days":180,"type":"email","name":"Win-back"}]}', 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_workflows` WHERE `name` = 'Re-engagement journey' AND `deleted_at` IS NULL);
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Reminder', w.`id`, NULL, 90, 'We miss you, {{FirstName}}', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Re-engagement journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Reminder' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Comeback voucher', w.`id`, NULL, 120, 'A little something to welcome you back', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Re-engagement journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Comeback voucher' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Win-back', w.`id`, NULL, 180, 'One last offer before your points expire', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Re-engagement journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Win-back' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_workflows` (`name`, `description`, `trigger`, `status`, `definition`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Cart abandonment journey', 'Cart inactive 7 days: reminder, then an incentive at 14 days.', 'cart_abandonment', 1, '{"entry":"Cart inactive for 7 days","exit":"Purchase","steps":[{"offset_days":7,"type":"email","name":"Cart reminder"},{"offset_days":14,"type":"email","name":"Cart incentive"}]}', 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_workflows` WHERE `name` = 'Cart abandonment journey' AND `deleted_at` IS NULL);
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Cart reminder', w.`id`, NULL, 7, 'You left something in your cart', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Cart abandonment journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Cart reminder' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `list_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT 'Cart incentive', w.`id`, NULL, 14, 'Complete your order and save', 1, @now, @now FROM `edm_workflows` w
WHERE w.`name` = 'Cart abandonment journey' AND w.`deleted_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `edm_autoresponders` a WHERE a.`workflow_id` = w.`id` AND a.`name` = 'Cart incentive' AND a.`deleted_at` IS NULL)
LIMIT 1;
INSERT INTO `edm_workflows` (`name`, `description`, `trigger`, `status`, `definition`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Bounce recovery', 'Soft bounce detected: wait 3 days, retry once, mark inactive if it fails again.', 'soft_bounce', 1, '{"entry":"Soft bounce detected","steps":[{"offset_days":3,"type":"retry","name":"Retry the bounced email"},{"offset_days":3,"type":"mark_inactive","name":"Mark inactive if the retry fails"}],"exit":"Delivered on retry, or marked inactive"}', 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_workflows` WHERE `name` = 'Bounce recovery' AND `deleted_at` IS NULL);

-- ----------------------------------------------------------------------
-- Starter templates (HTML; open in the template editor as an Html block)
-- ----------------------------------------------------------------------
INSERT INTO `edm_templates` (`name`, `category`, `html`, `editor_json`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Basic newsletter', 'Newsletter', '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#222;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;"><tr><td align="center" style="padding:24px 12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;"><tr><td style="padding:28px 24px 8px;font-size:22px;font-weight:bold;">Hi {{FirstName}},</td></tr><tr><td style="padding:8px 24px;font-size:15px;line-height:1.6;">Here is what is new this month. Replace this text with your story.</td></tr><tr><td style="padding:8px 24px;font-size:15px;line-height:1.6;">You have <strong>{{PointsBalance}}</strong> points, valid until {{PointsExpiry}}.</td></tr><tr><td style="padding:16px 24px 28px;"><a href="https://www.example.com/?utm_source=edm&amp;utm_medium=email" style="display:inline-block;padding:12px 24px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Shop now</a></td></tr><tr><td style="padding:0 24px 24px;font-size:13px;color:#555;">Visit us at {{NearestOutlet}}.</td></tr><tr><td style="padding:16px 24px;font-size:12px;color:#888;text-align:center;border-top:1px solid #eee;">You are receiving this as a {{MembershipType}} member. <a href="{{UnsubscribeLink}}" style="color:#888;">Unsubscribe</a></td></tr></table></td></tr></table></body></html>', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_templates` WHERE `name` = 'Basic newsletter' AND `deleted_at` IS NULL);
INSERT INTO `edm_templates` (`name`, `category`, `html`, `editor_json`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Birthday voucher', 'Birthday', '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#222;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;"><tr><td align="center" style="padding:24px 12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;"><tr><td style="padding:28px 24px 8px;font-size:24px;font-weight:bold;text-align:center;">Happy birthday, {{FirstName}}!</td></tr><tr><td style="padding:8px 24px;font-size:15px;line-height:1.6;text-align:center;">Celebrate with a gift from us. Use this code at checkout or show it at {{NearestOutlet}}:</td></tr><tr><td style="padding:12px 24px;text-align:center;"><span style="display:inline-block;padding:12px 28px;border:2px dashed #0d6efd;font-size:22px;font-weight:bold;letter-spacing:2px;">{{VoucherCode}}</span></td></tr><tr><td style="padding:16px 24px 28px;text-align:center;"><a href="https://www.example.com/birthday?utm_source=edm&amp;utm_medium=email&amp;utm_campaign=birthday" style="display:inline-block;padding:12px 24px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Redeem now</a></td></tr><tr><td style="padding:16px 24px;font-size:12px;color:#888;text-align:center;border-top:1px solid #eee;">You are receiving this as a {{MembershipType}} member. <a href="{{UnsubscribeLink}}" style="color:#888;">Unsubscribe</a></td></tr></table></td></tr></table></body></html>', NULL, 'System', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_templates` WHERE `name` = 'Birthday voucher' AND `deleted_at` IS NULL);
