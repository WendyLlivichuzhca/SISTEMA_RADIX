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

    // Función recursiva para construir el árbol (máx. 2 niveles: hijos y nietos)
    function buildTree(PDO $pdo, int $padre_id, int $depth = 0, int $maxDepth = 2): array {
        if ($depth >= $maxDepth) return [];

        $stmt = $pdo->prepare("
            SELECT u.id, u.nickname, u.wallet_address, u.tipo_usuario,
                   tp.tablero_tipo as tablero_actual,
                   (SELECT estado FROM pagos WHERE id_emisor = u.id AND tipo = 'regalo' LIMIT 1) as pago_estado
            FROM referidos r
            JOIN usuarios u ON r.id_hijo = u.id
            LEFT JOIN tableros_progreso tp ON tp.usuario_id = u.id AND tp.estado = 'en_progreso'
            WHERE r.id_padre = ?
            ORDER BY r.posicion ASC
        ");
        $stmt->execute([$padre_id]);
        $hijos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($hijos as &$hijo) {
            $hijo['hijos'] = buildTree($pdo, $hijo['id'], $depth + 1, $maxDepth);
        }
        return $hijos;
    }

    $nivel = null;
    $stmt2 = $pdo->prepare("SELECT tablero_tipo FROM tableros_progreso WHERE usuario_id = ? AND estado = 'en_progreso' LIMIT 1");
    $stmt2->execute([$user_id]);
    $t = $stmt2->fetch();
    $nivel = $t ? $t['tablero_tipo'] : 'A';

    $arbol = [
        'id'            => $user_id,
        'nickname'      => $root['nickname'],
        'wallet_address'=> $root['wallet_address'],
        'tipo_usuario'  => $root['tipo_usuario'],
        'tablero_actual'=> $nivel,
        'pago_estado'   => 'completado',   // el propio usuario
        'es_raiz'       => true,
        'hijos'         => buildTree($pdo, $user_id),
    ];

    sendResponse(['success' => true, 'arbol' => $arbol]);

} catch (PDOException $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}
