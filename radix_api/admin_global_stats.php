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
    return in_array($value, ['real', 'clon', 'master', 'sistema', 'inactivo'], true) ? $value : null;
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
        $resTeso = $stmt->fetch();
        $tesoreria = (float)($resTeso['valor_decimal'] ?? 0);

        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'real'");
        $totalReales = (int)($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'clon'");
        $totalClones = (int)($stmt->fetchColumn() ?: 0);

        $phasePools = [
            'salto_fase_1' => 0.0,
            'salto_fase_2' => 0.0,
            'salto_fase_3' => 0.0,
        ];
        $utilidadMasterTotal = 0.0;
        $stmt = $pdo->query("
            SELECT tipo, COALESCE(SUM(monto), 0) AS total
            FROM pagos
            WHERE tipo IN ('salto_fase_1','salto_fase_2','salto_fase_3','utilidad_master')
              AND estado = 'completado'
            GROUP BY tipo
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $poolRow) {
            $tipoPool = (string)($poolRow['tipo'] ?? '');
            $montoPool = (float)($poolRow['total'] ?? 0);
            if ($tipoPool === 'utilidad_master') {
                $utilidadMasterTotal = $montoPool;
                continue;
            }
            if (array_key_exists($tipoPool, $phasePools)) {
                $phasePools[$tipoPool] = $montoPool;
            }
        }
        $fase1Pool = (float)$phasePools['salto_fase_1'];
        $fase2Pool = (float)$phasePools['salto_fase_2'];
        $fase3Pool = (float)$phasePools['salto_fase_3'];
        $phasePoolTotal = $fase1Pool + $fase2Pool + $fase3Pool;

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
        $resME = $stmt->fetch();
        $masterEarnings = (float)($resME['total'] ?? 0);

        $stmt = $pdo->query("
            SELECT COALESCE(SUM(monto_pagado), 0) AS total
            FROM pagos
            WHERE tipo = 'regalo'
              AND estado = 'completado'
              AND origen_fondos = 'externo'
        ");
        $resTB = $stmt->fetch();
        $totalBlockchain = (float)($resTB['total'] ?? 0);

        $stmt = $pdo->query("
            SELECT COALESCE(SUM(credito_saldo), 0) AS total
            FROM usuarios
            WHERE tipo_usuario = 'real'
        ");
        $resCred = $stmt->fetch();
        $creditosExcedente = (float)($resCred['total'] ?? 0);

        // Retiros ya pagados a usuarios (salieron físicamente del sistema)
        $retirosProcessados = 0.0;
        try {
            $stmt = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM retiros WHERE estado = 'procesado'");
            $retirosProcessados = (float)($stmt->fetchColumn() ?: 0);
        } catch (Exception $e) { /* tabla puede no existir en instalaciones antiguas */ }

        $saldoWalletEstimado = max(0.0, $totalBlockchain - $retirosProcessados);

        // ─── FÓRMULA CORRECTA DE INTEGRIDAD ───────────────────────────────────
        // La obligación con usuarios se calcula DESDE el blockchain hacia abajo,
        // NO desde ganancia_tablero (que puede acumularse por ciclos internos).
        // blockchain = retiros_ya_pagados + tesoreria + pools + creditos + saldo_adeudado_usuarios
        // → saldo_adeudado_usuarios = blockchain - retiros - tesoreria - pools - creditos
        // utilidad_master ya entra a tesoreria_balance, por eso no se descuenta aparte.
        $obligacionUsuarios = max(
            0,
            $totalBlockchain
            - $retirosProcessados
            - $tesoreria
            - $phasePoolTotal
            - $reentradaPool
            - $creditosExcedente
        );

        // pendienteDistribuir ya no se necesita en la vista de integridad;
        // se mantiene en 0 para no romper otras partes del sistema.
        $pendienteDistribuir = 0;

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

        $hasNombreAudit = userColumnExists($pdo, 'nombre_completo');
        $nombreExprAudit = $hasNombreAudit
            ? "COALESCE(NULLIF(u.nombre_completo,''), u.nickname)"
            : "u.nickname";
        $stmt = $pdo->query("
            SELECT al.id, al.accion, al.detalles, al.fecha, al.fase_numero,
                   al.tabla_afectada,
                   u.nickname, ({$nombreExprAudit}) AS nombre_completo,
                   u.tipo_usuario
            FROM auditoria_logs al
            LEFT JOIN usuarios u ON al.usuario_id = u.id
            ORDER BY al.id DESC
            LIMIT 50
        ");
        $logsActividad = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $retirosPendientes = [];
        try {
            $hasNombre   = userColumnExists($pdo, 'nombre_completo');
            $hasTelefono = userColumnExists($pdo, 'telefono');
            $hasCorreo   = userColumnExists($pdo, 'correo_electronico');
            $hasTelegram = userColumnExists($pdo, 'telegram_username');

            $nombreExpr   = $hasNombre   ? "COALESCE(NULLIF(u.nombre_completo,''), u.nickname)" : "u.nickname";
            $telefonoSel  = $hasTelefono ? 'u.telefono'           : "'' AS telefono";
            $correoSel    = $hasCorreo   ? 'u.correo_electronico' : "'' AS correo_electronico";
            $telegramSel  = $hasTelegram ? 'u.telegram_username'  : "'' AS telegram_username";

            $hasFaseRetiro = false;
            try {
                $chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='retiros' AND COLUMN_NAME='fase_numero'");
                $hasFaseRetiro = (int)$chk->fetchColumn() > 0;
            } catch (\Exception $e2) {}
            $faseSelect = $hasFaseRetiro ? ', r.fase_numero' : ', 0 AS fase_numero';

            $stmt = $pdo->query("
                SELECT
                    r.id,
                    r.monto,
                    r.wallet_destino,
                    r.fecha_solicitud,
                    u.id        AS usuario_id,
                    u.nickname,
                    ({$nombreExpr}) AS nombre_completo,
                    ({$telefonoSel}),
                    ({$correoSel}),
                    ({$telegramSel}),
                    TIMESTAMPDIFF(HOUR, r.fecha_solicitud, NOW()) AS horas_esperando
                    {$faseSelect}
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
                ) AS pago_estado,
                (
                    SELECT tp.tablero_tipo
                    FROM tableros_progreso tp
                    WHERE tp.usuario_id = u.id
                      AND tp.estado = 'en_progreso'
                    ORDER BY tp.fase_numero DESC,
                             FIELD(tp.tablero_tipo, 'C', 'B', 'A')
                    LIMIT 1
                ) AS tablero_actual,
                (
                    SELECT tp.fase_numero
                    FROM tableros_progreso tp
                    WHERE tp.usuario_id = u.id
                      AND tp.estado = 'en_progreso'
                    ORDER BY tp.fase_numero DESC,
                             FIELD(tp.tablero_tipo, 'C', 'B', 'A')
                    LIMIT 1
                ) AS fase_actual,
                (
                    SELECT COUNT(*)
                    FROM tableros_progreso tp2
                    WHERE tp2.usuario_id = u.id
                      AND tp2.estado = 'completado'
                ) AS ciclos_completados
            FROM usuarios u
            WHERE u.tipo_usuario IN ('real', 'master', 'inactivo')
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

        /* ── VISTA GLOBAL: DESGLOSE POR FASE ──────────────────── */
        $PHASE_BOARD_COSTS = [
            0 => ['A' => 10,    'B' => 20,    'C' => 40],
            1 => ['A' => 100,   'B' => 200,   'C' => 400],
            2 => ['A' => 1000,  'B' => 2000,  'C' => 4000],
            3 => ['A' => 10000, 'B' => 20000, 'C' => 40000],
        ];

        $faseBreakdown = [];
        try {
            $fasesConf = $pdo->query(
                "SELECT fase_numero, nombre, activa FROM fases_config ORDER BY fase_numero ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $distData = $pdo->query("
                SELECT fase_numero, tablero_tipo,
                       COALESCE(SUM(monto),0) AS total_dist,
                       COUNT(DISTINCT COALESCE(beneficiario_usuario_id, id_receptor)) AS personas
                FROM pagos
                WHERE tipo='ganancia_tablero' AND estado='completado'
                GROUP BY fase_numero, tablero_tipo
            ")->fetchAll(PDO::FETCH_ASSOC);

            $activeUsers = $pdo->query("
                SELECT tp.fase_numero, tp.tablero_tipo, u.tipo_usuario, COUNT(*) AS cnt
                FROM tableros_progreso tp
                JOIN usuarios u ON u.id = tp.usuario_id
                WHERE tp.estado = 'en_progreso'
                GROUP BY tp.fase_numero, tp.tablero_tipo, u.tipo_usuario
            ")->fetchAll(PDO::FETCH_ASSOC);

            $completionData = $pdo->query("
                SELECT fase_numero, COUNT(*) AS total
                FROM auditoria_logs
                WHERE accion LIKE 'CICLO_COMPLETADO%'
                GROUP BY fase_numero
            ")->fetchAll(PDO::FETCH_ASSOC);

            $saltoData = $pdo->query("
                SELECT
                    CASE tipo
                        WHEN 'salto_fase_1' THEN 1
                        WHEN 'salto_fase_2' THEN 2
                        WHEN 'salto_fase_3' THEN 3
                    END AS fase_destino,
                    COALESCE(SUM(monto),0) AS pool
                FROM pagos
                WHERE tipo IN ('salto_fase_1','salto_fase_2','salto_fase_3')
                  AND estado = 'completado'
                GROUP BY tipo
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Retiros procesados por fase (defensivo si no existe columna)
            $retirosData = [];
            try {
                $retirosData = $pdo->query("
                    SELECT fase_numero, COUNT(*) AS cnt, COALESCE(SUM(monto),0) AS total
                    FROM retiros WHERE estado='procesado'
                    GROUP BY fase_numero
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e2) {}

            // Build lookup maps
            $dMap = [];
            foreach ($distData as $r) {
                $dMap[$r['fase_numero']][$r['tablero_tipo']] = [
                    'total'   => (float)$r['total_dist'],
                    'personas'=> (int)$r['personas'],
                ];
            }
            $aMap = [];
            foreach ($activeUsers as $r) {
                $aMap[$r['fase_numero']][$r['tablero_tipo']][$r['tipo_usuario']] = (int)$r['cnt'];
            }
            $cMap = [];
            foreach ($completionData as $r) $cMap[(int)$r['fase_numero']] = (int)$r['total'];
            $sMap = [];
            foreach ($saltoData as $r) $sMap[(int)$r['fase_destino']] = (float)$r['pool'];
            $rMap = [];
            foreach ($retirosData as $r) {
                $rMap[(int)$r['fase_numero']] = ['cnt' => (int)$r['cnt'], 'total' => (float)$r['total']];
            }

            $gd = function($fn, $tb) use ($dMap) { return $dMap[$fn][$tb] ?? ['total'=>0,'personas'=>0]; };
            $ga = function($fn, $tb, $tipo) use ($aMap) { return $aMap[$fn][$tb][$tipo] ?? 0; };

            foreach ($fasesConf as $fc) {
                $fn = (int)$fc['fase_numero'];
                $costs = $PHASE_BOARD_COSTS[$fn] ?? ['A'=>0,'B'=>0,'C'=>0];
                $dA = $gd($fn,'A'); $dB = $gd($fn,'B'); $dC = $gd($fn,'C');
                $rA = $ga($fn,'A','real'); $cA = $ga($fn,'A','clon');
                $rB = $ga($fn,'B','real'); $cB = $ga($fn,'B','clon');
                $rC = $ga($fn,'C','real'); $cC = $ga($fn,'C','clon');
                $faseBreakdown[] = [
                    'fase_numero'        => $fn,
                    'nombre'             => $fc['nombre'],
                    'activa'             => (bool)$fc['activa'],
                    'costo_a'            => $costs['A'],
                    'costo_b'            => $costs['B'],
                    'costo_c'            => $costs['C'],
                    'dist_a'             => $dA['total'],  'personas_a' => $dA['personas'],
                    'dist_b'             => $dB['total'],  'personas_b' => $dB['personas'],
                    'dist_c'             => $dC['total'],  'personas_c' => $dC['personas'],
                    'dist_total'         => $dA['total'] + $dB['total'] + $dC['total'],
                    'reales_en_a'        => $rA, 'clones_en_a' => $cA,
                    'reales_en_b'        => $rB, 'clones_en_b' => $cB,
                    'reales_en_c'        => $rC, 'clones_en_c' => $cC,
                    'total_reales_red'   => $rA + $rB + $rC,
                    'total_clones_red'   => $cA + $cB + $cC,
                    'ciclos_completados' => $cMap[$fn] ?? 0,
                    'pool_entrada'       => $sMap[$fn] ?? 0,
                    'retiros_cnt'        => $rMap[$fn]['cnt'] ?? 0,
                    'retiros_total'      => $rMap[$fn]['total'] ?? 0,
                ];
            }
        } catch (\Exception $e) {
            error_log('RADIX fase_breakdown ERROR: ' . $e->getMessage());
        }
        /* ── FIN DESGLOSE POR FASE ─────────────────────────────── */

        /* ── EMBUDO DE USUARIOS ─────────────────────────────────── */
        $embudo = ['registrados'=>$totalReales,'pagaron'=>0,'en_a'=>0,'en_b'=>0,'en_c'=>0,'completaron'=>0,'retiraron'=>0];
        try {
            $embudo['pagaron'] = (int)$pdo->query("
                SELECT COUNT(DISTINCT id_emisor) FROM pagos
                WHERE tipo='regalo' AND estado='completado'
            ")->fetchColumn();

            $boardRows = $pdo->query("
                SELECT tp.tablero_tipo, COUNT(DISTINCT tp.usuario_id) AS cnt
                FROM tableros_progreso tp
                JOIN usuarios u ON u.id=tp.usuario_id
                WHERE tp.estado='en_progreso' AND u.tipo_usuario='real'
                GROUP BY tp.tablero_tipo
            ")->fetchAll(PDO::FETCH_KEY_PAIR);
            $embudo['en_a'] = (int)($boardRows['A'] ?? 0);
            $embudo['en_b'] = (int)($boardRows['B'] ?? 0);
            $embudo['en_c'] = (int)($boardRows['C'] ?? 0);

            $embudo['completaron'] = (int)$pdo->query("
                SELECT COUNT(DISTINCT usuario_id) FROM auditoria_logs
                WHERE accion LIKE 'CICLO_COMPLETADO%'
            ")->fetchColumn();

            try {
                $embudo['retiraron'] = (int)$pdo->query("
                    SELECT COUNT(DISTINCT usuario_id) FROM retiros WHERE estado='procesado'
                ")->fetchColumn();
            } catch (\Exception $e2) {}
        } catch (\Exception $e) {}
        /* ── FIN EMBUDO ─────────────────────────────────────────── */

        /* ── VELOCIDAD SEMANAL ──────────────────────────────────── */
        $velocidad = ['usuarios_esta_semana'=>0,'usuarios_semana_pasada'=>0,'dist_esta_semana'=>0,'dist_semana_pasada'=>0,'tableros_completados_semana'=>0];
        try {
            $resV1 = $pdo->query("
                SELECT
                    COUNT(CASE WHEN fecha_registro >= DATE_SUB(NOW(), INTERVAL 7 DAY)  THEN 1 END) AS esta_semana,
                    COUNT(CASE WHEN fecha_registro >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                               AND fecha_registro <  DATE_SUB(NOW(), INTERVAL 7 DAY)   THEN 1 END) AS semana_pasada
                FROM usuarios WHERE tipo_usuario='real'
            ")->fetch(PDO::FETCH_ASSOC);
            $velocidad['usuarios_esta_semana']    = (int)($resV1['esta_semana'] ?? 0);
            $velocidad['usuarios_semana_pasada']  = (int)($resV1['semana_pasada'] ?? 0);

            $resV2 = $pdo->query("
                SELECT
                    COALESCE(SUM(CASE WHEN fecha_pago >= DATE_SUB(NOW(), INTERVAL 7 DAY)  THEN monto ELSE 0 END),0) AS esta_semana,
                    COALESCE(SUM(CASE WHEN fecha_pago >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                               AND fecha_pago <  DATE_SUB(NOW(), INTERVAL 7 DAY) THEN monto ELSE 0 END),0) AS semana_pasada
                FROM pagos WHERE tipo='ganancia_tablero' AND estado='completado'
            ")->fetch(PDO::FETCH_ASSOC);
            $velocidad['dist_esta_semana']   = (float)($resV2['esta_semana']   ?? 0);
            $velocidad['dist_semana_pasada'] = (float)($resV2['semana_pasada'] ?? 0);

            $velocidad['tableros_completados_semana'] = (int)$pdo->query("
                SELECT COUNT(*) FROM auditoria_logs
                WHERE accion LIKE 'CICLO_COMPLETADO%'
                  AND fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetchColumn();
        } catch (\Exception $e) {}
        /* ── FIN VELOCIDAD ──────────────────────────────────────── */

        /* ── SALUD FINANCIERA Y ALERTAS ────────────────────────── */
        $totalRetirosPendientesMonto = 0.0;
        $countRetirosPendientes      = 0;
        $retiroMasAntiguoFecha       = null;
        $countPagosSinConfirmar      = 0;
        $pagoMasAntiguoFecha         = null;

        // Sumar montos de retiros pendientes y detectar el más antiguo
        foreach ($retirosPendientes as $rp) {
            $totalRetirosPendientesMonto += (float)($rp['monto'] ?? 0);
            $countRetirosPendientes++;
            if (!$retiroMasAntiguoFecha || strtotime($rp['fecha_solicitud']) < strtotime($retiroMasAntiguoFecha)) {
                $retiroMasAntiguoFecha = $rp['fecha_solicitud'];
            }
        }

        // Pagos pendientes de usuarios reales sin confirmar (regalo pendiente)
        try {
            $stmtPP = $pdo->query("
                SELECT COUNT(*) AS cnt, MIN(p.fecha_pago) AS mas_antiguo
                FROM pagos p
                JOIN usuarios u ON u.id = p.id_emisor
                WHERE p.tipo = 'regalo'
                  AND p.estado = 'pendiente'
                  AND u.tipo_usuario = 'real'
            ");
            $ppRow = $stmtPP->fetch(PDO::FETCH_ASSOC);
            $countPagosSinConfirmar = (int)($ppRow['cnt'] ?? 0);
            $pagoMasAntiguoFecha    = $ppRow['mas_antiguo'] ?? null;
        } catch (\Exception $e) {}

        // Construir alertas automáticas
        $alertas = [];
        $COSTO_CLON = 10.0; // costo de activar un agente IA

        // 🔴 CRÍTICO: Retiros esperando más de 48h
        if ($retiroMasAntiguoFecha) {
            $horasEspera = (time() - strtotime($retiroMasAntiguoFecha)) / 3600;
            if ($horasEspera > 48) {
                $alertas[] = [
                    'nivel'    => 'critico',
                    'icono'    => '⏰',
                    'titulo'   => 'Retiro lleva más de 48h sin procesar',
                    'mensaje'  => "El retiro más antiguo está esperando " . round($horasEspera) . " horas. Los usuarios deben recibir su pago a tiempo.",
                    'accion'   => 'Aprobar Retiros',
                    'seccion'  => 'retiros',
                ];
            }
        }

        // 🟡 ADVERTENCIA: Retiros pendientes esperando más de 24h
        if ($retiroMasAntiguoFecha) {
            $horasEspera = (time() - strtotime($retiroMasAntiguoFecha)) / 3600;
            if ($horasEspera > 24 && $horasEspera <= 48) {
                $alertas[] = [
                    'nivel'    => 'advertencia',
                    'icono'    => '⚠️',
                    'titulo'   => 'Retiros pendientes hace más de 24h',
                    'mensaje'  => "Hay $countRetirosPendientes retiro(s) esperando más de 24 horas. Por $" . number_format($totalRetirosPendientesMonto, 2) . " total.",
                    'accion'   => 'Revisar Retiros',
                    'seccion'  => 'retiros',
                ];
            }
        }

        // 🟡 ADVERTENCIA: Hay retiros pendientes (informativo)
        if ($countRetirosPendientes > 0 && !array_filter($alertas, function($a) { return $a['seccion'] === 'retiros' && $a['nivel'] === 'critico'; })) {
            $alertas[] = [
                'nivel'    => 'info',
                'icono'    => '💸',
                'titulo'   => "$countRetirosPendientes retiro(s) esperando aprobación",
                'mensaje'  => "Total a pagar: $" . number_format($totalRetirosPendientesMonto, 2) . " USDT. La tesorería mostrada en este panel corresponde a clones/agentes IA y no se descuenta con retiros.",
                'accion'   => 'Aprobar Ahora',
                'seccion'  => 'retiros',
            ];
        }

        // 🟡 ADVERTENCIA: Pagos sin confirmar de usuarios reales más de 24h
        if ($countPagosSinConfirmar > 0 && $pagoMasAntiguoFecha) {
            $horasSinConf = (time() - strtotime($pagoMasAntiguoFecha)) / 3600;
            $alertas[] = [
                'nivel'    => $horasSinConf > 24 ? 'advertencia' : 'info',
                'icono'    => '📥',
                'titulo'   => "$countPagosSinConfirmar pago(s) de entrada sin confirmar",
                'mensaje'  => "Hay pagos en blockchain aún con estado 'pendiente'. El más antiguo lleva " . round($horasSinConf) . "h. Revisa el verificador de pagos.",
                'accion'   => 'Revisar Pagos',
                'seccion'  => 'usuarios',
            ];
        }

        // 🟡 ADVERTENCIA: Tesorería baja (menos de 2 clones de reserva)
        if ($tesoreria < ($COSTO_CLON * 2) && $countRetirosPendientes === 0) {
            $alertas[] = [
                'nivel'    => 'advertencia',
                'icono'    => '🏦',
                'titulo'   => 'Tesorería baja',
                'mensaje'  => "Solo quedan $" . number_format($tesoreria, 2) . " en tesorería. Considera no activar más Agentes IA hasta que ingrese nuevo capital.",
                'accion'   => 'Ver Tesorería',
                'seccion'  => 'ledger',
            ];
        }

        // 🟡 ADVERTENCIA: Hay dinero sin distribuir
        if ($pendienteDistribuir > 5.0) {
            $alertas[] = [
                'nivel'    => 'advertencia',
                'icono'    => '🔄',
                'titulo'   => "$" . number_format($pendienteDistribuir, 2) . " pendiente de distribuir",
                'mensaje'  => "Hay fondos recibidos en blockchain que aún no se han distribuido en la red. Verifica que el motor de pagos esté funcionando.",
                'accion'   => 'Ver Libro Mayor',
                'seccion'  => 'ledger',
            ];
        }

        // 🟢 Todo OK si no hay alertas críticas ni advertencias
        $hayProblemas = !empty(array_filter($alertas, function($a) { return in_array($a['nivel'], ['critico','advertencia']); }));
        $saludNivel = $hayProblemas
            ? (array_filter($alertas, function($a) { return $a['nivel'] === 'critico'; }) ? 'critico' : 'advertencia')
            : 'ok';

        $saludFinanciera = [
            'nivel'                         => $saludNivel,
            'tesoreria'                     => $tesoreria,
            'total_retiros_pendientes'      => $totalRetirosPendientesMonto,
            'count_retiros_pendientes'      => $countRetirosPendientes,
            'count_pagos_sin_confirmar'     => $countPagosSinConfirmar,
            'retiro_mas_antiguo'            => $retiroMasAntiguoFecha,
            'pago_sin_conf_mas_antiguo'     => $pagoMasAntiguoFecha,
            'pendiente_distribuir'          => $pendienteDistribuir,
            'solvente'                      => null,
        ];
        /* ── FIN SALUD FINANCIERA ──────────────────────────────── */

        sendResponse([
            'success' => true,
            'tesoreria' => $tesoreria,
            'fase1_pool' => $fase1Pool,
            'phase_pool_total' => $phasePoolTotal,
            'phase_pools' => [
                'fase_1' => $fase1Pool,
                'fase_2' => $fase2Pool,
                'fase_3' => $fase3Pool,
            ],
            'utilidad_master_total' => $utilidadMasterTotal,
            'reentrada_pool' => $reentradaPool,
            'reservas_aplicadas' => $reservasAplicadas,
            'reservas_pendientes' => $reservasPendientes,
            'master_id1_earnings' => $masterEarnings,
            'retiros_procesados' => $retirosProcessados,
            'saldo_wallet_estimado' => $saldoWalletEstimado,
            'obligacion_usuarios' => $obligacionUsuarios,
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
                    ['value' => 'inactivo', 'label' => 'Inactivos'],
                ],
            ],
            'crecimiento_diario' => $crecimientoDiario,
            'logs' => $logsClones,
            'logs_reservas' => $logsReservas,
            'logs_actividad' => $logsActividad,
            'lista_usuarios' => $listaUsuarios,
            'retiros_pendientes' => $retirosPendientes,
            'tesoreria_movimientos' => $tesoreriaMovimientos,
            'salud_financiera' => $saludFinanciera,
            'alertas'          => $alertas,
            'fase_breakdown'   => $faseBreakdown,
            'embudo'           => $embudo,
            'velocidad'        => $velocidad,
        ]);
    } catch (PDOException $e) {
        error_log('RADIX admin_global_stats ERROR: ' . $e->getMessage());
        sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
    }
} else {
    sendResponse(['error' => 'Metodo no permitido'], 405);
}
?>
