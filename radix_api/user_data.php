<?php
require_once 'config.php';
session_start();

// Endpoint para obtener información detallada del usuario para el Dashboard Premium
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Seguridad: solo se permite si hay sesión activa
    if (empty($_SESSION['radix_wallet'])) {
        sendResponse(['error' => 'No autorizado'], 401);
    }

    // La wallet siempre viene de la sesión (no del GET, para evitar acceso cruzado)
    $wallet = $_SESSION['radix_wallet'];

    if (empty($wallet)) {
        sendResponse(['error' => 'La billetera es necesaria'], 400);
    }

    try {
        // 1. Datos básicos del usuario
        $stmt = $pdo->prepare("SELECT id, nickname, wallet_address, tipo_usuario, telegram_chat_id, fecha_registro FROM usuarios WHERE wallet_address = ?");
        $stmt->execute([$wallet]);
        $user = $stmt->fetch();

        if (!$user) {
            sendResponse(['error' => 'Usuario no encontrado'], 404);
        }

        $user_id = $user['id'];

        // 2. Tablero actual y progreso visual
        $stmt = $pdo->prepare("SELECT id, tablero_tipo, ciclo, fecha_inicio FROM tableros_progreso WHERE usuario_id = ? AND estado = 'en_progreso' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $tablero = $stmt->fetch();
        $nivel_actual = $tablero ? $tablero['tablero_tipo'] : 'A';
        $ciclo_actual = $tablero ? $tablero['ciclo'] : 1;

        // 3. Contador de Clones Activos (Agentes IA) — asignados PARA este usuario
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM referidos r JOIN usuarios u ON r.id_hijo = u.id WHERE r.id_padre = ? AND u.tipo_usuario = 'clon'");
        $stmt->execute([$user_id]);
        $clones_count = (int)($stmt->fetch()['total'] ?? 0);

        // 4. Referidos directos (Humanos) con estado de pago y su tablero actual
        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.nickname,
                u.wallet_address AS wallet,
                u.tipo_usuario AS tipo,
                r.posicion,
                (SELECT estado FROM pagos WHERE id_emisor = u.id AND tipo = 'regalo' ORDER BY id DESC LIMIT 1) AS pago_estado,
                (SELECT tablero_tipo FROM tableros_progreso WHERE usuario_id = u.id AND estado = 'en_progreso' ORDER BY id DESC LIMIT 1) AS nivel_actual
            FROM referidos r
            JOIN usuarios u ON r.id_hijo = u.id
            WHERE r.id_padre = ? AND u.tipo_usuario = 'real'
            ORDER BY r.posicion ASC
        ");
        $stmt->execute([$user_id]);
        $referidos_reales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Cálculo de Ganancias
        // 5a. Ganancia bruta acumulada (todos los tableros completados)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM pagos WHERE id_receptor = ? AND estado = 'completado' AND tipo = 'ganancia_tablero'");
        $stmt->execute([$user_id]);
        $total_ganado_bruto = (float)$stmt->fetch()['total'];

        // 5b. Deducciones automáticas del sistema al completar ciclo
        //   - salto_fase_1: $100 que van al pool de Fase 1 (retención del sistema)
        //   - reentrada:    $10 que permiten volver a participar en Fase 1 (retención del sistema)
        //   Ambas se deducen automáticamente en matrix_logic.php al completar Tablero C.
        //   NO son pagos manuales del usuario — son retenciones de sus ganancias brutas.
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM pagos WHERE id_emisor = ? AND estado = 'completado' AND tipo IN ('salto_fase_1', 'reentrada')");
        $stmt->execute([$user_id]);
        $total_deducciones = (float)$stmt->fetch()['total'];

        // 5c. Reserva Fase 1 personal: cuánto ha aportado este usuario al pool de Fase 1
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM pagos WHERE id_emisor = ? AND estado = 'completado' AND tipo = 'salto_fase_1'");
        $stmt->execute([$user_id]);
        $reserva_fase1 = (float)$stmt->fetch()['total'];

        // 5d. Retiros ya procesados (aprobados y pagados por el admin)
        //     Se descuentan para evitar que el usuario pueda retirar el mismo saldo dos veces.
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM retiros WHERE usuario_id = ? AND estado = 'procesado'");
        $stmt->execute([$user_id]);
        $total_ya_retirado = (float)($stmt->fetch()['total'] ?? 0);

        // Saldo neto disponible para retiro (bruto - deducciones del sistema - retiros ya cobrados)
        $earnings_net = $total_ganado_bruto - $total_deducciones - $total_ya_retirado;

        // 5e. Historial de movimientos (ganancias + retenciones) para mostrar en el dashboard
        $stmt = $pdo->prepare("
            SELECT tipo, monto, fecha_pago AS fecha, estado,
                   CASE tipo
                     WHEN 'ganancia_tablero' THEN 'Ganancia Tablero'
                     WHEN 'salto_fase_1'     THEN 'Retención Fase 1'
                     WHEN 'reentrada'        THEN 'Re-entrada Fase 1'
                     ELSE tipo
                   END AS tipo_label,
                   CASE tipo
                     WHEN 'ganancia_tablero' THEN 'ingreso'
                     ELSE 'deduccion'
                   END AS direccion
            FROM pagos
            WHERE id_receptor = ? AND tipo = 'ganancia_tablero' AND estado = 'completado'
            UNION ALL
            SELECT tipo, monto, fecha_pago AS fecha, estado,
                   CASE tipo
                     WHEN 'salto_fase_1' THEN 'Retención Fase 1'
                     WHEN 'reentrada'    THEN 'Re-entrada Fase 1'
                     ELSE tipo
                   END AS tipo_label,
                   'deduccion' AS direccion
            FROM pagos
            WHERE id_emisor = ? AND tipo IN ('salto_fase_1','reentrada') AND estado = 'completado'
            ORDER BY fecha DESC
            LIMIT 20
        ");
        $stmt->execute([$user_id, $user_id]);
        $historial_ganancias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 6. Pago pendiente + wallet del patrón a quien debe enviar el USDT
        $stmt = $pdo->prepare("
            SELECT p.id, p.monto, patron.wallet_address AS wallet_patron
            FROM pagos p
            JOIN usuarios patron ON p.id_receptor = patron.id
            WHERE p.id_emisor = ? AND p.estado = 'pendiente' AND p.tipo = 'regalo'
            ORDER BY p.id ASC LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $pago_pendiente = $stmt->fetch() ?: null;

        // 7. Estadísticas de Tesorería Global (Solo para el Master — tipo_usuario = 'master')
        $treasury_stats = null;
        if ($user['tipo_usuario'] === 'master') {
            // Balance de Tesorería (Fondos para Clones)
            $stmt = $pdo->prepare("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
            $stmt->execute();
            $tesoreria_bal = (float)($stmt->fetch()['valor_decimal'] ?? 0);

            // Conteo de Usuarios Reales (Total Red)
            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'real'");
            $total_reales = (int)($stmt->fetchColumn() ?? 0);

            // Reserva Fase 1 acumulada (todas las retenciones de $100)
            $stmt = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE tipo = 'salto_fase_1' AND estado = 'completado'");
            $fase1_pool = (float)($stmt->fetchColumn() ?? 0);

            // Libro Mayor (Últimos movimientos de tesorería)
            $stmt = $pdo->query("
                SELECT fecha, motivo AS concepto, monto, tipo
                FROM tesoreria_movimientos
                ORDER BY id DESC
                LIMIT 15
            ");
            $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Ganancia acumulada del Master (regalos recibidos completados)
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE id_receptor = 1 AND tipo = 'regalo' AND estado = 'completado'");
            $stmt->execute();
            $master_earnings = (float)($stmt->fetchColumn() ?? 0);

            $treasury_stats = [
                'tesoreria_balance' => $tesoreria_bal,
                'total_reales'      => $total_reales,
                'fase1_pool'        => $fase1_pool,
                'ledger'            => $ledger,
                'master_earnings'   => $master_earnings,
            ];
        }

        // 8. Construir y devolver respuesta completa
        sendResponse([
            'success'       => true,
            'user'          => [
                'id'             => (int)$user['id'],
                'nickname'       => $user['nickname'],
                'wallet'         => $user['wallet_address'],
                'tipo_usuario'   => $user['tipo_usuario'],
                'nivel'          => $nivel_actual,
                'ciclo'          => (int)$ciclo_actual,
                'clones_count'   => $clones_count,
                'has_telegram'   => !empty($user['telegram_chat_id']),
                'pago_pendiente' => $pago_pendiente !== null,
            ],
            // Saldo neto disponible para retirar
            'earnings'       => round($earnings_net, 2),
            // Desglose para transparencia
            'earnings_bruto'       => round($total_ganado_bruto, 2),
            'earnings_deducciones' => round($total_deducciones, 2),
            // Aporte personal al pool de Fase 1 (para widget val-reserva)
            'reserva_fase1'  => round($reserva_fase1, 2),
            // Equipo directo humano (para widget val-equipo-count y tabla de equipo)
            'referidos'      => $referidos_reales,
            // Historial con ingresos y retenciones diferenciados
            'historial'      => $historial_ganancias,
            // Pago pendiente en blockchain
            'pago_pendiente' => $pago_pendiente,
            // Solo para master (null para usuarios normales)
            'treasury'       => $treasury_stats,
        ]);

    } catch (PDOException $e) {
        error_log("RADIX user_data ERROR: " . $e->getMessage());
        sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
    }
} else {
    sendResponse(['error' => 'Método no permitido'], 405);
}
?>
