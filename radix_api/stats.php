<?php
require_once 'config.php';

// Endpoint para obtener estadísticas globales (Radix v2.0)
try {
    // Total de usuarios activos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE estado = 'activo'");
    $total_users = $stmt->fetch()['total'];

    // Total de 'regalos' (pagos de tipo regalo completados)
    $stmt = $pdo->query("SELECT SUM(monto) as total FROM pagos WHERE estado = 'completado' AND tipo = 'regalo'");
    $total_gifts = $stmt->fetch()['total'] ?? 0;

    // Días desde el lanzamiento (primer registro)
    $stmt = $pdo->query("SELECT DATEDIFF(NOW(), MIN(fecha_registro)) as dias FROM usuarios");
    $days = $stmt->fetch()['dias'] ?? 0;

    sendResponse([
        'users' => (int)$total_users,
        'gifts' => (float)$total_gifts,
        'days' => (int)$days + 1
    ]);

} catch (PDOException $e) {
    sendResponse(['error' => 'Error al obtener estadísticas del servidor'], 500);
}
?>
