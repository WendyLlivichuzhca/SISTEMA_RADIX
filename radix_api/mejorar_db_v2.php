<?php
require_once 'radix_api/config.php';

echo "<h1>RADIX CORE V2.0 - MEJORA ESTRUCTURAL</h1>";

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 1. CREAR EL USUARIO SISTEMA (ID 1000)
    // Este usuario representa a la Tesorería y al Motor de Empresa
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = 1000");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO usuarios (id, wallet_address, nickname, tipo_usuario, estado) VALUES (1000, '0x0000_RADIX_SYSTEM_CORE', 'SISTEMA_RADIX', 'clon', 'activo')");
        $stmt->execute();
        echo "✅ Nodo Sistema (ID 1000) Creado.<br>";
    }

    // 2. NUEVA TABLA: MOVIMIENTOS DE TESORERÍA (Auditoría Contable)
    $sql_tesoreria = "CREATE TABLE IF NOT EXISTS `tesoreria_movimientos` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tipo` enum('ingreso','egreso') NOT NULL,
        `monto` decimal(18,8) NOT NULL,
        `motivo` varchar(255) DEFAULT NULL,
        `relacion_id` int(11) DEFAULT NULL,
        `fecha` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;";
    $pdo->exec($sql_tesoreria);
    echo "✅ Tabla 'tesoreria_movimientos' Creada.<br>";

    // 3. LIBERAR TIPOS DE PAGO (De ENUM a VARCHAR)
    // El ENUM es muy rígido. Cambiamos a VARCHAR(50) para que acepte cualquier tipo de ganancia sin errores.
    $pdo->exec("ALTER TABLE pagos MODIFY `tipo` VARCHAR(50) NOT NULL;");
    echo "✅ Columna 'tipo' de pagos liberada (VARCHAR(50)).<br>";

    // 4. REVERTIR NOT NULL EN PAGOS (Ahora que tenemos el ID 1000)
    // Primero limpiamos posibles nulos si los hubiera (ponerles 1000)
    $pdo->exec("UPDATE pagos SET id_emisor = 1000 WHERE id_emisor IS NULL;");
    $pdo->exec("UPDATE pagos SET id_receptor = 1000 WHERE id_receptor IS NULL;");

    $pdo->exec("ALTER TABLE pagos MODIFY id_emisor int(11) NOT NULL;");
    $pdo->exec("ALTER TABLE pagos MODIFY id_receptor int(11) NOT NULL;");
    echo "✅ Integridad de tabla 'pagos' Restaurada (NOT NULL).<br>";

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "<h2 style='color:green'>🎉 ¡ESTRUCTURA RADIX CORE V2.0 ACTIVADA!</h2>";
    echo "<p>Tu base de datos ahora es 100% robusta y profesional.</p>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ ERROR EN MEJORA: " . $e->getMessage() . "</h2>";
}
?>
