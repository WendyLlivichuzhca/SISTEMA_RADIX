<?php
require_once 'radix_api/config.php';

echo "<h1>DEPURACIÓN DE ESTADOS RADIX</h1>";

try {
    // 1. Estado del Usuario Maestro (ID 1)
    $stmt = $pdo->prepare("SELECT * FROM tableros_progreso WHERE usuario_id = 1 ORDER BY id DESC");
    $stmt->execute();
    $master_prog = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Usuario Maestro (ID 1):</h3><table border='1'><tr><th>Tablero</th><th>Ciclo</th><th>Estado</th></tr>";
    foreach($master_prog as $p) echo "<tr><td>{$p['tablero_tipo']}</td><td>{$p['ciclo']}</td><td>{$p['estado']}</td></tr>";
    echo "</table>";

    // 2. Estado de los Líderes
    $stmt = $pdo->prepare("SELECT u.id, u.nickname, t.tablero_tipo, t.ciclo, t.estado 
                          FROM usuarios u 
                          JOIN tableros_progreso t ON u.id = t.usuario_id 
                          WHERE u.id IN (SELECT id_hijo FROM referidos WHERE id_padre = 1)
                          AND t.estado = 'en_progreso'");
    $stmt->execute();
    $lideres_prog = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Líderes de la Dueña:</h3><table border='1'><tr><th>ID</th><th>Nick</th><th>Tablero Actual</th></tr>";
    foreach($lideres_prog as $l) echo "<tr><td>{$l['id']}</td><td>{$l['nickname']}</td><td>{$l['tablero_tipo']} (Ciclo {$l['ciclo']})</td></tr>";
    echo "</table>";

} catch (Exception $e) {
    echo $e->getMessage();
}
?>
