-- Rotate the temporary shared password for the six IHTM center test accounts.
-- Guard on the previous bcrypt hash so rerunning this migration cannot overwrite
-- a later per-account password rotation.

UPDATE staff
SET password_hash = '$2y$10$HUTXk5onoJytqHOMkb5QHOlWFT8BX3BzQLmZ7JnKSIssisIM6Vu5W'
WHERE username IN (
    'sms_admin',
    'jkloan_admin',
    'mahila_admin',
    'sci_admin',
    'trauma_admin',
    'zenana_admin'
)
AND password_hash = '$2y$10$XKwm3bO6y3Xwr83e0Nl5TuZfeTLNjd5aUp6HT/YtqIboPV.xjSQDC';
