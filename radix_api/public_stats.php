<?php
/**
 * public_stats.php — Estadísticas públicas para la landing page.
 * Solo expone conteos agregados sin datos sensibles.
 * NO requiere autenticación.
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

try {
    // ── Si viene ?ref_wallet= devolver solo el nickname del referidor ──
    $ref_wallet = trim($_GET['ref_wallet'] ?? '');
    if (!empty($ref_wallet)) {
        $stmt = $pdo->prepare("SELECT nickname FROM usuarios WHERE wallet_address = ? AND tipo_usuario = 'real' LIMIT 1");
        $stmt->execute([$ref_wallet]);
        $row = $stmt->fetch();
        sendResponse([
            'success'  => true,
            'nickname' => $row ? $row['nickname'] : null,
        ]);
    }

    // Total de usuarios reales registrados
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'real'");
    $total_usuarios = (int)$stmt->fetchColumn();

    // Total USDT distribuido a usuarios (ganancias de tablero completadas = pagos reales)
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(p.monto), 0)
        FROM pagos p
        JOIN usuarios u ON p.id_receptor = u.id
        WHERE p.tipo = 'ganancia_tablero'
          AND p.estado = 'completado'
          AND u.tipo_usuario = 'real'
    ");
    $total_pagado = (float)$stmt->fetchColumn();

    sendResponse([
        'success'        => true,
        'total_usuarios' => $total_usuarios,
        'total_pagado'   => $total_pagado,
    ]);

} catch (PDOException $e) {
    // En caso de error, devolver ceros (no exponer detalles del error)
    sendResponse([
        'success'        => true,
        'total_usuarios' => 0,
        'total_pagado'   => 0.0,
    ]);
}
?>
