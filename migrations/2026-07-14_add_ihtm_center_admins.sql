-- Add one center-bound testing administrator for each IHTM blood center.
-- The bcrypt value is intentionally shared for the initial test cycle. On duplicate,
-- account scope is repaired but password_hash is NOT updated, so rerunning this
-- migration cannot undo a later password change.

INSERT INTO staff(username,password_hash,full_name,role,org_id,center_id,active)
SELECT 'sms_admin','$2y$10$XKwm3bO6y3Xwr83e0Nl5TuZfeTLNjd5aUp6HT/YtqIboPV.xjSQDC','SMS Center Administrator','center_incharge',o.id,c.id,1
FROM organizations o JOIN blood_centers c ON c.org_id=o.id
WHERE o.short_name='IHTM' AND c.code='SMS'
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),role=VALUES(role),org_id=VALUES(org_id),center_id=VALUES(center_id),active=1;

INSERT INTO staff(username,password_hash,full_name,role,org_id,center_id,active)
SELECT 'jkloan_admin','$2y$10$XKwm3bO6y3Xwr83e0Nl5TuZfeTLNjd5aUp6HT/YtqIboPV.xjSQDC','JKLoan Center Administrator','center_incharge',o.id,c.id,1
FROM organizations o JOIN blood_centers c ON c.org_id=o.id
WHERE o.short_name='IHTM' AND c.code='JKLOAN'
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),role=VALUES(role),org_id=VALUES(org_id),center_id=VALUES(center_id),active=1;

INSERT INTO staff(username,password_hash,full_name,role,org_id,center_id,active)
SELECT 'mahila_admin','$2y$10$XKwm3bO6y3Xwr83e0Nl5TuZfeTLNjd5aUp6HT/YtqIboPV.xjSQDC','Mahila Center Administrator','center_incharge',o.id,c.id,1
FROM organizations o JOIN blood_centers c ON c.org_id=o.id
WHERE o.short_name='IHTM' AND c.code='MAHILA'
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),role=VALUES(role),org_id=VALUES(org_id),center_id=VALUES(center_id),active=1;

INSERT INTO staff(username,password_hash,full_name,role,org_id,center_id,active)
SELECT 'sci_admin','$2y$10$XKwm3bO6y3Xwr83e0Nl5TuZfeTLNjd5aUp6HT/YtqIboPV.xjSQDC','SCI Center Administrator','center_incharge',o.id,c.id,1
FROM organizations o JOIN blood_centers c ON c.org_id=o.id
WHERE o.short_name='IHTM' AND c.code='SCI'
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),role=VALUES(role),org_id=VALUES(org_id),center_id=VALUES(center_id),active=1;

INSERT INTO staff(username,password_hash,full_name,role,org_id,center_id,active)
SELECT 'trauma_admin','$2y$10$XKwm3bO6y3Xwr83e0Nl5TuZfeTLNjd5aUp6HT/YtqIboPV.xjSQDC','Trauma Center Administrator','center_incharge',o.id,c.id,1
FROM organizations o JOIN blood_centers c ON c.org_id=o.id
WHERE o.short_name='IHTM' AND c.code='TRAUMA'
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),role=VALUES(role),org_id=VALUES(org_id),center_id=VALUES(center_id),active=1;

INSERT INTO staff(username,password_hash,full_name,role,org_id,center_id,active)
SELECT 'zenana_admin','$2y$10$XKwm3bO6y3Xwr83e0Nl5TuZfeTLNjd5aUp6HT/YtqIboPV.xjSQDC','Zenana Center Administrator','center_incharge',o.id,c.id,1
FROM organizations o JOIN blood_centers c ON c.org_id=o.id
WHERE o.short_name='IHTM' AND c.code='ZENANA'
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),role=VALUES(role),org_id=VALUES(org_id),center_id=VALUES(center_id),active=1;
