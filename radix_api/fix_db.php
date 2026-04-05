<?php
require_once 'radix_api/config.php';

echo "<h1>REPARADOR ESTRUCTURAL DE BASE DE DATOS</h1>";

try {
    // 1. Desactivar checks de llaves foráneas para poder modificar columnas
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    echo "✅ Claves foráneas desactivadas temporalmente.<br>";

    // 2. Modificar id_emisor para permitir NULL
    $pdo->exec("ALTER TABLE pagos MODIFY id_emisor int(11) NULL;");
    echo "✅ Columna 'id_emisor' ahora permite NULL.<br>";

    // 3. Modificar id_receptor para permitir NULL
    $pdo->exec("ALTER TABLE pagos MODIFY id_receptor int(11) NULL;");
    echo "✅ Columna 'id_receptor' ahora permite NULL.<br>";

    // 4. Reactivar checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "✅ Seguridad de base de datos reactivada.<br>";

    echo "<h2 style='color:green'>🎉 ¡ÉXITO! La base de datos ha sido reparada.</h2>";
    echo "<p>Vuelve a ejecutar <b>test_matrix.php</b> ahora.</p>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ ERROR EN REPARACIÓN: " . $e->getMessage() . "</h2>";
}
?>
