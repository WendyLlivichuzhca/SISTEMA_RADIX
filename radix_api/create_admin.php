<?php
require_once 'config.php';

// Script temporal para crear el primer administrador
$user = 'admin_radix';
$pass = 'Admin2026!'; // Cambia esto por algo más seguro después
$hash = password_hash($pass, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("INSERT INTO administradores (usuario, password_hash, rol) VALUES (?, ?, 'superadmin')");
    $stmt->execute([$user, $hash]);
    echo "¡Administrador creado con éxito!\n";
    echo "Usuario: $user\nContraseña: $pass\n";
    echo "\nBORRA ESTE ARCHIVO DESPUÉS DE USARLO POR SEGURIDAD.";
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo "El usuario administrador ya existe.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>
