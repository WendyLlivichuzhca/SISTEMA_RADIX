<?php
/**
 * public_stats.php — Estadísticas públicas para la landing page.
 * Solo expone conteos agregados sin datos sensibles.
 * NO requiere autenticación.
 */
require_once 'config.php';

function publicStatsDisplayNameExpr(PDO $pdo): string
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME = 'nombre_completo'
    ");
    $stmt->execute();
    $hasNombre = (bool)$stmt->fetchColumn();

    return $hasNombre
        ? "COALESCE(NULLIF(nombre_completo, ''), nickname)"
        : "nickname";
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

try {
    // ── Si viene ?ref_wallet= devolver el nombre visible del referidor ──
    $ref_wallet = trim($_GET['ref_wallet'] ?? '');
    if (!empty($ref_wallet)) {
        $displayExpr = publicStatsDisplayNameExpr($pdo) . " AS display_name";
        $stmt = $pdo->prepare("SELECT nickname, {$displayExpr} FROM usuarios WHERE wallet_address = ? AND tipo_usuario = 'real' LIMIT 1");
        $stmt->execute([$ref_wallet]);
        $row = $stmt->fetch();
        sendResponse([
            'success'      => true,
            'nickname'     => $row ? $row['nickname'] : null,
            'display_name' => $row ? ($row['display_name'] ?: $row['nickname']) : null,
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
          AND p.propietario_flujo = 'usuario'
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
