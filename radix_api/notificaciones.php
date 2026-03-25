<?php
/**
 * notificaciones.php — RADIX Phase 0
 * Sistema de notificaciones externas vía Telegram Bot API.
 * MEJORA #6: Avisar al usuario cuando completa un tablero o recibe un clon.
 *
 * CONFIGURACIÓN:
 *  1. Crea tu bot con @BotFather en Telegram → obtendrás el BOT_TOKEN.
 *  2. Coloca el BOT_TOKEN en tu .env o en config.php como constante.
 *  3. El usuario vincula su chat_id desde el dashboard (botón "Vincular Telegram").
 */

// ── Configuración del Bot ────────────────────────────────
define('TELEGRAM_BOT_TOKEN', $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: (defined('TELEGRAM_TOKEN') ? TELEGRAM_TOKEN : ''));

/**
 * Envía un mensaje de Telegram a un chat_id específico.
 * No requiere librerías externas — solo file_get_contents o cURL.
 *
 * @param  string $chat_id  ID del chat del usuario en Telegram
 * @param  string $texto    Mensaje a enviar (soporta Markdown básico)
 * @return bool             true si el envío fue exitoso
 */
function enviarTelegram(string $chat_id, string $texto): bool {
    $token = TELEGRAM_BOT_TOKEN;
    if (empty($token) || empty($chat_id)) return false;

    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $body = json_encode([
        'chat_id'    => $chat_id,
        'text'       => $texto,
        'parse_mode' => 'Markdown',
    ]);

    $opciones = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true,
        ]
    ];

    $contexto   = stream_context_create($opciones);
    $respuesta  = @file_get_contents($url, false, $contexto);
    if ($respuesta === false) return false;

    $data = json_decode($respuesta, true);
    return !empty($data['ok']);
}

/**
 * Notifica al usuario cuando completa un tablero.
 *
 * @param  PDO    $pdo        Conexión a la BD
 * @param  int    $user_id    ID del usuario beneficiario
 * @param  string $tablero    'A', 'B' o 'C'
 * @param  float  $ganancia   Monto ganado en este tablero
 */
function notificarAvanceTablero(PDO $pdo, int $user_id, string $tablero, float $ganancia): void {
    $chat_id = obtenerChatId($pdo, $user_id);
    if (!$chat_id) return;

    $emojis = ['A' => '🅰️', 'B' => '🅱️', 'C' => '©️'];
    $emoji  = $emojis[$tablero] ?? '🏆';

    $mensaje = "🎉 *¡Tablero {$tablero} completado!*\n\n"
             . "{$emoji} Ganaste *\${$ganancia} USDT* en este tablero.\n"
             . "📈 Tu ciclo continúa avanzando.\n\n"
             . "_Sistema RADIX — Fase 0_";

    enviarTelegram($chat_id, $mensaje);
}

/**
 * Notifica al usuario cuando se le activa un Agente IA (clon).
 *
 * @param  PDO   $pdo      Conexión a la BD
 * @param  int   $user_id  ID del usuario beneficiario
 * @param  float $monto    Monto que aporta el clon al tablero
 */
function notificarClonActivado(PDO $pdo, int $user_id, float $monto): void {
    $chat_id = obtenerChatId($pdo, $user_id);
    if (!$chat_id) return;

    $mensaje = "🤖 *¡Agente IA activado para ti!*\n\n"
             . "El sistema RADIX ha inyectado un Agente IA en tu red.\n"
             . "💰 Aporte al tablero: *\${$monto} USDT*\n\n"
             . "_Tu red sigue creciendo — Sistema RADIX_";

    enviarTelegram($chat_id, $mensaje);
}

/**
 * Notifica al usuario cuando hay un nuevo referido en su red.
 *
 * @param  PDO    $pdo         Conexión a la BD
 * @param  int    $user_id     ID del patrocinador
 * @param  string $nuevo_nick  Nickname del nuevo referido
 */
function notificarNuevoReferido(PDO $pdo, int $user_id, string $nuevo_nick): void {
    $chat_id = obtenerChatId($pdo, $user_id);
    if (!$chat_id) return;

    $mensaje = "👤 *¡Nuevo referido en tu red!*\n\n"
             . "*{$nuevo_nick}* acaba de unirse usando tu link.\n"
             . "⏳ Espera a que realice su pago de \$10 USDT para activar el slot.\n\n"
             . "_Sistema RADIX — Fase 0_";

    enviarTelegram($chat_id, $mensaje);
}

/**
 * Obtiene el telegram_chat_id del usuario desde la BD.
 * Retorna null si el usuario no vinculó Telegram.
 */
function obtenerChatId(PDO $pdo, int $user_id): ?string {
    try {
        $stmt = $pdo->prepare("SELECT telegram_chat_id FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        return ($row && !empty($row['telegram_chat_id'])) ? (string)$row['telegram_chat_id'] : null;
    } catch (Exception $e) {
        return null; // Columna puede no existir aún
    }
}
