<?php
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

function profileHasColumn(PDO $pdo, string $column): bool
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
    $wallet = $_SESSION['radix_wallet'];
    $hasNombre = profileHasColumn($pdo, 'nombre_completo');
    $hasTelefono = profileHasColumn($pdo, 'telefono');
    $hasCorreo = profileHasColumn($pdo, 'correo_electronico');
    $hasTelegramUsername = profileHasColumn($pdo, 'telegram_username');
    $hasPassword = profileHasColumn($pdo, 'password_hash');

    $nombreSelect = $hasNombre ? 'nombre_completo' : "'' AS nombre_completo";
    $telefonoSelect = $hasTelefono ? 'telefono' : "'' AS telefono";
    $correoSelect = $hasCorreo ? 'correo_electronico' : "'' AS correo_electronico";
    $telegramSelect = $hasTelegramUsername ? 'telegram_username' : "'' AS telegram_username";
    $passwordSelect = $hasPassword ? 'password_hash' : "'' AS password_hash";
    $displayNameSelect = $hasNombre
        ? "COALESCE(NULLIF(nombre_completo, ''), nickname) AS display_name"
        : "nickname AS display_name";

    $stmt = $pdo->prepare("
        SELECT
            id,
            nickname,
            wallet_address,
            {$displayNameSelect},
            {$nombreSelect},
            {$telefonoSelect},
            {$correoSelect},
            {$telegramSelect},
            {$passwordSelect}
        FROM usuarios
        WHERE wallet_address = ?
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendResponse(['error' => 'Usuario no encontrado'], 404);
    }

    sendResponse([
        'success' => true,
        'profile' => [
            'id' => (int)$user['id'],
            'nickname' => $user['nickname'],
            'display_name' => $user['display_name'] ?: $user['nickname'],
            'wallet' => $user['wallet_address'],
            'nombre_completo' => $user['nombre_completo'] ?? '',
            'telefono' => $user['telefono'] ?? '',
            'correo_electronico' => $user['correo_electronico'] ?? '',
            'telegram_username' => $user['telegram_username'] ?? '',
            'has_password' => !empty($user['password_hash']),
        ],
    ]);
} catch (Throwable $e) {
    error_log('RADIX profile_get ERROR: ' . $e->getMessage());
    sendResponse(['error' => 'No se pudo cargar el perfil.'], 500);
}
