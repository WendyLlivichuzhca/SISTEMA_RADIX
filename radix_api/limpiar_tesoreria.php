<?php
// limpiar_tesoreria.php — RADIX FIX
require_once 'radix_api/config.php';

session_start();

// Verificación de seguridad rápida (Master ID 1)
if (empty($_SESSION['radix_wallet'])) {
    die("❌ Error: Sesión no iniciada. Por favor, entra al dashboard primero.");
}

$user_wallet = $_SESSION['radix_wallet'];
$stmt = $pdo->prepare("SELECT id, tipo_usuario FROM usuarios WHERE wallet_address = ?");
$stmt->execute([$user_wallet]);
$user = $stmt->fetch();

if (!$user || $user['tipo_usuario'] !== 'master') {
    die("❌ Error: Acceso denegado. Este script solo puede ser ejecutado por la cuenta Master.");
}

try {
    // 1. Poner balance a 0 en la configuración
    $pdo->exec("UPDATE sistema_config SET valor_decimal = 0 WHERE clave = 'tesoreria_balance'");
    
    // 2. Limpiar el historial (Libro Mayor)
    $pdo->exec("TRUNCATE TABLE tesoreria_movimientos");
    
    // 3. Confirmación visual
    echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'>";
    echo "<div style='font-size:4rem;'>✅</div>";
    echo "<h1 style='color:#00e676; margin:20px 0;'>Tesorería Reiniciada</h1>";
    echo "<p style='color:#888; font-size:1.1rem;'>El balance ha sido puesto en <b>$0.00</b> y el historial se ha limpiado.</p>";
    echo "<a href='dashboard.php' style='display:inline-block; margin-top:30px; background:#9d00ff; color:#fff; text-decoration:none; padding:12px 24px; border-radius:12px; font-weight:800; box-shadow:0 4px 15px rgba(157,0,255,0.3); transition:0.2s;'>VOLVER AL DASHBOARD</a>";
    echo "</div>";
} catch (Exception $e) {
    echo "<h1 style='color:red;'>❌ Error en la base de datos</h1>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>
