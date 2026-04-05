<?php
/**
 * setup_webhook.php — RADIX Phase 0
 * Registra el webhook del bot de Telegram UNA sola vez.
 *
 * INSTRUCCIONES:
 *   1. Asegúrate de tener TELEGRAM_BOT_TOKEN en tu .env
 *   2. Abre este archivo en el navegador UNA VEZ:
 *      https://tudominio.com/setup_webhook.php
 *   3. Verás "✅ Webhook registrado exitosamente."
 *   4. ELIMINA o mueve este archivo después de usarlo (seguridad).
 *
 * ⚠️  REQUIERE HTTPS — Telegram solo acepta webhooks con SSL válido.
 */

// ── Protección básica: solo desde IP local o con clave ───────────────────
$clave_secreta = $_GET['key'] ?? '';
define('SETUP_KEY', 'radix_setup_2026'); // Cambia esto antes de usar

if ($clave_secreta !== SETUP_KEY) {
    http_response_code(403);
    die('<h2>403 — Acceso denegado.</h2><p>Usa: setup_webhook.php?key=radix_setup_2026</p>');
}

// ── Cargar token desde .env ───────────────────────────────────────────────
$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {
    foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
if (empty($token)) {
    die('<h2>❌ Error</h2><p>TELEGRAM_BOT_TOKEN no encontrado en .env</p>');
}

// ── URL del webhook (detectar subcarpeta automáticamente) ────────────────
$protocolo  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$dominio    = $_SERVER['HTTP_HOST'] ?? '';
$ruta_base  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$webhook_url = "{$protocolo}://{$dominio}{$ruta_base}/radix_api/telegram_webhook.php";

// ── Registrar webhook en Telegram API ────────────────────────────────────
$api_url = "https://api.telegram.org/bot{$token}/setWebhook";
$payload = json_encode([
    'url'             => $webhook_url,
    'allowed_updates' => ['message'],
    'drop_pending_updates' => true,   // Ignora mensajes viejos acumulados
]);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 10,
    ]
]);

$result   = file_get_contents($api_url, false, $ctx);
$response = json_decode($result, true);

// ── Mostrar resultado ─────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>RADIX — Setup Webhook Telegram</title>
<style>
  body { font-family: monospace; background: #030305; color: #eee; padding: 40px; }
  .ok  { color: #00e676; }
  .err { color: #ff4444; }
  pre  { background: #12121a; padding: 20px; border-radius: 10px; overflow-x: auto; color: #cc44ff; }
</style>
</head>
<body>
<h2>RADIX — Registro de Webhook Telegram</h2>
<hr>
<?php if (!empty($response['ok']) && $response['ok'] === true): ?>
  <p class="ok">✅ Webhook registrado exitosamente.</p>
  <p>URL registrada: <strong><?= htmlspecialchars($webhook_url) ?></strong></p>
  <p>Ahora cuando un usuario escriba <code>/start</code> al bot, recibirá su Chat ID automáticamente.</p>
  <br>
  <p style="color:#ff9800;">⚠️ <strong>Elimina este archivo del servidor ahora que ya lo usaste.</strong></p>
<?php else: ?>
  <p class="err">❌ Error al registrar el webhook.</p>
  <p>Respuesta de Telegram:</p>
  <pre><?= htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT)) ?></pre>
  <p>Verifica:</p>
  <ul>
    <li>Que tu servidor tenga HTTPS con certificado válido.</li>
    <li>Que el TELEGRAM_BOT_TOKEN en .env sea correcto.</li>
    <li>Que la URL <code><?= htmlspecialchars($webhook_url) ?></code> sea pública y accesible.</li>
  </ul>
<?php endif; ?>
<hr>
<pre><?= htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</body>
</html>
