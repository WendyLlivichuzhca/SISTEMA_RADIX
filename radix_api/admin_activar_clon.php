<?php
/**
 * admin_activar_clon.php — RADIX Multi-Fase
 * Activa un Agente IA (clon) desde el panel admin.
 * Si se envía 'nickname', asigna el clon a ese usuario específico.
 * Si no se envía, el sistema elige automáticamente al más elegible.
 */
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'clon_logic.php';
requireAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$nickname = trim($_POST['nickname'] ?? '');

try {
    // Si viene nickname, activar el clon para ese usuario específico
    if (!empty($nickname)) {
        $resultado = intentarActivarClonParaUsuario($pdo, $nickname);
    } else {
        // Sin nickname → el sistema elige automáticamente
        $resultado = intentarActivarClon($pdo);
    }

    $exito = (stripos($resultado, 'activado') !== false || stripos($resultado, 'Clon') !== false);

    $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (1, 'CLON_MANUAL_ADMIN', 'usuarios', ?)")
        ->execute(["Activación manual por admin: $resultado"]);

    sendResponse([
        'success'   => $exito,
        'resultado' => $resultado,
    ]);

} catch (Exception $e) {
    sendResponse(['error' => $e->getMessage()], 500);
}
