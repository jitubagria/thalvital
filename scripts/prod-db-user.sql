-- ThalVital production database users (least privilege).
-- Run once on the production MariaDB as an admin (e.g. root) AFTER creating the
-- `thalvital` database. Fill in strong passwords from your password manager first —
-- NEVER commit real passwords; this file ships only placeholders.
--
--   CREATE DATABASE thalvital CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1) Application user — CRUD only, NO DDL. This is what config.php's DB_USER/DB_PASS use.
--    The running app never issues CREATE/ALTER/DROP, so it must not hold those rights.
CREATE USER IF NOT EXISTS 'thalvital_app'@'localhost' IDENTIFIED BY 'REPLACE_WITH_STRONG_APP_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON thalvital.* TO 'thalvital_app'@'localhost';

-- 2) Migration user — schema/DDL, used by hand to apply schema.sql and additive migrations.
--    Keep these credentials OUT of config.php; use them only for migrations, then idle.
CREATE USER IF NOT EXISTS 'thalvital_migrate'@'localhost' IDENTIFIED BY 'REPLACE_WITH_STRONG_MIGRATE_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON thalvital.* TO 'thalvital_migrate'@'localhost';

FLUSH PRIVILEGES;
-- Verify:  SHOW GRANTS FOR 'thalvital_app'@'localhost';
--          SHOW GRANTS FOR 'thalvital_migrate'@'localhost';
