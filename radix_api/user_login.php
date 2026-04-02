<?php
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Metodo no permitido'], 405);
}

$login = trim($_POST['login'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($login === '' || $password === '') {
    sendResponse(['error' => 'Ingresa tu correo, telefono o wallet y tu contrasena.'], 400);
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

    if (empty($columns['password_hash'])) {
        sendResponse(['error' => 'Falta habilitar el campo de contrasena en la base de datos.'], 500);
    }

    $displayNameSelect = !empty($columns['nombre_completo'])
        ? "COALESCE(NULLIF(nombre_completo, ''), nickname) AS display_name"
        : "nickname AS display_name";

    $where = ["wallet_address = ?"];
    $params = [$login];

    if (!empty($columns['correo_electronico'])) {
        $where[] = "LOWER(correo_electronico) = LOWER(?)";
        $params[] = $login;
    }
    if (!empty($columns['telefono'])) {
        $where[] = "telefono = ?";
        $params[] = $login;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            wallet_address,
            nickname,
            {$displayNameSelect},
            tipo_usuario,
            password_hash
        FROM usuarios
        WHERE " . implode(' OR ', $where) . "
        LIMIT 1
    ");
    $stmt->execute($params);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(['error' => 'No encontramos una cuenta con esos datos.'], 404);
    }

    if (empty($user['password_hash'])) {
        sendResponse(['error' => 'Tu cuenta aun no tiene contrasena configurada. Contacta a soporte para recuperarla.'], 400);
    }

    if (!password_verify($password, $user['password_hash'])) {
        sendResponse(['error' => 'Contrasena incorrecta.'], 401);
    }

    $_SESSION['radix_wallet'] = $user['wallet_address'];
    $_SESSION['radix_user_id'] = $user['id'];
    $_SESSION['radix_nickname'] = $user['display_name'] ?: $user['nickname'];
    $_SESSION['tipo_usuario'] = $user['tipo_usuario'];

    unset($_SESSION['radix_wallet_verificada'], $_SESSION['radix_verificada_at']);

    sendResponse(['success' => true]);
} catch (PDOException $e) {
    error_log("RADIX user_login ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
}
?>
