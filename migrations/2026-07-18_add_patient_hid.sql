-- HID is a nullable patient-level identifier. Existing visit HID values are not
-- backfilled because older rows may contain the former timestamp fallback rather
-- than a hospital-issued identifier.
SET @add_patient_hid = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='patients' AND COLUMN_NAME='hid'),
  'DO 1',
  'ALTER TABLE patients ADD COLUMN hid VARCHAR(30) NULL AFTER patient_id'
);
PREPARE add_patient_hid_stmt FROM @add_patient_hid;
EXECUTE add_patient_hid_stmt;
DEALLOCATE PREPARE add_patient_hid_stmt;

SET @add_patient_hid_unique = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='patients' AND INDEX_NAME='uq_patients_hid'),
  'DO 1',
  'ALTER TABLE patients ADD UNIQUE INDEX uq_patients_hid (hid)'
);
PREPARE add_patient_hid_unique_stmt FROM @add_patient_hid_unique;
EXECUTE add_patient_hid_unique_stmt;
DEALLOCATE PREPARE add_patient_hid_unique_stmt;

-- A patient HID can legitimately recur across multiple transfusion visits, so the
-- former visit-level UNIQUE constraint is removed and replaced by a normal index.
SET @visits_hid_unique_index = (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='hid'
    AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY'
  ORDER BY INDEX_NAME LIMIT 1
);
SET @drop_visits_hid_unique = IF(
  @visits_hid_unique_index IS NULL,
  'DO 1',
  CONCAT('ALTER TABLE visits DROP INDEX `', REPLACE(@visits_hid_unique_index, '`', '``'), '`')
);
PREPARE drop_visits_hid_unique_stmt FROM @drop_visits_hid_unique;
EXECUTE drop_visits_hid_unique_stmt;
DEALLOCATE PREPARE drop_visits_hid_unique_stmt;

SET @make_visit_hid_nullable = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='hid' AND IS_NULLABLE='NO'),
  'ALTER TABLE visits MODIFY COLUMN hid VARCHAR(30) NULL',
  'DO 1'
);
PREPARE make_visit_hid_nullable_stmt FROM @make_visit_hid_nullable;
EXECUTE make_visit_hid_nullable_stmt;
DEALLOCATE PREPARE make_visit_hid_nullable_stmt;

SET @add_visit_hid_index = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND INDEX_NAME='idx_visit_hid'),
  'DO 1',
  'ALTER TABLE visits ADD INDEX idx_visit_hid (hid)'
);
PREPARE add_visit_hid_index_stmt FROM @add_visit_hid_index;
EXECUTE add_visit_hid_index_stmt;
DEALLOCATE PREPARE add_visit_hid_index_stmt;
