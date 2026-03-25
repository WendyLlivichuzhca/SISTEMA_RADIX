<?php
require_once 'radix_api/config.php';
require_once 'radix_api/matrix_logic.php';

echo "<h1>RADIX — SIMULADOR DE SALTO AL TABLERO C</h1>";

try {
    // 1. Obtener los 3 líderes reales del usuario 1
    $stmt = $pdo->prepare("SELECT id, nickname FROM usuarios WHERE id IN (SELECT id_hijo FROM referidos WHERE id_padre = 1) AND tipo_usuario = 'real' LIMIT 3");
    $stmt->execute();
    $lideres = $stmt->fetchAll();

    if (count($lideres) < 3) {
        die("<h2 style='color:red;'>Error: Necesitas tener 3 referidos registrados primero.</h2>");
    }

    echo "<h3>Líderes detectados:</h3><ul>";
    foreach ($lideres as $l) echo "<li>ID: {$l['id']} - {$l['nickname']}</li>";
    echo "</ul>";

    // 2. Para cada líder, crear 3 referidos de prueba (Nivel 2 de la red)
    foreach ($lideres as $lider) {
        $lider_id = $lider['id'];
        $lider_nick = $lider['nickname'];
        
        echo "<hr><h4>Procesando red para: $lider_nick</h4>";

        for ($i = 1; $i <= 3; $i++) {
            $test_wallet = "0xTEST_C_" . substr(md5($lider_id . $i . time()), 0, 8);
            $test_nick = $lider_nick . "_Sub_" . $i;

            // Registrar usuario si no existe
            $stmt = $pdo->prepare("INSERT INTO usuarios (wallet_address, nickname, tipo_usuario, estado) VALUES (?, ?, 'real', 'activo') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $stmt->execute([$test_wallet, $test_nick]);
            $hijo_id = $pdo->lastInsertId();

            // Vincular en red
            $stmt = $pdo->prepare("INSERT IGNORE INTO referidos (id_padre, id_hijo, posicion) VALUES (?, ?, ?)");
            $stmt->execute([$lider_id, $hijo_id, $i]);

            // Registrar PAGO COMPLETADO de $10 al líder
            $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, ?, 10.00, 'regalo', 'completado')");
            $stmt->execute([$hijo_id, $lider_id]);

            echo "✅ Creado referido $test_nick -> Pagó $10 a $lider_nick.<br>";
        }

        // 3. Verificar avance del líder (Esto lo saltará al Tablero B)
        verificarAvanceTablero($lider_id, $pdo);
        echo "⭐️ <b>$lider_nick</b> ha saltado al Tablero B.<br>";
    }

    // 4. Finalmente, verificar avance del usuario principal (Esto lo saltará al Tablero C)
    verificarAvanceTablero(1, $pdo);

    echo "<h2 style='color:green'>🎉 ¡TODO LISTO!</h2>";
    echo "<p>Tus 3 líderes ya están en el Tablero B. <b>Tu cuenta acaba de saltar al Tablero C.</b></p>";
    echo "<a href='dashboard.php?wallet=0x6cfe4cae1f15d5788b0c16a09b30cc4b76917597'>VER MI DASHBOARD ACTUALIZADO</a>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ ERROR EN SIMUIACIÓN: " . $e->getMessage() . "</h2>";
}
?>
