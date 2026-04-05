<?php
require_once 'config.php';
require_once 'phase_config.php';
session_start();

function userDataHasColumn(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function userDataDisplayNameExpr(PDO $pdo, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return userDataHasColumn($pdo, 'nombre_completo')
        ? "COALESCE(NULLIF({$prefix}nombre_completo, ''), {$prefix}nickname)"
        : "{$prefix}nickname";
}

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
        $displayNameSelect = userDataDisplayNameExpr($pdo) . " AS display_name";

        $stmt = $pdo->prepare("SELECT id, nickname, {$displayNameSelect}, wallet_address, tipo_usuario, telegram_chat_id, fecha_registro FROM usuarios WHERE wallet_address = ?");
        $stmt->execute([$wallet]);
        $user = $stmt->fetch();

        if (!$user) {
            sendResponse(['error' => 'Usuario no encontrado'], 404);
        }

        $user_id = $user['id'];

        $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'max_referidos' LIMIT 1");
        $stmt->execute();
        $max_referidos = max(1, (int)($stmt->fetchColumn() ?: 3));

        // 2. Tablero actual y contexto visual del dashboard
        $stmt = $pdo->prepare("
            SELECT id, fase_numero, tablero_tipo, ciclo, fecha_inicio
            FROM tableros_progreso
            WHERE usuario_id = ?
              AND estado = 'en_progreso'
            ORDER BY fase_numero DESC, ciclo DESC, id DESC
        ");
        $stmt->execute([$user_id]);
        $activeBoards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $parallelBoards = [];
        foreach ($activeBoards as $activeBoardRow) {
            $faseNumeroBoard = (int)$activeBoardRow['fase_numero'];
            $phaseConfig = getPhaseConfig($pdo, $faseNumeroBoard);
            $parallelBoards[] = [
                'id' => (int)$activeBoardRow['id'],
                'fase_numero' => $faseNumeroBoard,
                'fase_nombre' => $phaseConfig['nombre'] ?: ('Fase ' . $faseNumeroBoard),
                'tablero_tipo' => $activeBoardRow['tablero_tipo'],
                'ciclo' => (int)$activeBoardRow['ciclo'],
                'fecha_inicio' => $activeBoardRow['fecha_inicio'],
            ];
        }

        $tablero = $activeBoards[0] ?? null;
        $fase_actual = $tablero ? (int)$tablero['fase_numero'] : 0;
        $ciclo_actual = $tablero ? (int)$tablero['ciclo'] : 1;
        $nivel_actual = 'A';
        $dashboard_phase_cfg = getPhaseConfig($pdo, $fase_actual);

        if ($tablero) {
            $nivel_actual = $tablero['tablero_tipo'];
        } else {
            // Sin tablero activo: identificar la fase mas alta ya cerrada para no ocultar avances paralelos.
            $stmt_check = $pdo->prepare("
                SELECT fase_numero, ciclo
                FROM tableros_progreso
                WHERE usuario_id = ?
                  AND tablero_tipo = 'C'
                  AND estado = 'completado'
                ORDER BY fase_numero DESC, ciclo DESC, id DESC
                LIMIT 1
            ");
            $stmt_check->execute([$user_id]);
            $completedBoard = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($completedBoard) {
                $fase_actual = (int)$completedBoard['fase_numero'];
                $ciclo_actual = (int)$completedBoard['ciclo'];
                $nivel_actual = 'FASE_COMPLETADA';
                $dashboard_phase_cfg = getPhaseConfig($pdo, $fase_actual);
            }
        }

        // 3. Contador de Clones Activos (Agentes IA) del ciclo actual
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM referidos r
            JOIN usuarios u ON r.id_hijo = u.id
            WHERE r.id_padre = ?
              AND r.fase_numero = ?
              AND r.ciclo = ?
              AND u.tipo_usuario = 'clon'
        ");
        $stmt->execute([$user_id, $fase_actual, $ciclo_actual]);
        $clones_count = (int)($stmt->fetch()['total'] ?? 0);

        // 4. Referidos directos (Humanos) del ciclo actual con estado de pago y su tablero actual
        $referidoDisplayNameSelect = userDataDisplayNameExpr($pdo, 'u') . " AS display_name";

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.nickname,
                {$referidoDisplayNameSelect},
                u.wallet_address AS wallet,
                u.tipo_usuario AS tipo,
                r.posicion,
                r.ciclo,
                (SELECT estado FROM pagos WHERE id_emisor = u.id AND fase_numero = ? AND tipo = 'regalo' ORDER BY id DESC LIMIT 1) AS pago_estado,
                (SELECT tablero_tipo FROM tableros_progreso WHERE usuario_id = u.id AND fase_numero = ? AND estado = 'en_progreso' ORDER BY ciclo DESC, id DESC LIMIT 1) AS nivel_actual
            FROM referidos r
            JOIN usuarios u ON r.id_hijo = u.id
            WHERE r.id_padre = ?
              AND r.fase_numero = ?
              AND r.ciclo = ?
              AND u.tipo_usuario = 'real'
            ORDER BY r.posicion ASC
        ");
        $stmt->execute([$fase_actual, $fase_actual, $user_id, $fase_actual, $ciclo_actual]);
        $referidos_reales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN u.tipo_usuario = 'real' THEN 1 ELSE 0 END) AS reales,
                SUM(CASE WHEN u.tipo_usuario = 'clon' THEN 1 ELSE 0 END) AS clones
            FROM referidos r
            JOIN usuarios u ON r.id_hijo = u.id
            WHERE r.id_padre = ?
              AND r.fase_numero = ?
              AND r.ciclo = ?
        ");
        $stmt->execute([$user_id, $fase_actual, $ciclo_actual]);
        $equipo_ciclo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['reales' => 0, 'clones' => 0];

        // 4b. Resumen visual por fase para mostrar todas las fases en paralelo
        $phaseOverview = [];

        $stmtPhaseActive = $pdo->prepare("
            SELECT id, fase_numero, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
            FROM tableros_progreso
            WHERE usuario_id = ?
              AND fase_numero = ?
              AND estado = 'en_progreso'
            ORDER BY ciclo DESC,
                     CASE tablero_tipo
                       WHEN 'C' THEN 3
                       WHEN 'B' THEN 2
                       ELSE 1
                     END DESC,
                     id DESC
            LIMIT 1
        ");

        $stmtPhaseLatest = $pdo->prepare("
            SELECT id, fase_numero, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
            FROM tableros_progreso
            WHERE usuario_id = ?
              AND fase_numero = ?
            ORDER BY ciclo DESC,
                     CASE tablero_tipo
                       WHEN 'C' THEN 3
                       WHEN 'B' THEN 2
                       ELSE 1
                     END DESC,
                     CASE estado
                       WHEN 'en_progreso' THEN 1
                       ELSE 0
                     END DESC,
                     id DESC
            LIMIT 1
        ");

        $stmtPhaseTeam = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN u.tipo_usuario = 'real' THEN 1 ELSE 0 END), 0) AS reales,
                COALESCE(SUM(CASE WHEN u.tipo_usuario = 'clon' THEN 1 ELSE 0 END), 0) AS clones,
                COUNT(*) AS total
            FROM referidos r
            JOIN usuarios u ON u.id = r.id_hijo
            WHERE r.id_padre = ?
              AND r.fase_numero = ?
              AND r.ciclo = ?
        ");

        $stmtPhaseCompletedCycles = $pdo->prepare("
            SELECT COUNT(DISTINCT ciclo) AS total
            FROM tableros_progreso
            WHERE usuario_id = ?
              AND fase_numero = ?
              AND tablero_tipo = 'C'
              AND estado = 'completado'
        ");

        for ($phaseNumber = 0; $phaseNumber <= 3; $phaseNumber++) {
            $phaseCfg = getPhaseConfig($pdo, $phaseNumber);
            $phaseBoardA = getPhaseBoardConfig($pdo, $phaseNumber, 'A');
            $phaseBoardC = getPhaseBoardConfig($pdo, $phaseNumber, 'C');

            $stmtPhaseActive->execute([$user_id, $phaseNumber]);
            $phaseActiveBoard = $stmtPhaseActive->fetch(PDO::FETCH_ASSOC) ?: null;

            $stmtPhaseLatest->execute([$user_id, $phaseNumber]);
            $phaseLatestBoard = $stmtPhaseLatest->fetch(PDO::FETCH_ASSOC) ?: null;

            $phaseContextBoard = $phaseActiveBoard ?: $phaseLatestBoard;
            $phaseCycle = $phaseContextBoard ? (int)$phaseContextBoard['ciclo'] : 1;
            $phaseBoardType = $phaseContextBoard['tablero_tipo'] ?? null;
            $phaseBoardState = $phaseContextBoard['estado'] ?? null;

            $stmtPhaseTeam->execute([$user_id, $phaseNumber, $phaseCycle]);
            $phaseTeam = $stmtPhaseTeam->fetch(PDO::FETCH_ASSOC) ?: ['reales' => 0, 'clones' => 0, 'total' => 0];

            $stmtPhaseCompletedCycles->execute([$user_id, $phaseNumber]);
            $phaseCompletedCycles = (int)($stmtPhaseCompletedCycles->fetchColumn() ?: 0);

            $phaseStatus = 'sin_iniciar';
            if ($phaseActiveBoard) {
                $phaseStatus = 'en_progreso';
            } elseif ($phaseLatestBoard && $phaseLatestBoard['tablero_tipo'] === 'C' && $phaseLatestBoard['estado'] === 'completado') {
                $phaseStatus = 'completada';
            } elseif ($phaseLatestBoard) {
                $phaseStatus = 'historial';
            }

            $phaseProgressPercent = 0;
            if ($phaseContextBoard) {
                if ($phaseBoardType === 'A') {
                    $phaseProgressPercent = 34;
                } elseif ($phaseBoardType === 'B') {
                    $phaseProgressPercent = 67;
                } elseif ($phaseBoardType === 'C') {
                    $phaseProgressPercent = $phaseBoardState === 'completado' ? 100 : 84;
                }
            }

            $phaseTeamTotal = (int)($phaseTeam['total'] ?? 0);
            $phaseSlotsPercent = (int)round(min(100, ($phaseTeamTotal / $max_referidos) * 100));

            $phaseOverview[] = [
                'fase_numero' => $phaseNumber,
                'fase_nombre' => $phaseCfg['nombre'] ?: ('Fase ' . $phaseNumber),
                'descripcion' => $phaseCfg['descripcion'],
                'activa_config' => (int)($phaseCfg['activa'] ?? 0),
                'is_primary' => $phaseNumber === $fase_actual,
                'has_activity' => $phaseContextBoard !== null,
                'estado_usuario' => $phaseStatus,
                'current_board' => $phaseContextBoard ? [
                    'id' => (int)$phaseContextBoard['id'],
                    'tablero_tipo' => $phaseBoardType,
                    'ciclo' => $phaseCycle,
                    'estado' => $phaseBoardState,
                    'fecha_inicio' => $phaseContextBoard['fecha_inicio'],
                    'fecha_fin' => $phaseContextBoard['fecha_fin'],
                ] : null,
                'board_progress_percent' => $phaseProgressPercent,
                'team_progress_percent' => $phaseSlotsPercent,
                'team_reales' => (int)($phaseTeam['reales'] ?? 0),
                'team_clones' => (int)($phaseTeam['clones'] ?? 0),
                'team_total' => $phaseTeamTotal,
                'team_required' => $max_referidos,
                'completed_cycles' => $phaseCompletedCycles,
                'entry_amount' => round((float)($phaseBoardA['monto_entrada'] ?? 0), 2),
                'next_seed_amount' => round((float)($phaseBoardC['semilla_siguiente_fase'] ?? 0), 2),
                'reentry_amount' => round((float)($phaseBoardC['monto_reentrada'] ?? 0), 2),
            ];
        }

        // 5. Cálculo de Ganancias
        // 5a. Ganancia bruta acumulada (todos los tableros completados)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM pagos WHERE id_receptor = ? AND propietario_flujo = 'usuario' AND estado = 'completado' AND tipo = 'ganancia_tablero'");
        $stmt->execute([$user_id]);
        $total_ganado_bruto = (float)$stmt->fetch()['total'];

        // 5b. Deducciones automáticas del sistema al completar ciclo
        //   - salto_fase_1: $100 que van al pool de Fase 1 (retención del sistema)
        //   - reentrada:    $10 que permiten volver a participar en Fase 1 (retención del sistema)
        //   Ambas se deducen automáticamente en matrix_logic.php al completar Tablero C.
        //   NO son pagos manuales del usuario — son retenciones de sus ganancias brutas.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(monto), 0) as total
            FROM pagos
            WHERE id_emisor = ?
              AND propietario_flujo = 'usuario'
              AND estado = 'completado'
              AND (tipo LIKE 'salto_fase_%' OR tipo = 'reentrada')
        ");
        $stmt->execute([$user_id]);
        $total_deducciones = (float)$stmt->fetch()['total'];

        // 5c. Reserva Fase 1 personal: cuánto ha aportado este usuario al pool de Fase 1
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM pagos WHERE id_emisor = ? AND propietario_flujo = 'usuario' AND estado = 'completado' AND tipo = 'salto_fase_1'");
        $stmt->execute([$user_id]);
        $reserva_fase1 = (float)$stmt->fetch()['total'];

        // 5d. Retiros ya procesados (aprobados y pagados por el admin)
        //     Se descuentan para evitar que el usuario pueda retirar el mismo saldo dos veces.
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM retiros WHERE usuario_id = ? AND estado = 'procesado'");
        $stmt->execute([$user_id]);
        $total_ya_retirado = (float)($stmt->fetch()['total'] ?? 0);

        // 5e. Crédito por excedente de pago (cuando el usuario pagó más de $10 al entrar)
        //     Se acumula en usuarios.credito_saldo y se suma al saldo final.
        $stmt = $pdo->prepare("SELECT COALESCE(credito_saldo, 0) as credito FROM usuarios WHERE id = ?");
        $stmt->execute([$user_id]);
        $credito_saldo = (float)($stmt->fetch()['credito'] ?? 0);

        // Saldo neto disponible para retiro (bruto - deducciones + crédito excedente - retiros ya cobrados)
        $earnings_net = $total_ganado_bruto - $total_deducciones + $credito_saldo - $total_ya_retirado;

        // 5f. Verificar si el usuario completó la Fase 0 (Tablero C completado)
        //     Solo cuando fase0_completada=true se habilita el botón RETIRAR en el frontend.
        $stmt = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = 0 AND tablero_tipo = 'C' AND estado = 'completado' LIMIT 1");
        $stmt->execute([$user_id]);
        $fase0_completada = $user['tipo_usuario'] === 'real' ? (bool)$stmt->fetch() : false;

        // 5e. Historial de movimientos (ganancias + retenciones) para mostrar en el dashboard
        $stmt = $pdo->prepare("
            SELECT tipo, monto, fecha_pago AS fecha, estado, tablero_tipo,
                   CONCAT('Ganancia Tablero ', tablero_tipo) AS tipo_label,
                   'ingreso' AS direccion
            FROM pagos
            WHERE id_receptor = ? AND propietario_flujo = 'usuario' AND tipo = 'ganancia_tablero' AND estado = 'completado'
            UNION ALL
            SELECT tipo, monto, fecha_pago AS fecha, estado, tablero_tipo,
                   CASE tipo
                     WHEN 'salto_fase_1' THEN 'Reserva automatica Fase 1'
                     WHEN 'reentrada'    THEN 'Reentrada ciclo siguiente'
                     ELSE tipo
                   END AS tipo_label,
                   'deduccion' AS direccion
            FROM pagos
            WHERE id_emisor = ?
              AND propietario_flujo = 'usuario'
              AND (tipo LIKE 'salto_fase_%' OR tipo = 'reentrada')
              AND estado = 'completado'
            ORDER BY fecha DESC
            LIMIT 20
        ");
        $stmt->execute([$user_id, $user_id]);
        $historial_ganancias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5f-2. Saldo disponible por fase (para botones RETIRAR por fase)
        // Verificar si retiros.fase_numero ya existe (puede no haber corrido el ALTER TABLE aún)
        $retirosTienenFase = false;
        try {
            $chkCol = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='retiros' AND COLUMN_NAME='fase_numero'");
            $retirosTienenFase = (int)$chkCol->fetchColumn() > 0;
        } catch (\Exception $e) { $retirosTienenFase = false; }

        $earnings_por_fase = [];
        $stmtFaseBruto = $pdo->prepare("
            SELECT COALESCE(SUM(monto),0) as t
            FROM pagos
            WHERE id_receptor=? AND propietario_flujo='usuario' AND estado='completado'
              AND tipo='ganancia_tablero' AND fase_numero=?
        ");
        $stmtFaseDeduc = $pdo->prepare("
            SELECT COALESCE(SUM(monto),0) as t
            FROM pagos
            WHERE id_emisor=? AND propietario_flujo='usuario' AND estado='completado'
              AND (tipo LIKE 'salto_fase_%' OR tipo='reentrada') AND fase_numero=?
        ");
        $stmtFaseCompletadaC = $pdo->prepare("
            SELECT COUNT(*) FROM tableros_progreso
            WHERE usuario_id=? AND fase_numero=? AND tablero_tipo='C' AND estado='completado'
        ");

        // Sólo preparar queries con fase_numero si la columna existe en retiros
        $stmtFaseRetiro   = $retirosTienenFase ? $pdo->prepare("SELECT COALESCE(SUM(monto),0) as t FROM retiros WHERE usuario_id=? AND fase_numero=? AND estado='procesado'") : null;
        $stmtFasePendiente= $retirosTienenFase ? $pdo->prepare("SELECT COUNT(*) FROM retiros WHERE usuario_id=? AND fase_numero=? AND estado='pendiente'") : null;

        for ($fn = 0; $fn <= 3; $fn++) {
            $stmtFaseBruto->execute([$user_id, $fn]);
            $fn_bruto = (float)$stmtFaseBruto->fetch()['t'];

            $stmtFaseDeduc->execute([$user_id, $fn]);
            $fn_deduc = (float)$stmtFaseDeduc->fetch()['t'];

            $fn_credito = 0.0;
            if ($fn === 0) {
                $fn_credito = $credito_saldo;
            }

            $fn_retirado = 0.0;
            if ($stmtFaseRetiro) {
                $stmtFaseRetiro->execute([$user_id, $fn]);
                $fn_retirado = (float)$stmtFaseRetiro->fetch()['t'];
            }

            $fn_saldo = $fn_bruto - $fn_deduc + $fn_credito - $fn_retirado;

            $stmtFaseCompletadaC->execute([$user_id, $fn]);
            $fn_tablero_c_ok = (int)$stmtFaseCompletadaC->fetchColumn() > 0;

            $fn_tiene_pendiente = false;
            if ($stmtFasePendiente) {
                $stmtFasePendiente->execute([$user_id, $fn]);
                $fn_tiene_pendiente = (int)$stmtFasePendiente->fetchColumn() > 0;
            }

            $earnings_por_fase[$fn] = [
                'fase_numero'      => $fn,
                'saldo_disponible' => round($fn_saldo, 2),
                'tablero_c_ok'     => $fn_tablero_c_ok,
                'tiene_pendiente'  => $fn_tiene_pendiente,
                'puede_retirar'    => $user['tipo_usuario'] === 'real' && $fn_tablero_c_ok && !$fn_tiene_pendiente && $fn_saldo >= 10,
            ];
        }

        // 5g. Reservas internas y reentrada del usuario para transparencia del dashboard
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN hacia_destino = 'B' THEN monto ELSE 0 END), 0) AS reserva_b,
                COALESCE(SUM(CASE WHEN hacia_destino = 'C' THEN monto ELSE 0 END), 0) AS reserva_c,
                COALESCE(SUM(CASE WHEN hacia_destino = 'FASE1' THEN monto ELSE 0 END), 0) AS reserva_fase1,
                COALESCE(SUM(CASE WHEN hacia_destino = 'FASE2' THEN monto ELSE 0 END), 0) AS reserva_fase2,
                COALESCE(SUM(CASE WHEN hacia_destino = 'FASE3' THEN monto ELSE 0 END), 0) AS reserva_fase3,
                COALESCE(SUM(CASE WHEN hacia_destino = 'REENTRADA_A' THEN monto ELSE 0 END), 0) AS reserva_reentrada
            FROM reservas_tablero
            WHERE usuario_id = ?
              AND propietario_flujo = 'usuario'
        ");
        $stmt->execute([$user_id]);
        $reservas_usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare("
            SELECT desde_tablero, hacia_destino, ciclo_origen, ciclo_destino, monto, estado, detalle, fecha_creacion, fecha_uso
            FROM reservas_tablero
            WHERE usuario_id = ?
              AND propietario_flujo = 'usuario'
            ORDER BY id DESC
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        $reservas_historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 6. Pago pendiente + wallet del patrón a quien debe enviar el USDT
        $stmt = $pdo->prepare("
            SELECT p.id, p.monto,
                   COALESCE(p.wallet_destino_real, patron.wallet_address, ?) AS wallet_patron
            FROM pagos p
            LEFT JOIN usuarios patron ON p.id_receptor = patron.id
            WHERE p.id_emisor = ? AND p.estado = 'pendiente' AND p.tipo = 'regalo'
            ORDER BY p.id ASC LIMIT 1
        ");
        $stmt->execute([RADIX_CENTRAL_WALLET, $user_id]);
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

            // Total distribuido a la red (MASTER no tiene ganancias personales — es la billetera central del sistema)
            // Los pagos tipo 'regalo' a id=1 son entradas de tesorería, no utilidad personal del master.
            $stmt = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE tipo = 'ganancia_tablero' AND propietario_flujo = 'usuario' AND estado = 'completado'");
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
                'display_name'   => $user['display_name'] ?: $user['nickname'],
                'wallet'         => $user['wallet_address'],
                'tipo_usuario'   => $user['tipo_usuario'],
                'fase_numero'    => (int)$fase_actual,
                'fase_nombre'    => $dashboard_phase_cfg['nombre'] ?: ('Fase ' . $fase_actual),
                'nivel'          => $nivel_actual,
                'ciclo'          => (int)$ciclo_actual,
                'clones_count'   => $clones_count,
                'has_telegram'   => !empty($user['telegram_chat_id']),
                'pago_pendiente' => $pago_pendiente !== null,
            ],
            'tablero'        => $tablero ? [
                'id'           => (int)$tablero['id'],
                'fase_numero'  => (int)$tablero['fase_numero'],
                'fase_nombre'  => getPhaseConfig($pdo, (int)$tablero['fase_numero'])['nombre'] ?: ('Fase ' . (int)$tablero['fase_numero']),
                'tipo'         => $tablero['tablero_tipo'],
                'ciclo'        => (int)$tablero['ciclo'],
                'fecha_inicio' => $tablero['fecha_inicio'],
            ] : null,
            'dashboard_context' => [
                'fase_numero'  => (int)$fase_actual,
                'fase_nombre'  => $dashboard_phase_cfg['nombre'] ?: ('Fase ' . $fase_actual),
                'nivel'        => $nivel_actual,
                'tablero_tipo' => $tablero['tablero_tipo'] ?? null,
                'ciclo'        => (int)$ciclo_actual,
                'eyebrow'      => 'RADIX PHASE ' . $fase_actual,
            ],
            'parallel_boards' => $parallelBoards,
            'secondary_board' => $parallelBoards[1] ?? null,
            // Saldo neto disponible para retirar
            'earnings'       => round($earnings_net, 2),
            // Desglose para transparencia
            'earnings_bruto'       => round($total_ganado_bruto, 2),
            'earnings_deducciones' => round($total_deducciones, 2),
            'credito_saldo'        => round($credito_saldo, 2),
            'fase0_completada'     => $fase0_completada,
            // Saldo disponible para retiro por cada fase (para botones RETIRAR por fase)
            'earnings_por_fase'    => array_values($earnings_por_fase),
            // Aporte personal al pool de Fase 1 (para widget val-reserva)
            'reserva_fase1'  => round($reserva_fase1, 2),
            'reservas'       => [
                'a_b'         => round((float)($reservas_usuario['reserva_b'] ?? 0), 2),
                'b_c'         => round((float)($reservas_usuario['reserva_c'] ?? 0), 2),
                'fase1'       => round((float)($reservas_usuario['reserva_fase1'] ?? 0), 2),
                'fase2'       => round((float)($reservas_usuario['reserva_fase2'] ?? 0), 2),
                'fase3'       => round((float)($reservas_usuario['reserva_fase3'] ?? 0), 2),
                'reentrada_a' => round((float)($reservas_usuario['reserva_reentrada'] ?? 0), 2),
                'historial'   => $reservas_historial,
            ],
            'equipo_ciclo'   => [
                'ciclo'  => (int)$ciclo_actual,
                'reales' => (int)($equipo_ciclo['reales'] ?? 0),
                'clones' => (int)($equipo_ciclo['clones'] ?? 0),
            ],
            'phase_overview' => $phaseOverview,
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
