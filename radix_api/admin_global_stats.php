<?php
require_once 'config.php';

// admin_global_stats.php - API exclusiva para la dueña (RADIX Master Control)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // 1. Balance de Tesorería (Fondos para Clones)
        $stmt = $pdo->prepare("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
        $stmt->execute();
        $tesoreria = $stmt->fetch()['valor_decimal'] ?? 0;

        // 2. Conteo de Usuarios (Reales vs Clones)
        $stmt = $pdo->query("SELECT tipo_usuario, COUNT(*) as cuenta FROM usuarios GROUP BY tipo_usuario");
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $total_reales = $counts['real'] ?? 0;
        $total_clones = $counts['clon'] ?? 0;

        // 3. Fondos Acumulados para Fase 1 (Deducciones de $100)
        $stmt = $pdo->query("SELECT SUM(monto) as total FROM pagos WHERE tipo = 'salto_fase_1' AND estado = 'completado'");
        $fase1_pool = $stmt->fetch()['total'] ?? 0;

        // 4. Última actividad de Clones (Agente IA)
        $stmt = $pdo->query("SELECT details, created_at FROM auditoria_logs WHERE action = 'ACTIVACION_CLON' ORDER BY id DESC LIMIT 5");
        $logs_clones = $stmt->fetchAll();

        // 5. Ganancia de la Dueña (Cuenta ID #1 - Basado en Wallet de la dueña)
        // Nota: En producción, aquí se filtraría por el ID 1 o la wallet maestra.
        $stmt = $pdo->query("SELECT SUM(monto) as total FROM pagos WHERE id_receptor = 1 AND tipo = 'ganancia_tablero'");
        $master_earnings = $stmt->fetch()['total'] ?? 0;

        sendResponse([
            'success' => true,
            'tesoreria' => (float)$tesoreria,
            'usuarios' => [
                'reales' => (int)$total_reales,
                'clones' => (int)$total_clones,
                'total' => (int)($total_reales + $total_clones)
            ],
            'fase1_pool' => (float)$fase1_pool,
            'master_id1_earnings' => (float)$master_earnings,
            'logs' => $logs_clones
        ]);

    } catch (PDOException $e) {
        sendResponse(['error' => 'Error administrativo: ' . $e->getMessage()], 500);
    }
}
?>
