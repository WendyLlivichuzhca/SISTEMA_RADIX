<?php
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
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

    if ($nombre === '' || $telefono === '' || $correo === '') {
        sendResponse(['error' => 'Todos los campos son obligatorios.'], 400);
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'El correo electrónico no es válido.'], 400);
    }

    $hasNombre = profileUpdateHasColumn($pdo, 'nombre_completo');
    $hasTelefono = profileUpdateHasColumn($pdo, 'telefono');
    $hasCorreo = profileUpdateHasColumn($pdo, 'correo_electronico');

    if (!$hasNombre || !$hasTelefono || !$hasCorreo) {
        sendResponse(['error' => 'Faltan columnas de perfil en la base de datos.'], 500);
    }

    $stmt = $pdo->prepare("
        UPDATE usuarios
        SET nombre_completo = ?, telefono = ?, correo_electronico = ?
        WHERE wallet_address = ?
        LIMIT 1
    ");
    $stmt->execute([$nombre, $telefono, $correo, $wallet]);

    sendResponse([
        'success' => true,
        'message' => 'Perfil actualizado correctamente.',
        'profile' => [
            'display_name' => $nombre,
            'nombre_completo' => $nombre,
            'telefono' => $telefono,
            'correo_electronico' => $correo,
        ],
    ]);
} catch (Throwable $e) {
    error_log('RADIX profile_update ERROR: ' . $e->getMessage());
    sendResponse(['error' => 'No se pudo actualizar el perfil.'], 500);
}
