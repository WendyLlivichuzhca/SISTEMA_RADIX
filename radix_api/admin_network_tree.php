<?php
/**
 * admin_network_tree.php
 * Árbol general administrativo de la red RADIX.
 * Solo lectura: no toca pagos, tableros ni lógica de negocio.
 */
require_once 'admin_auth.php';
require_once 'config.php';

requireAdminSession();

function adminNetworkTreeDisplayNameExpr(PDO $pdo, string $alias = ''): string
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME = 'nombre_completo'
    ");
    $stmt->execute();

    $prefix = $alias !== '' ? $alias . '.' : '';
    return ((int)$stmt->fetchColumn() > 0)
        ? "COALESCE(NULLIF({$prefix}nombre_completo, ''), {$prefix}nickname)"
        : "{$prefix}nickname";
}

function fetchPhaseOptions(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT fase_numero, nombre, activa
            FROM fases_config
            ORDER BY fase_numero ASC
        ");
        $rows = $stmt->fetchAll();
        if ($rows) {
            return array_map(static function (array $row): array {
                return [
                    'fase_numero' => (int)$row['fase_numero'],
                    'nombre' => $row['nombre'] ?: ('Fase ' . (int)$row['fase_numero']),
                    'activa' => (int)($row['activa'] ?? 0),
                ];
            }, $rows);
        }
    } catch (Throwable $e) {
        error_log('RADIX admin_network_tree phases fallback: ' . $e->getMessage());
    }

    return [
        ['fase_numero' => 0, 'nombre' => 'Fase 0', 'activa' => 1],
    ];
}

function fetchCycleOptions(PDO $pdo, int $faseNumero): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT ciclo
        FROM (
            SELECT ciclo
            FROM referidos
            WHERE fase_numero = ?

            UNION

            SELECT ciclo
            FROM tableros_progreso
            WHERE fase_numero = ?
        ) t
        ORDER BY ciclo DESC
    ");
    $stmt->execute([$faseNumero, $faseNumero]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $cycles = array_values(array_unique(array_map('intval', $rows ?: [1])));
    if (!$cycles) {
        $cycles = [1];
    }
    rsort($cycles, SORT_NUMERIC);
    return $cycles;
}

function resolveRootNode(PDO $pdo, int $faseNumero, int $ciclo, string $rootQuery): ?array
{
    $displayName = adminNetworkTreeDisplayNameExpr($pdo, 'u');

    if ($rootQuery !== '') {
        $isNumeric = ctype_digit($rootQuery);
        $sql = "
            SELECT DISTINCT
                u.id,
                u.nickname,
                {$displayName} AS display_name,
                u.wallet_address,
                u.tipo_usuario
            FROM usuarios u
            LEFT JOIN referidos r_any
              ON (r_any.id_padre = u.id OR r_any.id_hijo = u.id)
             AND r_any.fase_numero = ?
             AND r_any.ciclo = ?
            LEFT JOIN tableros_progreso tp
              ON tp.usuario_id = u.id
             AND tp.fase_numero = ?
             AND tp.ciclo = ?
            WHERE u.tipo_usuario IN ('real', 'clon')
              AND (r_any.id IS NOT NULL OR tp.id IS NOT NULL)
              AND (
                    " . ($isNumeric ? "u.id = ?" : "1 = 0") . "
                 OR {$displayName} LIKE ?
                 OR u.nickname LIKE ?
                 OR u.wallet_address LIKE ?
              )
            ORDER BY
                CASE
                    WHEN " . ($isNumeric ? "u.id = ?" : "0") . " THEN 0
                    WHEN {$displayName} LIKE ? THEN 1
                    WHEN u.nickname LIKE ? THEN 2
                    ELSE 3
                END,
                u.id ASC
            LIMIT 1
        ";

        $like = '%' . $rootQuery . '%';
        $params = [$faseNumero, $ciclo, $faseNumero, $ciclo];
        if ($isNumeric) {
            $params[] = (int)$rootQuery;
        }
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        if ($isNumeric) {
            $params[] = (int)$rootQuery;
        }
        $params[] = $like;
        $params[] = $like;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.nickname,
            {$displayName} AS display_name,
            u.wallet_address,
            u.tipo_usuario
        FROM usuarios u
        LEFT JOIN referidos r_in
          ON r_in.id_hijo = u.id
         AND r_in.fase_numero = ?
         AND r_in.ciclo = ?
        LEFT JOIN referidos r_any
          ON (r_any.id_padre = u.id OR r_any.id_hijo = u.id)
         AND r_any.fase_numero = ?
         AND r_any.ciclo = ?
        LEFT JOIN tableros_progreso tp
          ON tp.usuario_id = u.id
         AND tp.fase_numero = ?
         AND tp.ciclo = ?
        WHERE u.tipo_usuario IN ('real', 'clon')
          AND (r_any.id IS NOT NULL OR tp.id IS NOT NULL)
          AND r_in.id IS NULL
        ORDER BY
            CASE WHEN u.patrocinador_id IS NULL THEN 0 ELSE 1 END,
            CASE WHEN u.tipo_usuario = 'real' THEN 0 ELSE 1 END,
            u.id ASC
        LIMIT 1
    ");
    $stmt->execute([$faseNumero, $ciclo, $faseNumero, $ciclo, $faseNumero, $ciclo]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.nickname,
            {$displayName} AS display_name,
            u.wallet_address,
            u.tipo_usuario
        FROM usuarios u
        LEFT JOIN referidos r_any
          ON (r_any.id_padre = u.id OR r_any.id_hijo = u.id)
         AND r_any.fase_numero = ?
         AND r_any.ciclo = ?
        LEFT JOIN tableros_progreso tp
          ON tp.usuario_id = u.id
         AND tp.fase_numero = ?
         AND tp.ciclo = ?
        WHERE u.tipo_usuario IN ('real', 'clon')
          AND (r_any.id IS NOT NULL OR tp.id IS NOT NULL)
        ORDER BY u.id ASC
        LIMIT 1
    ");
    $stmt->execute([$faseNumero, $ciclo, $faseNumero, $ciclo]);
    return $stmt->fetch() ?: null;
}

function fetchTreeParticipants(PDO $pdo, int $faseNumero, int $ciclo): array
{
    $displayName = adminNetworkTreeDisplayNameExpr($pdo, 'u');
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.nickname,
            {$displayName} AS display_name,
            u.wallet_address,
            u.tipo_usuario,
            u.patrocinador_id,
            (
                SELECT tablero_tipo
                FROM tableros_progreso
                WHERE usuario_id = u.id
                  AND fase_numero = ?
                ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
                LIMIT 1
            ) AS tablero_actual,
            (
                SELECT estado
                FROM pagos
                WHERE id_emisor = u.id
                  AND fase_numero = ?
                  AND tipo = 'regalo'
                ORDER BY id DESC
                LIMIT 1
            ) AS pago_estado
        FROM usuarios u
        LEFT JOIN referidos r_any
          ON (r_any.id_padre = u.id OR r_any.id_hijo = u.id)
         AND r_any.fase_numero = ?
         AND r_any.ciclo = ?
        LEFT JOIN tableros_progreso tp
          ON tp.usuario_id = u.id
         AND tp.fase_numero = ?
         AND tp.ciclo = ?
        WHERE u.tipo_usuario IN ('real', 'clon')
          AND (r_any.id IS NOT NULL OR tp.id IS NOT NULL)
        ORDER BY u.id ASC
    ");
    $stmt->execute([$faseNumero, $faseNumero, $faseNumero, $ciclo, $faseNumero, $ciclo]);
    $rows = $stmt->fetchAll();

    $nodes = [];
    foreach ($rows as $row) {
        $nodes[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'nickname' => $row['nickname'],
            'display_name' => $row['display_name'] ?: $row['nickname'],
            'wallet_address' => $row['wallet_address'],
            'tipo_usuario' => $row['tipo_usuario'],
            'patrocinador_id' => $row['patrocinador_id'] !== null ? (int)$row['patrocinador_id'] : null,
            'tablero_actual' => $row['tablero_actual'] ?: 'A',
            'pago_estado' => $row['pago_estado'] ?: 'pendiente',
            'hijos' => [],
        ];
    }

    return $nodes;
}

function buildAdminNetworkTree(
    int $rootId,
    array $nodesById,
    array $childrenByParent,
    array &$visited,
    bool $isRoot = false
): ?array {
    if (!isset($nodesById[$rootId])) {
        return null;
    }

    $visited[$rootId] = true;
    $node = $nodesById[$rootId];
    $node['es_raiz'] = $isRoot;
    $node['hijos'] = [];

    $children = $childrenByParent[$rootId] ?? [];
    usort($children, static function (array $a, array $b): int {
        return ($a['posicion'] ?? 99) <=> ($b['posicion'] ?? 99);
    });

    foreach ($children as $childMeta) {
        $childId = (int)$childMeta['id_hijo'];
        if (isset($visited[$childId])) {
            continue;
        }
        $child = buildAdminNetworkTree($childId, $nodesById, $childrenByParent, $visited, false);
        if ($child !== null) {
            $child['posicion'] = (int)$childMeta['posicion'];
            $node['hijos'][] = $child;
        }
    }

    return $node;
}

function summarizeAdminTree(array $tree, int $depth = 0): array
{
    $summary = [
        'nodos' => 1,
        'reales' => $tree['tipo_usuario'] === 'real' ? 1 : 0,
        'clones' => $tree['tipo_usuario'] === 'clon' ? 1 : 0,
        'profundidad' => $depth,
    ];

    foreach (($tree['hijos'] ?? []) as $child) {
        $childSummary = summarizeAdminTree($child, $depth + 1);
        $summary['nodos'] += $childSummary['nodos'];
        $summary['reales'] += $childSummary['reales'];
        $summary['clones'] += $childSummary['clones'];
        $summary['profundidad'] = max($summary['profundidad'], $childSummary['profundidad']);
    }

    return $summary;
}

try {
    $phaseOptions = fetchPhaseOptions($pdo);
    $requestedPhase = isset($_GET['fase_numero']) ? (int)$_GET['fase_numero'] : null;
    $phaseNumbers = array_column($phaseOptions, 'fase_numero');
    $faseNumero = ($requestedPhase !== null && in_array($requestedPhase, $phaseNumbers, true))
        ? $requestedPhase
        : (int)($phaseOptions[0]['fase_numero'] ?? 0);

    $cycleOptions = fetchCycleOptions($pdo, $faseNumero);
    $requestedCycle = isset($_GET['ciclo']) ? (int)$_GET['ciclo'] : null;
    $ciclo = ($requestedCycle !== null && in_array($requestedCycle, $cycleOptions, true))
        ? $requestedCycle
        : (int)($cycleOptions[0] ?? 1);

    $rootQuery = trim((string)($_GET['root'] ?? ''));
    $rootNode = resolveRootNode($pdo, $faseNumero, $ciclo, $rootQuery);
    if (!$rootNode) {
        sendResponse([
            'success' => true,
            'arbol' => null,
            'resumen' => ['nodos' => 0, 'reales' => 0, 'clones' => 0, 'profundidad' => 0],
            'filtros' => [
                'fase_numero' => $faseNumero,
                'ciclo' => $ciclo,
                'root_query' => $rootQuery,
                'root_resuelto' => null,
                'fases' => $phaseOptions,
                'ciclos' => $cycleOptions,
            ],
        ]);
    }

    $nodesById = fetchTreeParticipants($pdo, $faseNumero, $ciclo);
    $rootId = (int)$rootNode['id'];

    if (!isset($nodesById[$rootId])) {
        $nodesById[$rootId] = [
            'id' => $rootId,
            'nickname' => $rootNode['nickname'],
            'display_name' => $rootNode['display_name'] ?: $rootNode['nickname'],
            'wallet_address' => $rootNode['wallet_address'],
            'tipo_usuario' => $rootNode['tipo_usuario'],
            'patrocinador_id' => null,
            'tablero_actual' => 'A',
            'pago_estado' => 'completado',
            'hijos' => [],
        ];
    }

    $stmt = $pdo->prepare("
        SELECT id_padre, id_hijo, posicion
        FROM referidos
        WHERE fase_numero = ?
          AND ciclo = ?
        ORDER BY posicion ASC, id ASC
    ");
    $stmt->execute([$faseNumero, $ciclo]);
    $relations = $stmt->fetchAll();

    $childrenByParent = [];
    foreach ($relations as $relation) {
        $parentId = (int)$relation['id_padre'];
        $childrenByParent[$parentId][] = [
            'id_hijo' => (int)$relation['id_hijo'],
            'posicion' => (int)$relation['posicion'],
        ];
    }

    $visited = [];
    $tree = buildAdminNetworkTree($rootId, $nodesById, $childrenByParent, $visited, true);
    if ($tree === null) {
        sendResponse([
            'success' => true,
            'arbol' => null,
            'resumen' => ['nodos' => 0, 'reales' => 0, 'clones' => 0, 'profundidad' => 0],
            'filtros' => [
                'fase_numero' => $faseNumero,
                'ciclo' => $ciclo,
                'root_query' => $rootQuery,
                'root_resuelto' => null,
                'fases' => $phaseOptions,
                'ciclos' => $cycleOptions,
            ],
        ]);
    }

    $summary = summarizeAdminTree($tree);

    sendResponse([
        'success' => true,
        'arbol' => $tree,
        'resumen' => $summary,
        'filtros' => [
            'fase_numero' => $faseNumero,
            'ciclo' => $ciclo,
            'root_query' => $rootQuery,
            'root_resuelto' => [
                'id' => $rootId,
                'nickname' => $tree['nickname'],
                'display_name' => $tree['display_name'],
                'wallet_address' => $tree['wallet_address'],
                'tipo_usuario' => $tree['tipo_usuario'],
            ],
            'fases' => $phaseOptions,
            'ciclos' => $cycleOptions,
        ],
    ]);
} catch (Throwable $e) {
    error_log('RADIX admin_network_tree ERROR: ' . $e->getMessage());
    sendResponse(['error' => 'Error al construir el árbol administrativo.'], 500);
}
