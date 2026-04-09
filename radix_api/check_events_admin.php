<?php
/**
 * check_events_admin.php — RADIX System
 * Endpoint de polling exclusivo para el RADIX_MASTER.
 * El dashboard admin lo consulta cada 30s para mostrar toasts en tiempo real.
 *
 * Retorna eventos nuevos desde el último timestamp consultado:
 *   - Nuevos usuarios registrados
 *   - Pagos de entrada confirmados
 *   - Retiros solicitados
 *   - Alertas de tesorería
 */
require_once 'config.php';
require_once 'admin_auth.php';
requireAdminSession();

$since    = isset($_GET['since']) ? (int)$_GET['since'] : (time() - 60);
$since_dt = date('Y-m-d H:i:s', $since);

$eventos = [];

try {
    // 1. Nuevos usuarios registrados desde el último check
    $stmt = $pdo->prepare("
        SELECT nickname, fecha_registro
        FROM usuarios
        WHERE tipo_usuario = 'real'
          AND fecha_registro > ?
        ORDER BY fecha_registro DESC
        LIMIT 10
    ");
    $stmt->execute([$since_dt]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $eventos[] = [
            'tipo'    => 'nuevo_usuario',
            'mensaje' => "🟢 Nuevo usuario: {$u['nickname']} se unió a la red.",
            'color'   => '#00e676',
        ];
    }

    // 2. Pagos confirmados en blockchain desde el último check
    $stmt = $pdo->prepare("
        SELECT p.monto_pagado, u.nickname, p.fase_numero
        FROM pagos p
        JOIN usuarios u ON p.id_emisor = u.id
        WHERE p.tipo = 'regalo'
          AND p.estado = 'completado'
          AND p.origen_fondos = 'externo'
          AND p.fecha_pago > ?
        ORDER BY p.fecha_pago DESC
        LIMIT 10
    ");
    $stmt->execute([$since_dt]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $eventos[] = [
            'tipo'    => 'pago_confirmado',
            'mensaje' => "💎 Pago confirmado: {$p['nickname']} pagó \${$p['monto_pagado']} USDT (Fase {$p['fase_numero']}).",
            'color'   => '#00d2ff',
        ];
    }

    // 3. Retiros solicitados desde el último check (URGENTE)
    $stmt = $pdo->prepare("
        SELECT r.monto, r.fase_numero, u.nickname
        FROM retiros r
        JOIN usuarios u ON r.usuario_id = u.id
        WHERE r.estado = 'pendiente'
          AND r.fecha_solicitud > ?
        ORDER BY r.fecha_solicitud DESC
        LIMIT 10
    ");
    $stmt->execute([$since_dt]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eventos[] = [
            'tipo'    => 'retiro_solicitado',
            'mensaje' => "🚨 Retiro solicitado: {$r['nickname']} pide \${$r['monto']} USDT (Fase {$r['fase_numero']}).",
            'color'   => '#ff5252',
            'urgente' => true,
        ];
    }

    // 4. Verificar si la tesorería está baja (alerta permanente, no por timestamp)
    $stmt = $pdo->query("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
    $tesoreria = (float)($stmt->fetchColumn() ?: 0);
    if ($tesoreria < 50.0 && $tesoreria >= 0) {
        $eventos[] = [
            'tipo'    => 'tesoreria_baja',
            'mensaje' => "🏦 Tesorería baja: $" . number_format($tesoreria, 2) . " USDT disponibles.",
            'color'   => '#ffb300',
            'urgente' => true,
        ];
    }

    echo json_encode([
        'success'      => true,
        'eventos'      => $eventos,
        'timestamp'    => time(),
        'tiene_nuevos' => count($eventos) > 0,
    ]);

} catch (PDOException $e) {
    error_log("RADIX check_events_admin ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'eventos' => [], 'timestamp' => time()]);
}
