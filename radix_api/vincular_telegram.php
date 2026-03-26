<?php
/**
 * vincular_telegram.php — RADIX Phase 0
 * Permite al usuario vincular su Telegram chat_id para recibir notificaciones.
 * MEJORA #6: Notificaciones Telegram.
 *
 * Flujo:
 *  1. Usuario abre @radix_bot (o el bot configurado) en Telegram.
 *  2. Escribe /start — el bot responde con su chat_id.
 *  3. Usuario pega ese chat_id en el dashboard y presiona "Vincular".
 *  4. Este endpoint guarda el chat_id en la BD y envía un mensaje de prueba.
 */
require_once 'config.php';
require_once 'notificaciones.php';
session_start();

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$chat_id     = trim($_POST['chat_id'] ?? '');
$desvincular = !empty($_POST['desvincular']);

// ── Desvincular Telegram ────────────────────────────────────────────────────
if ($desvincular) {
    $wallet = $_SESSION['radix_wallet'];
    $stmt = $pdo->prepare("UPDATE usuarios SET telegram_chat_id = NULL WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    sendResponse(['success' => true, 'mensaje' => 'Telegram desvinculado.']);
}

// Validación básica: solo dígitos y opcionalmente signo negativo (grupos son negativos)
if (!preg_match('/^-?\d{5,15}$/', $chat_id)) {
    sendResponse(['error' => 'Chat ID inválido. Debe ser el número que te dio el bot (ej: 123456789).'], 400);
}

$wallet = $_SESSION['radix_wallet'];

try {
    // 1. Obtener usuario
    $stmt = $pdo->prepare("SELECT id, nickname FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch();
    if (!$user) sendResponse(['error' => 'Usuario no encontrado.'], 404);

    $user_id  = $user['id'];
    $nickname = $user['nickname'];

    // 2. Guardar chat_id en la BD
    $stmt = $pdo->prepare("UPDATE usuarios SET telegram_chat_id = ? WHERE id = ?");
    $stmt->execute([$chat_id, $user_id]);

    // 3. Enviar mensaje de prueba para confirmar
    $mensaje_prueba = "✅ *¡Telegram vinculado exitosamente!*\n\n"
                    . "Hola *{$nickname}*, recibirás notificaciones aquí cuando:\n"
                    . "• 🏆 Completes un tablero\n"
                    . "• 🤖 Se active un Agente IA en tu red\n"
                    . "• 👤 Un nuevo referido se una a tu equipo\n\n"
                    . "_Sistema RADIX — Fase 0_";

    $enviado = enviarTelegram($chat_id, $mensaje_prueba);

    if (!$enviado) {
        // Guardado en BD pero no se pudo enviar el mensaje de prueba
        sendResponse([
            'success'  => true,
            'advertencia' => 'Chat ID guardado, pero no se pudo enviar el mensaje de prueba. Verifica que hayas iniciado el bot primero con /start.',
        ]);
    }

    // 4. Log de auditoría
    $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (?, 'VINCULAR_TELEGRAM', 'usuarios', ?)");
    $stmt->execute([$user_id, "Telegram chat_id vinculado: {$chat_id}"]);

    sendResponse([
        'success' => true,
        'mensaje' => '✅ Telegram vinculado. Revisa tu chat — te enviamos un mensaje de prueba.',
    ]);

} catch (PDOException $e) {
    sendResponse(['error' => 'Error del servidor: ' . $e->getMessage()], 500);
}
