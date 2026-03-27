<?php
/**
 * check_events.php — RADIX Phase 0
 * Endpoint de polling cada 30s. Retorna eventos nuevos desde el último check.
 * MEJORA #2: Sistema de notificaciones en tiempo real.
 */
require_once 'config.php';
session_start();

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

$wallet  = $_SESSION['radix_wallet'];
$since   = isset($_GET['since']) ? (int)$_GET['since'] : (time() - 60);
$since_dt = date('Y-m-d H:i:s', $since);

try {
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch();

    if (!$user) sendResponse(['eventos' => []]);

    $user_id = $user['id'];
    $eventos = [];

    // 1. Nuevos referidos desde el último check (máx. 10 por polling)
    $stmt = $pdo->prepare("
        SELECT u.nickname, u.fecha_registro
        FROM referidos r
        JOIN usuarios u ON r.id_hijo = u.id
        WHERE r.id_padre = ?
          AND u.tipo_usuario = 'real'
          AND u.fecha_registro > ?
        ORDER BY u.fecha_registro DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id, $since_dt]);
    $nuevos_refs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nuevos_refs as $ref) {
        $eventos[] = [
            'tipo'    => 'nuevo_referido',
            'mensaje' => "👤 ¡Nuevo referido! {$ref['nickname']} se unió a tu red.",
            'color'   => '#00d2ff',
        ];
    }

    // 2. Avances de tablero (ganancias nuevas) (máx. 10 por polling)
    $stmt = $pdo->prepare("
        SELECT monto, tipo, fecha_pago
        FROM pagos
        WHERE id_receptor = ?
          AND estado = 'completado'
          AND tipo = 'ganancia_tablero'
          AND fecha_pago > ?
        ORDER BY fecha_pago DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id, $since_dt]);
    $nuevas_ganancias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nuevas_ganancias as $g) {
        $eventos[] = [
            'tipo'    => 'avance_tablero',
            'mensaje' => "🏆 ¡Tablero completado! Ganaste \${$g['monto']} USDT.",
            'color'   => '#00e676',
        ];
    }

    // 3. Clones activados para este usuario (máx. 10 por polling)
    $stmt = $pdo->prepare("
        SELECT al.detalles, al.fecha
        FROM auditoria_logs al
        WHERE al.usuario_id = ?
          AND al.accion = 'ACTIVACION_CLON'
          AND al.fecha > ?
        ORDER BY al.fecha DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id, $since_dt]);
    $nuevos_clones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nuevos_clones as $c) {
        $eventos[] = [
            'tipo'    => 'clon_activado',
            'mensaje' => "🤖 ¡Agente IA activado para ti! Tu red está creciendo.",
            'color'   => '#9d00ff',
        ];
    }

    sendResponse([
        'eventos'    => $eventos,
        'timestamp'  => time(),
        'tiene_nuevos' => count($eventos) > 0,
    ]);

} catch (PDOException $e) {
    error_log("RADIX check_events ERROR: " . $e->getMessage());
    sendResponse(['eventos' => []]);
}
