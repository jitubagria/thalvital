-- Center-level public location supports one shared department spanning multiple cities.
-- Existing centers inherit their organization's location; org columns remain fallback data.
SET @add_center_country = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='blood_centers' AND COLUMN_NAME='country'),
  'DO 1',
  'ALTER TABLE blood_centers ADD COLUMN country VARCHAR(80) NULL AFTER code'
);
PREPARE add_center_country_stmt FROM @add_center_country;
EXECUTE add_center_country_stmt;
DEALLOCATE PREPARE add_center_country_stmt;

SET @add_center_state = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='blood_centers' AND COLUMN_NAME='state'),
  'DO 1',
  'ALTER TABLE blood_centers ADD COLUMN state VARCHAR(80) NULL AFTER country'
);
PREPARE add_center_state_stmt FROM @add_center_state;
EXECUTE add_center_state_stmt;
DEALLOCATE PREPARE add_center_state_stmt;

SET @add_center_city = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='blood_centers' AND COLUMN_NAME='city'),
  'DO 1',
  'ALTER TABLE blood_centers ADD COLUMN city VARCHAR(80) NULL AFTER state'
);
PREPARE add_center_city_stmt FROM @add_center_city;
EXECUTE add_center_city_stmt;
DEALLOCATE PREPARE add_center_city_stmt;

UPDATE blood_centers c
JOIN organizations o ON o.id = c.org_id
SET c.country = COALESCE(NULLIF(c.country, ''), o.country),
    c.state = COALESCE(NULLIF(c.state, ''), o.state),
    c.city = COALESCE(NULLIF(c.city, ''), o.city)
WHERE c.country IS NULL OR c.country = ''
   OR c.state IS NULL OR c.state = ''
   OR c.city IS NULL OR c.city = '';
