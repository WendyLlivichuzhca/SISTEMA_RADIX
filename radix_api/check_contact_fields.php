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
    $stmt = $pdo->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME IN ('nombre_completo', 'telefono', 'correo_electronico', 'password_hash')
    ");
    $columns = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

    $has_contact_columns = !empty($columns['nombre_completo']) && !empty($columns['telefono']) && !empty($columns['correo_electronico']);
    $has_password_column = !empty($columns['password_hash']);

    if (!$has_contact_columns && !$has_password_column) {
        sendResponse([
            'success' => true,
            'has_contact_data' => false,
            'has_password' => false,
            'supports_password_login' => false,
            'contact' => null,
        ]);
    }

    $selectParts = [];
    $selectParts[] = $has_contact_columns
        ? "nombre_completo, telefono, correo_electronico"
        : "'' AS nombre_completo, '' AS telefono, '' AS correo_electronico";
    $selectParts[] = $has_password_column
        ? "CASE WHEN password_hash IS NOT NULL AND password_hash <> '' THEN 1 ELSE 0 END AS has_password"
        : "0 AS has_password";

    $stmt = $pdo->prepare("
        SELECT " . implode(', ', $selectParts) . "
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
            'has_password' => false,
            'supports_password_login' => $has_password_column,
            'contact' => null,
        ]);
    }

    $nombre = trim((string)($user['nombre_completo'] ?? ''));
    $telefono = trim((string)($user['telefono'] ?? ''));
    $correo = trim((string)($user['correo_electronico'] ?? ''));

    $has_contact_data = ($nombre !== '' && $telefono !== '' && $correo !== '');
    $has_password = !empty($user['has_password']);

    sendResponse([
        'success' => true,
        'has_contact_data' => $has_contact_data,
        'has_password' => $has_password,
        'supports_password_login' => $has_password_column,
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
