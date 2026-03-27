<?php
require_once __DIR__ . '/../config.php';

try {
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

    echo "OK: tabla reservas_tablero creada o ya existente.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
