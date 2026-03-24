<?php
require_once 'config.php';

// Endpoint para obtener información detallada del usuario para el Dashboard Premium
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wallet = $_GET['wallet'] ?? '';

    if (empty($wallet)) {
        sendResponse(['error' => 'La billetera es necesaria'], 400);
    }

    try {
        // 1. Datos básicos del usuario
        $stmt = $pdo->prepare("SELECT id, nickname, wallet_address, tipo_usuario, fecha_registro FROM usuarios WHERE wallet_address = ?");
        $stmt->execute([$wallet]);
        $user = $stmt->fetch();

        if (!$user) {
            sendResponse(['error' => 'Usuario no encontrado'], 404);
        }

        $user_id = $user['id'];

        // 2. Tablero actual y progreso visual
        $stmt = $pdo->prepare("SELECT tablero_tipo, ciclo FROM tableros_progreso WHERE usuario_id = ? AND estado = 'en_progreso' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $tablero = $stmt->fetch();
        $nivel_actual = $tablero ? $tablero['tablero_tipo'] : 'A';
        $ciclo_actual = $tablero ? $tablero['ciclo'] : 1;

        // 3. Contador de Clones Activos (Agentes IA creados por este usuario o para este usuario)
        // En RADIX, los clones son usuarios tipo 'clon' cuyo patrocinador es el usuario
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE patrocinador_id = ? AND tipo_usuario = 'clon'");
        $stmt->execute([$user_id]);
        $clones_count = $stmt->fetch()['total'] ?? 0;

        // 4. Referidos directos (Humanos)
        $stmt = $pdo->prepare("
            SELECT u.nickname, u.wallet_address, u.tipo_usuario as tipo, r.posicion 
            FROM referidos r
            JOIN usuarios u ON r.id_hijo = u.id
            WHERE r.id_padre = ? AND u.tipo_usuario = 'real'
            ORDER BY r.posicion ASC
        ");
        $stmt->execute([$user_id]);
        $referidos_reales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Cálculo de Ganancia NETA (Diagrama: $40 al final del ciclo)
        // Sumamos ganancias de tablero (10 en A, 20 en B, 120 en C = $150 total)
        $stmt = $pdo->prepare("SELECT SUM(monto) as total FROM pagos WHERE id_receptor = ? AND estado = 'completado' AND tipo = 'ganancia_tablero'");
        $stmt->execute([$user_id]);
        $total_ganado_bruto = $stmt->fetch()['total'] ?? 0;

        // Restamos deducciones automáticas (100 Fase 1, 10 Re-entrada)
        $stmt = $pdo->prepare("SELECT SUM(monto) as total FROM pagos WHERE id_emisor = ? AND estado = 'completado' AND tipo IN ('salto_fase_1', 'reentrada')");
        $stmt->execute([$user_id]);
        $total_deducciones = $stmt->fetch()['total'] ?? 0;

        $earnings_net = (float)$total_ganado_bruto - (float)$total_deducciones;

        sendResponse([
            'success' => true,
            'user' => [
                'nickname' => $user['nickname'],
                'wallet' => $user['wallet_address'],
                'tipo' => $user['tipo_usuario'],
                'nivel' => $nivel_actual,
                'ciclo' => $ciclo_actual,
                'fecha_registro' => $user['fecha_registro'],
                'clones_count' => (int)$clones_count
            ],
            'referidos' => $referidos_reales,
            'earnings' => (float)$earnings_net,
            'debug_info' => [
                'bruto' => $total_ganado_bruto,
                'deducciones' => $total_deducciones
            ]
        ]);

    } catch (PDOException $e) {
        sendResponse(['error' => 'Error en el servidor: ' . $e->getMessage()], 500);
    }
}
?>

