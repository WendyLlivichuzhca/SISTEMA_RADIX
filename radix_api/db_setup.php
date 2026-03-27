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

    // 4. Agregar columnas de verificación blockchain a pagos
    try {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN tx_hash VARCHAR(66) NULL UNIQUE AFTER estado");
    } catch (Exception $e) { /* Ya existe */ }

    try {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN fecha_confirmacion TIMESTAMP NULL AFTER tx_hash");
    } catch (Exception $e) { /* Ya existe */ }

    // 5. Modificar ENUM de pagos para incluir tipos nuevos
    try {
        $pdo->exec("ALTER TABLE pagos MODIFY COLUMN tipo ENUM('regalo','ganancia_tablero','tesoreria_clon','salto_fase_1','reentrada') NOT NULL");
    } catch (Exception $e) { /* Ya existe */ }

    // 6. Agregar columna telegram_chat_id a usuarios (MEJORA #6)
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN telegram_chat_id VARCHAR(30) NULL DEFAULT NULL AFTER tipo_usuario");
    } catch (Exception $e) { /* Ya existe */ }

    // 7. Crear tabla retiros si no existe (MEJORA #4)
    $pdo->exec("CREATE TABLE IF NOT EXISTS retiros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        wallet_destino VARCHAR(100) NOT NULL,
        estado ENUM('pendiente','procesado','rechazado') DEFAULT 'pendiente',
        fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fecha_proceso TIMESTAMP NULL,
        notas TEXT,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");

    // 8b. Restricción única en referidos: un padre no puede tener dos hijos en la misma posición
    //     Protege contra race conditions en registros simultáneos (fraude de posición doble)
    try {
        $pdo->exec("ALTER TABLE referidos ADD UNIQUE KEY unique_padre_posicion (id_padre, posicion)");
    } catch (Exception $e) { /* Constraint ya existe */ }

    // 8c. Restricción única en referidos: un hijo no puede estar dos veces bajo el mismo padre
    try {
        $pdo->exec("ALTER TABLE referidos ADD UNIQUE KEY unique_padre_hijo (id_padre, id_hijo)");
    } catch (Exception $e) { /* Constraint ya existe */ }

    // 8. Crear tabla tesoreria_movimientos si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS tesoreria_movimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('ingreso','egreso') NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        motivo TEXT,
        relacion_id INT NULL,
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reservas_tablero (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        desde_tablero ENUM('A','B','C') NOT NULL,
        hacia_destino VARCHAR(20) NOT NULL,
        ciclo_origen INT NOT NULL DEFAULT 1,
        ciclo_destino INT NULL,
        monto DECIMAL(10,2) NOT NULL,
        estado ENUM('reservado','usado','cancelado') DEFAULT 'reservado',
        detalle VARCHAR(255) NULL,
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fecha_uso TIMESTAMP NULL DEFAULT NULL,
        KEY idx_usuario_estado (usuario_id, estado),
        KEY idx_ciclo (ciclo_origen, ciclo_destino),
        CONSTRAINT fk_reservas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");

    echo "✅ Base de datos actualizada correctamente para Phase 0.";

} catch (PDOException $e) {
    echo "❌ Error actualizando base de datos: " . $e->getMessage();
}
?>
