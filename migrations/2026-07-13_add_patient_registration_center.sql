-- Persist and audit the center that registered a patient while keeping patients org-scoped.
SET @add_registered_center = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='patients' AND COLUMN_NAME='registered_center_id'),
  'DO 1',
  'ALTER TABLE patients ADD COLUMN registered_center_id INT UNSIGNED NULL AFTER registered_by'
);
PREPARE add_registered_center_stmt FROM @add_registered_center;
EXECUTE add_registered_center_stmt;
DEALLOCATE PREPARE add_registered_center_stmt;

SET @registration_center_fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND CONSTRAINT_NAME = 'fk_patients_registered_center'
);
SET @registration_center_fk_sql = IF(
  @registration_center_fk_exists = 0,
  'ALTER TABLE patients ADD CONSTRAINT fk_patients_registered_center FOREIGN KEY (registered_center_id) REFERENCES blood_centers(id)',
  'DO 1'
);
PREPARE registration_center_fk_stmt FROM @registration_center_fk_sql;
EXECUTE registration_center_fk_stmt;
DEALLOCATE PREPARE registration_center_fk_stmt;

UPDATE patients p
JOIN staff s ON s.id = p.registered_by
SET p.registered_center_id = s.center_id
WHERE p.registered_center_id IS NULL AND s.center_id IS NOT NULL;
