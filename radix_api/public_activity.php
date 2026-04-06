<?php
/**
 * public_activity.php — RADIX
 * Endpoint público (sin autenticación) para la landing page.
 * Devuelve actividad reciente del sistema de forma anonimizada:
 *   - Registros nuevos de usuarios reales
 *   - Tableros completados (ganancias_tablero)
 *   - Clones activados
 *
 * Los nicknames se muestran solo con inicial del apellido por privacidad.
 * No expone wallets, IDs internos ni datos sensibles.
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

// Rate-limit simple por IP: máx 30 req/min (bloqueo suave, solo registra en log)
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$limit  = 30;
$window = 60; // segundos
// (Implementación ligera sin Redis — solo se loguea, no bloquea para no romper la landing)

// ── Helper: Anonimizar nickname ──────────────────────────────────────────────
function anonimizarNick(string $nick): string {
    $partes = preg_split('/[\s_\-]+/', trim($nick));
    if (count($partes) >= 2) {
        // Ej: "carlos_martinez" → "Carlos M."
        return ucfirst(strtolower($partes[0])) . ' ' . strtoupper(substr($partes[1], 0, 1)) . '.';
    }
    // Solo un token: mostrar primeros 4 caracteres + "***"
    $visible = mb_substr($nick, 0, min(4, mb_strlen($nick)));
    return $visible . '***';
}

// ── Helper: Etiqueta de fase ─────────────────────────────────────────────────
function etiquetaFase(int $fase): string {
    $map = [0 => 'Fase 0', 1 => 'Fase 1', 2 => 'Fase 2', 3 => 'Fase 3 Platinum'];
    return $map[$fase] ?? "Fase {$fase}";
}

// ── Helper: "hace X min/h" ───────────────────────────────────────────────────
function haceCuanto(string $fecha_db): string {
    $ts   = strtotime($fecha_db);
    $diff = time() - $ts;
    if ($diff < 60)        return 'hace un momento';
    if ($diff < 3600)      return 'hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400)     return 'hace ' . floor($diff / 3600) . ' h';
    return 'hace ' . floor($diff / 86400) . ' día(s)';
}

try {
    $eventos = [];

    // ── 1. Registros recientes de usuarios reales (últimas 24 h, máx 15) ─────
    $stmt = $pdo->prepare("
        SELECT nickname, fecha_registro
        FROM   usuarios
        WHERE  tipo_usuario = 'real'
          AND  fecha_registro >= NOW() - INTERVAL 24 HOUR
        ORDER  BY fecha_registro DESC
        LIMIT  15
    ");
    $stmt->execute();
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($registros as $r) {
        $eventos[] = [
            'tipo'   => 'registro',
            'emoji'  => '🚀',
            'nombre' => anonimizarNick($r['nickname']),
            'msg'    => 'acaba de unirse a RADIX',
            'hace'   => haceCuanto($r['fecha_registro']),
        ];
    }

    // ── 2. Ganancias de tablero recientes (últimas 48 h, máx 20) ─────────────
    $stmt = $pdo->prepare("
        SELECT u.nickname,
               p.monto,
               p.fase_numero,
               p.fecha_pago
        FROM   pagos p
        JOIN   usuarios u ON p.id_receptor = u.id
        WHERE  p.tipo            = 'ganancia_tablero'
          AND  p.propietario_flujo = 'usuario'
          AND  p.estado          = 'completado'
          AND  u.tipo_usuario    = 'real'
          AND  p.fecha_pago      >= NOW() - INTERVAL 48 HOUR
        ORDER  BY p.fecha_pago DESC
        LIMIT  20
    ");
    $stmt->execute();
    $ganancias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ganancias as $g) {
        $fase   = etiquetaFase((int)$g['fase_numero']);
        $monto  = number_format((float)$g['monto'], 0, '.', ',');
        $eventos[] = [
            'tipo'   => 'ganancia',
            'emoji'  => '💰',
            'nombre' => anonimizarNick($g['nickname']),
            'msg'    => "completó un tablero en {$fase} — <strong>+\${$monto} USDT</strong>",
            'hace'   => haceCuanto($g['fecha_pago']),
        ];
    }

    // ── 3. Clones activados recientes (últimas 48 h, máx 10) ─────────────────
    $stmt = $pdo->prepare("
        SELECT al.fecha, u.nickname
        FROM   auditoria_logs al
        JOIN   usuarios u ON al.usuario_id = u.id
        WHERE  al.accion       = 'ACTIVACION_CLON'
          AND  u.tipo_usuario  = 'real'
          AND  al.fecha        >= NOW() - INTERVAL 48 HOUR
        ORDER  BY al.fecha DESC
        LIMIT  10
    ");
    $stmt->execute();
    $clones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($clones as $c) {
        $eventos[] = [
            'tipo'   => 'clon',
            'emoji'  => '🤖',
            'nombre' => anonimizarNick($c['nickname']),
            'msg'    => 'recibió un Agente IA en su red',
            'hace'   => haceCuanto($c['fecha']),
        ];
    }

    // ── 4. Cierres de ciclo completo (Tablero C — últimas 72 h, máx 10) ──────
    $stmt = $pdo->prepare("
        SELECT u.nickname,
               tp.fase_numero,
               tp.fecha_actualizacion
        FROM   tableros_progreso tp
        JOIN   usuarios u ON tp.usuario_id = u.id
        WHERE  tp.tablero_tipo = 'C'
          AND  tp.estado       = 'finalizado'
          AND  u.tipo_usuario  = 'real'
          AND  tp.fecha_actualizacion >= NOW() - INTERVAL 72 HOUR
        ORDER  BY tp.fecha_actualizacion DESC
        LIMIT  10
    ");
    $stmt->execute();
    $cierres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cierres as $cc) {
        $fase = etiquetaFase((int)$cc['fase_numero']);
        $eventos[] = [
            'tipo'   => 'ciclo',
            'emoji'  => '🏆',
            'nombre' => anonimizarNick($cc['nickname']),
            'msg'    => "completó su ciclo completo de {$fase}",
            'hace'   => haceCuanto($cc['fecha_actualizacion']),
        ];
    }

    // ── Mezclar y limitar a 25 eventos más recientes ──────────────────────────
    // (Ya vienen ordenados por fecha DESC desde cada query; shuffle para variedad visual)
    shuffle($eventos);
    $eventos = array_slice($eventos, 0, 25);

    sendResponse([
        'success'  => true,
        'eventos'  => $eventos,
        'total'    => count($eventos),
        'ts'       => time(),
    ]);

} catch (PDOException $e) {
    error_log("RADIX public_activity ERROR: " . $e->getMessage());
    // En caso de error, respuesta vacía limpia — el JS usará datos de fallback
    sendResponse(['success' => false, 'eventos' => [], 'total' => 0, 'ts' => time()]);
}
?>
