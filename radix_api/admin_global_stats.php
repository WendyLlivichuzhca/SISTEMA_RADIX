<?php
require_once 'config.php';
require_once 'admin_auth.php';

function userColumnExists(PDO $pdo, string $column): bool
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

function readNullableIntFilter(string $key): ?int
{
    $value = $_GET[$key] ?? 'all';
    if ($value === '' || $value === null || $value === 'all') {
        return null;
    }

    return ctype_digit((string)$value) ? (int)$value : null;
}

function readNullableBoardFilter(string $key): ?string
{
    $value = strtoupper(trim((string)($_GET[$key] ?? 'all')));
    return in_array($value, ['A', 'B', 'C'], true) ? $value : null;
}

function readNullableUserTypeFilter(string $key): ?string
{
    $value = strtolower(trim((string)($_GET[$key] ?? 'all')));
    return in_array($value, ['real', 'clon', 'master', 'sistema'], true) ? $value : null;
}

function addPhaseBoardCycleFilters(
    array &$conditions,
    array &$params,
    string $phaseField,
    string $boardField,
    string $cycleField,
    ?int $phaseFilter,
    ?string $boardFilter,
    ?int $cycleFilter
): void {
    if ($phaseFilter !== null) {
        $conditions[] = "{$phaseField} = ?";
        $params[] = $phaseFilter;
    }

    if ($boardFilter !== null) {
        $conditions[] = "{$boardField} = ?";
        $params[] = $boardFilter;
    }

    if ($cycleFilter !== null) {
        $conditions[] = "{$cycleField} = ?";
        $params[] = $cycleFilter;
    }
}

function addUserTypeFilter(array &$conditions, array &$params, string $userTypeField, ?string $userTypeFilter): void
{
    if ($userTypeFilter !== null) {
        $conditions[] = "{$userTypeField} = ?";
        $params[] = $userTypeFilter;
    }
}

function fetchPhaseOptions(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT fase_numero, nombre
        FROM fases_config
        ORDER BY fase_numero ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchCycleOptions(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT DISTINCT ciclo
        FROM (
            SELECT ciclo FROM tableros_progreso
            UNION
            SELECT ciclo FROM pagos
        ) ciclos
        WHERE ciclo IS NOT NULL
        ORDER BY ciclo ASC
    ");

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

requireAdminSession();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $phaseFilter = readNullableIntFilter('fase_numero');
        $boardFilter = readNullableBoardFilter('tablero_tipo');
        $cycleFilter = readNullableIntFilter('ciclo');
        $userTypeFilter = readNullableUserTypeFilter('tipo_usuario');

        $phaseOptions = fetchPhaseOptions($pdo);
        $cycleOptions = fetchCycleOptions($pdo);
        $logicalBeneficiaryExpr = "COALESCE(p.beneficiario_usuario_id, p.id_receptor)";
        $beneficiaryDisplayExpr = userColumnExists($pdo, 'nombre_completo')
            ? "COALESCE(NULLIF(bu.nombre_completo, ''), NULLIF(bu.nickname, ''), CONCAT('Usuario ', {$logicalBeneficiaryExpr}))"
            : "COALESCE(NULLIF(bu.nickname, ''), CONCAT('Usuario ', {$logicalBeneficiaryExpr}))";

        $stmt = $pdo->prepare("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
        $stmt->execute();
        $tesoreria = (float)($stmt->fetch()['valor_decimal'] ?? 0);

        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'real'");
        $totalReales = (int)($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'clon'");
        $totalClones = (int)($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE tipo = 'salto_fase_1' AND estado = 'completado'");
        $fase1Pool = (float)($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE tipo = 'reentrada' AND estado = 'completado'");
        $reentradaPool = (float)($stmt->fetchColumn() ?: 0);

        $reservasAplicadas = 0.0;
        $reservasPendientes = 0.0;
        $logsReservas = [];
        try {
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(monto), 0)
                FROM reservas_tablero
                WHERE estado = 'usado'
            ");
            $reservasAplicadas = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->query("
                SELECT COALESCE(SUM(monto), 0)
                FROM reservas_tablero
                WHERE estado = 'reservado'
            ");
            $reservasPendientes = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->query("
                SELECT
                    rt.usuario_id,
                    rt.fase_numero,
                    rt.desde_tablero,
                    rt.hacia_destino,
                    rt.ciclo_origen,
                    rt.ciclo_destino,
                    rt.monto,
                    rt.estado,
                    rt.detalle,
                    rt.fecha_creacion,
                    rt.fecha_uso,
                    u.nickname
                FROM reservas_tablero rt
                LEFT JOIN usuarios u ON rt.usuario_id = u.id
                ORDER BY rt.id DESC
                LIMIT 20
            ");
            $logsReservas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
        }

        $stmt = $pdo->query("
            SELECT al.id, al.detalles, al.fecha, u.nickname, u.wallet_address
            FROM auditoria_logs al
            LEFT JOIN usuarios u ON al.usuario_id = u.id
            WHERE al.accion = 'ACTIVACION_CLON'
            ORDER BY al.id DESC
            LIMIT 10
        ");
        $logsClonesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $logsClones = array_map(function ($row) {
            preg_match('/\\$(\\d+(?:\\.\\d+)?)/', $row['detalles'] ?? '', $match);
            $row['monto'] = isset($match[1]) ? (float)$match[1] : null;
            return $row;
        }, $logsClonesRaw);

        $stmt = $pdo->query("
            SELECT COALESCE(SUM(monto), 0) AS total
            FROM pagos
            WHERE tipo = 'ganancia_tablero'
              AND propietario_flujo = 'usuario'
              AND estado = 'completado'
        ");
        $masterEarnings = (float)($stmt->fetch()['total'] ?? 0);

        $stmt = $pdo->query("
            SELECT COALESCE(SUM(monto_pagado), 0) AS total
            FROM pagos
            WHERE tipo = 'regalo'
              AND estado = 'completado'
              AND origen_fondos = 'externo'
        ");
        $totalBlockchain = (float)($stmt->fetch()['total'] ?? 0);

        $stmt = $pdo->query("
            SELECT COALESCE(SUM(credito_saldo), 0) AS total
            FROM usuarios
            WHERE tipo_usuario = 'real'
        ");
        $creditosExcedente = (float)($stmt->fetch()['total'] ?? 0);

        $pendienteDistribuir = max(
            0,
            $totalBlockchain
            - $masterEarnings
            - $tesoreria
            - $reservasAplicadas
            - $fase1Pool
            - $reentradaPool
            - $creditosExcedente
        );

        $progressConditions = ["tp.estado = 'en_progreso'"];
        $progressParams = [];
        addPhaseBoardCycleFilters(
            $progressConditions,
            $progressParams,
            'tp.fase_numero',
            'tp.tablero_tipo',
            'tp.ciclo',
            $phaseFilter,
            $boardFilter,
            $cycleFilter
        );
        addUserTypeFilter($progressConditions, $progressParams, 'u.tipo_usuario', $userTypeFilter);

        $stmt = $pdo->prepare("
            SELECT tp.tablero_tipo, COUNT(*) AS cantidad
            FROM tableros_progreso tp
            INNER JOIN usuarios u ON u.id = tp.usuario_id
            WHERE " . implode(' AND ', $progressConditions) . "
            GROUP BY tp.tablero_tipo
        ");
        $stmt->execute($progressParams);
        $distributionRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $distribucionTableros = [
            'A' => (int)($distributionRaw['A'] ?? 0),
            'B' => (int)($distributionRaw['B'] ?? 0),
            'C' => (int)($distributionRaw['C'] ?? 0),
        ];

        $stmt = $pdo->prepare("
            SELECT u.tipo_usuario, COUNT(DISTINCT tp.usuario_id) AS cantidad
            FROM tableros_progreso tp
            INNER JOIN usuarios u ON u.id = tp.usuario_id
            WHERE " . implode(' AND ', $progressConditions) . "
            GROUP BY u.tipo_usuario
        ");
        $stmt->execute($progressParams);
        $ratioRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $ratioRealesClones = [
            'reales' => (int)($ratioRaw['real'] ?? 0),
            'clones' => (int)($ratioRaw['clon'] ?? 0),
        ];

        $gainConditions = [
            "p.tipo = 'ganancia_tablero'",
            "p.estado = 'completado'",
        ];
        $gainParams = [];
        addPhaseBoardCycleFilters(
            $gainConditions,
            $gainParams,
            'p.fase_numero',
            'p.tablero_tipo',
            'p.ciclo',
            $phaseFilter,
            $boardFilter,
            $cycleFilter
        );
        addUserTypeFilter($gainConditions, $gainParams, 'bu.tipo_usuario', $userTypeFilter);

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(p.monto), 0) AS total_distribuido,
                COUNT(*) AS pagos_distribuidos,
                COUNT(DISTINCT {$logicalBeneficiaryExpr}) AS beneficiarios,
                COALESCE(SUM(CASE WHEN ABS(p.monto - 10.00) < 0.0001 THEN 1 ELSE 0 END), 0) AS pagos_de_diez,
                COUNT(DISTINCT CASE WHEN ABS(p.monto - 10.00) < 0.0001 THEN {$logicalBeneficiaryExpr} END) AS beneficiarios_con_diez
            FROM pagos p
            LEFT JOIN usuarios bu ON bu.id = {$logicalBeneficiaryExpr}
            WHERE " . implode(' AND ', $gainConditions)
        );
        $stmt->execute($gainParams);
        $distributionSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare("
            SELECT
                p.fase_numero,
                p.tablero_tipo,
                p.ciclo,
                COUNT(*) AS pagos_distribuidos,
                COUNT(DISTINCT {$logicalBeneficiaryExpr}) AS beneficiarios,
                COALESCE(SUM(p.monto), 0) AS total_distribuido,
                COALESCE(SUM(CASE WHEN ABS(p.monto - 10.00) < 0.0001 THEN 1 ELSE 0 END), 0) AS pagos_de_diez,
                COUNT(DISTINCT CASE WHEN ABS(p.monto - 10.00) < 0.0001 THEN {$logicalBeneficiaryExpr} END) AS beneficiarios_con_diez
            FROM pagos p
            LEFT JOIN usuarios bu ON bu.id = {$logicalBeneficiaryExpr}
            WHERE " . implode(' AND ', $gainConditions) . "
            GROUP BY p.fase_numero, p.tablero_tipo, p.ciclo
            ORDER BY p.fase_numero ASC, FIELD(p.tablero_tipo, 'A', 'B', 'C'), p.ciclo ASC
        ");
        $stmt->execute($gainParams);
        $distributionDetail = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                {$logicalBeneficiaryExpr} AS beneficiario_id,
                {$beneficiaryDisplayExpr} AS display_name,
                COALESCE(bu.tipo_usuario, 'desconocido') AS tipo_usuario,
                COUNT(*) AS pagos_de_diez,
                COALESCE(SUM(p.monto), 0) AS total_de_diez,
                MAX(p.fecha_pago) AS ultima_fecha
            FROM pagos p
            LEFT JOIN usuarios bu ON bu.id = {$logicalBeneficiaryExpr}
            WHERE " . implode(' AND ', $gainConditions) . "
              AND ABS(p.monto - 10.00) < 0.0001
            GROUP BY
                {$logicalBeneficiaryExpr},
                {$beneficiaryDisplayExpr},
                COALESCE(bu.tipo_usuario, 'desconocido')
            ORDER BY MAX(p.id) DESC
            LIMIT 12
        ");
        $stmt->execute($gainParams);
        $usersWithTen = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.fase_numero,
                p.tablero_tipo,
                p.ciclo,
                p.monto,
                p.fecha_pago,
                {$logicalBeneficiaryExpr} AS beneficiario_id,
                {$beneficiaryDisplayExpr} AS display_name,
                COALESCE(bu.tipo_usuario, 'desconocido') AS tipo_usuario
            FROM pagos p
            LEFT JOIN usuarios bu ON bu.id = {$logicalBeneficiaryExpr}
            WHERE " . implode(' AND ', $gainConditions) . "
            ORDER BY p.id DESC
            LIMIT 12
        ");
        $stmt->execute($gainParams);
        $latestGains = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("
            SELECT DATE(fecha_registro) AS dia, COUNT(*) AS nuevos
            FROM usuarios
            WHERE tipo_usuario = 'real'
              AND fecha_registro >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(fecha_registro)
            ORDER BY dia ASC
        ");
        $crecimientoDiario = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("
            SELECT al.accion, al.detalles, al.fecha, al.fase_numero, u.nickname
            FROM auditoria_logs al
            LEFT JOIN usuarios u ON al.usuario_id = u.id
            ORDER BY al.id DESC
            LIMIT 20
        ");
        $logsActividad = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $retirosPendientes = [];
        try {
            $stmt = $pdo->query("
                SELECT r.id, r.monto, r.wallet_destino, r.fecha_solicitud, u.nickname
                FROM retiros r
                JOIN usuarios u ON r.usuario_id = u.id
                WHERE r.estado = 'pendiente'
                ORDER BY r.fecha_solicitud ASC
                LIMIT 20
            ");
            $retirosPendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
        }

        $selectNombre = userColumnExists($pdo, 'nombre_completo')
            ? 'u.nombre_completo'
            : "'' AS nombre_completo";
        $selectTelefono = userColumnExists($pdo, 'telefono')
            ? 'u.telefono'
            : "'' AS telefono";
        $selectCorreo = userColumnExists($pdo, 'correo_electronico')
            ? 'u.correo_electronico'
            : "'' AS correo_electronico";

        $stmt = $pdo->query("
            SELECT
                u.id,
                u.nickname,
                u.wallet_address,
                u.tipo_usuario,
                u.fecha_registro,
                {$selectNombre},
                {$selectTelefono},
                {$selectCorreo},
                (
                    SELECT p.estado
                    FROM pagos p
                    WHERE p.id_emisor = u.id
                      AND p.tipo = 'regalo'
                    ORDER BY p.id DESC
                    LIMIT 1
                ) AS pago_estado
            FROM usuarios u
            WHERE u.tipo_usuario IN ('real', 'master')
            ORDER BY u.id ASC
        ");
        $listaUsuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("
            SELECT tipo, monto, motivo, fecha
            FROM tesoreria_movimientos
            ORDER BY id DESC
            LIMIT 30
        ");
        $tesoreriaMovimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse([
            'success' => true,
            'tesoreria' => $tesoreria,
            'fase1_pool' => $fase1Pool,
            'reentrada_pool' => $reentradaPool,
            'reservas_aplicadas' => $reservasAplicadas,
            'reservas_pendientes' => $reservasPendientes,
            'master_id1_earnings' => $masterEarnings,
            'total_blockchain' => $totalBlockchain,
            'creditos_excedente' => $creditosExcedente,
            'pendiente_distribuir' => $pendienteDistribuir,
            'usuarios' => [
                'reales' => $totalReales,
                'clones' => $totalClones,
                'total' => $totalReales + $totalClones,
            ],
            'distribucion_tableros' => $distribucionTableros,
            'ratio_reales_clones' => $ratioRealesClones,
            'resumen_distribucion' => [
                'total_distribuido' => (float)($distributionSummary['total_distribuido'] ?? 0),
                'pagos_distribuidos' => (int)($distributionSummary['pagos_distribuidos'] ?? 0),
                'beneficiarios' => (int)($distributionSummary['beneficiarios'] ?? 0),
                'pagos_de_diez' => (int)($distributionSummary['pagos_de_diez'] ?? 0),
                'beneficiarios_con_diez' => (int)($distributionSummary['beneficiarios_con_diez'] ?? 0),
            ],
            'distribucion_detalle' => $distributionDetail,
            'usuarios_con_diez' => $usersWithTen,
            'ultimas_ganancias' => $latestGains,
            'filtros' => [
                'fase_numero' => $phaseFilter ?? 'all',
                'tablero_tipo' => $boardFilter ?? 'all',
                'ciclo' => $cycleFilter ?? 'all',
                'tipo_usuario' => $userTypeFilter ?? 'all',
                'fases' => $phaseOptions,
                'tableros' => ['A', 'B', 'C'],
                'ciclos' => $cycleOptions,
                'tipos_usuario' => [
                    ['value' => 'all', 'label' => 'Todos'],
                    ['value' => 'real', 'label' => 'Reales'],
                    ['value' => 'clon', 'label' => 'Clones'],
                    ['value' => 'master', 'label' => 'Master'],
                    ['value' => 'sistema', 'label' => 'Sistema'],
                ],
            ],
            'crecimiento_diario' => $crecimientoDiario,
            'logs' => $logsClones,
            'logs_reservas' => $logsReservas,
            'logs_actividad' => $logsActividad,
            'lista_usuarios' => $listaUsuarios,
            'retiros_pendientes' => $retirosPendientes,
            'tesoreria_movimientos' => $tesoreriaMovimientos,
        ]);
    } catch (PDOException $e) {
        error_log('RADIX admin_global_stats ERROR: ' . $e->getMessage());
        sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
    }
} else {
    sendResponse(['error' => 'Metodo no permitido'], 405);
}
?>
