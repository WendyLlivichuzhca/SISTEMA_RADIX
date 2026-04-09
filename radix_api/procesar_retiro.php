<?php
/**
 * procesar_retiro.php — RADIX Phase 0
 * Permite al admin aprobar o rechazar solicitudes de retiro.
 * Solo accesible con sesión de admin activa.
 */
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'notificaciones.php';
requireAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$retiro_id = intval($_POST['retiro_id'] ?? 0);
$accion    = trim($_POST['accion'] ?? ''); // 'aprobar' o 'rechazar'
$notas     = trim($_POST['notas'] ?? '');

if ($retiro_id <= 0 || !in_array($accion, ['aprobar', 'rechazar'])) {
    sendResponse(['error' => 'Datos inválidos.'], 400);
}

try {
    // 1. Obtener retiro (incluye fase_numero para validación por fase)
    $stmt = $pdo->prepare("
        SELECT r.id, r.usuario_id, r.monto, r.wallet_destino, r.estado, r.fase_numero,
               u.nickname, u.telegram_chat_id
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

    // 1b. Solo al APROBAR: verificar que el usuario aún tiene saldo suficiente en ESA FASE
    //     Protege contra el caso de 2 retiros pendientes aprobados por el admin.
    if ($accion === 'aprobar') {
        $uid        = $retiro['usuario_id'];
        $fase_num   = (int)($retiro['fase_numero'] ?? 0);

        // Ganancias brutas de esta fase específica
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(monto),0) as t
            FROM pagos
            WHERE id_receptor=?
              AND propietario_flujo='usuario'
              AND estado='completado'
              AND tipo='ganancia_tablero'
              AND fase_numero=?
        ");
        $stmt->execute([$uid, $fase_num]);
        $bruto = (float)$stmt->fetch()['t'];

        // Deducciones (semillas + reentradas + utilidad final) de esta fase
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(monto),0) as t
            FROM pagos
            WHERE id_emisor=?
              AND propietario_flujo='usuario'
              AND estado='completado'
              AND (tipo LIKE 'salto_fase_%' OR tipo='reentrada' OR tipo='utilidad_master')
              AND fase_numero=?
        ");
        $stmt->execute([$uid, $fase_num]);
        $deducciones = (float)$stmt->fetch()['t'];

        // Crédito por excedente solo aplica en Fase 0
        $credito = 0.0;
        if ($fase_num === 0) {
            $stmt = $pdo->prepare("SELECT COALESCE(credito_saldo,0) as c FROM usuarios WHERE id=?");
            $stmt->execute([$uid]);
            $credito = (float)$stmt->fetch()['c'];
        }

        // Retiros ya procesados de esta misma fase (excluye el actual que aún está 'pendiente')
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(monto),0) as t
            FROM retiros
            WHERE usuario_id=?
              AND fase_numero=?
              AND estado='procesado'
        ");
        $stmt->execute([$uid, $fase_num]);
        $ya_retirado = (float)$stmt->fetch()['t'];

        $saldo_real = $bruto - $deducciones + $credito - $ya_retirado;

        if ($saldo_real < (float)$retiro['monto']) {
            sendResponse(['error' => "Saldo insuficiente en Fase {$fase_num}. El usuario tiene $" . number_format($saldo_real, 2) . " USDT disponible pero solicita $" . number_format($retiro['monto'], 2) . " USDT."], 400);
        }
    }

    $pdo->beginTransaction();

    $nuevo_estado = $accion === 'aprobar' ? 'procesado' : 'rechazado';

    // 2. Actualizar estado del retiro
    $stmt = $pdo->prepare("
        UPDATE retiros
        SET estado = ?, fecha_proceso = NOW(), notas = ?
        WHERE id = ?
    ");
    $stmt->execute([$nuevo_estado, $notas ?: null, $retiro_id]);

    // 3. Si se RECHAZA, devolver el saldo al usuario
    //    (marcar los pagos de ganancia como disponibles nuevamente)
    //    En realidad el saldo nunca se "bloqueó" — solo el retiro queda rechazado
    //    y el usuario puede volver a solicitarlo.

    // 4. Auditoría
    $accion_log = $accion === 'aprobar' ? 'RETIRO_APROBADO' : 'RETIRO_RECHAZADO';
    $fase_log   = (int)($retiro['fase_numero'] ?? 0);
    $stmt = $pdo->prepare("
        INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
        VALUES (?, ?, ?, 'retiros', ?)
    ");
    $stmt->execute([
        $retiro['usuario_id'],
        $fase_log,
        $accion_log,
        "Retiro ID $retiro_id (Fase {$fase_log}) de \${$retiro['monto']} USDT a {$retiro['wallet_destino']}. Notas: $notas"
    ]);

    $pdo->commit();

    // 5. Notificar al usuario por Telegram si tiene vinculado
    if (!empty($retiro['telegram_chat_id'])) {
        $fase_msg = (int)($retiro['fase_numero'] ?? 0);
        $nombre   = !empty($retiro['nickname']) ? $retiro['nickname'] : 'Usuario';

        if ($accion === 'aprobar') {
            $msg = "💸 *¡RETIRO APROBADO!*\n\n"
                 . "Hola *{$nombre}*, tu retiro fue aprobado.\n\n"
                 . "📌 Fase: *Fase {$fase_msg}*\n"
                 . "💵 Monto: *\$" . number_format((float)$retiro['monto'], 2) . " USDT*\n"
                 . "🏦 Wallet destino:\n`{$retiro['wallet_destino']}`\n\n"
                 . "El pago será enviado en breve a tu billetera TRC-20.\n\n"
                 . "_Sistema RADIX_";
        } else {
            $msg = "⚠️ *RETIRO RECHAZADO*\n\n"
                 . "Hola *{$nombre}*, tu solicitud fue rechazada.\n\n"
                 . "📌 Fase: *Fase {$fase_msg}*\n"
                 . "💵 Monto: *\$" . number_format((float)$retiro['monto'], 2) . " USDT*\n"
                 . ($notas ? "📋 Motivo: _{$notas}_\n\n" : "\n")
                 . "Tu saldo sigue disponible. Puedes volver a solicitarlo desde el dashboard.\n\n"
                 . "_Sistema RADIX_";
        }
        enviarTelegram($retiro['telegram_chat_id'], $msg);
    }

    sendResponse([
        'success' => true,
        'mensaje' => $accion === 'aprobar'
            ? "✅ Retiro aprobado. Usuario notificado."
            : "❌ Retiro rechazado. Usuario notificado.",
        'nuevo_estado' => $nuevo_estado,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("procesar_retiro ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor.'], 500);
}
?>
