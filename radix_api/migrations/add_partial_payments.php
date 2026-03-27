<?php
/**
 * Migración: Sistema de Pagos Parciales + Crédito por Excedente
 *
 * Ejecutar UNA SOLA VEZ desde phpMyAdmin (pestaña SQL).
 * Agrega estas columnas nuevas:
 *   pagos.monto_pagado  — acumula lo pagado en pagos parciales
 *   pagos.tx_hash_2     — segundo hash si el pago fue en 2 partes
 *   usuarios.credito_saldo — guarda el excedente si pagó más de $10
 */
require_once __DIR__ . '/../config.php';

try {
    // ── Columnas en tabla PAGOS ──────────────────────────────────────────────
    $cols_pagos = $pdo->query("SHOW COLUMNS FROM pagos")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('monto_pagado', $cols_pagos)) {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN monto_pagado DECIMAL(10,2) DEFAULT 0.00 AFTER monto");
        echo "✅ Columna 'monto_pagado' agregada a pagos.<br>";
    } else {
        echo "ℹ️ Columna 'monto_pagado' ya existe en pagos.<br>";
    }

    if (!in_array('tx_hash_2', $cols_pagos)) {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN tx_hash_2 VARCHAR(66) DEFAULT NULL AFTER tx_hash");
        $pdo->exec("ALTER TABLE pagos ADD UNIQUE KEY uk_tx_hash_2 (tx_hash_2)");
        echo "✅ Columna 'tx_hash_2' agregada a pagos con índice único.<br>";
    } else {
        echo "ℹ️ Columna 'tx_hash_2' ya existe en pagos.<br>";
    }

    // ── Columna en tabla USUARIOS ────────────────────────────────────────────
    $cols_usuarios = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('credito_saldo', $cols_usuarios)) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN credito_saldo DECIMAL(10,2) DEFAULT 0.00 AFTER estado");
        echo "✅ Columna 'credito_saldo' agregada a usuarios.<br>";
    } else {
        echo "ℹ️ Columna 'credito_saldo' ya existe en usuarios.<br>";
    }

    echo "<br>✅ Migración completada correctamente.";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
