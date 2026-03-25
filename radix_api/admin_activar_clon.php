<?php
/**
 * admin_activar_clon.php — RADIX Phase 0
 * Botón manual de emergencia para activar un clon desde el panel admin.
 * MEJORA #8: Panel admin completo con control de Tesorería.
 */
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'clon_logic.php';
requireAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

try {
    $resultado = intentarActivarClon($pdo);

    $exito = str_starts_with($resultado, '✅');

    // Log de auditoría con acción manual
    $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (1, 'CLON_MANUAL_ADMIN', 'usuarios', ?)")
        ->execute(["Activación manual por admin: $resultado"]);

    sendResponse([
        'success'   => $exito,
        'resultado' => $resultado,
    ]);

} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}
