<?php
require_once 'config.php';

// Script de configuracion inicial de Base de Datos para RADIX.
try {
    // 1. Crear tabla de configuracion del sistema (tesoreria)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sistema_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clave VARCHAR(50) UNIQUE NOT NULL,
        valor_decimal DECIMAL(18, 8) DEFAULT 0.00,
        valor_string TEXT,
        ultima_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Inicializar balance de tesoreria si no existe
    $pdo->exec("INSERT IGNORE INTO sistema_config (clave, valor_decimal) VALUES ('tesoreria_balance', 0.00)");

    // 2. Modificar tabla usuarios para soportar clones
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN tipo_usuario ENUM('real', 'clon') DEFAULT 'real' AFTER nickname");
    } catch (Exception $e) {
        // La columna ya existe, ignoramos.
    }

    // 3. Modificar tableros_progreso para soportar ciclos
    try {
        $pdo->exec("ALTER TABLE tableros_progreso ADD COLUMN ciclo INT DEFAULT 1 AFTER tablero_tipo");
    } catch (Exception $e) {
        // Ya existe.
    }

    // 4. Agregar columnas de verificacion blockchain a pagos
    try {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN tx_hash VARCHAR(66) NULL UNIQUE AFTER estado");
    } catch (Exception $e) { /* Ya existe */ }

    try {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN fecha_confirmacion TIMESTAMP NULL AFTER tx_hash");
    } catch (Exception $e) { /* Ya existe */ }

    // 5. Modificar ENUM de pagos para incluir tipos nuevos
    try {
        $pdo->exec("ALTER TABLE pagos MODIFY COLUMN tipo ENUM('regalo','ganancia_tablero','tesoreria_clon','salto_fase_1','salto_fase_2','salto_fase_3','reentrada') NOT NULL");
    } catch (Exception $e) { /* Ya existe */ }

    // 6. Agregar columnas de Telegram a usuarios
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN telegram_chat_id VARCHAR(30) NULL DEFAULT NULL AFTER tipo_usuario");
    } catch (Exception $e) { /* Ya existe */ }

    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN telegram_username VARCHAR(32) NULL DEFAULT NULL AFTER telegram_chat_id");
    } catch (Exception $e) { /* Ya existe */ }

    // 7. Crear tabla retiros si no existe
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

    // 8. Ajustar llaves unicas de referidos para fases paralelas.
    //    Un usuario puede existir bajo el mismo padre en fases distintas.
    $referidos_keys_to_drop = [
        'unique_padre_posicion',
        'unique_padre_hijo',
        'unique_padre_hijo_ciclo',
        'unique_padre_posicion_ciclo',
    ];

    foreach ($referidos_keys_to_drop as $key_name) {
        try {
            $pdo->exec("ALTER TABLE referidos DROP INDEX $key_name");
        } catch (Exception $e) {
            // La llave no existe en esta instalacion, continuar.
        }
    }

    try {
        $pdo->exec("ALTER TABLE referidos ADD UNIQUE KEY unique_padre_hijo_fase_ciclo (id_padre, id_hijo, fase_numero, ciclo)");
    } catch (Exception $e) { /* Constraint ya existe */ }

    try {
        $pdo->exec("ALTER TABLE referidos ADD UNIQUE KEY unique_padre_posicion_fase_ciclo (id_padre, fase_numero, ciclo, posicion)");
    } catch (Exception $e) { /* Constraint ya existe */ }

    // 9. Crear tabla tesoreria_movimientos si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS tesoreria_movimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('ingreso','egreso') NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        motivo TEXT,
        relacion_id INT NULL,
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 10. Crear tabla reservas_tablero si no existe
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

    // 11. Activar Fase 1 para la apertura paralela controlada.
    try {
        $pdo->exec("UPDATE fases_config SET activa = CASE WHEN fase_numero = 1 THEN 1 ELSE activa END");
    } catch (Exception $e) {
        // fases_config podria no existir aun en instalaciones parciales.
    }

    echo "Base de datos actualizada correctamente para RADIX.";
} catch (PDOException $e) {
    echo "Error actualizando base de datos: " . $e->getMessage();
}
?>
