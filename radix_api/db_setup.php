<?php
require_once 'config.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!tableExists($pdo, $table) || columnExists($pdo, $table, $column)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function ensureIndex(PDO $pdo, string $table, string $index, string $definition): void
{
    if (!tableExists($pdo, $table) || indexExists($pdo, $table, $index)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
}

function dropIndexIfExists(PDO $pdo, string $table, string $index): void
{
    if (!tableExists($pdo, $table) || !indexExists($pdo, $table, $index)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
}

try {
    // 1. Configuracion base del sistema y seguridad
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sistema_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clave VARCHAR(50) UNIQUE NOT NULL,
            valor_decimal DECIMAL(18,8) DEFAULT 0.00000000,
            valor_string TEXT DEFAULT NULL,
            ultima_actualizacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
    ");

    $pdo->exec("
        INSERT IGNORE INTO sistema_config (clave, valor_decimal)
        VALUES ('tesoreria_balance', 0.00000000)
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_intentos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            endpoint VARCHAR(30) NOT NULL DEFAULT 'admin',
            intentos TINYINT UNSIGNED NOT NULL DEFAULT 1,
            primer_fallo DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ultimo_fallo DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ip_endpoint (ip, endpoint)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 2. Usuarios
    if (tableExists($pdo, 'usuarios')) {
        ensureColumn($pdo, 'usuarios', 'tipo_usuario', "ENUM('real','clon','master','sistema','inactivo') NOT NULL DEFAULT 'real'");
        ensureColumn($pdo, 'usuarios', 'telegram_chat_id', "VARCHAR(30) NULL DEFAULT NULL");
        ensureColumn($pdo, 'usuarios', 'telegram_username', "VARCHAR(32) NULL DEFAULT NULL");
        ensureColumn($pdo, 'usuarios', 'credito_saldo', "DECIMAL(10,2) DEFAULT 0.00");

        $pdo->exec("
            ALTER TABLE `usuarios`
            MODIFY COLUMN `tipo_usuario`
            ENUM('real','clon','master','sistema','inactivo')
            NOT NULL DEFAULT 'real'
        ");
    }

    // 3. Progreso, red y auditoria por fase
    if (tableExists($pdo, 'tableros_progreso')) {
        ensureColumn($pdo, 'tableros_progreso', 'ciclo', "INT DEFAULT 1");
        ensureColumn($pdo, 'tableros_progreso', 'fase_numero', "INT NOT NULL DEFAULT 0");
        ensureIndex($pdo, 'tableros_progreso', 'idx_usuario_fase_estado', "KEY `idx_usuario_fase_estado` (`usuario_id`,`fase_numero`,`estado`)");
        ensureIndex($pdo, 'tableros_progreso', 'idx_fase_tablero_ciclo', "KEY `idx_fase_tablero_ciclo` (`fase_numero`,`tablero_tipo`,`ciclo`)");

        $pdo->exec("UPDATE `tableros_progreso` SET `fase_numero` = 0 WHERE `fase_numero` IS NULL");
    }

    if (tableExists($pdo, 'referidos')) {
        ensureColumn($pdo, 'referidos', 'fase_numero', "INT NOT NULL DEFAULT 0");

        $pdo->exec("UPDATE `referidos` SET `fase_numero` = 0 WHERE `fase_numero` IS NULL");

        dropIndexIfExists($pdo, 'referidos', 'unique_padre_posicion');
        dropIndexIfExists($pdo, 'referidos', 'unique_padre_hijo');
        dropIndexIfExists($pdo, 'referidos', 'unique_padre_hijo_ciclo');
        dropIndexIfExists($pdo, 'referidos', 'unique_padre_posicion_ciclo');

        ensureIndex($pdo, 'referidos', 'unique_padre_hijo_fase_ciclo', "UNIQUE KEY `unique_padre_hijo_fase_ciclo` (`id_padre`,`id_hijo`,`fase_numero`,`ciclo`)");
        ensureIndex($pdo, 'referidos', 'unique_padre_posicion_fase_ciclo', "UNIQUE KEY `unique_padre_posicion_fase_ciclo` (`id_padre`,`fase_numero`,`ciclo`,`posicion`)");
        ensureIndex($pdo, 'referidos', 'idx_padre_fase_ciclo', "KEY `idx_padre_fase_ciclo` (`id_padre`,`fase_numero`,`ciclo`)");
        ensureIndex($pdo, 'referidos', 'idx_hijo_fase_ciclo', "KEY `idx_hijo_fase_ciclo` (`id_hijo`,`fase_numero`,`ciclo`)");
    }

    if (tableExists($pdo, 'auditoria_logs')) {
        ensureColumn($pdo, 'auditoria_logs', 'fase_numero', "INT DEFAULT NULL");
        ensureIndex($pdo, 'auditoria_logs', 'idx_usuario_fase_fecha', "KEY `idx_usuario_fase_fecha` (`usuario_id`,`fase_numero`,`fecha`)");
    }

    // 4. Pagos: tipos actuales, flujo economico y pagos parciales
    if (tableExists($pdo, 'pagos')) {
        ensureColumn($pdo, 'pagos', 'fase_numero', "INT NOT NULL DEFAULT 0");
        ensureColumn($pdo, 'pagos', 'propietario_flujo', "ENUM('usuario','sistema') NOT NULL DEFAULT 'usuario'");
        ensureColumn($pdo, 'pagos', 'tx_hash', "VARCHAR(66) NULL DEFAULT NULL");
        ensureColumn($pdo, 'pagos', 'tx_hash_2', "VARCHAR(66) NULL DEFAULT NULL");
        ensureColumn($pdo, 'pagos', 'monto_pagado', "DECIMAL(10,2) DEFAULT 0.00");
        ensureColumn($pdo, 'pagos', 'fecha_confirmacion', "TIMESTAMP NULL DEFAULT NULL");

        $pdo->exec("
            ALTER TABLE `pagos`
            MODIFY COLUMN `tipo` ENUM(
                'regalo',
                'ganancia_tablero',
                'tesoreria_clon',
                'salto_fase_1',
                'salto_fase_2',
                'salto_fase_3',
                'utilidad_master',
                'reentrada'
            ) NOT NULL
        ");

        $pdo->exec("UPDATE `pagos` SET `fase_numero` = 0 WHERE `fase_numero` IS NULL");
        $pdo->exec("
            UPDATE `pagos`
            SET `propietario_flujo` = 'usuario'
            WHERE `propietario_flujo` IS NULL
               OR `propietario_flujo` NOT IN ('usuario', 'sistema')
        ");

        $pdo->exec("
            UPDATE `pagos` p
            JOIN `usuarios` u ON p.`id_emisor` = u.`id`
            SET p.`propietario_flujo` = 'sistema'
            WHERE u.`tipo_usuario` = 'clon'
        ");

        $pdo->exec("
            UPDATE `pagos`
            SET `propietario_flujo` = 'sistema'
            WHERE `tipo` IN ('tesoreria_clon')
               OR `id_emisor` IN (1000)
               OR `id_receptor` IN (1000)
        ");

        ensureIndex($pdo, 'pagos', 'tx_hash', "UNIQUE KEY `tx_hash` (`tx_hash`)");
        ensureIndex($pdo, 'pagos', 'uk_tx_hash_2', "UNIQUE KEY `uk_tx_hash_2` (`tx_hash_2`)");
        ensureIndex($pdo, 'pagos', 'idx_fase_tipo_estado', "KEY `idx_fase_tipo_estado` (`fase_numero`,`tipo`,`estado`)");
        ensureIndex($pdo, 'pagos', 'idx_usuario_fase_ciclo', "KEY `idx_usuario_fase_ciclo` (`id_emisor`,`fase_numero`,`ciclo`)");
        ensureIndex($pdo, 'pagos', 'idx_fase_propietario_tipo', "KEY `idx_fase_propietario_tipo` (`fase_numero`,`propietario_flujo`,`tipo`)");
        ensureIndex($pdo, 'pagos', 'idx_propietario_estado', "KEY `idx_propietario_estado` (`propietario_flujo`,`estado`)");
    }

    // 5. Reservas internas compatibles con multiphase
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reservas_tablero (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            fase_numero INT NOT NULL DEFAULT 0,
            propietario_flujo ENUM('usuario','sistema') NOT NULL DEFAULT 'usuario',
            desde_tablero ENUM('A','B','C') NOT NULL,
            hacia_destino VARCHAR(20) NOT NULL,
            ciclo_origen INT NOT NULL DEFAULT 1,
            ciclo_destino INT DEFAULT NULL,
            monto DECIMAL(10,2) NOT NULL,
            estado ENUM('reservado','usado','cancelado') NOT NULL DEFAULT 'reservado',
            detalle VARCHAR(255) DEFAULT NULL,
            fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_uso TIMESTAMP NULL DEFAULT NULL,
            KEY idx_usuario_estado (usuario_id, estado),
            KEY idx_ciclo (ciclo_origen, ciclo_destino),
            KEY idx_usuario_fase_destino (usuario_id, fase_numero, hacia_destino),
            KEY idx_usuario_propietario_estado (usuario_id, propietario_flujo, estado),
            CONSTRAINT fk_reservas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
    ");

    if (tableExists($pdo, 'reservas_tablero')) {
        ensureColumn($pdo, 'reservas_tablero', 'fase_numero', "INT NOT NULL DEFAULT 0");
        ensureColumn($pdo, 'reservas_tablero', 'propietario_flujo', "ENUM('usuario','sistema') NOT NULL DEFAULT 'usuario'");
        ensureIndex($pdo, 'reservas_tablero', 'idx_usuario_fase_destino', "KEY `idx_usuario_fase_destino` (`usuario_id`,`fase_numero`,`hacia_destino`)");
        ensureIndex($pdo, 'reservas_tablero', 'idx_usuario_propietario_estado', "KEY `idx_usuario_propietario_estado` (`usuario_id`,`propietario_flujo`,`estado`)");

        $pdo->exec("UPDATE `reservas_tablero` SET `fase_numero` = 0 WHERE `fase_numero` IS NULL");
        $pdo->exec("
            UPDATE `reservas_tablero`
            SET `propietario_flujo` = 'usuario'
            WHERE `propietario_flujo` IS NULL
               OR `propietario_flujo` NOT IN ('usuario', 'sistema')
        ");

        $pdo->exec("
            UPDATE `reservas_tablero` rt
            JOIN `usuarios` u ON rt.`usuario_id` = u.`id`
            SET rt.`propietario_flujo` = 'sistema'
            WHERE u.`tipo_usuario` = 'clon'
        ");
    }

    // 6. Retiros y tesoreria
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS retiros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            fase_numero TINYINT NOT NULL DEFAULT 0,
            monto DECIMAL(10,2) NOT NULL,
            wallet_destino VARCHAR(100) NOT NULL,
            estado ENUM('pendiente','procesado','rechazado') DEFAULT 'pendiente',
            fecha_solicitud TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_proceso TIMESTAMP NULL DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            KEY usuario_id (usuario_id),
            CONSTRAINT retiros_ibfk_1 FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
    ");

    if (tableExists($pdo, 'retiros')) {
        ensureColumn($pdo, 'retiros', 'fase_numero', "TINYINT NOT NULL DEFAULT 0");
        ensureIndex($pdo, 'retiros', 'usuario_id', "KEY `usuario_id` (`usuario_id`)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tesoreria_movimientos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo ENUM('ingreso','egreso') NOT NULL,
            monto DECIMAL(18,8) NOT NULL,
            motivo VARCHAR(255) DEFAULT NULL,
            relacion_id INT DEFAULT NULL,
            fecha TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
    ");

    if (tableExists($pdo, 'tesoreria_movimientos')) {
        $pdo->exec("
            ALTER TABLE `tesoreria_movimientos`
            MODIFY COLUMN `monto` DECIMAL(18,8) NOT NULL,
            MODIFY COLUMN `motivo` VARCHAR(255) DEFAULT NULL
        ");
    }

    // 7. Foundation multiphase completa
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fases_config (
            fase_numero INT NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            descripcion TEXT DEFAULT NULL,
            fase_siguiente INT DEFAULT NULL,
            activa TINYINT(1) NOT NULL DEFAULT 0,
            fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (fase_numero)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fases_tableros_config (
            id INT NOT NULL AUTO_INCREMENT,
            fase_numero INT NOT NULL,
            tablero_tipo ENUM('A','B','C') NOT NULL,
            monto_entrada DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ganancia_directa DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            aporte_tesoreria DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reserva_siguiente_tablero DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ganancia_bruta_cierre DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            semilla_siguiente_fase DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            monto_reentrada DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            clon_permitido TINYINT(1) NOT NULL DEFAULT 1,
            clon_monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            activa TINYINT(1) NOT NULL DEFAULT 1,
            fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_fase_tablero (fase_numero, tablero_tipo),
            KEY idx_fase_activa (fase_numero, activa),
            CONSTRAINT fk_fases_tableros_config_fase
                FOREIGN KEY (fase_numero) REFERENCES fases_config (fase_numero)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
    ");

    ensureIndex($pdo, 'fases_tableros_config', 'uk_fase_tablero', "UNIQUE KEY `uk_fase_tablero` (`fase_numero`,`tablero_tipo`)");
    ensureIndex($pdo, 'fases_tableros_config', 'idx_fase_activa', "KEY `idx_fase_activa` (`fase_numero`,`activa`)");

    $pdo->exec("
        INSERT INTO fases_config (fase_numero, nombre, descripcion, fase_siguiente, activa)
        VALUES
            (0, 'Fase 0', 'Fase actual operativa del sistema RADIX.', 1, 1),
            (1, 'Fase 1', 'Fase x10 basada en la semilla generada al cerrar la Fase 0.', 2, 1),
            (2, 'Fase 2', 'Fase futura preparada a nivel de estructura.', 3, 1),
            (3, 'Fase 3', 'Fase Platinum - Nivel Final. El ciclo cumbre del sistema RADIX.', NULL, 1)
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            descripcion = VALUES(descripcion),
            fase_siguiente = VALUES(fase_siguiente),
            activa = VALUES(activa)
    ");

    $pdo->exec("
        INSERT INTO fases_tableros_config (
            fase_numero, tablero_tipo, monto_entrada, ganancia_directa, aporte_tesoreria,
            reserva_siguiente_tablero, ganancia_bruta_cierre, semilla_siguiente_fase,
            monto_reentrada, clon_permitido, clon_monto, activa
        )
        VALUES
            (0, 'A', 10.00, 10.00, 10.00, 20.00, 0.00, 0.00, 0.00, 1, 10.00, 1),
            (0, 'B', 20.00, 20.00, 20.00, 40.00, 0.00, 0.00, 0.00, 1, 20.00, 1),
            (0, 'C', 40.00, 0.00, 40.00, 0.00, 120.00, 100.00, 10.00, 1, 40.00, 1),
            (1, 'A', 100.00, 100.00, 100.00, 200.00, 0.00, 0.00, 0.00, 1, 100.00, 1),
            (1, 'B', 200.00, 200.00, 200.00, 400.00, 0.00, 0.00, 0.00, 1, 200.00, 1),
            (1, 'C', 400.00, 0.00, 400.00, 0.00, 1200.00, 1000.00, 100.00, 1, 400.00, 1),
            (2, 'A', 1000.00, 1000.00, 1000.00, 2000.00, 0.00, 0.00, 0.00, 1, 1000.00, 1),
            (2, 'B', 2000.00, 2000.00, 2000.00, 4000.00, 0.00, 0.00, 0.00, 1, 2000.00, 1),
            (2, 'C', 4000.00, 0.00, 4000.00, 0.00, 12000.00, 10000.00, 1000.00, 1, 4000.00, 1),
            (3, 'A', 10000.00, 10000.00, 10000.00, 20000.00, 0.00, 0.00, 0.00, 1, 10000.00, 1),
            (3, 'B', 20000.00, 20000.00, 20000.00, 40000.00, 0.00, 0.00, 0.00, 1, 20000.00, 1),
            (3, 'C', 40000.00, 0.00, 40000.00, 0.00, 120000.00, 40000.00, 10000.00, 1, 40000.00, 1)
        ON DUPLICATE KEY UPDATE
            monto_entrada = VALUES(monto_entrada),
            ganancia_directa = VALUES(ganancia_directa),
            aporte_tesoreria = VALUES(aporte_tesoreria),
            reserva_siguiente_tablero = VALUES(reserva_siguiente_tablero),
            ganancia_bruta_cierre = VALUES(ganancia_bruta_cierre),
            semilla_siguiente_fase = VALUES(semilla_siguiente_fase),
            monto_reentrada = VALUES(monto_reentrada),
            clon_permitido = VALUES(clon_permitido),
            clon_monto = VALUES(clon_monto),
            activa = VALUES(activa)
    ");

    echo 'Base de datos actualizada correctamente para RADIX.';
} catch (PDOException $e) {
    echo 'Error actualizando base de datos: ' . $e->getMessage();
}
?>
