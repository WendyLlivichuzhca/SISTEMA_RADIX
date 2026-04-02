<?php
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Metodo no permitido'], 405);
}

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

function normalizeProfileTelegramUsername(string $value): string
{
    $value = preg_replace('/\s+/', '', trim($value));
    return ltrim($value, '@');
}

function profileTelegramUsernameIsValid(string $value): bool
{
    return $value === '' || (bool)preg_match('/^[A-Za-z0-9_]{5,32}$/', $value);
}

function profilePhoneIsValid(string $value): bool
{
    $digits = preg_replace('/\D+/', '', $value);
    $length = strlen($digits);
    return $length >= 7 && $length <= 20;
}

function profileUpdateHasColumn(PDO $pdo, string $column): bool
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
    $nombre = trim($_POST['nombre_completo'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo = trim($_POST['correo_electronico'] ?? '');
    $telegramUsername = normalizeProfileTelegramUsername((string)($_POST['telegram_username'] ?? ''));

    if ($nombre === '' || $telefono === '' || $correo === '') {
        sendResponse(['error' => 'Todos los campos son obligatorios.'], 400);
    }

    if (mb_strlen($nombre) < 3) {
        sendResponse(['error' => 'El nombre completo debe tener al menos 3 caracteres.'], 400);
    }

    if (!profilePhoneIsValid($telefono)) {
        sendResponse(['error' => 'El telefono no es valido.'], 400);
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'El correo electronico no es valido.'], 400);
    }

    if (!profileTelegramUsernameIsValid($telegramUsername)) {
        sendResponse(['error' => 'El usuario de Telegram no es valido. Usa solo letras, numeros o guion bajo, entre 5 y 32 caracteres.'], 400);
    }

    $hasNombre = profileUpdateHasColumn($pdo, 'nombre_completo');
    $hasTelefono = profileUpdateHasColumn($pdo, 'telefono');
    $hasCorreo = profileUpdateHasColumn($pdo, 'correo_electronico');
    $hasTelegramUsername = profileUpdateHasColumn($pdo, 'telegram_username');

    if (!$hasNombre || !$hasTelefono || !$hasCorreo) {
        sendResponse(['error' => 'Faltan columnas de perfil en la base de datos.'], 500);
    }

    if (!$hasTelegramUsername && $telegramUsername !== '') {
        sendResponse(['error' => 'Falta la columna telegram_username en la base de datos.'], 500);
    }

    $updateFields = [
        'nombre_completo = ?',
        'telefono = ?',
        'correo_electronico = ?',
    ];
    $updateValues = [$nombre, $telefono, $correo];

    if ($hasTelegramUsername) {
        $updateFields[] = 'telegram_username = ?';
        $updateValues[] = $telegramUsername === '' ? null : $telegramUsername;
    }

    $updateValues[] = $wallet;

    $stmt = $pdo->prepare("
        UPDATE usuarios
        SET " . implode(', ', $updateFields) . "
        WHERE wallet_address = ?
        LIMIT 1
    ");
    $stmt->execute($updateValues);

    sendResponse([
        'success' => true,
        'message' => 'Perfil actualizado correctamente.',
        'profile' => [
            'display_name' => $nombre,
            'nombre_completo' => $nombre,
            'telefono' => $telefono,
            'correo_electronico' => $correo,
            'telegram_username' => $hasTelegramUsername ? $telegramUsername : '',
        ],
    ]);
} catch (Throwable $e) {
    error_log('RADIX profile_update ERROR: ' . $e->getMessage());
    sendResponse(['error' => 'No se pudo actualizar el perfil.'], 500);
}
