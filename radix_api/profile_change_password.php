<?php
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

function profilePasswordHasColumn(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    if (!profilePasswordHasColumn($pdo, 'password_hash')) {
        sendResponse(['error' => 'Falta habilitar contraseñas en la base de datos.'], 500);
    }

    $wallet = $_SESSION['radix_wallet'];
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($newPassword === '' || $confirmPassword === '') {
        sendResponse(['error' => 'Debes completar la nueva contraseña y su confirmación.'], 400);
    }

    if (strlen($newPassword) < 8) {
        sendResponse(['error' => 'La nueva contraseña debe tener al menos 8 caracteres.'], 400);
    }

    if ($newPassword !== $confirmPassword) {
        sendResponse(['error' => 'La confirmación no coincide con la nueva contraseña.'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT id, password_hash
        FROM usuarios
        WHERE wallet_address = ?
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendResponse(['error' => 'Usuario no encontrado.'], 404);
    }

    if (!empty($user['password_hash']) && !password_verify($currentPassword, $user['password_hash'])) {
        sendResponse(['error' => 'La contraseña actual es incorrecta.'], 401);
    }

    $stmt = $pdo->prepare("
        UPDATE usuarios
        SET password_hash = ?
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);

    sendResponse([
        'success' => true,
        'message' => 'Contraseña actualizada correctamente.',
    ]);
} catch (Throwable $e) {
    error_log('RADIX profile_change_password ERROR: ' . $e->getMessage());
    sendResponse(['error' => 'No se pudo actualizar la contraseña.'], 500);
}
