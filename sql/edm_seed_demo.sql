-- EDM module DEMO data (odb database) - a walk-through dataset that fills every
-- page with every scenario. LOCAL / UAT ONLY - never load on production.
--
-- Run order (one session each, e.g. HeidiSQL "Run" or `mysql odb < file`):
--   1. edm_master.sql       (only when starting from an empty schema)
--   2. edm_seed.sql         custom fields, segments, journeys, templates
--   3. edm_seed_uat.sql     40 SES mailbox-simulator contacts
--   4. edm_seed_demo.sql    this file
--
-- Safe to run again: every block is skipped when its rows already exist
-- (contacts by email, newsletters by name, send log per newsletter, SES
-- events per message + type, suppressions by email). Values are derived from
-- CRC32 of ids, not RAND(), so a re-run produces the same picture. Dates are
-- relative to the day it is run (newsletters sent over the last ~12 months,
-- scheduled ones in the future).
--
-- Addresses: 600 generated contacts use the reserved .test top-level domain
-- (gmail.test, yahoo.test, ...) so nothing here can ever reach a real inbox;
-- they only appear in already-sent history. Every newsletter the send queue
-- could still pick up (Sending, Scheduled) targets the SES mailbox simulator
-- contacts of edm_seed_uat.sql only. Senders use alpro.test, so a real send
-- from them is refused by SES - re-point a newsletter to a verified sender
-- before testing a real send.
--
-- Staff: newsletters are owned by real odb staff ids (edit the @ variables
-- below to match your staff table). @me owns the newsletters a Department PIC
-- sees when you switch the dev role to "2: dept pic".
--
-- Scenarios covered
--   Newsletters  Draft (blank / complete / rejected with comment / missing
--                sender + subject / send date already passed), Pending approval
--                (yours, someone else's, send time passed), Scheduled (2, one
--                with exclude segment + reply-to), Sending (part-sent),
--                Completed (20, Nov last year to yesterday: high / low
--                engagement, bounce spike, complaints, unsubscribes, opens or
--                clicks tracking off, UTM off, Send now, skipped + failed
--                recipients, sent with no SES events = webhook notice),
--                Archived (from Completed with report, from Draft), deleted
--                (shows as "Deleted newsletter" in Messages sent)
--   Unsubscribe  reasons from the unsubscribe page (about half none = one-click)
--   Contacts     subscribed / unsubscribed / bounced, sparse fields, birthdays
--                this month, new in last 30 days, dormant, soft-deleted,
--                multiselect interests
--   Suppression  hard_bounce + spam_complaint + unsubscribed (from sends),
--                manual, inactive, soft_bounce, address not in contacts,
--                one restored (soft-deleted)
--   Settings     senders verified / pending / failed / deleted, default
--                sender, sending domains verified / pending / failed /
--                inactive, general + integration keys
--   Other        master tags, templates, Files library assets, custom trigger,
--                workflows (active / paused / draft, event + schedule kinds,
--                newsletter + template content), autoresponders

SET NAMES utf8mb4;
SET @now = NOW();
SET @d0 = CURDATE();

-- Staff (odb `staff`.id). @boss approves (EDM role 1).
SET @me   = 5609;
SET @bpt  = 835;
SET @di   = 897;
SET @eng  = 775;
SET @boss = 1;

SET @me_name   = (SELECT `nama_staff` FROM `staff` WHERE `id` = @me);
SET @boss_name = (SELECT `nama_staff` FROM `staff` WHERE `id` = @boss);

-- ----------------------------------------------------------------------
-- Custom fields: a multiselect (segment includes / merge tag join) and an
-- inactive field (hidden from pickers, shown Inactive on Custom fields).
-- ----------------------------------------------------------------------
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'interests', 'Interests', 'multiselect', '["Vitamins","Skincare","Baby care","Diabetes care","Eye care","Elderly care"]', 'Purchase', 1, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'interests' AND `deleted_at` IS NULL);
INSERT INTO `edm_custom_fields` (`key`, `label`, `type`, `options`, `category`, `is_active`, `created_at`, `updated_at`)
SELECT 'old_loyalty_tier', 'Old Loyalty Tier', 'text', NULL, 'Membership', 0, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_custom_fields` WHERE `key` = 'old_loyalty_tier' AND `deleted_at` IS NULL);

INSERT INTO `edm_segments` (`name`, `description`, `definition`, `created_by`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Vitamin lovers', 'Interests include Vitamins (multiselect).', '{"match":"all","groups":[{"match":"all","rules":[{"field":"field:interests","op":"includes","value":"Vitamins"}]}]}', @me, @me_name, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Vitamin lovers' AND `deleted_at` IS NULL);
INSERT INTO `edm_segments` (`name`, `description`, `definition`, `created_by`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'Birthday this month (Klang Valley)', 'Two groups: birthday in this month AND state in Klang Valley.', CONCAT('{"match":"all","groups":[{"match":"all","rules":[{"field":"field:birthday","op":"in_month","value":"', MONTH(@d0), '"}]},{"match":"any","rules":[{"field":"field:state","op":"is","value":"Selangor"},{"field":"field:state","op":"is","value":"W.P. Kuala Lumpur"}]}]}'), @me, @me_name, @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_segments` WHERE `name` = 'Birthday this month (Klang Valley)' AND `deleted_at` IS NULL);

-- ----------------------------------------------------------------------
-- Master tags (Email creator step 1 chips)
-- ----------------------------------------------------------------------
INSERT INTO `edm_master_tags` (`name`, `created_by`, `created_by_name`, `created_at`, `updated_at`)
SELECT t.`name`, @me, @me_name, @now, @now
  FROM (SELECT 'Promotion' AS `name` UNION ALL SELECT 'Health tips' UNION ALL SELECT 'Product launch'
        UNION ALL SELECT 'Event' UNION ALL SELECT 'Partner brand' UNION ALL SELECT 'Seasonal'
        UNION ALL SELECT 'Internal test') t
 WHERE NOT EXISTS (SELECT 1 FROM `edm_master_tags` m WHERE m.`name` = t.`name` AND m.`deleted_at` IS NULL);

-- ----------------------------------------------------------------------
-- Senders (Settings > Senders): verified (one default), pending, failed,
-- deleted. Sending domains: verified, pending, failed + inactive.
-- ----------------------------------------------------------------------
INSERT INTO `edm_senders` (`email`, `from_name`, `status`, `verified_at`, `is_default`, `created_by`, `created_by_name`, `created_at`, `updated_at`, `deleted_at`)
SELECT s.`email`, s.`from_name`, s.`status`, s.`verified_at`,
       IF(s.`want_default` = 1 AND NOT EXISTS (SELECT 1 FROM `edm_senders` d WHERE d.`is_default` = 1 AND d.`deleted_at` IS NULL), 1, 0),
       @me, @me_name, @now - INTERVAL 400 DAY, @now, s.`deleted_at`
  FROM (SELECT 'newsletter@alpro.test' AS `email`, 'Alpro Pharmacy' AS `from_name`, 2 AS `status`, @now - INTERVAL 399 DAY AS `verified_at`, 1 AS `want_default`, NULL AS `deleted_at`
        UNION ALL SELECT 'promo@alpro.test', 'Alpro Promotions', 2, @now - INTERVAL 380 DAY, 0, NULL
        UNION ALL SELECT 'bpt@alpro.test', 'Alpro BPT', 2, @now - INTERVAL 370 DAY, 0, NULL
        UNION ALL SELECT 'care@alpro.test', 'Alpro Customer Care', 2, @now - INTERVAL 360 DAY, 0, NULL
        UNION ALL SELECT 'events@alpro.test', 'Alpro Events', 1, NULL, 0, NULL
        UNION ALL SELECT 'news@alpro-legacy.test', 'Alpro (old domain)', 3, NULL, 0, NULL
        UNION ALL SELECT 'retired@alpro.test', 'Alpro Retired Sender', 2, @now - INTERVAL 390 DAY, 0, @now - INTERVAL 30 DAY) s
 WHERE NOT EXISTS (SELECT 1 FROM `edm_senders` x WHERE x.`email` = s.`email`);

INSERT INTO `edm_sending_domains` (`domain`, `dkim_status`, `spf_status`, `dmarc_status`, `is_active`, `created_at`, `updated_at`)
SELECT d.`domain`, d.`dkim`, d.`spf`, d.`dmarc`, d.`active`, @now - INTERVAL 400 DAY, @now
  FROM (SELECT 'alpro.test' AS `domain`, 2 AS `dkim`, 2 AS `spf`, 2 AS `dmarc`, 1 AS `active`
        UNION ALL SELECT 'promo.alpro.test', 2, 1, 1, 1
        UNION ALL SELECT 'alpro-legacy.test', 3, 3, 1, 0) d
 WHERE NOT EXISTS (SELECT 1 FROM `edm_sending_domains` x WHERE x.`domain` = d.`domain` AND x.`deleted_at` IS NULL);

INSERT INTO `edm_settings` (`group`, `key`, `value`, `label`, `created_at`, `updated_at`)
SELECT s.`group`, s.`key`, s.`value`, s.`label`, @now, @now
  FROM (SELECT 'general' AS `group`, 'company_name' AS `key`, 'Alpro Pharmacy' AS `value`, 'Company name' AS `label`
        UNION ALL SELECT 'general', 'footer_address', 'Alpro Pharmacy HQ, Kuala Lumpur (demo address)', 'Footer address'
        UNION ALL SELECT 'general', 'support_email', 'care@alpro.test', 'Support email'
        UNION ALL SELECT 'integrations', 'crm_webhook_url', 'https://crm.alpro.test/hooks/edm', 'CRM webhook (demo)'
        UNION ALL SELECT 'integrations', 'warehouse_sync', 'nightly 02:00', 'Customer Data Warehouse sync') s
 WHERE NOT EXISTS (SELECT 1 FROM `edm_settings` x WHERE x.`group` = s.`group` AND x.`key` = s.`key` AND x.`deleted_at` IS NULL);

-- ----------------------------------------------------------------------
-- Contacts: 600 generated (.test domains). n 599-600 are soft-deleted,
-- every 25th has sparse fields, every 15th has a birthday this month,
-- every 20th subscribed in the last 30 days, every 97th unsubscribed long ago.
-- Unsubscribed / bounced from sends are set further down.
-- ----------------------------------------------------------------------
INSERT INTO `edm_contacts` (`email`, `name`, `fields`, `source`, `status`, `subscribed_at`, `created_at`, `updated_at`, `deleted_at`)
SELECT g.`email`, g.`name`, g.`fields`, g.`source`, g.`status`, g.`subscribed_at`, g.`subscribed_at`, @now, g.`deleted_at`
  FROM (
    WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 600)
    SELECT
        LOWER(CONCAT(REPLACE(p.fn, ' ', ''), '.', REPLACE(p.ln, ' ', ''), p.n, '@', p.dom)) AS `email`,
        CONCAT(p.fn, ' ', p.ln) AS `name`,
        IF(p.n % 25 = 0,
            JSON_OBJECT('gender', ELT(1 + p.n % 2, 'F', 'M'), 'state', p.state),
            JSON_OBJECT(
                'gender', ELT(1 + p.n % 2, 'F', 'M'),
                'birthday', IF(p.n % 15 = 0,
                    CONCAT(1970 + p.n % 35, '-', DATE_FORMAT(@d0, '%m'), '-', LPAD(1 + p.n % 28, 2, '0')),
                    DATE_FORMAT(@d0 - INTERVAL (18 * 365 + (p.n * 97) % (50 * 365)) DAY, '%Y-%m-%d')),
                'race', ELT(1 + p.n % 6, 'Malay', 'Chinese', 'Indian', 'Bumiputera Sabah', 'Bumiputera Sarawak', 'Others'),
                'nationality', CASE WHEN p.n % 20 < 17 THEN 'Malaysian' WHEN p.n % 20 = 17 THEN 'Singaporean' WHEN p.n % 20 = 18 THEN 'Indonesian' ELSE 'Others' END,
                'preferred_language', ELT(1 + p.n % 4, 'Bahasa Malaysia', 'English', 'Chinese', 'Tamil'),
                'state', p.state,
                'city', p.city,
                'create_outlet', CONCAT('Alpro ', p.city),
                'last_visit_outlet', CONCAT('Alpro ', ELT(1 + (p.n * 5) % 6, 'Cheras', 'Klang', 'Petaling Jaya', 'Ipoh', 'Johor Bahru', 'Online')),
                'nearest_outlet', CONCAT('Alpro ', p.city),
                'membership_type', CASE WHEN p.n % 10 < 5 THEN 'Standard' WHEN p.n % 10 < 8 THEN 'Silver' WHEN p.n % 10 = 8 THEN 'Gold' ELSE 'Platinum' END,
                'member_category', IF(p.n % 12 = 0, 'Corporate', 'Personal'),
                'client_sales_category', ELT(1 + p.n % 4, 'Retail', 'Wholesale', 'Online', 'Staff'),
                'client_interest_group', ELT(1 + p.n % 4, 'Mother & baby', 'Senior care', 'Beauty', 'Chronic care'),
                'group_outlet', CONCAT('Alpro ', p.city),
                'mobile', CONCAT('01', LPAD(p.n, 8, '0')),
                'points_balance', CAST((p.n * 53) % 5000 AS CHAR),
                'points_expiry', DATE_FORMAT(@d0 + INTERVAL ((p.n * 11) % 365) DAY, '%Y-%m-%d'),
                'voucher_code', CONCAT('DEMO-', LPAD(p.n, 4, '0')),
                'product_category', ELT(1 + p.n % 6, 'Vitamins', 'Skincare', 'Baby care', 'Diabetes care', 'Eye care', 'Elderly care'),
                'product_brand', ELT(1 + p.n % 6, 'Blackmores', 'Cetaphil', 'Pigeon', 'Abbott', 'Bausch + Lomb', 'Ensure'),
                'purchase_frequency', CAST(p.n % 24 AS CHAR),
                'last_purchase_date', DATE_FORMAT(@d0 - INTERVAL ((p.n * 7) % 400) DAY, '%Y-%m-%d'),
                'total_spending', CAST((p.n * 137) % 6000 AS CHAR),
                'engagement_score', CAST((p.n * 31) % 101 AS CHAR),
                'dormant', IF((p.n * 7) % 400 > 300, '1', '0'),
                'rfm_segment', ELT(1 + p.n % 6, 'VIP', 'Loyal', 'Potential Loyalist', 'New', 'At Risk', 'Dormant'),
                'rfm_score', CAST(3 + p.n % 13 AS CHAR),
                'lofra', ELT(1 + p.n % 5, 'Loyal', 'Fresh', 'Risk', 'Hopper', 'Average'),
                'interests', JSON_ARRAY(
                    ELT(1 + p.n % 6, 'Vitamins', 'Skincare', 'Baby care', 'Diabetes care', 'Eye care', 'Elderly care'),
                    ELT(1 + (p.n + 2) % 6, 'Vitamins', 'Skincare', 'Baby care', 'Diabetes care', 'Eye care', 'Elderly care'))
            )) AS `fields`,
        ELT(1 + p.n % 4, 'import', 'import', 'website', 'manual') AS `source`,
        IF(p.n % 97 = 0, 2, 1) AS `status`,
        IF(p.n % 20 = 0,
            TIMESTAMP(@d0 - INTERVAL (p.n % 30) DAY, '09:15:00'),
            TIMESTAMP(@d0 - INTERVAL (400 + (p.n * 13) % 400) DAY, '09:15:00')) AS `subscribed_at`,
        IF(p.n >= 599, @now - INTERVAL 10 DAY, NULL) AS `deleted_at`
      FROM (
        SELECT n,
               ELT(1 + n % 20, 'Aisyah', 'Muhammad', 'Nurul', 'Siti', 'Ahmad', 'Wei Ling', 'Jun Hao', 'Mei Ling', 'Kok Wai', 'Hui Min',
                   'Priya', 'Kavitha', 'Ravi', 'Arjun', 'Farah', 'Hafiz', 'Amirah', 'Daniel', 'Grace', 'Jason') AS fn,
               ELT(1 + (n * 7) % 20, 'Abdullah', 'Rahman', 'Ismail', 'Hassan', 'Tan', 'Lim', 'Lee', 'Wong', 'Ng', 'Chong',
                   'Raj', 'Kumar', 'Subramaniam', 'Nair', 'Yusof', 'Ibrahim', 'Goh', 'Teo', 'Ong', 'Bakar') AS ln,
               CASE WHEN (n * 37) % 100 < 55 THEN 'gmail.test' WHEN (n * 37) % 100 < 70 THEN 'yahoo.test'
                    WHEN (n * 37) % 100 < 80 THEN 'hotmail.test' WHEN (n * 37) % 100 < 85 THEN 'icloud.test'
                    WHEN (n * 37) % 100 < 89 THEN 'outlook.test' WHEN (n * 37) % 100 < 92 THEN 'live.test'
                    WHEN (n * 37) % 100 < 94 THEN 'ymail.test' WHEN (n * 37) % 100 < 96 THEN 'yahoo.com.my.test'
                    ELSE 'alpro-staff.test' END AS dom,
               ELT(1 + (n * 3) % 16, 'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis',
                   'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu', 'W.P. Kuala Lumpur', 'W.P. Labuan', 'W.P. Putrajaya') AS state,
               ELT(1 + (n * 3) % 16, 'Johor Bahru', 'Alor Setar', 'Kota Bharu', 'Melaka', 'Seremban', 'Kuantan', 'Ipoh', 'Kangar',
                   'George Town', 'Kota Kinabalu', 'Kuching', 'Shah Alam', 'Kuala Terengganu', 'Kuala Lumpur', 'Labuan', 'Putrajaya') AS city
          FROM seq
      ) p
  ) g
 WHERE NOT EXISTS (SELECT 1 FROM `edm_contacts` x WHERE x.`email` = g.`email`);

-- ----------------------------------------------------------------------
-- Templates (Template Library): every template is an EmailBuilder.js block
-- tree (editor_json) plus the HTML the editor renders from it, so the
-- template editor and the Email creator open them as editable blocks.
-- Starter templates already loaded without a design (editor_json NULL, an
-- older edm_seed.sql) get theirs here too. One template is deleted.
-- ----------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `demo_tpl`;
CREATE TEMPORARY TABLE `demo_tpl` (
  `name` varchar(255) NOT NULL, `category` varchar(255) NULL, `html` longtext NOT NULL,
  `editor_json` longtext NOT NULL, `age` int NOT NULL, `deleted_at` datetime NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `demo_tpl` (`name`, `category`, `html`, `editor_json`, `age`, `deleted_at`) VALUES
('Basic newsletter', 'Newsletter', '<!DOCTYPE html><html><body><div style="background-color:#F4F6F8;color:#262626;font-family:&quot;Helvetica Neue&quot;, &quot;Arial Nova&quot;, &quot;Nimbus Sans&quot;, Arial, sans-serif;font-size:16px;font-weight:400;letter-spacing:0.15008px;line-height:1.5;margin:0;padding:32px 0;min-height:100%;width:100%"><table align="center" width="100%" style="margin:0 auto;max-width:600px;background-color:#FFFFFF" role="presentation" cellSpacing="0" cellPadding="0" border="0"><tbody><tr style="width:100%"><td><div style="padding:24px 24px 16px 24px;text-align:center"><a href="https://www.alpro.test" style="text-decoration:none" target="_blank"><img alt="Alpro" src="https://placehold.co/240x80/png?text=Alpro" width="120" style="width:120px;outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></a></div><h2 style="font-weight:bold;text-align:left;margin:0;font-size:24px;padding:16px 24px 8px 24px">Hi {{FirstName}},</h2><div style="font-weight:normal;text-align:left;padding:8px 24px 8px 24px"><p>Here is what is new this month. Replace this text with your story.</p>
</div><div style="font-weight:normal;text-align:left;padding:8px 24px 8px 24px"><p>You have <strong>{{PointsBalance}}</strong> points, valid until {{PointsExpiry}}.</p>
</div><div style="text-align:left;padding:16px 24px 16px 24px"><a href="https://www.alpro.test/?utm_source=edm&amp;utm_medium=email" style="color:#FFFFFF;font-size:16px;font-weight:bold;background-color:#0D6EFD;border-radius:4px;display:inline-block;padding:12px 20px;text-decoration:none" target="_blank"><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%;mso-text-raise:30" hidden>&nbsp;</i><![endif]--></span><span>Shop now</span><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%" hidden>&nbsp;</i><![endif]--></span></a></div><div style="color:#555555;font-size:13px;font-weight:normal;text-align:left;padding:8px 24px 8px 24px"><p>Visit us at {{NearestOutlet}}.</p>
</div><div style="padding:8px 24px 8px 24px"><hr style="width:100%;border:none;border-top:1px solid #E5E7EB;margin:0"/></div><div style="color:#8A929B;font-size:12px;text-align:center;padding:16px 24px 24px 24px">You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href="{{unsubscribe_url}}" style="color:#8A929B;">Unsubscribe</a></div></td></tr></tbody></table></div></body></html>', '{"root":{"type":"EmailLayout","data":{"backdropColor":"#F4F6F8","canvasColor":"#FFFFFF","textColor":"#262626","fontFamily":"MODERN_SANS","childrenIds":["block-1","block-2","block-3","block-4","block-5","block-6","block-7","block-8"]}},"block-1":{"type":"Image","data":{"props":{"url":"https://placehold.co/240x80/png?text=Alpro","alt":"Alpro","contentAlignment":"middle","linkHref":"https://www.alpro.test","width":120},"style":{"padding":{"top":24,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-2":{"type":"Heading","data":{"props":{"text":"Hi {{FirstName}},","level":"h2"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"left"}}},"block-3":{"type":"Text","data":{"props":{"text":"Here is what is new this month. Replace this text with your story.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"left","fontWeight":"normal"}}},"block-4":{"type":"Text","data":{"props":{"text":"You have **{{PointsBalance}}** points, valid until {{PointsExpiry}}.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"left","fontWeight":"normal"}}},"block-5":{"type":"Button","data":{"props":{"text":"Shop now","url":"https://www.alpro.test/?utm_source=edm&utm_medium=email","buttonBackgroundColor":"#0D6EFD","buttonStyle":"rounded","fullWidth":false},"style":{"padding":{"top":16,"bottom":16,"left":24,"right":24},"textAlign":"left"}}},"block-6":{"type":"Text","data":{"props":{"text":"Visit us at {{NearestOutlet}}.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"left","fontWeight":"normal","color":"#555555","fontSize":13}}},"block-7":{"type":"Divider","data":{"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24}},"props":{"lineColor":"#E5E7EB"}}},"block-8":{"type":"Html","data":{"props":{"contents":"You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href=\\"{{unsubscribe_url}}\\" style=\\"color:#8A929B;\\">Unsubscribe</a>"},"style":{"fontSize":12,"color":"#8A929B","textAlign":"center","padding":{"top":16,"bottom":24,"left":24,"right":24}}}}}', 400, NULL),
('Birthday voucher', 'Birthday', '<!DOCTYPE html><html><body><div style="background-color:#F4F6F8;color:#262626;font-family:&quot;Helvetica Neue&quot;, &quot;Arial Nova&quot;, &quot;Nimbus Sans&quot;, Arial, sans-serif;font-size:16px;font-weight:400;letter-spacing:0.15008px;line-height:1.5;margin:0;padding:32px 0;min-height:100%;width:100%"><table align="center" width="100%" style="margin:0 auto;max-width:600px;background-color:#FFFFFF" role="presentation" cellSpacing="0" cellPadding="0" border="0"><tbody><tr style="width:100%"><td><div style="padding:24px 24px 16px 24px;text-align:center"><a href="https://www.alpro.test" style="text-decoration:none" target="_blank"><img alt="Alpro" src="https://placehold.co/240x80/png?text=Alpro" width="120" style="width:120px;outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></a></div><div style="padding:0px 0px 0px 0px;text-align:center"><img alt="Happy birthday" src="https://placehold.co/600x240/png?text=Happy+Birthday" style="outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></div><h1 style="font-weight:bold;text-align:center;margin:0;font-size:32px;padding:16px 24px 8px 24px">Happy birthday, {{FirstName}}!</h1><div style="font-weight:normal;text-align:center;padding:8px 24px 8px 24px"><p>Celebrate with a gift from us. Use this code at checkout or show it at {{NearestOutlet}}:</p>
</div><div style="text-align:center;padding:8px 24px 8px 24px"><span style="display:inline-block;padding:12px 28px;border:2px dashed #0d6efd;font-size:22px;font-weight:bold;letter-spacing:2px;">{{VoucherCode}}</span></div><div style="text-align:center;padding:16px 24px 16px 24px"><a href="https://www.alpro.test/birthday?utm_source=edm&amp;utm_medium=email" style="color:#FFFFFF;font-size:16px;font-weight:bold;background-color:#0D6EFD;border-radius:4px;display:inline-block;padding:12px 20px;text-decoration:none" target="_blank"><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%;mso-text-raise:30" hidden>&nbsp;</i><![endif]--></span><span>Redeem now</span><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%" hidden>&nbsp;</i><![endif]--></span></a></div><div style="padding:8px 24px 8px 24px"><hr style="width:100%;border:none;border-top:1px solid #E5E7EB;margin:0"/></div><div style="color:#8A929B;font-size:12px;text-align:center;padding:16px 24px 24px 24px">You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href="{{unsubscribe_url}}" style="color:#8A929B;">Unsubscribe</a></div></td></tr></tbody></table></div></body></html>', '{"root":{"type":"EmailLayout","data":{"backdropColor":"#F4F6F8","canvasColor":"#FFFFFF","textColor":"#262626","fontFamily":"MODERN_SANS","childrenIds":["block-1","block-2","block-3","block-4","block-5","block-6","block-7","block-8"]}},"block-1":{"type":"Image","data":{"props":{"url":"https://placehold.co/240x80/png?text=Alpro","alt":"Alpro","contentAlignment":"middle","linkHref":"https://www.alpro.test","width":120},"style":{"padding":{"top":24,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-2":{"type":"Image","data":{"props":{"url":"https://placehold.co/600x240/png?text=Happy+Birthday","alt":"Happy birthday","contentAlignment":"middle","linkHref":null},"style":{"padding":{"top":0,"bottom":0,"left":0,"right":0},"textAlign":"center"}}},"block-3":{"type":"Heading","data":{"props":{"text":"Happy birthday, {{FirstName}}!","level":"h1"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"center"}}},"block-4":{"type":"Text","data":{"props":{"text":"Celebrate with a gift from us. Use this code at checkout or show it at {{NearestOutlet}}:","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"center","fontWeight":"normal"}}},"block-5":{"type":"Html","data":{"props":{"contents":"<span style=\\"display:inline-block;padding:12px 28px;border:2px dashed #0d6efd;font-size:22px;font-weight:bold;letter-spacing:2px;\\">{{VoucherCode}}</span>"},"style":{"textAlign":"center","padding":{"top":8,"bottom":8,"left":24,"right":24}}}},"block-6":{"type":"Button","data":{"props":{"text":"Redeem now","url":"https://www.alpro.test/birthday?utm_source=edm&utm_medium=email","buttonBackgroundColor":"#0D6EFD","buttonStyle":"rounded","fullWidth":false},"style":{"padding":{"top":16,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-7":{"type":"Divider","data":{"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24}},"props":{"lineColor":"#E5E7EB"}}},"block-8":{"type":"Html","data":{"props":{"contents":"You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href=\\"{{unsubscribe_url}}\\" style=\\"color:#8A929B;\\">Unsubscribe</a>"},"style":{"fontSize":12,"color":"#8A929B","textAlign":"center","padding":{"top":16,"bottom":24,"left":24,"right":24}}}}}', 400, NULL),
('Promotion - hero banner', 'Promotion', '<!DOCTYPE html><html><body><div style="background-color:#F4F6F8;color:#262626;font-family:&quot;Helvetica Neue&quot;, &quot;Arial Nova&quot;, &quot;Nimbus Sans&quot;, Arial, sans-serif;font-size:16px;font-weight:400;letter-spacing:0.15008px;line-height:1.5;margin:0;padding:32px 0;min-height:100%;width:100%"><table align="center" width="100%" style="margin:0 auto;max-width:600px;background-color:#FFFFFF" role="presentation" cellSpacing="0" cellPadding="0" border="0"><tbody><tr style="width:100%"><td><div style="padding:24px 24px 16px 24px;text-align:center"><a href="https://www.alpro.test" style="text-decoration:none" target="_blank"><img alt="Alpro" src="https://placehold.co/240x80/png?text=Alpro" width="120" style="width:120px;outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></a></div><div style="padding:0px 0px 0px 0px;text-align:center"><img alt="Sale" src="https://placehold.co/600x300/png?text=Year-End+Sale" style="outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></div><h1 style="font-weight:bold;text-align:center;margin:0;font-size:32px;padding:16px 24px 8px 24px">Up to 50% off this week</h1><div style="font-weight:normal;text-align:center;padding:8px 24px 8px 24px"><p>{{FirstName}}, our biggest deals of the season are here - in store at {{NearestOutlet}} and online.</p>
</div><div style="text-align:center;padding:16px 24px 16px 24px"><a href="https://shop.alpro.test/sale?utm_source=edm&amp;utm_medium=email" style="color:#FFFFFF;font-size:16px;font-weight:bold;background-color:#E5383B;border-radius:4px;display:inline-block;padding:12px 20px;text-decoration:none" target="_blank"><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%;mso-text-raise:30" hidden>&nbsp;</i><![endif]--></span><span>Shop the sale</span><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%" hidden>&nbsp;</i><![endif]--></span></a></div><div style="padding:16px 24px 16px 24px"><table align="center" width="100%" cellPadding="0" border="0" style="table-layout:fixed;border-collapse:collapse"><tbody style="width:100%"><tr style="width:100%"><td style="box-sizing:content-box;vertical-align:middle;padding-left:0;padding-right:8px"><div style="padding:0px 0px 0px 0px;text-align:center"><img alt="Vitamins" src="https://placehold.co/240x180/png?text=Vitamins" style="outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></div><div style="text-align:center;padding:8px 0px 8px 0px"><p><strong>Vitamins</strong> from RM19.90</p>
</div></td><td style="box-sizing:content-box;vertical-align:middle;padding-left:8px;padding-right:0"><div style="padding:0px 0px 0px 0px;text-align:center"><img alt="Skincare" src="https://placehold.co/240x180/png?text=Skincare" style="outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></div><div style="text-align:center;padding:8px 0px 8px 0px"><p><strong>Skincare</strong> 2 for RM50</p>
</div></td></tr></tbody></table></div><div style="padding:8px 24px 8px 24px"><hr style="width:100%;border:none;border-top:1px solid #E5E7EB;margin:0"/></div><div style="color:#8A929B;font-size:12px;text-align:center;padding:16px 24px 24px 24px">You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href="{{unsubscribe_url}}" style="color:#8A929B;">Unsubscribe</a></div></td></tr></tbody></table></div></body></html>', '{"root":{"type":"EmailLayout","data":{"backdropColor":"#F4F6F8","canvasColor":"#FFFFFF","textColor":"#262626","fontFamily":"MODERN_SANS","childrenIds":["block-1","block-2","block-3","block-4","block-5","block-10","block-11","block-12"]}},"block-1":{"type":"Image","data":{"props":{"url":"https://placehold.co/240x80/png?text=Alpro","alt":"Alpro","contentAlignment":"middle","linkHref":"https://www.alpro.test","width":120},"style":{"padding":{"top":24,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-2":{"type":"Image","data":{"props":{"url":"https://placehold.co/600x300/png?text=Year-End+Sale","alt":"Sale","contentAlignment":"middle","linkHref":null},"style":{"padding":{"top":0,"bottom":0,"left":0,"right":0},"textAlign":"center"}}},"block-3":{"type":"Heading","data":{"props":{"text":"Up to 50% off this week","level":"h1"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"center"}}},"block-4":{"type":"Text","data":{"props":{"text":"{{FirstName}}, our biggest deals of the season are here - in store at {{NearestOutlet}} and online.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"center","fontWeight":"normal"}}},"block-5":{"type":"Button","data":{"props":{"text":"Shop the sale","url":"https://shop.alpro.test/sale?utm_source=edm&utm_medium=email","buttonBackgroundColor":"#E5383B","buttonStyle":"rounded","fullWidth":false},"style":{"padding":{"top":16,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-6":{"type":"Image","data":{"props":{"url":"https://placehold.co/240x180/png?text=Vitamins","alt":"Vitamins","contentAlignment":"middle","linkHref":null},"style":{"padding":{"top":0,"bottom":0,"left":0,"right":0},"textAlign":"center"}}},"block-7":{"type":"Text","data":{"props":{"text":"**Vitamins** from RM19.90","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":0,"right":0},"textAlign":"center"}}},"block-8":{"type":"Image","data":{"props":{"url":"https://placehold.co/240x180/png?text=Skincare","alt":"Skincare","contentAlignment":"middle","linkHref":null},"style":{"padding":{"top":0,"bottom":0,"left":0,"right":0},"textAlign":"center"}}},"block-9":{"type":"Text","data":{"props":{"text":"**Skincare** 2 for RM50","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":0,"right":0},"textAlign":"center"}}},"block-10":{"type":"ColumnsContainer","data":{"style":{"padding":{"top":16,"bottom":16,"left":24,"right":24}},"props":{"columnsCount":2,"columnsGap":16,"columns":[{"childrenIds":["block-6","block-7"]},{"childrenIds":["block-8","block-9"]},{"childrenIds":[]}]}}},"block-11":{"type":"Divider","data":{"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24}},"props":{"lineColor":"#E5E7EB"}}},"block-12":{"type":"Html","data":{"props":{"contents":"You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href=\\"{{unsubscribe_url}}\\" style=\\"color:#8A929B;\\">Unsubscribe</a>"},"style":{"fontSize":12,"color":"#8A929B","textAlign":"center","padding":{"top":16,"bottom":24,"left":24,"right":24}}}}}', 200, NULL),
('Health tips digest', 'Newsletter', '<!DOCTYPE html><html><body><div style="background-color:#F4F6F8;color:#262626;font-family:&quot;Helvetica Neue&quot;, &quot;Arial Nova&quot;, &quot;Nimbus Sans&quot;, Arial, sans-serif;font-size:16px;font-weight:400;letter-spacing:0.15008px;line-height:1.5;margin:0;padding:32px 0;min-height:100%;width:100%"><table align="center" width="100%" style="margin:0 auto;max-width:600px;background-color:#FFFFFF" role="presentation" cellSpacing="0" cellPadding="0" border="0"><tbody><tr style="width:100%"><td><div style="padding:24px 24px 16px 24px;text-align:center"><a href="https://www.alpro.test" style="text-decoration:none" target="_blank"><img alt="Alpro" src="https://placehold.co/240x80/png?text=Alpro" width="120" style="width:120px;outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></a></div><h2 style="font-weight:bold;text-align:left;margin:0;font-size:24px;padding:16px 24px 8px 24px">Your health tips for this month</h2><div style="font-weight:normal;text-align:left;padding:8px 24px 8px 24px"><p>Hi {{FirstName}}, our pharmacists picked three simple habits for a healthier month.</p>
</div><div style="background-color:#F1F7FF;padding:8px 24px 8px 24px"><h3 style="font-weight:bold;text-align:left;margin:0;font-size:20px;padding:16px 24px 8px 24px">1. Stay hydrated</h3><div style="font-weight:normal;text-align:left;padding:8px 24px 8px 24px"><p>Aim for 8 glasses of water a day - more when it is hot.</p>
</div><h3 style="font-weight:bold;text-align:left;margin:0;font-size:20px;padding:16px 24px 8px 24px">2. Move every hour</h3><div style="font-weight:normal;text-align:left;padding:8px 24px 8px 24px"><p>A 5-minute walk each hour helps your back and your blood sugar.</p>
</div><h3 style="font-weight:bold;text-align:left;margin:0;font-size:20px;padding:16px 24px 8px 24px">3. Check your numbers</h3><div style="font-weight:normal;text-align:left;padding:8px 24px 8px 24px"><p>Free blood pressure checks at {{NearestOutlet}} every weekday.</p>
</div></div><div style="text-align:left;padding:16px 24px 16px 24px"><a href="https://www.alpro.test/health?utm_source=edm&amp;utm_medium=email" style="color:#FFFFFF;font-size:16px;font-weight:bold;background-color:#0D6EFD;border-radius:4px;display:inline-block;padding:12px 20px;text-decoration:none" target="_blank"><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%;mso-text-raise:30" hidden>&nbsp;</i><![endif]--></span><span>Read more tips</span><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%" hidden>&nbsp;</i><![endif]--></span></a></div><div style="padding:8px 24px 8px 24px"><hr style="width:100%;border:none;border-top:1px solid #E5E7EB;margin:0"/></div><div style="color:#8A929B;font-size:12px;text-align:center;padding:16px 24px 24px 24px">You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href="{{unsubscribe_url}}" style="color:#8A929B;">Unsubscribe</a></div></td></tr></tbody></table></div></body></html>', '{"root":{"type":"EmailLayout","data":{"backdropColor":"#F4F6F8","canvasColor":"#FFFFFF","textColor":"#262626","fontFamily":"MODERN_SANS","childrenIds":["block-1","block-2","block-3","block-10","block-11","block-12","block-13"]}},"block-1":{"type":"Image","data":{"props":{"url":"https://placehold.co/240x80/png?text=Alpro","alt":"Alpro","contentAlignment":"middle","linkHref":"https://www.alpro.test","width":120},"style":{"padding":{"top":24,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-2":{"type":"Heading","data":{"props":{"text":"Your health tips for this month","level":"h2"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"left"}}},"block-3":{"type":"Text","data":{"props":{"text":"Hi {{FirstName}}, our pharmacists picked three simple habits for a healthier month.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"left","fontWeight":"normal"}}},"block-4":{"type":"Heading","data":{"props":{"text":"1. Stay hydrated","level":"h3"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"left"}}},"block-5":{"type":"Text","data":{"props":{"text":"Aim for 8 glasses of water a day - more when it is hot.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"left","fontWeight":"normal"}}},"block-6":{"type":"Heading","data":{"props":{"text":"2. Move every hour","level":"h3"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"left"}}},"block-7":{"type":"Text","data":{"props":{"text":"A 5-minute walk each hour helps your back and your blood sugar.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"left","fontWeight":"normal"}}},"block-8":{"type":"Heading","data":{"props":{"text":"3. Check your numbers","level":"h3"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"left"}}},"block-9":{"type":"Text","data":{"props":{"text":"Free blood pressure checks at {{NearestOutlet}} every weekday.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"left","fontWeight":"normal"}}},"block-10":{"type":"Container","data":{"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"backgroundColor":"#F1F7FF"},"props":{"childrenIds":["block-4","block-5","block-6","block-7","block-8","block-9"]}}},"block-11":{"type":"Button","data":{"props":{"text":"Read more tips","url":"https://www.alpro.test/health?utm_source=edm&utm_medium=email","buttonBackgroundColor":"#0D6EFD","buttonStyle":"rounded","fullWidth":false},"style":{"padding":{"top":16,"bottom":16,"left":24,"right":24},"textAlign":"left"}}},"block-12":{"type":"Divider","data":{"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24}},"props":{"lineColor":"#E5E7EB"}}},"block-13":{"type":"Html","data":{"props":{"contents":"You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href=\\"{{unsubscribe_url}}\\" style=\\"color:#8A929B;\\">Unsubscribe</a>"},"style":{"fontSize":12,"color":"#8A929B","textAlign":"center","padding":{"top":16,"bottom":24,"left":24,"right":24}}}}}', 150, NULL),
('Event invitation', 'Event', '<!DOCTYPE html><html><body><div style="background-color:#F4F6F8;color:#262626;font-family:&quot;Helvetica Neue&quot;, &quot;Arial Nova&quot;, &quot;Nimbus Sans&quot;, Arial, sans-serif;font-size:16px;font-weight:400;letter-spacing:0.15008px;line-height:1.5;margin:0;padding:32px 0;min-height:100%;width:100%"><table align="center" width="100%" style="margin:0 auto;max-width:600px;background-color:#FFFFFF" role="presentation" cellSpacing="0" cellPadding="0" border="0"><tbody><tr style="width:100%"><td><div style="padding:24px 24px 16px 24px;text-align:center"><a href="https://www.alpro.test" style="text-decoration:none" target="_blank"><img alt="Alpro" src="https://placehold.co/240x80/png?text=Alpro" width="120" style="width:120px;outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></a></div><div style="padding:0px 0px 0px 0px;text-align:center"><img alt="Event" src="https://placehold.co/600x260/png?text=You+are+invited" style="outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></div><h1 style="font-weight:bold;text-align:center;margin:0;font-size:32px;padding:16px 24px 8px 24px">You are invited, {{FirstName}}</h1><div style="font-weight:normal;text-align:center;padding:8px 24px 8px 24px"><p>Join us for free health screenings, product demos and lucky draws.</p>
</div><div style="padding:8px 24px 8px 24px"><table align="center" width="100%" cellPadding="0" border="0" style="table-layout:fixed;border-collapse:collapse"><tbody style="width:100%"><tr style="width:100%"><td style="box-sizing:content-box;vertical-align:middle;padding-left:0;padding-right:8px"><div style="text-align:center;padding:8px 0px 8px 0px"><p><strong>When</strong><br/>Saturday, 10am - 4pm</p>
</div></td><td style="box-sizing:content-box;vertical-align:middle;padding-left:8px;padding-right:0"><div style="text-align:center;padding:8px 0px 8px 0px"><p><strong>Where</strong><br/>{{NearestOutlet}}</p>
</div></td></tr></tbody></table></div><div style="text-align:center;padding:16px 24px 16px 24px"><a href="https://www.alpro.test/events?utm_source=edm&amp;utm_medium=email" style="color:#FFFFFF;font-size:16px;font-weight:bold;background-color:#198754;border-radius:4px;display:inline-block;padding:12px 20px;text-decoration:none" target="_blank"><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%;mso-text-raise:30" hidden>&nbsp;</i><![endif]--></span><span>Reserve my spot</span><span><!--[if mso]><i style="letter-spacing: 20px;mso-font-width:-100%" hidden>&nbsp;</i><![endif]--></span></a></div><div style="padding:8px 24px 8px 24px"><hr style="width:100%;border:none;border-top:1px solid #E5E7EB;margin:0"/></div><div style="color:#8A929B;font-size:12px;text-align:center;padding:16px 24px 24px 24px">You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href="{{unsubscribe_url}}" style="color:#8A929B;">Unsubscribe</a></div></td></tr></tbody></table></div></body></html>', '{"root":{"type":"EmailLayout","data":{"backdropColor":"#F4F6F8","canvasColor":"#FFFFFF","textColor":"#262626","fontFamily":"MODERN_SANS","childrenIds":["block-1","block-2","block-3","block-4","block-7","block-8","block-9","block-10"]}},"block-1":{"type":"Image","data":{"props":{"url":"https://placehold.co/240x80/png?text=Alpro","alt":"Alpro","contentAlignment":"middle","linkHref":"https://www.alpro.test","width":120},"style":{"padding":{"top":24,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-2":{"type":"Image","data":{"props":{"url":"https://placehold.co/600x260/png?text=You+are+invited","alt":"Event","contentAlignment":"middle","linkHref":null},"style":{"padding":{"top":0,"bottom":0,"left":0,"right":0},"textAlign":"center"}}},"block-3":{"type":"Heading","data":{"props":{"text":"You are invited, {{FirstName}}","level":"h1"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"center"}}},"block-4":{"type":"Text","data":{"props":{"text":"Join us for free health screenings, product demos and lucky draws.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"center","fontWeight":"normal"}}},"block-5":{"type":"Text","data":{"props":{"text":"**When**  \\nSaturday, 10am - 4pm","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":0,"right":0},"textAlign":"center"}}},"block-6":{"type":"Text","data":{"props":{"text":"**Where**  \\n{{NearestOutlet}}","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":0,"right":0},"textAlign":"center"}}},"block-7":{"type":"ColumnsContainer","data":{"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24}},"props":{"columnsCount":2,"columnsGap":16,"columns":[{"childrenIds":["block-5"]},{"childrenIds":["block-6"]},{"childrenIds":[]}]}}},"block-8":{"type":"Button","data":{"props":{"text":"Reserve my spot","url":"https://www.alpro.test/events?utm_source=edm&utm_medium=email","buttonBackgroundColor":"#198754","buttonStyle":"rounded","fullWidth":false},"style":{"padding":{"top":16,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-9":{"type":"Divider","data":{"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24}},"props":{"lineColor":"#E5E7EB"}}},"block-10":{"type":"Html","data":{"props":{"contents":"You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href=\\"{{unsubscribe_url}}\\" style=\\"color:#8A929B;\\">Unsubscribe</a>"},"style":{"fontSize":12,"color":"#8A929B","textAlign":"center","padding":{"top":16,"bottom":24,"left":24,"right":24}}}}}', 90, NULL),
('Old seasonal layout', 'Seasonal', '<!DOCTYPE html><html><body><div style="background-color:#F4F6F8;color:#262626;font-family:&quot;Helvetica Neue&quot;, &quot;Arial Nova&quot;, &quot;Nimbus Sans&quot;, Arial, sans-serif;font-size:16px;font-weight:400;letter-spacing:0.15008px;line-height:1.5;margin:0;padding:32px 0;min-height:100%;width:100%"><table align="center" width="100%" style="margin:0 auto;max-width:600px;background-color:#FFFFFF" role="presentation" cellSpacing="0" cellPadding="0" border="0"><tbody><tr style="width:100%"><td><div style="padding:24px 24px 16px 24px;text-align:center"><a href="https://www.alpro.test" style="text-decoration:none" target="_blank"><img alt="Alpro" src="https://placehold.co/240x80/png?text=Alpro" width="120" style="width:120px;outline:none;border:none;text-decoration:none;vertical-align:middle;display:inline-block;max-width:100%"/></a></div><h1 style="font-weight:bold;text-align:center;margin:0;font-size:32px;padding:16px 24px 8px 24px">Season&#x27;s greetings</h1><div style="font-weight:normal;text-align:center;padding:8px 24px 8px 24px"><p>Warm wishes from all of us at Alpro.</p>
</div><div style="height:16px"></div><div style="color:#8A929B;font-size:12px;text-align:center;padding:16px 24px 24px 24px">You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href="{{unsubscribe_url}}" style="color:#8A929B;">Unsubscribe</a></div></td></tr></tbody></table></div></body></html>', '{"root":{"type":"EmailLayout","data":{"backdropColor":"#F4F6F8","canvasColor":"#FFFFFF","textColor":"#262626","fontFamily":"MODERN_SANS","childrenIds":["block-1","block-2","block-3","block-4","block-5"]}},"block-1":{"type":"Image","data":{"props":{"url":"https://placehold.co/240x80/png?text=Alpro","alt":"Alpro","contentAlignment":"middle","linkHref":"https://www.alpro.test","width":120},"style":{"padding":{"top":24,"bottom":16,"left":24,"right":24},"textAlign":"center"}}},"block-2":{"type":"Heading","data":{"props":{"text":"Season''s greetings","level":"h1"},"style":{"padding":{"top":16,"bottom":8,"left":24,"right":24},"textAlign":"center"}}},"block-3":{"type":"Text","data":{"props":{"text":"Warm wishes from all of us at Alpro.","markdown":true},"style":{"padding":{"top":8,"bottom":8,"left":24,"right":24},"textAlign":"center","fontWeight":"normal"}}},"block-4":{"type":"Spacer","data":{"props":{"height":16}}},"block-5":{"type":"Html","data":{"props":{"contents":"You are receiving this as a {{MembershipType}} member of Alpro Pharmacy.<br><a href=\\"{{unsubscribe_url}}\\" style=\\"color:#8A929B;\\">Unsubscribe</a>"},"style":{"fontSize":12,"color":"#8A929B","textAlign":"center","padding":{"top":16,"bottom":24,"left":24,"right":24}}}}}', 380, @now - INTERVAL 60 DAY);

INSERT INTO `edm_templates` (`name`, `category`, `thumbnail_url`, `html`, `editor_json`, `created_by`, `created_by_name`, `created_at`, `updated_at`, `deleted_at`)
SELECT d.`name`, d.`category`, NULL, d.`html`, d.`editor_json`, @me, @me_name, @now - INTERVAL d.`age` DAY, @now - INTERVAL d.`age` DAY, d.`deleted_at`
  FROM `demo_tpl` d
 WHERE NOT EXISTS (SELECT 1 FROM `edm_templates` x WHERE x.`name` = d.`name`);

UPDATE `edm_templates` t
  JOIN `demo_tpl` d ON d.`name` = t.`name`
   SET t.`html` = d.`html`, t.`editor_json` = d.`editor_json`, t.`updated_at` = @now
 WHERE t.`editor_json` IS NULL;

DROP TEMPORARY TABLE IF EXISTS `demo_tpl`;

-- Files library (the Email creator's Image panel). Placeholder images.
INSERT INTO `edm_assets` (`name`, `url`, `type`, `size_bytes`, `uploaded_by`, `uploaded_by_name`, `created_at`, `updated_at`)
SELECT a.`name`, a.`url`, a.`type`, a.`size`, @me, @me_name, @now - INTERVAL a.`age` DAY, @now - INTERVAL a.`age` DAY
  FROM (SELECT 'hero-year-end-sale.png' AS `name`, 'https://placehold.co/600x300/png?text=Year-End+Sale' AS `url`, 'image' AS `type`, 48213 AS `size`, 5 AS `age`
        UNION ALL SELECT 'hero-skincare-launch.png', 'https://placehold.co/600x300/png?text=Skincare+Launch', 'image', 51877, 12
        UNION ALL SELECT 'banner-11-11.png', 'https://placehold.co/600x200/png?text=11.11+Mega+Sale', 'image', 30112, 20
        UNION ALL SELECT 'icon-vitamins.png', 'https://placehold.co/120x120/png?text=Vitamins', 'image', 6021, 60
        UNION ALL SELECT 'logo-alpro-white.png', 'https://placehold.co/240x80/png?text=Alpro', 'image', 4410, 300
        UNION ALL SELECT 'footer-social.png', 'https://placehold.co/600x60/png?text=Follow+us', 'image', 7804, 300) a
 WHERE NOT EXISTS (SELECT 1 FROM `edm_assets` x WHERE x.`name` = a.`name` AND x.`deleted_at` IS NULL);

-- ----------------------------------------------------------------------
-- Newsletters. One row per scenario in a temporary profile table; the
-- profile also drives the generated send results (bp = per 10,000 sent):
--   log_mode  none | full (SES events) | noevents (sent, no SES events)
--             | partial (Sending, mailbox simulator contacts)
--   pct       share of the generated contacts that received it (%)
--   open/click/bounce/complaint/unsub/skip/fail  rates in bp
--   hard_pct  share of bounces that are hard (Permanent)
--   win       minutes after sending over which opens etc. spread
-- ----------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `demo_nl`;
CREATE TEMPORARY TABLE `demo_nl` (
  `name` varchar(255) NOT NULL, `subject` varchar(255) NULL, `preheader` varchar(255) NULL,
  `status` tinyint NOT NULL, `arch_from` tinyint NULL, `at` datetime NULL,
  `sender` varchar(255) NULL, `reply` varchar(255) NULL, `req` int NOT NULL, `tags` varchar(255) NULL,
  `inc_seg` varchar(255) NULL, `exc_seg` varchar(255) NULL, `uat` tinyint NOT NULL DEFAULT 0,
  `t_open` tinyint NOT NULL DEFAULT 1, `t_click` tinyint NOT NULL DEFAULT 1, `utm_on` tinyint NOT NULL DEFAULT 1,
  `utm_campaign` varchar(100) NULL, `send_now` tinyint NOT NULL DEFAULT 0, `is_deleted` tinyint NOT NULL DEFAULT 0,
  `tpl` varchar(255) NULL, `sub_at` datetime NULL, `rev_at` datetime NULL, `comment` text NULL,
  `log_mode` varchar(10) NOT NULL DEFAULT 'none', `pct` int NOT NULL DEFAULT 0,
  `open_bp` int NOT NULL DEFAULT 0, `click_bp` int NOT NULL DEFAULT 0, `bounce_bp` int NOT NULL DEFAULT 0,
  `hard_pct` int NOT NULL DEFAULT 50, `complaint_bp` int NOT NULL DEFAULT 0, `unsub_bp` int NOT NULL DEFAULT 0,
  `skip_bp` int NOT NULL DEFAULT 0, `fail_bp` int NOT NULL DEFAULT 0, `win` int NOT NULL DEFAULT 5760
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Completed (8): recent ones mirror the GetResponse sample; older ones fill
-- the monthly charts and the previous period of "This year".
INSERT INTO `demo_nl` (`name`, `subject`, `preheader`, `status`, `at`, `sender`, `reply`, `req`, `tags`, `inc_seg`, `exc_seg`,
    `t_open`, `t_click`, `utm_on`, `utm_campaign`, `send_now`, `tpl`, `log_mode`, `pct`, `open_bp`, `click_bp`, `bounce_bp`, `hard_pct`, `complaint_bp`, `unsub_bp`, `skip_bp`, `fail_bp`) VALUES
('Smart Fibre_SuGO365 Mirza', 'Smart Fibre is here - try SuGO365 today', 'Daily fibre for better digestion', 8, TIMESTAMP(@d0 - INTERVAL 1 DAY, '10:30:00'), 'promo@alpro.test', NULL, @me, 'Promotion,Partner brand', NULL, NULL, 1, 1, 1, 'smart-fibre-oct', 0, 'Basic newsletter', 'full', 95, 1719, 10, 49, 60, 3, 12, 0, 0),
('Malaysia Special Deal_BPT XinHui', 'Malaysia Special Deal: up to 50% off', 'Only this week at every Alpro outlet', 8, TIMESTAMP(@d0 - INTERVAL 3 DAY, '20:30:00'), 'bpt@alpro.test', 'care@alpro.test', @bpt, 'Promotion,Seasonal', NULL, NULL, 1, 1, 1, NULL, 0, 'Promotion - hero banner', 'full', 60, 2772, 502, 97, 30, 5, 15, 0, 0),
('AlproSugi Grand Opening Invitation_Balqis', 'You are invited: AlproSugi Grand Opening', 'Free health screening on opening day', 8, TIMESTAMP(@d0 - INTERVAL 3 DAY, '10:30:00'), 'events@alpro.test', NULL, @di, 'Event', 'Klang Valley', NULL, 1, 1, 1, NULL, 0, 'Event invitation', 'full', 30, 2525, 797, 0, 50, 0, 0, 0, 0),
('Viatris OTC_BPT Shayne', 'Viatris OTC essentials for your medicine cabinet', NULL, 8, TIMESTAMP(@d0 - INTERVAL 7 DAY, '10:30:00'), 'bpt@alpro.test', NULL, @bpt, 'Partner brand', NULL, NULL, 1, 1, 1, NULL, 0, 'Basic newsletter', 'full', 55, 1689, 12, 62, 40, 0, 10, 0, 0),
('Alpro Health Abbott Libre_Balqis', 'Monitor glucose the easy way with FreeStyle Libre', 'Pharmacist demo at selected outlets', 8, TIMESTAMP(@d0 - INTERVAL 8 DAY, '10:30:00'), 'newsletter@alpro.test', NULL, @di, 'Health tips,Partner brand', NULL, NULL, 1, 1, 1, NULL, 0, 'Health tips digest', 'full', 35, 2724, 1163, 0, 50, 0, 0, 0, 0),
('Turn It Red_Silvercare Alvin', 'Turn It Red for heart health', 'Silvercare picks for seniors', 8, TIMESTAMP(@d0 - INTERVAL 8 DAY, '10:00:00'), 'newsletter@alpro.test', NULL, @me, 'Health tips', NULL, NULL, 1, 1, 1, NULL, 1, 'Basic newsletter', 'full', 95, 2094, 35, 51, 50, 8, 20, 0, 0),
('ECZEVA_BPT_Shayne', 'ECZEVA: gentle care for sensitive skin', NULL, 8, TIMESTAMP(@d0 - INTERVAL 9 DAY, '10:30:00'), 'bpt@alpro.test', NULL, @bpt, 'Partner brand', NULL, NULL, 1, 1, 0, NULL, 0, 'Basic newsletter', 'full', 55, 1534, 3, 52, 40, 0, 5, 0, 0),
('Sandoz All_XinHui', 'Sandoz generics - same quality, better price', NULL, 8, TIMESTAMP(@d0 - INTERVAL 11 DAY, '10:00:00'), 'bpt@alpro.test', NULL, @bpt, 'Partner brand', NULL, NULL, 1, 1, 1, NULL, 0, 'Basic newsletter', 'full', 95, 2196, 9, 53, 50, 2, 10, 0, 0),
('Diabetes Awareness Talk_Engagement', 'Free talk: living well with diabetes', NULL, 8, TIMESTAMP(@d0 - INTERVAL 14 DAY, '15:00:00'), 'events@alpro.test', NULL, @eng, 'Event,Health tips', NULL, NULL, 1, 1, 1, NULL, 0, 'Event invitation', 'noevents', 20, 0, 0, 0, 50, 0, 0, 0, 0),
('Eye Care Week_BPT', 'Eye Care Week: free eye screening', 'Book your slot online', 8, TIMESTAMP(@d0 - INTERVAL 20 DAY, '10:30:00'), 'bpt@alpro.test', NULL, @bpt, 'Health tips', NULL, NULL, 1, 1, 1, NULL, 0, 'Basic newsletter', 'full', 60, 2000, 150, 80, 50, 0, 10, 900, 300),
('September Wellness Month_Engagement', 'September is Wellness Month', 'Tips, talks and treats all month', 8, TIMESTAMP(@d0 - INTERVAL 30 DAY, '10:30:00'), 'newsletter@alpro.test', NULL, @eng, 'Health tips,Seasonal', NULL, 'Dormant members', 1, 1, 1, NULL, 0, 'Health tips digest', 'full', 90, 2400, 300, 60, 50, 2, 15, 0, 0),
('Back to School Vitamins_Mirza', 'Back to school: vitamins for growing kids', NULL, 8, TIMESTAMP(@d0 - INTERVAL 45 DAY, '10:30:00'), 'promo@alpro.test', NULL, @me, 'Promotion,Seasonal', 'Vitamin lovers', NULL, 1, 1, 1, NULL, 0, 'Promotion - hero banner', 'full', 85, 1900, 150, 120, 70, 3, 18, 0, 0),
('Merdeka Sale_BPT', 'Merdeka Sale: 31% off storewide', NULL, 8, TIMESTAMP(@d0 - INTERVAL 60 DAY, '10:30:00'), 'promo@alpro.test', NULL, @bpt, 'Promotion,Seasonal', NULL, NULL, 1, 1, 1, NULL, 0, 'Promotion - hero banner', 'full', 92, 2600, 420, 70, 50, 4, 40, 0, 0),
('July Mega Clearance_Engagement', 'July Mega Clearance - last chance', NULL, 8, TIMESTAMP(@d0 - INTERVAL 95 DAY, '10:30:00'), 'promo@alpro.test', NULL, @eng, 'Promotion', NULL, NULL, 1, 1, 1, NULL, 0, 'Promotion - hero banner', 'full', 95, 1500, 60, 900, 55, 20, 300, 0, 0),
('Mid-Year Health Check_Mirza', 'Mid-year health check: are you on track?', NULL, 8, TIMESTAMP(@d0 - INTERVAL 120 DAY, '10:30:00'), 'newsletter@alpro.test', NULL, @me, 'Health tips', NULL, NULL, 0, 1, 1, NULL, 0, 'Health tips digest', 'full', 88, 0, 90, 60, 50, 1, 10, 0, 0),
('Mother''s Day Gifts_BPT', 'Mother''s Day gifts she will love', NULL, 8, TIMESTAMP(@d0 - INTERVAL 165 DAY, '10:30:00'), 'promo@alpro.test', NULL, @bpt, 'Promotion,Seasonal', NULL, NULL, 1, 1, 1, NULL, 0, 'Promotion - hero banner', 'full', 80, 2300, 200, 55, 50, 2, 12, 0, 0),
('Ramadan Kareem Promo_Engagement', 'Ramadan Kareem from Alpro', NULL, 8, TIMESTAMP(@d0 - INTERVAL 230 DAY, '10:30:00'), 'newsletter@alpro.test', NULL, @eng, 'Seasonal', NULL, NULL, 1, 0, 1, NULL, 0, 'Basic newsletter', 'full', 85, 2000, 0, 50, 50, 2, 10, 0, 0),
('Chinese New Year Ang Pow_BPT', 'Huat ah! CNY ang pow deals', NULL, 8, TIMESTAMP(@d0 - INTERVAL 265 DAY, '10:30:00'), 'promo@alpro.test', NULL, @bpt, 'Promotion,Seasonal', NULL, NULL, 1, 1, 1, NULL, 0, 'Promotion - hero banner', 'full', 90, 2500, 250, 45, 50, 3, 10, 0, 0),
('Year-End Thank You_Mirza', 'Thank you for a wonderful year', NULL, 8, TIMESTAMP(@d0 - INTERVAL 320 DAY, '10:30:00'), 'newsletter@alpro.test', NULL, @me, 'Seasonal', NULL, NULL, 1, 1, 1, NULL, 0, 'Basic newsletter', 'full', 88, 2200, 180, 50, 50, 2, 10, 0, 0);

-- Archived from Completed (report still reachable), deleted newsletter.
INSERT INTO `demo_nl` (`name`, `subject`, `status`, `arch_from`, `at`, `sender`, `req`, `tags`, `tpl`, `is_deleted`, `log_mode`, `pct`, `open_bp`, `click_bp`, `bounce_bp`, `hard_pct`, `complaint_bp`, `unsub_bp`) VALUES
('Black Friday Preview_BPT', 'Black Friday preview for members', 9, 8, TIMESTAMP(@d0 - INTERVAL 355 DAY, '10:30:00'), 'promo@alpro.test', @bpt, 'Promotion', 'Promotion - hero banner', 0, 'full', 80, 2050, 140, 60, 50, 2, 10),
('Old Promo (deleted)_BPT', 'An old promotion', 8, NULL, TIMESTAMP(@d0 - INTERVAL 200 DAY, '10:30:00'), 'promo@alpro.test', @bpt, 'Promotion', 'Basic newsletter', 1, 'full', 50, 1800, 120, 60, 50, 0, 10);

-- Sending (part-sent, mailbox simulator contacts only).
INSERT INTO `demo_nl` (`name`, `subject`, `preheader`, `status`, `at`, `sender`, `req`, `tags`, `uat`, `tpl`, `log_mode`, `pct`, `open_bp`, `click_bp`, `win`) VALUES
('Weekend Flash Sale_Mirza', 'Weekend Flash Sale starts now', '48 hours only', 7, @now - INTERVAL 45 MINUTE, 'promo@alpro.test', @me, 'Promotion', 1, 'Promotion - hero banner', 'partial', 60, 3000, 500, 30);

-- Scheduled (approved, mailbox simulator contacts only).
INSERT INTO `demo_nl` (`name`, `subject`, `preheader`, `status`, `at`, `sender`, `reply`, `req`, `tags`, `exc_seg`, `uat`, `tpl`) VALUES
('November Skincare Launch_Mirza', 'New: the November skincare edit', 'Members get 20% off launch week', 6, TIMESTAMP(@d0 + INTERVAL 2 DAY, '10:00:00'), 'promo@alpro.test', NULL, @me, 'Product launch', NULL, 1, 'Promotion - hero banner'),
('11.11 Mega Sale_BPT', '11.11 Mega Sale - set your reminder', NULL, 6, TIMESTAMP(@d0 + INTERVAL 19 DAY, '11:11:00'), 'bpt@alpro.test', 'care@alpro.test', @bpt, 'Promotion,Seasonal', 'Dormant members', 1, 'Promotion - hero banner');

-- Pending approval: yours, someone else's, and one whose send time passed.
INSERT INTO `demo_nl` (`name`, `subject`, `status`, `at`, `sender`, `req`, `tags`, `inc_seg`, `uat`, `tpl`) VALUES
('Year-End Sale Teaser_Mirza', 'Psst... our Year-End Sale is coming', 2, TIMESTAMP(@d0 + INTERVAL 5 DAY, '10:00:00'), 'promo@alpro.test', @me, 'Promotion,Seasonal', NULL, 1, 'Promotion - hero banner'),
('Pharmacist Consultation Week_BPT', 'Ask our pharmacists - free consultations', 2, TIMESTAMP(@d0 + INTERVAL 7 DAY, '10:30:00'), 'bpt@alpro.test', @bpt, 'Health tips,Event', 'Gold and Platinum members', 0, 'Event invitation'),
('Flu Vaccine Reminder_Engagement', 'Flu season: get vaccinated at Alpro', 2, TIMESTAMP(@d0 - INTERVAL 1 DAY, '09:00:00'), 'newsletter@alpro.test', @eng, 'Health tips', NULL, 0, 'Health tips digest');

-- Drafts: blank, complete, rejected, missing sender + subject, past date;
-- archived draft.
INSERT INTO `demo_nl` (`name`, `subject`, `preheader`, `status`, `arch_from`, `at`, `sender`, `req`, `tags`, `tpl`, `comment`) VALUES
('Untitled newsletter (demo)', NULL, NULL, 1, NULL, NULL, NULL, @me, NULL, NULL, NULL),
('December Health Tips_Mirza', 'December health tips for the holidays', 'Stay well through the festive season', 1, NULL, TIMESTAMP(@d0 + INTERVAL 25 DAY, '10:00:00'), 'newsletter@alpro.test', @me, 'Health tips', 'Health tips digest', NULL),
('Baby Fair Invitation_Mirza', 'Alpro Baby Fair - you are invited', NULL, 1, NULL, TIMESTAMP(@d0 + INTERVAL 12 DAY, '10:00:00'), 'events@alpro.test', @me, 'Event', 'Event invitation', 'Please replace the hero image (wrong outlet) and fix the voucher expiry date before resubmitting.'),
('Supplier Showcase_BPT', NULL, NULL, 1, NULL, TIMESTAMP(@d0 + INTERVAL 30 DAY, '10:00:00'), NULL, @bpt, 'Partner brand', 'Basic newsletter', NULL),
('Outlet Opening Penang_Engagement', 'Alpro George Town is open!', NULL, 1, NULL, TIMESTAMP(@d0 - INTERVAL 2 DAY, '10:00:00'), 'events@alpro.test', @eng, 'Event', 'Event invitation', NULL),
('Draft idea - Loyalty Revamp_Mirza', 'Your loyalty points just got better', NULL, 9, 1, NULL, 'newsletter@alpro.test', @me, 'Internal test', 'Basic newsletter', NULL);

-- Submission / decision times: submitted 2 days before sending (pending ones
-- a few hours ago), approved the day before; the rejected draft was
-- submitted then rejected yesterday.
UPDATE `demo_nl` SET `sub_at` = `at` - INTERVAL 2 DAY, `rev_at` = `at` - INTERVAL 1 DAY
 WHERE `status` IN (6, 7, 8) OR (`status` = 9 AND `arch_from` = 8);
UPDATE `demo_nl` SET `sub_at` = @now - INTERVAL 3 HOUR WHERE `status` = 2;
UPDATE `demo_nl` SET `sub_at` = @now - INTERVAL 2 DAY, `rev_at` = @now - INTERVAL 1 DAY WHERE `comment` IS NOT NULL;
-- Send now: approved minutes before it went out.
UPDATE `demo_nl` SET `sub_at` = `at` - INTERVAL 3 HOUR, `rev_at` = `at` - INTERVAL 2 MINUTE WHERE `send_now` = 1;

INSERT INTO `edm_campaigns` (`name`, `subject`, `preheader`, `tag_ids`, `sender_id`, `reply_to_id`, `audience`, `send_now`,
    `track_opens`, `track_clicks`, `utm_enabled`, `utm`, `status`, `archived_from`, `scheduled_at`,
    `requested_by`, `requested_by_name`, `submitted_by`, `submitted_by_name`, `submitted_department`, `submitted_at`,
    `reviewed_by`, `reviewed_by_name`, `reviewed_at`, `review_comment`, `created_at`, `updated_at`, `deleted_at`)
SELECT d.`name`, d.`subject`, d.`preheader`,
       COALESCE((SELECT JSON_ARRAYAGG(m.`id`) FROM `edm_master_tags` m WHERE m.`deleted_at` IS NULL AND FIND_IN_SET(m.`name`, d.`tags`) > 0), JSON_ARRAY()),
       (SELECT s.`id` FROM `edm_senders` s WHERE s.`email` = d.`sender` AND s.`deleted_at` IS NULL LIMIT 1),
       (SELECT s.`id` FROM `edm_senders` s WHERE s.`email` = d.`reply` AND s.`deleted_at` IS NULL LIMIT 1),
       JSON_OBJECT(
           'include', JSON_OBJECT(
               'segments', IF(d.`inc_seg` IS NULL, JSON_ARRAY(), JSON_ARRAY((SELECT g.`id` FROM `edm_segments` g WHERE g.`name` = d.`inc_seg` AND g.`deleted_at` IS NULL LIMIT 1))),
               'contacts', IF(d.`uat` = 1,
                   COALESCE((SELECT JSON_ARRAYAGG(c.`id`) FROM `edm_contacts` c WHERE c.`email` LIKE 'success+uat%@simulator.amazonses.com' AND c.`deleted_at` IS NULL), JSON_ARRAY()),
                   JSON_ARRAY())),
           'exclude', JSON_OBJECT(
               'segments', IF(d.`exc_seg` IS NULL, JSON_ARRAY(), JSON_ARRAY((SELECT g.`id` FROM `edm_segments` g WHERE g.`name` = d.`exc_seg` AND g.`deleted_at` IS NULL LIMIT 1))),
               'contacts', JSON_ARRAY())),
       d.`send_now`, d.`t_open`, d.`t_click`, d.`utm_on`,
       IF(d.`utm_campaign` IS NULL, NULL, JSON_OBJECT('source', 'edm', 'medium', 'email', 'campaign', d.`utm_campaign`, 'content', 'hero')),
       d.`status`, d.`arch_from`, d.`at`,
       d.`req`, (SELECT st.`nama_staff` FROM `staff` st WHERE st.`id` = d.`req`),
       IF(d.`sub_at` IS NULL, NULL, d.`req`),
       IF(d.`sub_at` IS NULL, NULL, (SELECT st.`nama_staff` FROM `staff` st WHERE st.`id` = d.`req`)),
       IF(d.`sub_at` IS NULL, NULL, (SELECT dp.`depart_name` FROM `staff` st LEFT JOIN `staff_department` dp ON dp.`id` = st.`department` WHERE st.`id` = d.`req`)),
       d.`sub_at`,
       IF(d.`rev_at` IS NULL, NULL, @boss), IF(d.`rev_at` IS NULL, NULL, @boss_name), d.`rev_at`, d.`comment`,
       COALESCE(LEAST(d.`at`, @now) - INTERVAL 6 DAY, @now - INTERVAL 1 HOUR),
       COALESCE(d.`rev_at`, d.`sub_at`, @now - INTERVAL 1 HOUR),
       IF(d.`is_deleted` = 1, @now - INTERVAL 20 DAY, NULL)
  FROM `demo_nl` d
 WHERE NOT EXISTS (SELECT 1 FROM `edm_campaigns` x WHERE x.`name` = d.`name`);

-- Designs: a copy of the profile's template (blank draft has none).
INSERT INTO `edm_campaign_content` (`campaign_id`, `html`, `editor_json`, `version`, `created_at`, `updated_at`)
SELECT c.`id`, t.`html`, t.`editor_json`, 1, c.`created_at`, c.`updated_at`
  FROM `demo_nl` d
  JOIN `edm_campaigns` c ON c.`name` = d.`name`
  JOIN `edm_templates` t ON t.`name` = d.`tpl` AND t.`deleted_at` IS NULL
 WHERE NOT EXISTS (SELECT 1 FROM `edm_campaign_content` x WHERE x.`campaign_id` = c.`id`);

UPDATE `edm_campaign_content` cc
  JOIN `edm_campaigns` c ON c.`id` = cc.`campaign_id`
  JOIN `demo_nl` d ON d.`name` = c.`name`
  JOIN `edm_templates` t ON t.`name` = d.`tpl` AND t.`deleted_at` IS NULL
   SET cc.`html` = t.`html`, cc.`editor_json` = t.`editor_json`, cc.`updated_at` = @now
 WHERE cc.`editor_json` IS NULL AND t.`editor_json` IS NOT NULL;

-- ----------------------------------------------------------------------
-- Send log: one row per recipient, results drawn from the profile.
-- h1 bounce / complaint, h2 open, h3 click, h4 unsubscribe, h5 timing,
-- h6 skip / fail - each CRC32 of (newsletter, contact).
-- ----------------------------------------------------------------------
INSERT INTO `edm_send_log` (`campaign_id`, `email`, `sent_at`, `status`, `ses_message_id`, `error`,
    `delivered_at`, `opened_at`, `clicked_at`, `bounced_at`, `complained_at`, `unsubscribed_at`,
    `unsubscribe_reason`, `unsubscribe_comment`, `created_at`, `updated_at`)
SELECT r.`campaign_id`, r.`email`, r.`sent_at`,
       CASE WHEN r.`skip` THEN 6 WHEN r.`fail` THEN 5 WHEN r.`noev` THEN 1 WHEN r.`bounced` THEN 3 WHEN r.`complained` THEN 4 ELSE 2 END,
       IF(r.`skip` OR r.`fail`, NULL, CONCAT('demo-', r.`campaign_id`, '-', r.`contact_id`)),
       CASE WHEN r.`skip` THEN ELT(1 + r.h6 % 3, 'suppressed: hard_bounce', 'cap: 2 per week', 'suppressed: unsubscribed')
            WHEN r.`fail` THEN 'SES MessageRejected: Email address is not verified.' END,
       IF(r.`skip` OR r.`fail` OR r.`noev` OR r.`bounced`, NULL, r.`sent_at` + INTERVAL (2 + r.h5 % 20) SECOND),
       IF(r.`opened` AND NOT (r.`skip` OR r.`fail` OR r.`noev`), r.`sent_at` + INTERVAL (30 + FLOOR(r.`w` * 60 * POW((r.h2 % 1000) / 1000, 3))) SECOND, NULL),
       IF(r.`clicked` AND NOT (r.`skip` OR r.`fail` OR r.`noev`), r.`sent_at` + INTERVAL (90 + FLOOR(r.`w` * 60 * POW((r.h2 % 1000) / 1000, 3)) + r.h3 % 120) SECOND, NULL),
       IF(r.`bounced` AND NOT (r.`skip` OR r.`fail` OR r.`noev`), r.`sent_at` + INTERVAL 6 SECOND, NULL),
       IF(r.`complained` AND NOT (r.`skip` OR r.`fail` OR r.`noev`), r.`sent_at` + INTERVAL (300 + FLOOR(r.`w` * 60 * POW((r.h1 % 1000) / 1000, 2))) SECOND, NULL),
       IF(r.`unsub` AND NOT (r.`skip` OR r.`fail` OR r.`noev`), r.`sent_at` + INTERVAL (200 + FLOOR(r.`w` * 60 * POW((r.h4 % 1000) / 1000, 2))) SECOND, NULL),
       -- Reason picked on the unsubscribe page; about half give none (one-click).
       IF(r.`unsub` AND NOT (r.`skip` OR r.`fail` OR r.`noev`),
          CASE WHEN r.h5 % 100 < 50 THEN NULL WHEN r.h5 % 100 < 68 THEN 'too_many_general'
               WHEN r.h5 % 100 < 80 THEN 'too_many_from_you' WHEN r.h5 % 100 < 88 THEN 'not_relevant'
               WHEN r.h5 % 100 < 93 THEN 'did_not_sign_up' WHEN r.h5 % 100 < 97 THEN 'no_longer_client' ELSE 'other' END, NULL),
       IF(r.`unsub` AND NOT (r.`skip` OR r.`fail` OR r.`noev`) AND r.h5 % 100 >= 97,
          ELT(1 + r.h5 % 3, 'Moving overseas', 'I prefer WhatsApp updates', 'Too many promotions, not enough health tips'), NULL),
       r.`sent_at`, r.`sent_at`
  FROM (
    SELECT b.*,
           (b.h6 < b.`skip_bp`) AS `skip`,
           (b.h6 >= b.`skip_bp` AND b.h6 < b.`skip_bp` + b.`fail_bp`) AS `fail`,
           (b.`log_mode` = 'noevents') AS `noev`,
           (b.h1 < b.`bounce_bp`) AS `bounced`,
           (b.h1 >= b.`bounce_bp` AND b.h1 < b.`bounce_bp` + b.`complaint_bp`) AS `complained`,
           (b.h1 >= b.`bounce_bp` AND b.h3 < b.`click_bp`) AS `clicked`,
           (b.h1 >= b.`bounce_bp` AND (b.h2 < b.`open_bp` OR b.h3 < b.`click_bp`)) AS `opened`,
           (b.h1 >= b.`bounce_bp` + b.`complaint_bp` AND b.h4 < b.`unsub_bp`) AS `unsub`,
           b.`at` + INTERVAL (b.h5 % LEAST(3600, b.`win` * 30)) SECOND AS `sent_at`,
           -- Spread window, never past now (recent newsletters).
           GREATEST(1, LEAST(b.`win`, TIMESTAMPDIFF(MINUTE, b.`at`, @now) - 70)) AS `w`
      FROM (
        SELECT c.`id` AS `campaign_id`, ct.`id` AS `contact_id`, ct.`email`, d.*,
               CRC32(CONCAT('b', c.`id`, '-', ct.`id`)) % 10000 AS h1,
               CRC32(CONCAT('o', c.`id`, '-', ct.`id`)) % 10000 AS h2,
               CRC32(CONCAT('k', c.`id`, '-', ct.`id`)) % 10000 AS h3,
               CRC32(CONCAT('u', c.`id`, '-', ct.`id`)) % 10000 AS h4,
               CRC32(CONCAT('t', c.`id`, '-', ct.`id`)) % 100000 AS h5,
               CRC32(CONCAT('s', c.`id`, '-', ct.`id`)) % 10000 AS h6
          FROM `demo_nl` d
          JOIN `edm_campaigns` c ON c.`name` = d.`name`
          JOIN `edm_contacts` ct
            ON (d.`log_mode` = 'partial' AND ct.`email` LIKE 'success+uat%@simulator.amazonses.com')
            OR (d.`log_mode` IN ('full', 'noevents') AND ct.`email` LIKE '%.test')
         WHERE d.`log_mode` <> 'none'
           AND (d.`log_mode` = 'partial' OR (ct.`created_at` <= d.`at` AND ct.`status` = 1))
           AND CRC32(CONCAT('p', c.`id`, '-', ct.`id`)) % 100 < d.`pct`
           AND NOT EXISTS (SELECT 1 FROM `edm_send_log` s WHERE s.`campaign_id` = c.`id`)
      ) b
  ) r;

-- ----------------------------------------------------------------------
-- SES events (Settings > SES event log, soft / hard bounce chart), one per
-- send log timestamp. Bounce type from CRC32 against the profile hard_pct.
-- ----------------------------------------------------------------------
INSERT INTO `edm_ses_events` (`ses_message_id`, `event_type`, `email`, `payload`, `occurred_at`, `created_at`, `updated_at`)
SELECT e.`mid`, e.`type`, e.`email`,
       JSON_SET(
           JSON_OBJECT('eventType', e.`type`,
               'mail', JSON_OBJECT('messageId', e.`mid`, 'timestamp', DATE_FORMAT(e.`sent_at` - INTERVAL 8 HOUR, '%Y-%m-%dT%H:%i:%s.000Z'),
                                   'source', 'newsletter@alpro.test', 'destination', JSON_ARRAY(e.`email`))),
           CONCAT('$.', LOWER(e.`type`)),
           CASE e.`type`
               WHEN 'Send' THEN JSON_OBJECT()
               WHEN 'Delivery' THEN JSON_OBJECT('timestamp', e.`iso`, 'processingTimeMillis', 1200 + e.`h` % 900,
                   'recipients', JSON_ARRAY(e.`email`), 'smtpResponse', '250 2.0.0 OK', 'reportingMTA', 'a8-50.smtp-out.amazonses.com')
               WHEN 'Open' THEN JSON_OBJECT('timestamp', e.`iso`, 'ipAddress', CONCAT('203.0.113.', e.`h` % 250),
                   'userAgent', ELT(1 + e.`h` % 3, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'Mozilla/5.0 (Linux; Android 14)'))
               WHEN 'Click' THEN JSON_OBJECT('timestamp', e.`iso`, 'ipAddress', CONCAT('203.0.113.', e.`h` % 250),
                   'link', ELT(1 + e.`h` % 3, 'https://www.alpro.test/promo?utm_source=edm&utm_medium=email', 'https://www.alpro.test/outlets?utm_source=edm&utm_medium=email', 'https://shop.alpro.test/?utm_source=edm&utm_medium=email'),
                   'userAgent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)')
               WHEN 'Bounce' THEN JSON_OBJECT('timestamp', e.`iso`,
                   'bounceType', IF(e.`hard`, 'Permanent', 'Transient'),
                   'bounceSubType', IF(e.`hard`, 'General', ELT(1 + e.`h` % 2, 'MailboxFull', 'General')),
                   'bouncedRecipients', JSON_ARRAY(JSON_OBJECT('emailAddress', e.`email`, 'action', 'failed', 'status', IF(e.`hard`, '5.1.1', '4.2.2'),
                       'diagnosticCode', IF(e.`hard`, 'smtp; 550 5.1.1 user unknown', 'smtp; 452 4.2.2 mailbox full'))),
                   'feedbackId', CONCAT('fb-', e.`mid`))
               WHEN 'Complaint' THEN JSON_OBJECT('timestamp', e.`iso`, 'complaintFeedbackType', 'abuse',
                   'complainedRecipients', JSON_ARRAY(JSON_OBJECT('emailAddress', e.`email`)), 'feedbackId', CONCAT('fb-', e.`mid`))
           END),
       e.`at`, e.`at`, e.`at`
  FROM (
    SELECT x.*, DATE_FORMAT(x.`at` - INTERVAL 8 HOUR, '%Y-%m-%dT%H:%i:%s.000Z') AS `iso`,
           CRC32(x.`mid`) % 100000 AS `h`
      FROM (
        SELECT s.`ses_message_id` AS `mid`, t.`type`, s.`email`, s.`sent_at`,
               CASE t.`type` WHEN 'Send' THEN s.`sent_at` WHEN 'Delivery' THEN s.`delivered_at` WHEN 'Open' THEN s.`opened_at`
                    WHEN 'Click' THEN s.`clicked_at` WHEN 'Bounce' THEN s.`bounced_at` WHEN 'Complaint' THEN s.`complained_at` END AS `at`,
               (CRC32(CONCAT('h', s.`ses_message_id`)) % 100 < d.`hard_pct`) AS `hard`
          FROM `edm_send_log` s
          JOIN `edm_campaigns` c ON c.`id` = s.`campaign_id`
          JOIN `demo_nl` d ON d.`name` = c.`name` AND d.`log_mode` IN ('full', 'partial')
          JOIN (SELECT 'Send' AS `type` UNION ALL SELECT 'Delivery' UNION ALL SELECT 'Open'
                UNION ALL SELECT 'Click' UNION ALL SELECT 'Bounce' UNION ALL SELECT 'Complaint') t
         WHERE s.`ses_message_id` LIKE 'demo-%'
      ) x
     WHERE x.`at` IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM `edm_ses_events` v WHERE v.`ses_message_id` = x.`mid` AND v.`event_type` = x.`type`)
  ) e;

-- ----------------------------------------------------------------------
-- What the events did to the masterlist (as EventHandler / unsubscribe.php
-- do live): hard bounce and complaint suppress the address, an unsubscribe
-- suppresses it and marks the contact unsubscribed; a hard bounce marks the
-- contact bounced.
-- ----------------------------------------------------------------------
INSERT INTO `edm_suppressions` (`email`, `reason`, `source`, `note`, `created_at`, `updated_at`)
SELECT x.`email`, MIN(x.`reason`), MIN(x.`source`), MIN(x.`note`), MIN(x.`at`), MIN(x.`at`)
  FROM (
    SELECT e.`email`, 'hard_bounce' AS `reason`, 'Amazon SES' AS `source`, 'General smtp; 550 5.1.1 user unknown' AS `note`, MIN(e.`occurred_at`) AS `at`
      FROM `edm_ses_events` e
     WHERE e.`event_type` = 'Bounce' AND e.`ses_message_id` LIKE 'demo-%'
       AND JSON_UNQUOTE(JSON_EXTRACT(e.`payload`, '$.bounce.bounceType')) = 'Permanent'
     GROUP BY e.`email`
    UNION ALL
    SELECT e.`email`, 'spam_complaint', 'Amazon SES', 'abuse', MIN(e.`occurred_at`)
      FROM `edm_ses_events` e
     WHERE e.`event_type` = 'Complaint' AND e.`ses_message_id` LIKE 'demo-%'
     GROUP BY e.`email`
    UNION ALL
    SELECT s.`email`, 'unsubscribed', 'Unsubscribe link', CONCAT('Newsletter #', MIN(s.`campaign_id`)), MIN(s.`unsubscribed_at`)
      FROM `edm_send_log` s
     WHERE s.`unsubscribed_at` IS NOT NULL AND s.`ses_message_id` LIKE 'demo-%'
     GROUP BY s.`email`
  ) x
 WHERE NOT EXISTS (SELECT 1 FROM `edm_suppressions` p WHERE p.`email` = x.`email` AND p.`deleted_at` IS NULL)
 GROUP BY x.`email`;

UPDATE `edm_contacts` c
   SET c.`status` = 2, c.`updated_at` = @now
 WHERE c.`status` = 1 AND c.`email` LIKE '%.test'
   AND EXISTS (SELECT 1 FROM `edm_send_log` s WHERE s.`email` = c.`email` AND s.`unsubscribed_at` IS NOT NULL);
UPDATE `edm_contacts` c
   SET c.`status` = 3, c.`updated_at` = @now
 WHERE c.`status` = 1 AND c.`email` LIKE '%.test'
   AND EXISTS (SELECT 1 FROM `edm_suppressions` p WHERE p.`email` = c.`email` AND p.`reason` = 'hard_bounce' AND p.`deleted_at` IS NULL);

-- Contacts unsubscribed before the module (every 97th) and the other reasons:
-- manual (also an address that is not a contact), inactive, soft_bounce,
-- and one removed again (restored).
INSERT INTO `edm_suppressions` (`email`, `reason`, `source`, `note`, `created_by`, `created_by_name`, `created_at`, `updated_at`, `deleted_at`)
SELECT x.`email`, x.`reason`, x.`source`, x.`note`, x.`by`, x.`by_name`, x.`at`, x.`at`, x.`deleted_at`
  FROM (
    SELECT c.`email`, 'unsubscribed' AS `reason`, 'Unsubscribe link' AS `source`, 'Before the EDM module (imported)' AS `note`,
           NULL AS `by`, NULL AS `by_name`, c.`subscribed_at` + INTERVAL 90 DAY AS `at`, NULL AS `deleted_at`
      FROM `edm_contacts` c
     WHERE c.`email` LIKE '%.test' AND c.`status` = 2 AND c.`deleted_at` IS NULL
       AND NOT EXISTS (SELECT 1 FROM `edm_send_log` s WHERE s.`email` = c.`email` AND s.`unsubscribed_at` IS NOT NULL)
    UNION ALL
    SELECT 'complaint.by.phone@gmail.test', 'manual', 'Customer service', 'Asked by phone to stop all marketing email', @me, @me_name, @now - INTERVAL 40 DAY, NULL
    UNION ALL
    SELECT 'ex.staff@alpro-staff.test', 'manual', 'HR request', 'Former staff member', @me, @me_name, @now - INTERVAL 75 DAY, NULL
    UNION ALL
    SELECT c.`email`, 'inactive', 'Sunset policy', 'No opens for 12 months', @me, @me_name, @now - INTERVAL 15 DAY, NULL
      FROM `edm_contacts` c
     WHERE c.`email` LIKE '%.test' AND c.`status` = 1 AND c.`deleted_at` IS NULL
       AND JSON_UNQUOTE(JSON_EXTRACT(c.`fields`, '$.dormant')) = '1' AND c.`id` % 9 = 0
    UNION ALL
    SELECT 'full.mailbox@yahoo.test', 'soft_bounce', 'Amazon SES', 'Mailbox full three times in a row', NULL, NULL, @now - INTERVAL 22 DAY, NULL
    UNION ALL
    SELECT 'restored.member@gmail.test', 'manual', 'Customer service', 'Added by mistake - removed (restored)', @me, @me_name, @now - INTERVAL 50 DAY, @now - INTERVAL 49 DAY
  ) x
 WHERE NOT EXISTS (SELECT 1 FROM `edm_suppressions` p WHERE p.`email` = x.`email`);

-- ----------------------------------------------------------------------
-- Automation: a custom trigger, workflows of every kind and status
-- (event now / delay in days / delay in hours; schedule once / daily /
-- weekly / monthly; newsletter or template content, or none) and
-- autoresponders.
-- ----------------------------------------------------------------------
INSERT INTO `edm_triggers` (`key`, `label`, `description`, `created_by`, `created_by_name`, `created_at`, `updated_at`)
SELECT 'app_download', 'Downloaded the Alpro app', 'Fired by the mobile app on first login (demo)', @me, @me_name, @now - INTERVAL 60 DAY, @now - INTERVAL 60 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `edm_triggers` WHERE `key` = 'app_download' AND `deleted_at` IS NULL);

INSERT INTO `edm_workflows` (`name`, `description`, `trigger_type`, `trigger`, `timing`, `status`, `definition`, `content_type`, `content_id`,
    `created_by`, `created_by_name`, `created_at`, `updated_at`)
SELECT w.`name`, w.`description`, w.`ttype`, w.`trig`, w.`timing`, w.`status`, NULL, w.`ctype`,
       CASE w.`ctype`
           WHEN 'template' THEN (SELECT t.`id` FROM `edm_templates` t WHERE t.`name` = w.`content` AND t.`deleted_at` IS NULL LIMIT 1)
           WHEN 'campaign' THEN (SELECT c.`id` FROM `edm_campaigns` c WHERE c.`name` = w.`content` AND c.`deleted_at` IS NULL LIMIT 1)
       END,
       @me, @me_name, @now - INTERVAL w.`age` DAY, @now - INTERVAL w.`age` DAY
  FROM (
    SELECT 'Birthday voucher (live)' AS `name`, 'Birthday greeting with a voucher on the day.' AS `description`, 'event' AS `ttype`, 'birthday' AS `trig`,
           JSON_OBJECT('send', 'now', 'delay_value', NULL, 'delay_unit', NULL, 'at_time', '09:00') AS `timing`, 2 AS `status`,
           'template' AS `ctype`, 'Birthday voucher' AS `content`, 90 AS `age`
    UNION ALL
    SELECT 'App download follow-up', 'Two days after the first app login.', 'event', 'app_download',
           JSON_OBJECT('send', 'delay', 'delay_value', 2, 'delay_unit', 'days', 'at_time', '10:00'), 3,
           'campaign', 'Smart Fibre_SuGO365 Mirza', 45
    UNION ALL
    SELECT 'Soft bounce retry notice', 'Six hours after a soft bounce.', 'event', 'soft_bounce',
           JSON_OBJECT('send', 'delay', 'delay_value', 6, 'delay_unit', 'hours', 'at_time', NULL), 1,
           'template', 'Basic newsletter', 20
    UNION ALL
    SELECT 'Monthly health tips', 'First day of every month.', 'schedule', NULL,
           JSON_OBJECT('repeat', 'monthly', 'date', NULL, 'time', '10:00', 'weekday', NULL, 'day', 1), 2,
           'template', 'Health tips digest', 120
    UNION ALL
    SELECT 'Weekly promo digest', 'Every Monday morning.', 'schedule', NULL,
           JSON_OBJECT('repeat', 'weekly', 'date', NULL, 'time', '09:30', 'weekday', 1, 'day', NULL), 1,
           'template', 'Promotion - hero banner', 10
    UNION ALL
    SELECT 'Penang outlet launch (one-off)', 'Once, on the opening day.', 'schedule', NULL,
           JSON_OBJECT('repeat', 'once', 'date', DATE_FORMAT(@d0 + INTERVAL 10 DAY, '%Y-%m-%d'), 'time', '08:00', 'weekday', NULL, 'day', NULL), 2,
           'template', 'Event invitation', 5
    UNION ALL
    SELECT 'Daily low-stock alert (paused)', 'Every day - paused while stock data is checked.', 'schedule', NULL,
           JSON_OBJECT('repeat', 'daily', 'date', NULL, 'time', '07:00', 'weekday', NULL, 'day', NULL), 3,
           NULL, NULL, 30
  ) w
 WHERE NOT EXISTS (SELECT 1 FROM `edm_workflows` x WHERE x.`name` = w.`name` AND x.`deleted_at` IS NULL);

INSERT INTO `edm_autoresponders` (`name`, `workflow_id`, `offset_days`, `subject`, `status`, `created_at`, `updated_at`)
SELECT a.`name`, w.`id`, a.`offset_days`, a.`subject`, a.`status`, @now, @now
  FROM (
    SELECT 'Birthday greeting' AS `name`, 'Birthday voucher (live)' AS `workflow`, 0 AS `offset_days`, 'Happy birthday, {{FirstName}}! A gift inside' AS `subject`, 2 AS `status`
    UNION ALL SELECT 'Birthday voucher reminder', 'Birthday voucher (live)', 7, 'Your birthday voucher {{voucher_code}} expires soon', 2
    UNION ALL SELECT 'App welcome', 'App download follow-up', 0, 'Welcome to the Alpro app', 3
    UNION ALL SELECT 'App first-order nudge', 'App download follow-up', 5, 'RM10 off your first app order', 1
  ) a
  JOIN `edm_workflows` w ON w.`name` = a.`workflow` AND w.`deleted_at` IS NULL
 WHERE NOT EXISTS (SELECT 1 FROM `edm_autoresponders` x WHERE x.`name` = a.`name` AND x.`workflow_id` = w.`id` AND x.`deleted_at` IS NULL);

DROP TEMPORARY TABLE IF EXISTS `demo_nl`;

-- Summary
SELECT 'contacts' AS `table`, COUNT(*) AS `active_rows` FROM `edm_contacts` WHERE `deleted_at` IS NULL
UNION ALL SELECT 'newsletters', COUNT(*) FROM `edm_campaigns` WHERE `deleted_at` IS NULL
UNION ALL SELECT 'send log', COUNT(*) FROM `edm_send_log` WHERE `deleted_at` IS NULL
UNION ALL SELECT 'ses events', COUNT(*) FROM `edm_ses_events` WHERE `deleted_at` IS NULL
UNION ALL SELECT 'suppressions', COUNT(*) FROM `edm_suppressions` WHERE `deleted_at` IS NULL
UNION ALL SELECT 'workflows', COUNT(*) FROM `edm_workflows` WHERE `deleted_at` IS NULL;
