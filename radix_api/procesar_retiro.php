<?php
/**
 * procesar_retiro.php
 * Solo marca un retiro como procesado cuando el admin ya realizo el pago
 * real en blockchain y registra el tx hash correspondiente.
 */
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'notificaciones.php';

requireAdminSession();

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Metodo no permitido'], 405);
}

$retiro_id = (int)($_POST['retiro_id'] ?? 0);
$accion = trim((string)($_POST['accion'] ?? ''));
$notas = trim((string)($_POST['notas'] ?? ''));
$tx_hash = trim((string)($_POST['tx_hash'] ?? ''));

if ($retiro_id <= 0 || !in_array($accion, ['aprobar', 'rechazar'], true)) {
    sendResponse(['error' => 'Datos invalidos.'], 400);
}

if ($tx_hash !== '' && strncasecmp($tx_hash, '0x', 2) === 0) {
    $tx_hash = substr($tx_hash, 2);
}

try {
    $retiroHasCreditoConsumido = retiroColumnExists($pdo, 'credito_consumido');
    $creditoSelect = $retiroHasCreditoConsumido
        ? ", COALESCE(r.credito_consumido, 0) AS credito_consumido"
        : ", 0 AS credito_consumido";

    $stmt = $pdo->prepare("
        SELECT r.id, r.usuario_id, r.monto, r.wallet_destino, r.estado, r.fase_numero,
               u.nickname, u.telegram_chat_id
               {$creditoSelect}
        FROM retiros r
        JOIN usuarios u ON r.usuario_id = u.id
        WHERE r.id = ? AND r.estado = 'pendiente'
        LIMIT 1
    ");
    $stmt->execute([$retiro_id]);
    $retiro = $stmt->fetch();

    if (!$retiro) {
        sendResponse(['error' => 'Retiro no encontrado o ya fue procesado.'], 404);
    }

    $fase_num = (int)($retiro['fase_numero'] ?? 0);

    if ($accion === 'aprobar') {
        if (!retiroColumnExists($pdo, 'tx_hash')) {
            sendResponse([
                'error' => 'La tabla retiros aun no tiene soporte para tx hash. Ejecuta la migracion add_retiro_tx_hash_support.php primero.'
            ], 500);
        }

        if ($tx_hash === '') {
            sendResponse(['error' => 'Debes pegar el tx hash real para marcar el retiro como procesado.'], 400);
        }

        if (!preg_match('/^[A-Fa-f0-9]{64}$/', $tx_hash)) {
            sendResponse(['error' => 'El tx hash debe tener 64 caracteres hexadecimales.'], 400);
        }

        $uid = (int)$retiro['usuario_id'];
        $credito_consumido_actual = (float)($retiro['credito_consumido'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(monto), 0) AS t
            FROM pagos
            WHERE id_receptor = ?
              AND propietario_flujo = 'usuario'
              AND estado = 'completado'
              AND tipo = 'ganancia_tablero'
              AND fase_numero = ?
        ");
        $stmt->execute([$uid, $fase_num]);
        $bruto = (float)$stmt->fetch()['t'];

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(monto), 0) AS t
            FROM pagos
            WHERE id_emisor = ?
              AND propietario_flujo = 'usuario'
              AND estado = 'completado'
              AND (tipo LIKE 'salto_fase_%' OR tipo = 'reentrada' OR tipo = 'utilidad_master')
              AND fase_numero = ?
        ");
        $stmt->execute([$uid, $fase_num]);
        $deducciones = (float)$stmt->fetch()['t'];

        $credito = 0.0;
        if ($fase_num === 0) {
            $stmt = $pdo->prepare("SELECT COALESCE(credito_saldo, 0) AS c FROM usuarios WHERE id = ?");
            $stmt->execute([$uid]);
            $credito = (float)$stmt->fetch()['c'];
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(monto - COALESCE(credito_consumido, 0)), 0) AS t
            FROM retiros
            WHERE usuario_id = ?
              AND fase_numero = ?
              AND estado IN ('procesado', 'pendiente')
              AND id <> ?
        ");
        $stmt->execute([$uid, $fase_num, $retiro_id]);
        $ya_comprometido = (float)$stmt->fetch()['t'];

        $saldo_real = $bruto - $deducciones + $credito - $ya_comprometido;
        $monto_retiro = max(0.0, (float)$retiro['monto'] - $credito_consumido_actual);

        if ($saldo_real < $monto_retiro) {
            sendResponse([
                'error' => "Saldo insuficiente en Fase {$fase_num}. El usuario tiene $" . number_format($saldo_real, 2) . " USDT disponibles para cubrir este retiro pero necesita $" . number_format($monto_retiro, 2) . " USDT."
            ], 400);
        }

        $stmt = $pdo->prepare("SELECT id FROM retiros WHERE tx_hash = ? LIMIT 1");
        $stmt->execute([$tx_hash]);
        $duplicado = $stmt->fetch();
        if ($duplicado) {
            sendResponse(['error' => 'Ese tx hash ya esta registrado en otro retiro.'], 409);
        }
    }

    $pdo->beginTransaction();

    $nuevo_estado = $accion === 'aprobar' ? 'procesado' : 'rechazado';

    if ($accion === 'aprobar') {
        $stmt = $pdo->prepare("
            UPDATE retiros
            SET estado = 'procesado',
                fecha_proceso = NOW(),
                notas = ?,
                tx_hash = ?
            WHERE id = ?
              AND estado = 'pendiente'
        ");
        $stmt->execute([$notas !== '' ? $notas : null, $tx_hash, $retiro_id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE retiros
            SET estado = 'rechazado',
                fecha_proceso = NOW(),
                notas = ?
            WHERE id = ?
              AND estado = 'pendiente'
        ");
        $stmt->execute([$notas !== '' ? $notas : null, $retiro_id]);

        if ($fase_num === 0 && $retiroHasCreditoConsumido && (float)($retiro['credito_consumido'] ?? 0) > 0) {
            $stmtCredito = $pdo->prepare("
                UPDATE usuarios
                SET credito_saldo = credito_saldo + ?
                WHERE id = ?
            ");
            $stmtCredito->execute([(float)$retiro['credito_consumido'], (int)$retiro['usuario_id']]);
        }
    }

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('No se pudo actualizar el retiro. Intenta recargar la pagina.');
    }

    $accion_log = $accion === 'aprobar' ? 'RETIRO_PROCESADO_CON_HASH' : 'RETIRO_RECHAZADO';
    $detalles = "Retiro ID {$retiro_id} (Fase {$fase_num}) de $" . number_format((float)$retiro['monto'], 2) . " USDT a {$retiro['wallet_destino']}.";
    if ($accion === 'aprobar') {
        $detalles .= " TX_HASH: {$tx_hash}.";
    }
    if ((float)($retiro['credito_consumido'] ?? 0) > 0) {
        $detalles .= " Credito aplicado: $" . number_format((float)$retiro['credito_consumido'], 2) . ".";
    }
    if ($notas !== '') {
        $detalles .= " Notas: {$notas}";
    }

    $stmt = $pdo->prepare("
        INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
        VALUES (?, ?, ?, 'retiros', ?)
    ");
    $stmt->execute([
        $retiro['usuario_id'],
        $fase_num,
        $accion_log,
        $detalles
    ]);

    $pdo->commit();

    if (!empty($retiro['telegram_chat_id'])) {
        $nombre = !empty($retiro['nickname']) ? $retiro['nickname'] : 'Usuario';

        if ($accion === 'aprobar') {
            $msg = "*RETIRO PAGADO*\n\n"
                . "Hola *{$nombre}*, tu retiro ya fue enviado en blockchain.\n\n"
                . "Fase: *Fase {$fase_num}*\n"
                . "Monto: *$" . number_format((float)$retiro['monto'], 2) . " USDT*\n"
                . "Wallet destino:\n`{$retiro['wallet_destino']}`\n\n"
                . "TX Hash:\n`{$tx_hash}`\n\n"
                . "_Sistema RADIX_";
        } else {
            $msg = "*RETIRO RECHAZADO*\n\n"
                . "Hola *{$nombre}*, tu solicitud fue rechazada.\n\n"
                . "Fase: *Fase {$fase_num}*\n"
                . "Monto: *$" . number_format((float)$retiro['monto'], 2) . " USDT*\n";

            if ($notas !== '') {
                $msg .= "Motivo: _{$notas}_\n\n";
            } else {
                $msg .= "\n";
            }

            $msg .= "Tu saldo sigue disponible. Puedes volver a solicitarlo desde el dashboard.\n\n"
                . "_Sistema RADIX_";
        }

        enviarTelegram($retiro['telegram_chat_id'], $msg);
    }

    sendResponse([
        'success' => true,
        'mensaje' => $accion === 'aprobar'
            ? 'Retiro marcado como pagado con tx hash y usuario notificado.'
            : 'Retiro rechazado y usuario notificado.',
        'nuevo_estado' => $nuevo_estado,
        'tx_hash' => $accion === 'aprobar' ? $tx_hash : null,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('procesar_retiro ERROR: ' . $e->getMessage());

    $code = 500;
    if ($e instanceof RuntimeException) {
        $code = 409;
    }

    sendResponse(['error' => $e->getMessage() ?: 'Error del servidor.'], $code);
}
?>
