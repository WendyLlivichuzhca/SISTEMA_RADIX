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
    // 1. Obtener retiro
    $stmt = $pdo->prepare("
        SELECT r.id, r.usuario_id, r.monto, r.wallet_destino, r.estado, u.nickname, u.telegram_chat_id
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
    $stmt = $pdo->prepare("
        INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles)
        VALUES (?, ?, 'retiros', ?)
    ");
    $stmt->execute([
        $retiro['usuario_id'],
        $accion_log,
        "Retiro ID $retiro_id de \${$retiro['monto']} USDT a {$retiro['wallet_destino']}. Notas: $notas"
    ]);

    $pdo->commit();

    // 5. Notificar al usuario por Telegram si tiene vinculado
    if (!empty($retiro['telegram_chat_id'])) {
        if ($accion === 'aprobar') {
            $msg = "💸 *¡Tu retiro fue aprobado!*\n\n"
                 . "Monto: *\${$retiro['monto']} USDT*\n"
                 . "Wallet: `{$retiro['wallet_destino']}`\n\n"
                 . "El pago será enviado en breve a tu billetera TRC-20.\n\n"
                 . "_Sistema RADIX_";
        } else {
            $msg = "⚠️ *Tu solicitud de retiro fue rechazada.*\n\n"
                 . "Monto: *\${$retiro['monto']} USDT*\n"
                 . ($notas ? "Motivo: $notas\n\n" : "\n")
                 . "Tu saldo sigue disponible. Puedes volver a solicitarlo.\n\n"
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
