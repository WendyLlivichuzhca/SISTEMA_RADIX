<?php
require_once 'radix_api/config.php';
require_once 'radix_api/matrix_logic.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>DIAGNÓSTICO RADIX - SALTO DE TABLERO</h1>";

$user_id = 1; // Tu ID de dueña

try {
    echo "<p>Cargando datos para el Usuario ID: 1...</p>";
    
    // 1. Verificar Tablero Actual
    $stmt = $pdo->prepare("SELECT id, tablero_tipo, estado FROM tableros_progreso WHERE usuario_id = ? AND estado = 'en_progreso'");
    $stmt->execute([$user_id]);
    $tablero = $stmt->fetch();
    
    if (!$tablero) {
        die("❌ ERROR: El usuario no tiene un tablero en progreso.");
    }
    echo "✅ Tablero Actual: " . $tablero['tablero_tipo'] . " (ID: " . $tablero['id'] . ")<br>";

    // 2. Ejecutar Conteo de Referidos (Simulando matrix_logic)
    $stmt = $pdo->prepare("
        SELECT r.id_hijo, p.monto, p.estado, p.tipo
        FROM referidos r
        JOIN pagos p ON r.id_hijo = p.id_emisor
        WHERE r.id_padre = ?
    ");
    $stmt->execute([$user_id]);
    $referidos_check = $stmt->fetchAll();
    
    echo "<h3>Análisis de Referidos (" . count($referidos_check) . " encontrados):</h3>";
    $completos = 0;
    foreach ($referidos_check as $r) {
        $check = ($r['monto'] >= 9.99 && $r['estado'] == 'completado' && $r['tipo'] == 'regalo');
        if($check) $completos++;
        echo "- Hijo ID: " . $r['id_hijo'] . " | Monto: " . $r['monto'] . " | Estado: " . $r['estado'] . " | Tipo: " . $r['tipo'] . " | ¿Válido?: " . ($check ? 'SÍ' : 'NO') . "<br>";
    }

    echo "<h4>Total Válidos: $completos / 3 necesarios.</h4>";

    if ($completos >= 3) {
        echo "<p>Iniciando proceso de SALTO MANUAL...</p>";
        $exito = verificarAvanceTablero($user_id, $pdo);
        if ($exito) {
            echo "<h2 style='color:green'>🎉 ¡ÉXITO! El motor financiero se ha activado y has saltado de tablero.</h2>";
        } else {
            echo "<h2 style='color:red'>❌ ERROR MISTERIOSO: verificarAvanceTablero devolvió FALSE. Revisar logs.</h2>";
        }
    } else {
        echo "<p style='color:orange'>⚠️ No tienes referidos suficientes o sus pagos no están 'completado'.</p>";
    }

} catch (Exception $e) {
    echo "<h2>❌ FALLO CRÍTICO: " . $e->getMessage() . "</h2>";
}
?>
