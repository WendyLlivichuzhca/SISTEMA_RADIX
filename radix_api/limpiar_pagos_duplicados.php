<?php
/**
 * limpiar_pagos_duplicados.php
 * Limpia los pagos duplicados y autopagos generados por el bug de registro.php.
 *
 * USAR UNA SOLA VEZ y luego este archivo se bloquea automáticamente en .htaccess.
 * Acceder con: /radix_api/limpiar_pagos_duplicados.php?clave=RADIX_CLEAN_2024
 */
require_once 'config.php';

$clave = $_GET['clave'] ?? '';
if ($clave !== 'RADIX_CLEAN_2024') {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado. Usa ?clave=RADIX_CLEAN_2024']));
}

try {
    $pdo->beginTransaction();

    // 1. Borrar AUTOPAGOS: pagos donde id_emisor = id_receptor (RADIX_MASTER pagándose a sí mismo)
    $stmt = $pdo->prepare("DELETE FROM pagos WHERE id_emisor = id_receptor AND estado = 'pendiente'");
    $stmt->execute();
    $autopagos = $stmt->rowCount();

    // 2. Borrar DUPLICADOS por usuario: conservar solo el pago pendiente más reciente por emisor
    // (usuarios que se registraron varias veces generaron múltiples pagos pendientes)
    $stmt = $pdo->prepare("
        DELETE FROM pagos
        WHERE estado = 'pendiente'
          AND tipo = 'regalo'
          AND id NOT IN (
              SELECT max_id FROM (
                  SELECT MAX(id) as max_id
                  FROM pagos
                  WHERE estado = 'pendiente' AND tipo = 'regalo'
                  GROUP BY id_emisor
              ) as ultimos
          )
    ");
    $stmt->execute();
    $duplicados = $stmt->rowCount();

    $pdo->commit();

    // Mostrar estado final de pagos pendientes
    $stmt = $pdo->query("
        SELECT p.id, e.nickname as emisor, r.nickname as receptor, p.monto, p.fecha_pago
        FROM pagos p
        JOIN usuarios e ON p.id_emisor = e.id
        JOIN usuarios r ON p.id_receptor = r.id
        WHERE p.estado = 'pendiente' AND p.tipo = 'regalo'
        ORDER BY p.id ASC
    ");
    $pendientes = $stmt->fetchAll();

    echo json_encode([
        'success'             => true,
        'autopagos_borrados'  => $autopagos,
        'duplicados_borrados' => $duplicados,
        'pagos_pendientes_restantes' => $pendientes,
        'importante'          => '⚠️ Limpieza completada. Agrega limpiar_pagos_duplicados al .htaccess.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
