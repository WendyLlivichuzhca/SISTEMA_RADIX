<?php
require_once 'config.php';

// Script de configuración inicial de Base de Datos para RADIX Phase 0
try {
    // 1. Crear tabla de configuración del sistema (Tesorería)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sistema_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clave VARCHAR(50) UNIQUE NOT NULL,
        valor_decimal DECIMAL(18, 8) DEFAULT 0.00,
        valor_string TEXT,
        ultima_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Inicializar balance de tesorería si no existe
    $pdo->exec("INSERT IGNORE INTO sistema_config (clave, valor_decimal) VALUES ('tesoreria_balance', 0.00)");

    // 2. Modificar tabla usuarios para soportar Clones
    // Intentar añadir la columna tipo_usuario
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN tipo_usuario ENUM('real', 'clon') DEFAULT 'real' AFTER nickname");
    } catch (Exception $e) {
        // La columna ya existe, ignoramos
    }

    // 3. Modificar tableros_progreso para soportar ciclos
    try {
        $pdo->exec("ALTER TABLE tableros_progreso ADD COLUMN ciclo INT DEFAULT 1 AFTER tablero_tipo");
    } catch (Exception $e) {
        // Ya existe
    }

    echo "✅ Base de datos actualizada correctamente para Phase 0.";

} catch (PDOException $e) {
    echo "❌ Error actualizando base de datos: " . $e->getMessage();
}
?>
