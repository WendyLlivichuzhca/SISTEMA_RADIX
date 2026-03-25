<?php
/**
 * telegram_webhook.php — RADIX Phase 0
 * Endpoint que recibe todos los mensajes entrantes del bot de Telegram.
 *
 * FLUJO:
 *   1. Usuario escribe /start en el bot de Telegram.
 *   2. Telegram envía un POST a esta URL (webhook).
 *   3. Este script detecta el comando /start y responde con el chat_id del usuario.
 *   4. El usuario copia ese chat_id y lo pega en el dashboard para vincularse.
 *
 * REGISTRO DEL WEBHOOK:
 *   Ejecuta setup_webhook.php UNA sola vez desde el navegador para registrarlo.
 */

require_once 'config.php';
require_once 'notificaciones.php'; // Para usar enviarTelegram()

// Solo aceptar POST de Telegram (ignora cualquier otro acceso)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Leer el cuerpo del request (Telegram envía JSON)
$input  = file_get_contents('php://input');
$update = json_decode($input, true);

if (empty($update)) {
    http_response_code(200); // Siempre responder 200 a Telegram
    exit;
}

// ── Extraer datos del mensaje ────────────────────────────────────────────
$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!$message) {
    http_response_code(200);
    exit;
}

$chat_id  = (string)($message['chat']['id']       ?? '');
$text     = trim($message['text']                  ?? '');
$nombre   = $message['from']['first_name']         ?? 'Usuario';

if (empty($chat_id)) {
    http_response_code(200);
    exit;
}

// ── Manejar comandos ─────────────────────────────────────────────────────

if ($text === '/start' || str_starts_with($text, '/start')) {

    // Responder con el chat_id y las instrucciones para vincularlo
    $respuesta = "👋 *¡Hola, {$nombre}!*\n\n"
               . "Bienvenido al bot de notificaciones de *RADIX*.\n\n"
               . "Tu *Chat ID* es:\n"
               . "`{$chat_id}`\n\n"
               . "📋 *Cópialo* (toca el número de arriba) y pégalo en tu dashboard de RADIX en la sección *\"Notificaciones Telegram\"*.\n\n"
               . "Una vez vinculado recibirás alertas automáticas cuando:\n"
               . "• 👤 Alguien se una a tu red\n"
               . "• 🏆 Completes un tablero\n"
               . "• 🤖 Se active un Agente IA para ti\n\n"
               . "_Sistema RADIX — Fase 0_";

    enviarTelegram($chat_id, $respuesta);

} elseif ($text === '/id') {

    // Comando de emergencia para ver el chat_id
    enviarTelegram($chat_id, "🆔 Tu Chat ID es: `{$chat_id}`");

} elseif ($text === '/ayuda' || $text === '/help') {

    $ayuda = "📚 *Comandos disponibles:*\n\n"
           . "/start — Obtén tu Chat ID para vincularte\n"
           . "/id — Ver tu Chat ID directamente\n"
           . "/ayuda — Mostrar esta ayuda\n\n"
           . "_Sistema RADIX Notificaciones_";

    enviarTelegram($chat_id, $ayuda);

} else {
    // Cualquier otro mensaje
    enviarTelegram($chat_id, "ℹ️ Escribe /start para obtener tu Chat ID y vincular tu cuenta RADIX.");
}

// Siempre responder 200 a Telegram (importante — si no lo hace, reintenta el envío)
http_response_code(200);
echo json_encode(['ok' => true]);
