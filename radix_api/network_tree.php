<?php
/**
 * network_tree.php — RADIX Phase 0
 * Retorna la estructura jerárquica del árbol de red del usuario en formato JSON.
 * MEJORA #3: Árbol visual de red en el dashboard.
 */
require_once 'config.php';
session_start();

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

$wallet = $_SESSION['radix_wallet'];

try {
    // Obtener el usuario raíz
    $stmt = $pdo->prepare("SELECT id, nickname, wallet_address, tipo_usuario FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $root = $stmt->fetch();
    if (!$root) sendResponse(['error' => 'Usuario no encontrado'], 404);

    $user_id = $root['id'];

    // Obtener el ciclo actual del usuario raíz para no mezclar reentradas viejas con la red vigente
    $stmt = $pdo->prepare("
        SELECT ciclo
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $ciclo_actual = (int)($stmt->fetchColumn() ?: 1);

    // Función recursiva para construir el árbol (máx. 2 niveles: hijos y nietos)
    function buildTree(PDO $pdo, int $padre_id, int $ciclo_actual, int $depth = 0, int $maxDepth = 2): array {
        if ($depth >= $maxDepth) return [];

        $stmt = $pdo->prepare("
            SELECT u.id, u.nickname, u.wallet_address, u.tipo_usuario,
                   r.posicion,
                   r.ciclo,
                   (SELECT tablero_tipo FROM tableros_progreso
                    WHERE usuario_id = u.id AND estado = 'en_progreso'
                    ORDER BY id DESC LIMIT 1) as tablero_actual,
                   (SELECT estado FROM pagos WHERE id_emisor = u.id AND tipo = 'regalo' ORDER BY id DESC LIMIT 1) as pago_estado
            FROM referidos r
            JOIN usuarios u ON r.id_hijo = u.id
            WHERE r.id_padre = ?
              AND r.ciclo = ?
            ORDER BY r.posicion ASC
        ");
        $stmt->execute([$padre_id, $ciclo_actual]);
        $hijos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($hijos as &$hijo) {
            $hijo['hijos'] = buildTree($pdo, (int)$hijo['id'], $ciclo_actual, $depth + 1, $maxDepth);
        }
        return $hijos;
    }

    $nivel = null;
    $stmt2 = $pdo->prepare("SELECT tablero_tipo FROM tableros_progreso WHERE usuario_id = ? AND estado = 'en_progreso' ORDER BY id DESC LIMIT 1");
    $stmt2->execute([$user_id]);
    $t = $stmt2->fetch();
    if ($t) {
        $nivel = $t['tablero_tipo'];
    } else {
        // Verificar si ya completó Fase 0 (Tablero C completado)
        $stmt_check = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = ? AND tablero_tipo = 'C' AND estado = 'completado' LIMIT 1");
        $stmt_check->execute([$user_id]);
        $nivel = $stmt_check->fetch() ? 'FASE0_COMPLETADA' : 'A';
    }

    $arbol = [
        'id'            => $user_id,
        'nickname'      => $root['nickname'],
        'wallet_address'=> $root['wallet_address'],
        'tipo_usuario'  => $root['tipo_usuario'],
        'tablero_actual'=> $nivel,
        'ciclo_actual'  => $ciclo_actual,
        'pago_estado'   => 'completado',   // el propio usuario
        'es_raiz'       => true,
        'hijos'         => buildTree($pdo, $user_id, $ciclo_actual),
    ];

    sendResponse(['success' => true, 'arbol' => $arbol]);

} catch (PDOException $e) {
    error_log("RADIX network_tree ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
}
