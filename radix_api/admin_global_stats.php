<?php
require_once 'config.php';
require_once 'admin_auth.php';
requireAdminSession(); // 🔒 Solo admins autenticados

// admin_global_stats.php - API exclusiva para la dueña (RADIX Master Control)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // 1. Balance de Tesorería (Fondos para Clones)
        $stmt = $pdo->prepare("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
        $stmt->execute();
        $tesoreria = $stmt->fetch()['valor_decimal'] ?? 0;

        // 2. Conteo de Usuarios (Reales vs Clones)
        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'real'");
        $total_reales = (int)($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'clon'");
        $total_clones = (int)($stmt->fetchColumn() ?: 0);

        // 3. Fondos Acumulados para Fase 1 (Deducciones de $100)
        $stmt = $pdo->query("SELECT SUM(monto) FROM pagos WHERE tipo = 'salto_fase_1' AND estado = 'completado'");
        $fase1_pool = (float)($stmt->fetchColumn() ?: 0);

        // 4. Historial detallado de clones activados (con usuario beneficiario)
        $stmt = $pdo->query("
            SELECT al.detalles, al.fecha,
                   u.nickname, u.wallet_address,
                   tm.monto
            FROM auditoria_logs al
            LEFT JOIN usuarios u ON al.usuario_id = u.id
            LEFT JOIN tesoreria_movimientos tm ON tm.relacion_id = al.usuario_id AND tm.tipo = 'egreso'
            WHERE al.accion = 'ACTIVACION_CLON'
            ORDER BY al.id DESC LIMIT 10
        ");
        $logs_clones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Ganancia del Master (Cuenta ID #1)
        $stmt = $pdo->query("SELECT SUM(monto) as total FROM pagos WHERE id_receptor = 1 AND tipo = 'ganancia_tablero' AND estado = 'completado'");
        $master_earnings = (float)($stmt->fetch()['total'] ?? 0);

        // 6. Distribución de usuarios por tablero actual
        $stmt = $pdo->query("
            SELECT tablero_tipo, COUNT(*) as cantidad
            FROM tableros_progreso
            WHERE estado = 'en_progreso'
            GROUP BY tablero_tipo
        ");
        $dist_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $distribucion_tableros = [
            'A' => (int)($dist_raw['A'] ?? 0),
            'B' => (int)($dist_raw['B'] ?? 0),
            'C' => (int)($dist_raw['C'] ?? 0),
        ];

        // 7. Crecimiento diario — nuevos usuarios por día (últimos 7 días)
        $stmt = $pdo->query("
            SELECT DATE(fecha_registro) as dia, COUNT(*) as nuevos
            FROM usuarios
            WHERE tipo_usuario = 'real'
              AND fecha_registro >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(fecha_registro)
            ORDER BY dia ASC
        ");
        $crecimiento_diario = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 8. Registro de Actividad General (TODOS los tipos de acción)
        $stmt = $pdo->query("
            SELECT al.accion, al.detalles, al.fecha, u.nickname
            FROM auditoria_logs al
            LEFT JOIN usuarios u ON al.usuario_id = u.id
            ORDER BY al.id DESC LIMIT 20
        ");
        $logs_actividad = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 9. Solicitudes de retiro pendientes
        $retiros_pendientes = [];
        try {
            $stmt = $pdo->query("
                SELECT r.id, r.monto, r.wallet_destino, r.fecha_solicitud, u.nickname
                FROM retiros r
                JOIN usuarios u ON r.usuario_id = u.id
                WHERE r.estado = 'pendiente'
                ORDER BY r.fecha_solicitud ASC
                LIMIT 20
            ");
            $retiros_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { }

        // 10. Lista completa de usuarios reales
        $stmt = $pdo->query("
            SELECT id, nickname, wallet_address, tipo_usuario, fecha_registro
            FROM usuarios
            WHERE tipo_usuario IN ('real', 'master')
            ORDER BY id ASC
        ");
        $lista_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 11. Movimientos de Tesorería (libro mayor)
        $stmt = $pdo->query("
            SELECT tipo, monto, motivo, fecha
            FROM tesoreria_movimientos
            ORDER BY id DESC LIMIT 30
        ");
        $tesoreria_movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse([
            'success'               => true,
            'tesoreria'             => (float)$tesoreria,
            'fase1_pool'            => (float)$fase1_pool,
            'master_id1_earnings'   => (float)$master_earnings,
            'usuarios' => [
                'reales' => (int)$total_reales,
                'clones' => (int)$total_clones,
                'total'  => (int)($total_reales + $total_clones),
            ],
            'distribucion_tableros' => $distribucion_tableros,
            'crecimiento_diario'    => $crecimiento_diario,
            'logs'                  => $logs_clones,
            'logs_actividad'        => $logs_actividad,
            'lista_usuarios'        => $lista_usuarios,
            'retiros_pendientes'    => $retiros_pendientes,
            'tesoreria_movimientos' => $tesoreria_movimientos,
        ]);

    } catch (PDOException $e) {
        sendResponse(['error' => 'Error del servidor: ' . $e->getMessage()], 500);
    }
} else {
    sendResponse(['error' => 'Método no permitido'], 405);
}
?>
