ALTER TABLE staff
    ADD COLUMN edm INT NOT NULL DEFAULT 0
    COMMENT '0 = none, 1 = superadmin, 2 = admin, 3 = bpt team, 4 = management'
    AFTER procurement;
