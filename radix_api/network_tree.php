<?php
/**
 * network_tree.php — RADIX Phase 0
 * Retorna la estructura jerárquica del árbol de red del usuario en formato JSON.
 * MEJORA #3: Árbol visual de red en el dashboard.
 */
require_once 'config.php';
require_once 'phase_config.php';
session_start();

function networkTreeDisplayNameExpr(PDO $pdo, string $alias = ''): string
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

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

$wallet = $_SESSION['radix_wallet'];

try {
    // Obtener el usuario raíz
    $rootDisplayNameSelect = networkTreeDisplayNameExpr($pdo) . " AS display_name";
    $stmt = $pdo->prepare("SELECT id, nickname, {$rootDisplayNameSelect}, wallet_address, tipo_usuario FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $root = $stmt->fetch();
    if (!$root) sendResponse(['error' => 'Usuario no encontrado'], 404);

    $user_id = $root['id'];

    $requestedPhase = filter_input(INPUT_GET, 'fase_numero', FILTER_VALIDATE_INT);
    $requestedCycle = filter_input(INPUT_GET, 'ciclo', FILTER_VALIDATE_INT);

    if ($requestedPhase === false) {
        $requestedPhase = null;
    }
    if ($requestedCycle === false) {
        $requestedCycle = null;
    }

    if ($requestedPhase === null) {
        $stmt = $pdo->prepare("
            SELECT fase_numero, ciclo
            FROM tableros_progreso
            WHERE usuario_id = ?
              AND estado = 'en_progreso'
            ORDER BY fase_numero DESC, ciclo DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $activeBoard = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activeBoard) {
            $requestedPhase = (int)$activeBoard['fase_numero'];
            $requestedCycle = $requestedCycle ?? (int)$activeBoard['ciclo'];
        } else {
            $requestedPhase = 0;
        }
    }

    $fase_numero = max(0, (int)$requestedPhase);

    // Obtener el ciclo actual del usuario raiz para no mezclar reentradas viejas con la red vigente
    if ($requestedCycle === null) {
        $stmt = $pdo->prepare("
            SELECT ciclo
            FROM tableros_progreso
            WHERE usuario_id = ?
              AND fase_numero = ?
            ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$user_id, $fase_numero]);
        $requestedCycle = (int)($stmt->fetchColumn() ?: 1);
    }

    $ciclo_actual = max(1, (int)$requestedCycle);
    $phaseConfig = getPhaseConfig($pdo, $fase_numero);

    // Función recursiva para construir el árbol (máx. 2 niveles: hijos y nietos)
    function buildTree(PDO $pdo, int $padre_id, int $fase_numero, int $ciclo_actual, int $depth = 0, int $maxDepth = 2): array {
        if ($depth >= $maxDepth) return [];

        $stmt = $pdo->prepare("
            SELECT u.id, u.nickname, " . networkTreeDisplayNameExpr($pdo, 'u') . " AS display_name, u.wallet_address, u.tipo_usuario,
                   r.posicion,
                   r.ciclo,
                   (SELECT tablero_tipo FROM tableros_progreso
                    WHERE usuario_id = u.id AND fase_numero = ? AND estado = 'en_progreso'
                    ORDER BY ciclo DESC, id DESC LIMIT 1) as tablero_actual,
                   (SELECT estado FROM pagos WHERE id_emisor = u.id AND fase_numero = ? AND tipo = 'regalo' ORDER BY id DESC LIMIT 1) as pago_estado
            FROM referidos r
            JOIN usuarios u ON r.id_hijo = u.id
            WHERE r.id_padre = ?
              AND r.fase_numero = ?
              AND r.ciclo = ?
            ORDER BY r.posicion ASC
        ");
        $stmt->execute([$fase_numero, $fase_numero, $padre_id, $fase_numero, $ciclo_actual]);
        $hijos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($hijos as &$hijo) {
            $hijo['fase_numero'] = $fase_numero;
            $hijo['hijos'] = buildTree($pdo, (int)$hijo['id'], $fase_numero, $ciclo_actual, $depth + 1, $maxDepth);
        }
        return $hijos;
    }

    $nivel = null;
    $stmt2 = $pdo->prepare("SELECT tablero_tipo FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = ? AND estado = 'en_progreso' ORDER BY ciclo DESC, id DESC LIMIT 1");
    $stmt2->execute([$user_id, $fase_numero]);
    $t = $stmt2->fetch();
    if ($t) {
        $nivel = $t['tablero_tipo'];
    } else {
        $stmt_check = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = ? AND tablero_tipo = 'C' AND estado = 'completado' LIMIT 1");
        $stmt_check->execute([$user_id, $fase_numero]);
        $nivel = $stmt_check->fetch() ? 'FASE_COMPLETADA' : 'A';
    }

    $arbol = [
        'id'            => $user_id,
        'fase_numero'   => $fase_numero,
        'fase_nombre'   => $phaseConfig['nombre'] ?: ('Fase ' . $fase_numero),
        'nickname'      => $root['nickname'],
        'display_name'  => $root['display_name'] ?: $root['nickname'],
        'wallet_address'=> $root['wallet_address'],
        'tipo_usuario'  => $root['tipo_usuario'],
        'tablero_actual'=> $nivel,
        'ciclo_actual'  => $ciclo_actual,
        'pago_estado'   => 'completado',   // el propio usuario
        'es_raiz'       => true,
        'hijos'         => buildTree($pdo, $user_id, $fase_numero, $ciclo_actual),
    ];

    sendResponse([
        'success' => true,
        'fase_numero' => $fase_numero,
        'fase_nombre' => $phaseConfig['nombre'] ?: ('Fase ' . $fase_numero),
        'ciclo' => $ciclo_actual,
        'arbol' => $arbol
    ]);

} catch (PDOException $e) {
    error_log("RADIX network_tree ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
}
