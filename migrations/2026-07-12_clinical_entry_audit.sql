-- ThalVital additive migration — clinical entry screen (grouping / serology / antibody writes)
-- Adds field + before/after value capture to the audit trail (so antibody adds and
-- blood-group / phenotype anchor changes leave a real who-changed-what-from-what row),
-- plus the one missing forward reagent column (Anti-A,B) on blood_groupings.
--
-- Additive and reversible: only ADD COLUMN, nothing dropped or altered in place.
-- Apply on existing databases AFTER a verified backup (see DEPLOYMENT.md → Later releases).
-- Fresh installs get these columns from schema.sql; do not run this there.

ALTER TABLE audit_log       ADD COLUMN IF NOT EXISTS field        VARCHAR(80) NULL AFTER target_id;
ALTER TABLE audit_log       ADD COLUMN IF NOT EXISTS before_value TEXT        NULL AFTER field;
ALTER TABLE audit_log       ADD COLUMN IF NOT EXISTS after_value  TEXT        NULL AFTER before_value;
ALTER TABLE blood_groupings ADD COLUMN IF NOT EXISTS anti_AB      VARCHAR(3)  NULL AFTER anti_B;
