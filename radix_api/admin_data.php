<?php
require_once 'config.php';

// Endpoint para el panel administrativo (Protegido por lógica básica)
$tab = $_GET['tab'] ?? 'stats';

try {
    $response = [];

    if ($tab === 'stats') {
        // Stats resumidas
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
        $total_users = $stmt->fetch()['total'];

        $stmt = $pdo->query("SELECT SUM(monto) as total FROM pagos WHERE estado = 'completado'");
        $total_gifts = $stmt->fetch()['total'] ?? 0;

        // Últimos 10 usuarios
        $stmt = $pdo->query("SELECT wallet_address, nickname, fecha_registro, ip_registro as ip_address FROM usuarios ORDER BY id DESC LIMIT 10");
        $recent = $stmt->fetchAll();

        $response = [
            'stats' => ['total_users' => (int)$total_users, 'total_gifts' => (float)$total_gifts],
            'recent_users' => $recent
        ];
    } elseif ($tab === 'users') {
        $stmt = $pdo->query("
            SELECT u.*, tp.tablero_tipo as nivel 
            FROM usuarios u
            LEFT JOIN tableros_progreso tp ON u.id = tp.usuario_id AND tp.estado = 'en_progreso'
            ORDER BY u.id DESC
        ");
        $response['users'] = $stmt->fetchAll();
    } elseif ($tab === 'config') {
        $stmt = $pdo->query("SELECT clave, valor FROM configuracion");
        $config = [];
        while ($row = $stmt->fetch()) {
            $config[$row['clave']] = $row['valor'];
        }
        $response['config'] = $config;
    }

    sendResponse($response);

} catch (PDOException $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}
?>
