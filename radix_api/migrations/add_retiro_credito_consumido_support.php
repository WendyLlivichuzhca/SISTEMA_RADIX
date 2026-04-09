<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain');

function retiroCreditColumnExists(PDO $pdo, string $column): bool
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

try {
    echo "Verificando soporte de credito_consumido en retiros...\n";

    if (!retiroCreditColumnExists($pdo, 'credito_consumido')) {
        $pdo->exec("
            ALTER TABLE retiros
            ADD COLUMN credito_consumido DECIMAL(10,2) NOT NULL DEFAULT 0.00
            AFTER fecha_proceso
        ");
        echo "OK: columna credito_consumido agregada.\n";
    } else {
        echo "OK: columna credito_consumido ya existe.\n";
    }

    echo "Proceso completado.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
