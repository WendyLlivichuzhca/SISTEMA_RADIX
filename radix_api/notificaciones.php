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

// ── Configuración SMTP (Gmail) ──────────────────────────────────────────────
define('RADIX_SMTP_HOST', $_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('RADIX_SMTP_PORT', (int)($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?: 587));
define('RADIX_SMTP_USER', $_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?: '');
define('RADIX_SMTP_PASS', $_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?: '');
define('RADIX_SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? getenv('SMTP_FROM_EMAIL') ?: RADIX_SMTP_USER);
define('RADIX_SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? getenv('SMTP_FROM_NAME') ?: 'RADIX');
define('RADIX_SMTP_SECURE', $_ENV['SMTP_SECURE'] ?? getenv('SMTP_SECURE') ?: 'tls');

function radix_smtp_configured(): bool {
    return RADIX_SMTP_USER !== '' && RADIX_SMTP_PASS !== '' && RADIX_SMTP_FROM_EMAIL !== '';
}

function radix_encode_subject(string $subject): string {
    $subject = trim($subject);
    if ($subject === '') return '';
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

function radix_build_email_html(string $title, array $lines): string {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $out  = '<div style="font-family:Arial,sans-serif;color:#111;line-height:1.5;">';
    $out .= '<h2 style="margin:0 0 12px;">' . $safeTitle . '</h2>';
    foreach ($lines as $line) {
        $out .= '<div>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $out .= '<div style="margin-top:12px;color:#666;font-size:12px;">Sistema RADIX</div>';
    $out .= '</div>';
    return $out;
}

function radix_build_email_text(array $lines): string {
    return implode("\n", $lines) . "\n\nSistema RADIX";
}

function radix_smtp_send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    if (!radix_smtp_configured()) return false;

    $to = trim($to);
    if ($to === '') return false;

    $host = RADIX_SMTP_HOST;
    $port = RADIX_SMTP_PORT;
    $secure = strtolower(RADIX_SMTP_SECURE);

    $fp = @fsockopen($host, $port, $errno, $errstr, 12);
    if (!$fp) {
        error_log("RADIX SMTP connect error: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($fp, 12);

    $read = function() use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (preg_match('/^\\d{3}\\s/', $line)) break;
        }
        return $data;
    };

    $expect = function(array $codes) use ($read) {
        $response = $read();
        if ($response === '') return false;
        $code = (int)substr($response, 0, 3);
        return in_array($code, $codes, true);
    };

    $write = function(string $cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    if (!$expect([220])) { fclose($fp); return false; }
    $write('EHLO radix');
    if (!$expect([250])) { fclose($fp); return false; }

    if ($secure === 'tls') {
        $write('STARTTLS');
        if (!$expect([220])) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $write('EHLO radix');
        if (!$expect([250])) { fclose($fp); return false; }
    }

    $write('AUTH LOGIN');
    if (!$expect([334])) { fclose($fp); return false; }
    $write(base64_encode(RADIX_SMTP_USER));
    if (!$expect([334])) { fclose($fp); return false; }
    $write(base64_encode(RADIX_SMTP_PASS));
    if (!$expect([235])) { fclose($fp); return false; }

    $fromEmail = RADIX_SMTP_FROM_EMAIL;
    $fromName = RADIX_SMTP_FROM_NAME;
    $write("MAIL FROM:<{$fromEmail}>");
    if (!$expect([250])) { fclose($fp); return false; }
    $write("RCPT TO:<{$to}>");
    if (!$expect([250, 251])) { fclose($fp); return false; }

    $write('DATA');
    if (!$expect([354])) { fclose($fp); return false; }

    $boundary = 'radix_' . bin2hex(random_bytes(8));
    $subjectEnc = radix_encode_subject($subject);
    $textBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

    $headers  = "From: " . radix_encode_subject($fromName) . " <{$fromEmail}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: {$subjectEnc}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($textBody));
    $body .= "\r\n--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody));
    $body .= "\r\n--{$boundary}--\r\n";

    $write($headers . "\r\n" . $body . "\r\n.");
    $ok = $expect([250]);
    $write('QUIT');
    fclose($fp);
    return $ok;
}

function enviarEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    return radix_smtp_send($to, $subject, $htmlBody, $textBody);
}

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
 * @param  float  $ganancia   Monto neto ganado (ya descontada semilla de fase siguiente)
 * @param  bool   $ciclo_completo  true cuando es Tablero C (ciclo finalizado)
 */
function notificarAvanceTablero(PDO $pdo, int $user_id, string $tablero, float $ganancia, bool $ciclo_completo = false): void {
    $chat_id = obtenerChatId($pdo, $user_id);
    $correo  = obtenerCorreoUsuario($pdo, $user_id);

    // Obtener nombre del usuario
    try {
        $stmt = $pdo->prepare("SELECT nombre_completo, nickname FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        $nombre = ($u && !empty($u['nombre_completo'])) ? $u['nombre_completo'] : ($u['nickname'] ?? 'Usuario');
    } catch (Exception $e) { $nombre = 'Usuario'; }

    if ($ciclo_completo) {
        $mensaje = "🏆 *¡CICLO COMPLETADO, {$nombre}!*\n\n"
                 . "Completaste los 3 tableros (A → B → C) de tu ciclo RADIX.\n\n"
                 . "💸 *Saldo disponible para retiro:*\n"
                 . "   *\$" . number_format($ganancia, 2) . " USDT*\n\n"
                 . "👉 Ingresa a tu dashboard y solicita tu retiro.\n\n"
                 . "_Sistema RADIX_";
    } else {
        $siguiente = ['A' => 'Tablero B', 'B' => 'Tablero C'];
        $sig_txt   = isset($siguiente[$tablero])
                   ? "📌 Siguiente: *{$siguiente[$tablero]}*"
                   : "✅ ¡Listo para retirar!";

        $emojis = ['A' => '🅰️', 'B' => '🅱️'];
        $emoji  = $emojis[$tablero] ?? '🎉';

        $mensaje = "🎉 *¡Tablero {$tablero} completado!*\n\n"
                 . "Hola *{$nombre}*\n"
                 . "{$emoji} Ganaste *\$" . number_format($ganancia, 2) . " USDT* en este tablero.\n"
                 . $sig_txt . "\n\n"
                 . "_Sistema RADIX_";
    }

    if ($ciclo_completo) {
        $emailTitle = "Ciclo completado en RADIX";
        $emailLines = [
            "Hola {$nombre}, completaste los 3 tableros de tu ciclo RADIX.",
            "Saldo disponible para retiro: $" . number_format($ganancia, 2) . " USDT.",
            "Ingresa a tu dashboard y solicita tu retiro."
        ];
    } else {
        $emailTitle = "Tablero {$tablero} completado";
        $emailLines = [
            "Hola {$nombre}, completaste el tablero {$tablero}.",
            "Ganaste $" . number_format($ganancia, 2) . " USDT en este tablero.",
            strip_tags($sig_txt)
        ];
    }

    if ($chat_id) {
        enviarTelegram($chat_id, $mensaje);
    }
    if (!empty($correo)) {
        enviarEmail($correo, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
    }
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
    $correo  = obtenerCorreoUsuario($pdo, $user_id);

    $mensaje = "🤖 *¡Agente IA activado para ti!*\n\n"
             . "El sistema RADIX ha inyectado un Agente IA en tu red.\n"
             . "💰 Aporte al tablero: *\${$monto} USDT*\n\n"
             . "_Tu red sigue creciendo — Sistema RADIX_";

    if ($chat_id) {
        enviarTelegram($chat_id, $mensaje);
    }
    if (!empty($correo)) {
        $emailTitle = "Agente IA activado en tu red";
        $emailLines = [
            "Se activó un Agente IA en tu red.",
            "Aporte al tablero: $" . number_format($monto, 2) . " USDT."
        ];
        enviarEmail($correo, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
    }
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
    $correoUsuario = obtenerCorreoUsuario($pdo, $user_id);

    // Obtener datos completos del nuevo referido
    try {
        $stmt = $pdo->prepare("SELECT nombre_completo, telefono, correo_electronico FROM usuarios WHERE nickname = ? LIMIT 1");
        $stmt->execute([$nuevo_nick]);
        $ref = $stmt->fetch();
    } catch (Exception $e) { $ref = null; }

    $nombre   = ($ref && !empty($ref['nombre_completo'])) ? $ref['nombre_completo'] : '—';
    $telefono = ($ref && !empty($ref['telefono']))         ? $ref['telefono']        : '—';
    $correo   = ($ref && !empty($ref['correo_electronico'])) ? $ref['correo_electronico'] : '—';

    $mensaje = "👤 *¡NUEVO REFERIDO EN TU RED!*\n\n"
             . "👤 Nickname: *{$nuevo_nick}*\n"
             . "📝 Nombre: *{$nombre}*\n"
             . "📞 Teléfono: *{$telefono}*\n"
             . "📧 Correo: *{$correo}*\n\n"
             . "⏳ Esperando su pago de \$10 USDT para activar su slot.\n\n"
             . "_Sistema RADIX_";

    if ($chat_id) {
        enviarTelegram($chat_id, $mensaje);
    }
    if (!empty($correoUsuario)) {
        $emailTitle = "Nuevo referido en tu red";
        $emailLines = [
            "Nickname: {$nuevo_nick}",
            "Nombre: {$nombre}",
            "Teléfono: {$telefono}",
            "Correo: {$correo}",
            "Esperando su pago de $10 USDT para activar su slot."
        ];
        enviarEmail($correoUsuario, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
    }
}

/**
 * Notifica al usuario con un mensaje de bienvenida al registrarse.
 * Se llama una sola vez, justo después del registro exitoso.
 *
 * @param PDO $pdo      Conexión BD
 * @param int $user_id  ID del nuevo usuario
 */
function notificarBienvenida(PDO $pdo, int $user_id): void {
    $chat_id = obtenerChatId($pdo, $user_id);
    $correo  = obtenerCorreoUsuario($pdo, $user_id);

    try {
        $stmt = $pdo->prepare("SELECT nickname, nombre_completo, wallet_address FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
    } catch (Exception $e) { return; }

    if (!$u) return;

    $nombre  = !empty($u['nombre_completo']) ? $u['nombre_completo'] : $u['nickname'];
    $wallet  = $u['wallet_address'] ?? '';
    $nick    = $u['nickname'] ?? '';

    $mensaje = "🎉 *¡BIENVENIDO A RADIX, {$nombre}!*\n\n"
             . "Tu cuenta ha sido creada exitosamente.\n\n"
             . "👤 Nickname: *{$nick}*\n"
             . "🔗 Tu wallet (link de referido):\n`{$wallet}`\n\n"
             . "Comparte tu wallet con tus referidos para que se unan a tu red.\n\n"
             . "💡 Vincula tu Telegram desde el dashboard para recibir notificaciones en tiempo real.\n\n"
             . "_Sistema RADIX_";

    if ($chat_id) {
        enviarTelegram($chat_id, $mensaje);
    }
    if (!empty($correo)) {
        $emailTitle = "Bienvenido a RADIX";
        $emailLines = [
            "Tu cuenta ha sido creada exitosamente.",
            "Nickname: {$nick}",
            "Wallet (link de referido): {$wallet}",
            "Comparte tu wallet con tus referidos para que se unan a tu red."
        ];
        enviarEmail($correo, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
    }
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

function obtenerCorreoUsuario(PDO $pdo, int $user_id): ?string {
    try {
        $stmt = $pdo->prepare("SELECT correo_electronico FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        return ($row && !empty($row['correo_electronico'])) ? (string)$row['correo_electronico'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function obtenerCorreoMaster(PDO $pdo): ?string {
    try {
        $stmt = $pdo->query("
            SELECT correo_electronico
            FROM usuarios
            WHERE tipo_usuario = 'master'
            ORDER BY id ASC
            LIMIT 1
        ");
        $row = $stmt->fetch();
        return ($row && !empty($row['correo_electronico'])) ? (string)$row['correo_electronico'] : null;
    } catch (Exception $e) {
        return null;
    }
}
