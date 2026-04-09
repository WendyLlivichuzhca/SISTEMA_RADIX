<?php
require_once 'config.php';

header('Content-Type: text/plain');

$maintenance_key = $_GET['key'] ?? '';
define('RADIX_MAINTENANCE_KEY', $_ENV['RADIX_MAINTENANCE_KEY'] ?? (getenv('RADIX_MAINTENANCE_KEY') ?: 'radix_tools_2026'));

if ($maintenance_key !== RADIX_MAINTENANCE_KEY) {
    http_response_code(403);
    die(
        '<h2>403 - Acceso denegado.</h2><p>Usa: repair_reabrir_retiros_no_pagados.php?key='
        . htmlspecialchars(RADIX_MAINTENANCE_KEY, ENT_QUOTES, 'UTF-8')
        . '</p>'
    );
}

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

$retirosObjetivo = [1, 2];
$txHashDisponible = retiroColumnExists($pdo, 'tx_hash');

try {
    $pdo->beginTransaction();

    echo "Reabriendo retiros marcados como procesados sin pago real...\n";

    foreach ($retirosObjetivo as $retiroId) {
        $stmt = $pdo->prepare("
            SELECT id, usuario_id, estado, monto, fase_numero
            FROM retiros
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$retiroId]);
        $retiro = $stmt->fetch();

        if (!$retiro) {
            echo "- Retiro {$retiroId}: no existe.\n";
            continue;
        }

        if ($retiro['estado'] !== 'procesado') {
            echo "- Retiro {$retiroId}: se omite porque esta en estado '{$retiro['estado']}'.\n";
            continue;
        }

        $nota = 'Reabierto automaticamente: retiro aprobado sin pago real en blockchain. Debe procesarse nuevamente con tx hash valido.';

        if ($txHashDisponible) {
            $stmt = $pdo->prepare("
                UPDATE retiros
                SET estado = 'pendiente',
                    fecha_solicitud = NOW(),
                    fecha_proceso = NULL,
                    tx_hash = NULL,
                    notas = ?
                WHERE id = ?
            ");
            $stmt->execute([$nota, $retiroId]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE retiros
                SET estado = 'pendiente',
                    fecha_solicitud = NOW(),
                    fecha_proceso = NULL,
                    notas = ?
                WHERE id = ?
            ");
            $stmt->execute([$nota, $retiroId]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
            VALUES (?, ?, 'RETIRO_REABIERTO_SIN_PAGO', 'retiros', ?)
        ");
        $stmt->execute([
            $retiro['usuario_id'],
            (int)$retiro['fase_numero'],
            "Retiro ID {$retiroId} reabierto a pendiente por no haber sido pagado realmente en blockchain. Monto: $" . number_format((float)$retiro['monto'], 2) . " USDT."
        ]);

        echo "- Retiro {$retiroId}: reabierto correctamente.\n";
    }

    $pdo->commit();
    echo "Proceso completado.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
