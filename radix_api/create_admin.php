<?php
/**
 * create_admin.php — RADIX Phase 0
 * Script de emergencia para crear el primer usuario administrativo.
 * 
 * USO:
 *   1. Abre en el navegador: https://tu-dominio.com/radix_api/create_admin.php?user=ADMIN&pass=PASSWORD
 *   2. Una vez creado, BORRA este archivo inmediatamente.
 */
require_once 'config.php';

// Credenciales por defecto (puedes cambiarlas aquí o por la URL)
$user = $_GET['user'] ?? 'admin_radix';
$pass = $_GET['pass'] ?? 'Radix2026!';

if (empty($user) || empty($pass)) {
    die("<h2>Uso incorrecto</h2><p>Usa: create_admin.php?user=TU_USUARIO&pass=TU_CONTRASENA</p>");
}

try {
    // 1. Asegurar que la tabla existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS administradores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        rol ENUM('superadmin', 'soporte') DEFAULT 'soporte',
        ultima_conexion TIMESTAMP NULL
    )");

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    // 2. Insertar o actualizar admin
    $stmt = $pdo->prepare("INSERT INTO administradores (usuario, password_hash, rol) 
                           VALUES (?, ?, 'superadmin')
                           ON DUPLICATE KEY UPDATE password_hash = ?");
    $stmt->execute([$user, $hash, $hash]);

    echo "<h2>✅ Administrador creado/actualizado exitosamente</h2>";
    echo "<ul><li>Usuario: <b>$user</b></li><li>Rol: <b>superadmin</b></li></ul>";
    echo "<p style='color:red;'><b>⚠️ IMPORTANTE: Borra este archivo (create_admin.php) de tu servidor ahora mismo por seguridad.</b></p>";
    echo "<p><a href='../admin_login.php'>Ir al Login →</a></p>";

} catch (PDOException $e) {
    die("<h2>❌ Error</h2><p>" . $e->getMessage() . "</p>");
}
