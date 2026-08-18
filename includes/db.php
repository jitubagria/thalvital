<?php
require_once __DIR__ . '/../config.php';

function new_db_connection(): PDO {
    return new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) return $pdo = new_db_connection();
    try { $pdo->query('SELECT 1'); }
    catch (PDOException $exception) { $pdo = new_db_connection(); }
    return $pdo;
}
