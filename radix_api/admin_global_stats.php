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

        // 3b. Re-entradas ya reinvertidas dentro del sistema
        $stmt = $pdo->query("SELECT SUM(monto) FROM pagos WHERE tipo = 'reentrada' AND estado = 'completado'");
        $reentrada_pool = (float)($stmt->fetchColumn() ?: 0);

        // 3c. Reservas internas aplicadas entre tableros (A -> B, B -> C)
        $reservas_aplicadas = 0.0;
        $reservas_pendientes = 0.0;
        $logs_reservas = [];
        try {
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(monto), 0)
                FROM reservas_tablero
                WHERE estado = 'usado'
            ");
            $reservas_aplicadas = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->query("
                SELECT COALESCE(SUM(monto), 0)
                FROM reservas_tablero
                WHERE estado = 'reservado'
            ");
            $reservas_pendientes = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->query("
                SELECT rt.usuario_id, rt.desde_tablero, rt.hacia_destino, rt.ciclo_origen,
                       rt.ciclo_destino, rt.monto, rt.estado, rt.detalle, rt.fecha_creacion,
                       rt.fecha_uso, u.nickname
                FROM reservas_tablero rt
                LEFT JOIN usuarios u ON rt.usuario_id = u.id
                ORDER BY rt.id DESC
                LIMIT 20
            ");
            $logs_reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // La tabla puede no existir aun en instalaciones antiguas.
        }

        // 4. Historial detallado de clones activados (con usuario beneficiario)
        // NOTA: Se eliminó el LEFT JOIN con tesoreria_movimientos porque producía filas
        // duplicadas cuando un usuario recibía más de un clon (N egresos × M logs = N×M filas).
        // El monto se extrae del campo 'detalles' que ya lo guarda en formato "$X de tesorería".
        $stmt = $pdo->query("
            SELECT al.id, al.detalles, al.fecha,
                   u.nickname, u.wallet_address
            FROM auditoria_logs al
            LEFT JOIN usuarios u ON al.usuario_id = u.id
            WHERE al.accion = 'ACTIVACION_CLON'
            ORDER BY al.id DESC LIMIT 10
        ");
        $logs_clones_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Extraer el monto del texto: "Clon X generado con $10 de tesorería."
        $logs_clones = array_map(function($row) {
            preg_match('/\$(\d+(?:\.\d+)?)/', $row['detalles'] ?? '', $m);
            $row['monto'] = isset($m[1]) ? (float)$m[1] : null;
            return $row;
        }, $logs_clones_raw);

        // 5. Total distribuido a la red como ganancias de tableros completados.
        //    NOTA: RADIX_MASTER NO tiene ganancias personales — es la billetera central del sistema.
        //    Los pagos tipo 'regalo' que llegan a id=1 son ENTRADAS del sistema, NO utilidad del master.
        //    La ganancia real se genera vía 'ganancia_tablero' cuando un tablero se completa (matrix_logic.php).
        $stmt = $pdo->query("SELECT COALESCE(SUM(monto), 0) as total FROM pagos WHERE tipo = 'ganancia_tablero' AND estado = 'completado'");
        $master_earnings = (float)($stmt->fetch()['total'] ?? 0);  // = total ya distribuido a usuarios de la red

        // 5b. Total físico recibido en blockchain (TODOS los pagos de entrada van a RADIX_MASTER wallet on-chain)
        //     Incluye pagos donde id_receptor es cualquier usuario (ej: TKqT recibe comisión de TQ2R)
        //     pero el USDT físico siempre llega a la billetera central TDLFwy5swL2B8stX6tgUgQr2BjB1DFdwoU
        $stmt = $pdo->query("SELECT COALESCE(SUM(monto_pagado), 0) as total FROM pagos WHERE tipo = 'regalo' AND estado = 'completado'");
        $total_blockchain = (float)($stmt->fetch()['total'] ?? 0);

        // 5c. Total pendiente de distribuir = entradas blockchain - fondos ya asignados internamente.
        //     Se descuentan ganancias ya pagadas, tesoreria, reservas usadas, fondo Fase 1 y re-entradas.
        $pendiente_distribuir = max(
            0,
            $total_blockchain
            - $master_earnings
            - $tesoreria
            - $reservas_aplicadas
            - $fase1_pool
            - $reentrada_pool
        );

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
            'reentrada_pool'        => (float)$reentrada_pool,
            'reservas_aplicadas'    => (float)$reservas_aplicadas,
            'reservas_pendientes'   => (float)$reservas_pendientes,
            'master_id1_earnings'   => (float)$master_earnings,
            'total_blockchain'      => (float)$total_blockchain,
            'pendiente_distribuir'  => (float)$pendiente_distribuir,
            'usuarios' => [
                'reales' => (int)$total_reales,
                'clones' => (int)$total_clones,
                'total'  => (int)($total_reales + $total_clones),
            ],
            'distribucion_tableros' => $distribucion_tableros,
            'crecimiento_diario'    => $crecimiento_diario,
            'logs'                  => $logs_clones,
            'logs_reservas'         => $logs_reservas,
            'logs_actividad'        => $logs_actividad,
            'lista_usuarios'        => $lista_usuarios,
            'retiros_pendientes'    => $retiros_pendientes,
            'tesoreria_movimientos' => $tesoreria_movimientos,
        ]);

    } catch (PDOException $e) {
        error_log("RADIX admin_global_stats ERROR: " . $e->getMessage());
        sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
    }
} else {
    sendResponse(['error' => 'Método no permitido'], 405);
}
?>
