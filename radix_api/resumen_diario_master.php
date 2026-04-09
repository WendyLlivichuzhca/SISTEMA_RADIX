<?php
/**
 * resumen_diario_master.php — RADIX System
 * Envía el resumen diario al RADIX_MASTER vía Telegram.
 *
 * USO:
 *   Opción A — Cron job (recomendado):
 *     Agregar en cPanel > Cron Jobs:
 *       0 8 * * * php /ruta/a/radix_api/resumen_diario_master.php
 *     (Se ejecuta todos los días a las 8:00 AM del servidor)
 *
 *   Opción B — Manualmente desde el panel admin:
 *     Hacer una petición GET a: radix_api/resumen_diario_master.php?clave=RADIX_RESUMEN
 *     (Solo accesible con la clave correcta para proteger el endpoint)
 *
 * SEGURIDAD:
 *   Si se accede vía web (no CLI), requiere la clave secreta en el query string.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notificaciones.php';
require_once __DIR__ . '/master_notif.php';

// ── Protección si se accede vía HTTP (no desde cron CLI) ─────────────────────
if (php_sapi_name() !== 'cli') {
    $clave_enviada = trim($_GET['clave'] ?? '');
    $clave_correcta = '';

    if (defined('RESUMEN_DIARIO_CLAVE')) {
        $clave_correcta = trim((string) RESUMEN_DIARIO_CLAVE);
    } else {
        $clave_env = getenv('RESUMEN_DIARIO_CLAVE');
        if ($clave_env !== false) {
            $clave_correcta = trim((string) $clave_env);
        }
    }

    // No permitimos una clave por defecto insegura para el acceso web manual.
    if ($clave_correcta === '') {
        http_response_code(503);
        die(json_encode(['error' => 'Resumen diario no habilitado: configura RESUMEN_DIARIO_CLAVE.']));
    }

    if (!hash_equals($clave_correcta, $clave_enviada)) {
        http_response_code(403);
        die(json_encode(['error' => 'No autorizado']));
    }
}

// ── Ejecutar el resumen ───────────────────────────────────────────────────────
try {
    enviarResumenDiarioMaster($pdo);

    $msg_ok = "✅ Resumen diario enviado al master vía Telegram.";
    if (php_sapi_name() === 'cli') {
        echo $msg_ok . PHP_EOL;
    } else {
        echo json_encode(['success' => true, 'mensaje' => $msg_ok]);
    }
} catch (Exception $e) {
    $msg_err = "❌ Error al enviar resumen: " . $e->getMessage();
    error_log("RADIX resumen_diario ERROR: " . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo $msg_err . PHP_EOL;
    } else {
        echo json_encode(['success' => false, 'error' => $msg_err]);
    }
}
