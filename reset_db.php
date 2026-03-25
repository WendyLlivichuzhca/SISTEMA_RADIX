<?php
require_once 'radix_api/config.php';

echo "<h1>RADIX SYSTEM RESET — INICIO DESDE CERO</h1>";

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 1. Limpiar Referidos (Borrar todo)
    $pdo->exec("TRUNCATE TABLE referidos;");
    echo "✅ Red de referidos limpiada.<br>";

    // 2. Limpiar Pagos (Borrar todo)
    $pdo->exec("TRUNCATE TABLE pagos;");
    echo "✅ Historial de pagos limpiado.<br>";

    // 3. Limpiar Tableros de Progreso (Borrar todo pero re-crear el Tablero A para ID 1)
    $pdo->exec("TRUNCATE TABLE tableros_progreso;");
    $pdo->exec("INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo, estado) VALUES (1, 'A', 1, 'en_progreso')");
    echo "✅ Niveles de progreso reiniciados (ID 1 en Tablero A Ciclo 1).<br>";

    // 4. Limpiar Movimientos de Tesorería y Logs
    $pdo->exec("TRUNCATE TABLE tesoreria_movimientos;");
    $pdo->exec("TRUNCATE TABLE auditoria_logs;");
    echo "✅ Auditoría y Tesorería reiniciadas.<br>";

    // 5. Reiniciar Balance de Tesorería a 0
    $pdo->exec("UPDATE sistema_config SET valor_decimal = 0 WHERE clave = 'tesoreria_balance';");
    echo "✅ Fondos de Clones puestos a $0.<br>";

    // 6. LIMPIAR USUARIOS (BORRAR TODO EXCEPTO ID 1 Y 1000)
    // Usamos el ID de la dueña (1) y el sistema (1000)
    $pdo->exec("DELETE FROM usuarios WHERE id NOT IN (1, 1000);");
    echo "✅ Usuarios de prueba eliminados (ID 1 y 1000 conservados).<br>";

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "<h2 style='color:green'>🎉 ¡ÉXITO! Tu sistema está como nuevo y listo para comenzar.</h2>";
    echo "<p>Vuelve a tu <b>Dashboard</b> y verás saldo $0 y Ciclo 1 puesto en Tablero A.</p>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ ERROR EN RESET: " . $e->getMessage() . "</h2>";
}
?>
