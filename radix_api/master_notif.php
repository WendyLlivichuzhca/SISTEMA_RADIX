<?php
/**
 * master_notif.php — RADIX System
 * Notificaciones Telegram exclusivas para el RADIX_MASTER (admin).
 *
 * EVENTOS EN TIEMPO REAL (llegan al instante):
 *   - Nuevo usuario registrado
 *   - Pago de entrada confirmado
 *   - Retiro solicitado por un usuario
 *   - Tesorería baja (< umbral configurable)
 *
 * RESUMEN DIARIO:
 *   - Llamar a enviarResumenDiarioMaster($pdo) desde un cron o manualmente
 *
 * IMPORTANTE: Este archivo NO redefine TELEGRAM_BOT_TOKEN.
 *   Siempre requiere notificaciones.php primero (que ya define el token y enviarTelegram).
 */

if (!function_exists('enviarTelegram')) {
    require_once __DIR__ . '/notificaciones.php';
}

// ─── Umbral de tesorería baja (puedes cambiar este valor) ────────────────────
if (!defined('MASTER_TESORERIA_UMBRAL')) {
    define('MASTER_TESORERIA_UMBRAL', 50.0);
}

/**
 * Obtiene el telegram_chat_id del RADIX_MASTER.
 * Retorna null si el master no ha vinculado Telegram.
 */
function obtenerChatIdMaster(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->query("
            SELECT telegram_chat_id
            FROM usuarios
            WHERE tipo_usuario = 'master'
            ORDER BY id ASC
            LIMIT 1
        ");
        $row = $stmt->fetch();
        return ($row && !empty($row['telegram_chat_id'])) ? (string)$row['telegram_chat_id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Notifica al master cuando un nuevo usuario se registra.
 *
 * @param PDO    $pdo             Conexión BD
 * @param string $nickname        Nickname del nuevo usuario
 * @param string $patrocinador    Nickname del patrocinador
 * @param int    $total_usuarios  Total actual de usuarios reales en el sistema
 */
function notificarMasterNuevoUsuario(PDO $pdo, string $nickname, string $patrocinador, int $total_usuarios = 0): void
{
    $chat_id = obtenerChatIdMaster($pdo);
    $correoMaster = obtenerCorreoMaster($pdo);
    if (!$chat_id && !$correoMaster) return;

    // Obtener datos completos del nuevo usuario
    try {
        $stmt = $pdo->prepare("SELECT nombre_completo, telefono, correo_electronico, wallet_address FROM usuarios WHERE nickname = ? LIMIT 1");
        $stmt->execute([$nickname]);
        $u = $stmt->fetch();
    } catch (Exception $e) { $u = null; }

    $nombre   = ($u && !empty($u['nombre_completo'])) ? $u['nombre_completo'] : '—';
    $telefono = ($u && !empty($u['telefono']))         ? $u['telefono']        : '—';
    $correo   = ($u && !empty($u['correo_electronico'])) ? $u['correo_electronico'] : '—';
    $wallet   = ($u && !empty($u['wallet_address']))   ? $u['wallet_address']  : '—';
    $total_txt = $total_usuarios > 0 ? "\n👥 Total en red: *{$total_usuarios}*" : '';

    $msg = "🟢 *NUEVO USUARIO REGISTRADO*\n\n"
         . "👤 Nickname: *{$nickname}*\n"
         . "📝 Nombre: *{$nombre}*\n"
         . "📞 Teléfono: *{$telefono}*\n"
         . "📧 Correo: *{$correo}*\n"
         . "🏦 Wallet: `{$wallet}`\n"
         . "🔗 Patrocinador: *{$patrocinador}*"
         . $total_txt . "\n\n"
         . "_Sistema RADIX_";

    if ($chat_id) {
        enviarTelegram($chat_id, $msg);
    }
    if (!empty($correoMaster)) {
        $emailTitle = "Nuevo usuario registrado";
        $emailLines = [
            "Nickname: {$nickname}",
            "Nombre: {$nombre}",
            "Teléfono: {$telefono}",
            "Correo: {$correo}",
            "Wallet: {$wallet}",
            "Patrocinador: {$patrocinador}",
            $total_usuarios > 0 ? "Total en red: {$total_usuarios}" : "Total en red: —"
        ];
        enviarEmail($correoMaster, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
    }
}

/**
 * Notifica al master cuando un pago de entrada es confirmado en blockchain.
 *
 * @param PDO    $pdo      Conexión BD
 * @param string $nickname Nickname del usuario que pagó
 * @param float  $monto    Monto pagado en USDT
 * @param int    $fase     Número de fase
 */
function notificarMasterPagoConfirmado(PDO $pdo, string $nickname, float $monto, int $fase = 0): void
{
    $chat_id = obtenerChatIdMaster($pdo);
    $correoMaster = obtenerCorreoMaster($pdo);
    if (!$chat_id && !$correoMaster) return;

    // Obtener datos completos del usuario que pagó
    try {
        $stmt = $pdo->prepare("SELECT nombre_completo, telefono, correo_electronico, wallet_address FROM usuarios WHERE nickname = ? LIMIT 1");
        $stmt->execute([$nickname]);
        $u = $stmt->fetch();
    } catch (Exception $e) { $u = null; }

    $nombre   = ($u && !empty($u['nombre_completo'])) ? $u['nombre_completo'] : '—';
    $telefono = ($u && !empty($u['telefono']))         ? $u['telefono']        : '—';
    $correo   = ($u && !empty($u['correo_electronico'])) ? $u['correo_electronico'] : '—';
    $wallet   = ($u && !empty($u['wallet_address']))   ? $u['wallet_address']  : '—';

    $msg = "💎 *PAGO CONFIRMADO EN BLOCKCHAIN*\n\n"
         . "👤 Nickname: *{$nickname}*\n"
         . "📝 Nombre: *{$nombre}*\n"
         . "📞 Teléfono: *{$telefono}*\n"
         . "📧 Correo: *{$correo}*\n"
         . "🏦 Wallet: `{$wallet}`\n"
         . "💵 Monto pagado: *\${$monto} USDT*\n"
         . "📌 Fase: *Fase {$fase}*\n\n"
         . "_Sistema RADIX_";

    if ($chat_id) {
        enviarTelegram($chat_id, $msg);
    }
    if (!empty($correoMaster)) {
        $emailTitle = "Pago confirmado en blockchain";
        $emailLines = [
            "Nickname: {$nickname}",
            "Nombre: {$nombre}",
            "Teléfono: {$telefono}",
            "Correo: {$correo}",
            "Wallet: {$wallet}",
            "Monto pagado: $" . number_format($monto, 2) . " USDT",
            "Fase: {$fase}"
        ];
        enviarEmail($correoMaster, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
    }
}

/**
 * Notifica al master cuando un usuario solicita un retiro.
 * Este es un evento URGENTE — el master debe actuar.
 *
 * @param PDO    $pdo       Conexión BD
 * @param string $nickname  Nickname del usuario
 * @param float  $monto     Monto solicitado en USDT
 * @param string $wallet    Wallet destino del usuario
 * @param int    $fase      Número de fase del retiro
 */
function notificarMasterRetiroSolicitado(PDO $pdo, string $nickname, float $monto, string $wallet, int $fase = 0): void
{
    $chat_id = obtenerChatIdMaster($pdo);
    $correoMaster = obtenerCorreoMaster($pdo);
    if (!$chat_id && !$correoMaster) return;

    // Obtener datos completos del usuario que solicita el retiro
    try {
        $stmt = $pdo->prepare("SELECT nombre_completo, telefono, correo_electronico FROM usuarios WHERE nickname = ? LIMIT 1");
        $stmt->execute([$nickname]);
        $u = $stmt->fetch();
    } catch (Exception $e) { $u = null; }

    $nombre   = ($u && !empty($u['nombre_completo'])) ? $u['nombre_completo'] : '—';
    $telefono = ($u && !empty($u['telefono']))         ? $u['telefono']        : '—';
    $correo   = ($u && !empty($u['correo_electronico'])) ? $u['correo_electronico'] : '—';

    $msg = "🚨 *RETIRO SOLICITADO — ACCIÓN REQUERIDA*\n\n"
         . "👤 Nickname: *{$nickname}*\n"
         . "📝 Nombre: *{$nombre}*\n"
         . "📞 Teléfono: *{$telefono}*\n"
         . "📧 Correo: *{$correo}*\n"
         . "💸 Monto: *\$" . number_format($monto, 2) . " USDT*\n"
         . "📌 Fase: *Fase {$fase}*\n"
         . "🏦 Wallet destino: `{$wallet}`\n\n"
         . "⚡ Aprueba o rechaza desde el panel admin.\n\n"
         . "_Sistema RADIX_";

    if ($chat_id) {
        enviarTelegram($chat_id, $msg);
    }
    if (!empty($correoMaster)) {
        $emailTitle = "Retiro solicitado - acción requerida";
        $emailLines = [
            "Nickname: {$nickname}",
            "Nombre: {$nombre}",
            "Teléfono: {$telefono}",
            "Correo: {$correo}",
            "Monto: $" . number_format($monto, 2) . " USDT",
            "Fase: {$fase}",
            "Wallet destino: {$wallet}"
        ];
        enviarEmail($correoMaster, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
    }
}

/**
 * Notifica al master cuando la tesorería baja del umbral configurado.
 * Solo envía si bajó del umbral (evita spam verificando el estado previo).
 *
 * @param PDO   $pdo       Conexión BD
 * @param float $tesoreria Balance actual de tesorería
 */
function notificarMasterTesoreriaBaja(PDO $pdo, float $tesoreria): void
{
    $chat_id = obtenerChatIdMaster($pdo);
    $correoMaster = obtenerCorreoMaster($pdo);
    if (!$chat_id && !$correoMaster) return;

    if ($tesoreria >= MASTER_TESORERIA_UMBRAL) return;

    $msg = "🏦 *TESORERÍA BAJA — ATENCIÓN*\n\n"
         . "💰 Balance actual: *\$" . number_format($tesoreria, 2) . " USDT*\n"
         . "⚠️ El umbral mínimo es *\$" . number_format(MASTER_TESORERIA_UMBRAL, 2) . " USDT*\n\n"
         . "Considera recargar la tesorería para seguir activando Agentes IA.\n\n"
         . "_Sistema RADIX_";

    if ($chat_id) {
        enviarTelegram($chat_id, $msg);
    }
    if (!empty($correoMaster)) {
        $emailTitle = "Tesorería baja - atención";
        $emailLines = [
            "Balance actual: $" . number_format($tesoreria, 2) . " USDT",
            "Umbral mínimo: $" . number_format(MASTER_TESORERIA_UMBRAL, 2) . " USDT",
            "Considera recargar la tesorería para seguir activando Agentes IA."
        ];
        enviarEmail($correoMaster, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
    }
}

/**
 * Envía el resumen diario al master con todas las estadísticas del día.
 * Llama a esta función desde un cron job (ej. cada día a las 8am) o manualmente.
 *
 * @param PDO $pdo Conexión BD
 */
function enviarResumenDiarioMaster(PDO $pdo): void
{
    $chat_id = obtenerChatIdMaster($pdo);
    $correoMaster = obtenerCorreoMaster($pdo);
    if (!$chat_id && !$correoMaster) return;

    $hoy = date('Y-m-d');

    try {
        // Usuarios nuevos hoy
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM usuarios
            WHERE tipo_usuario = 'real'
              AND DATE(fecha_registro) = ?
        ");
        $stmt->execute([$hoy]);
        $nuevos_hoy = (int)$stmt->fetchColumn();

        // Total usuarios reales
        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'real'");
        $total_usuarios = (int)$stmt->fetchColumn();

        // Pagos confirmados hoy
        $stmt = $pdo->prepare("
            SELECT COUNT(*), COALESCE(SUM(monto_pagado), 0)
            FROM pagos
            WHERE tipo = 'regalo'
              AND estado = 'completado'
              AND origen_fondos = 'externo'
              AND DATE(fecha_pago) = ?
        ");
        $stmt->execute([$hoy]);
        [$pagos_hoy, $monto_pagos_hoy] = $stmt->fetch(PDO::FETCH_NUM);

        // Tableros completados hoy
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tableros_progreso
            WHERE estado = 'completado'
              AND DATE(fecha_fin) = ?
        ");
        $stmt->execute([$hoy]);
        $tableros_hoy = (int)$stmt->fetchColumn();

        // Retiros pendientes (total, no solo hoy)
        $stmt = $pdo->query("
            SELECT COUNT(*), COALESCE(SUM(monto), 0)
            FROM retiros
            WHERE estado = 'pendiente'
        ");
        [$retiros_pendientes, $monto_retiros] = $stmt->fetch(PDO::FETCH_NUM);

        // Balance tesorería actual
        $stmt = $pdo->query("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
        $tesoreria = (float)($stmt->fetchColumn() ?: 0);

        // Total blockchain recibido
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(monto_pagado), 0)
            FROM pagos
            WHERE tipo = 'regalo'
              AND estado = 'completado'
              AND origen_fondos = 'externo'
        ");
        $total_blockchain = (float)$stmt->fetchColumn();

        $fecha_display = date('d/m/Y');

        $msg = "📊 *RESUMEN DIARIO RADIX*\n"
             . "📅 {$fecha_display}\n"
             . "━━━━━━━━━━━━━━━━━━━\n\n"
             . "👥 *USUARIOS*\n"
             . "   • Nuevos hoy: *{$nuevos_hoy}*\n"
             . "   • Total red: *{$total_usuarios}*\n\n"
             . "💰 *PAGOS HOY*\n"
             . "   • Confirmados: *{$pagos_hoy}* pago(s)\n"
             . "   • Monto total: *\$" . number_format((float)$monto_pagos_hoy, 2) . " USDT*\n\n"
             . "🏆 *TABLEROS*\n"
             . "   • Completados hoy: *{$tableros_hoy}*\n\n"
             . "💸 *RETIROS PENDIENTES*\n"
             . "   • Cantidad: *{$retiros_pendientes}*\n"
             . "   • Monto total: *\$" . number_format((float)$monto_retiros, 2) . " USDT*\n\n"
             . "🏦 *FINANZAS*\n"
             . "   • Tesorería: *\$" . number_format($tesoreria, 2) . " USDT*\n"
             . "   • Total blockchain: *\$" . number_format($total_blockchain, 2) . " USDT*\n\n"
             . ($retiros_pendientes > 0 ? "⚠️ _Hay retiros pendientes de aprobación._\n\n" : "✅ _Sin retiros pendientes._\n\n")
             . "_Sistema RADIX_";

        if ($chat_id) {
            enviarTelegram($chat_id, $msg);
        }
        if (!empty($correoMaster)) {
            $emailTitle = "Resumen diario RADIX";
            $emailLines = [
                "Fecha: " . $fecha_display,
                "Usuarios nuevos hoy: {$nuevos_hoy}",
                "Total en red: {$total_usuarios}",
                "Pagos confirmados hoy: {$pagos_hoy}",
                "Monto pagos hoy: $" . number_format((float)$monto_pagos_hoy, 2) . " USDT",
                "Tableros completados hoy: {$tableros_hoy}",
                "Retiros pendientes: {$retiros_pendientes}",
                "Monto retiros pendientes: $" . number_format((float)$monto_retiros, 2) . " USDT",
                "Tesorería: $" . number_format($tesoreria, 2) . " USDT",
                "Total blockchain: $" . number_format($total_blockchain, 2) . " USDT"
            ];
            enviarEmail($correoMaster, $emailTitle, radix_build_email_html($emailTitle, $emailLines), radix_build_email_text($emailLines));
        }

    } catch (Exception $e) {
        error_log("RADIX master_notif resumen_diario ERROR: " . $e->getMessage());
    }
}
