<?php
require_once 'config.php';

// Endpoint para el login de administradores
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';

    if (empty($user) || empty($pass)) {
        sendResponse(['error' => 'Usuario y contraseña requeridos'], 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM administradores WHERE usuario = ?");
        $stmt->execute([$user]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($pass, $admin['password_hash'])) {
            // Actualizar última conexión
            $stmt = $pdo->prepare("UPDATE administradores SET ultima_conexion = NOW() WHERE id = ?");
            $stmt->execute([$admin['id']]);

            // En una app real, usaríamos sesiones o JWT
            sendResponse([
                'success' => true,
                'message' => 'Login exitoso',
                'role' => $admin['rol'],
                'user' => $admin['usuario']
            ]);
        } else {
            sendResponse(['error' => 'Credenciales inválidas'], 401);
        }

    } catch (PDOException $e) {
        sendResponse(['error' => 'Error en el servidor: ' . $e->getMessage()], 500);
    }
}
?>
