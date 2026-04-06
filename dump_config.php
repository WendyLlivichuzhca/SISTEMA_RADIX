<?php
require_once __DIR__ . '/radix_api/config.php';

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "--- FASES_CONFIG ---\n";
    $stmt = $pdo->query("SELECT * FROM fases_config");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }

    echo "\n--- FASES_TABLEROS_CONFIG ---\n";
    $stmt = $pdo->query("SELECT * FROM fases_tableros_config");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
