<?php
require_once 'config.php';

// Endpoint para acciones administrativas (suspender, activar, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario_id = $_POST['id'] ?? null;
    $nuevo_estado = $_POST['estado'] ?? null;

    if (!$usuario_id || !$nuevo_estado) {
        sendResponse(['error' => 'Datos incompletos'], 400);
    }

    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevo_estado, $usuario_id]);

        // Log en auditoría
        $stmt = $pdo->prepare("INSERT INTO auditoria_logs (accion, tabla_afectada, detalles) VALUES ('CAMBIO_ESTADO_USUARIO', 'usuarios', ?)");
        $stmt->execute(["Usuario ID $usuario_id cambiado a $nuevo_estado"]);

        sendResponse(['success' => true]);
    } catch (PDOException $e) {
        sendResponse(['error' => $e->getMessage()], 500);
    }
}
?>
