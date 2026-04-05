<?php
require_once 'radix_api/config.php';

echo "<h1>RADIX — ACTIVACIÓN DE INVERSIÓN SEMILLA</h1>";

try {
    // Verificar si ya existe el pago de inversión para evitar duplicados
    $stmt = $pdo->prepare("SELECT id FROM pagos WHERE id_emisor = 1 AND tipo = 'regalo' AND monto = 10.00 LIMIT 1");
    $stmt->execute();
    
    if ($stmt->fetch()) {
        echo "<h2 style='color:orange'>⚠️ Tu inversión de $10 ya estaba registrada.</h2>";
    } else {
        // Insertar el pago de inversión
        $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (1, 1000, 10.00, 'regalo', 'completado')");
        $stmt->execute();
        echo "<h2 style='color:green'>✅ ¡ÉXITO! Tu inversión semilla de $10.00 ha sido activada.</h2>";
    }

    echo "<p>Ahora ya puedes proceder con el pago de tus referidos en phpMyAdmin.</p>";
    echo "<a href='dashboard.php?wallet=0x6cfe4cae1f15d5788b0c16a09b30cc4b76917597'>Volver al Dashboard</a>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ ERROR AL ACTIVAR: " . $e->getMessage() . "</h2>";
}
?>
