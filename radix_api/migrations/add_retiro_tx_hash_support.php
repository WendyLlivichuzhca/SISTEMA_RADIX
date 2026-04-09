<?php
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: text/plain');

function retiroColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'retiros'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function retiroIndexExists(PDO $pdo, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'retiros'
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    echo "Actualizando soporte de tx hash para retiros...\n";

    if (!retiroColumnExists($pdo, 'tx_hash')) {
        $pdo->exec("ALTER TABLE retiros ADD COLUMN tx_hash VARCHAR(66) DEFAULT NULL AFTER fecha_proceso");
        echo "- Columna tx_hash creada.\n";
    } else {
        echo "- Columna tx_hash ya existia.\n";
    }

    if (!retiroIndexExists($pdo, 'uk_retiros_tx_hash')) {
        $pdo->exec("ALTER TABLE retiros ADD UNIQUE KEY uk_retiros_tx_hash (tx_hash)");
        echo "- Indice unico uk_retiros_tx_hash creado.\n";
    } else {
        echo "- Indice uk_retiros_tx_hash ya existia.\n";
    }

    echo "Listo.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
