-- ThalVital additive migration — v1.1 location cascade (Country → State → City)
-- Adds organizations.country (city/state already exist). Non-destructive.
-- Apply on existing databases AFTER a verified backup (see DEPLOYMENT.md → Later releases).
-- Fresh installs get this column from schema.sql; do not run there.

ALTER TABLE organizations ADD COLUMN IF NOT EXISTS country VARCHAR(80) NULL AFTER type;

-- Backfill existing organizations that predate the column. IHTM (and any current org)
-- is in India; set explicitly rather than a column DEFAULT so the tool stays country-neutral.
UPDATE organizations SET country = 'India' WHERE country IS NULL OR country = '';
