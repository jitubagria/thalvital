<?php
// Copy to config.php and set deployment-specific values. Do not commit config.php.
define('DB_HOST', 'localhost');
define('DB_NAME', 'thalvital');
define('DB_USER', 'replace_me');
define('DB_PASS', 'replace_me');
define('AADHAAR_SALT', 'replace_with_a_permanent_64_character_random_secret');
// '/' when hosted at the domain root; '/thalvital/' when hosted in a subdirectory.
define('BASE_URL', '/');
define('SITE_NAME', 'ThalVital');
define('SESSION_TIMEOUT', 3600);
date_default_timezone_set('Asia/Kolkata');
