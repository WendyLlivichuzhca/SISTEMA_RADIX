<?php
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$wallet = trim($_GET['wallet'] ?? '');

if ($wallet === '') {
    sendResponse(['error' => 'Wallet requerida'], 400);
}

$nonceWallet = trim((string)($_SESSION['radix_nonce_wallet'] ?? ''));
$nonceExpira = (int)($_SESSION['radix_nonce_expira'] ?? 0);

if ($nonceWallet !== $wallet || $nonceExpira < time()) {
    sendResponse(['error' => 'Verificación previa requerida'], 401);
}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME IN ('nombre_completo', 'telefono', 'correo_electronico')
    ");
    $stmt->execute();
    $column_count = (int)$stmt->fetchColumn();

    if ($column_count < 3) {
        sendResponse([
            'success' => true,
            'has_contact_data' => false,
            'contact' => null,
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT nombre_completo, telefono, correo_electronico
        FROM usuarios
        WHERE wallet_address = ?
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendResponse([
            'success' => true,
            'has_contact_data' => false,
            'contact' => null,
        ]);
    }

    $nombre = trim((string)($user['nombre_completo'] ?? ''));
    $telefono = trim((string)($user['telefono'] ?? ''));
    $correo = trim((string)($user['correo_electronico'] ?? ''));

    $has_contact_data = ($nombre !== '' && $telefono !== '' && $correo !== '');

    sendResponse([
        'success' => true,
        'has_contact_data' => $has_contact_data,
        'contact' => $has_contact_data ? [
            'nombre_completo' => $nombre,
            'telefono' => $telefono,
            'correo_electronico' => $correo,
        ] : null,
    ]);
} catch (PDOException $e) {
    error_log("RADIX check_contact_fields ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
}
?>
