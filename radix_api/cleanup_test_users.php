<?php
/**
 * cleanup_test_users.php
 * Elimina usuarios de prueba (patrocinador_id = NULL, excluyendo ID 1 y ID 1000).
 * IMPORTANTE: Ejecutar UNA SOLA VEZ antes del lanzamiento. Luego borrar este archivo del servidor.
 */
require_once 'radix_api/config.php';

// Usuarios protegidos que NUNCA se borran
$ids_protegidos = [1, 1000];
$placeholders   = implode(',', $ids_protegidos);

// Encontrar usuarios de prueba: tipo real, sin patrocinador, no protegidos
$stmt = $pdo->prepare("
    SELECT id, nickname, wallet_address, fecha_registro
    FROM usuarios
    WHERE patrocinador_id IS NULL
      AND tipo_usuario = 'real'
      AND id NOT IN ($placeholders)
    ORDER BY id ASC
");
$stmt->execute();
$test_users = $stmt->fetchAll();
$test_ids   = array_column($test_users, 'id');

$confirmar = isset($_POST['confirmar']) && $_POST['confirmar'] === 'SI_BORRAR';
$mensaje   = '';
$error     = '';

if ($confirmar && count($test_ids) > 0) {
    $in = implode(',', array_map('intval', $test_ids));
    try {
        $pdo->beginTransaction();

        // Borrar en orden correcto (respetando FK)
        $pdo->exec("DELETE FROM auditoria_logs     WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM tesoreria_movimientos WHERE relacion_id IN ($in)");
        $pdo->exec("DELETE FROM pagos              WHERE id_emisor IN ($in) OR id_receptor IN ($in)");
        $pdo->exec("DELETE FROM tableros_progreso  WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM referidos          WHERE id_padre IN ($in) OR id_hijo IN ($in)");
        $pdo->exec("DELETE FROM usuarios           WHERE id IN ($in)");

        $pdo->commit();
        $mensaje = "✅ Se eliminaron " . count($test_ids) . " usuarios de prueba correctamente. Ahora borra este archivo del servidor.";
        $test_users = []; // Vaciar lista tras el borrado
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "❌ Error durante la limpieza: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>RADIX — Limpieza de Datos de Prueba</title>
    <style>
        body { font-family: monospace; background: #0d0d0d; color: #e0e0e0; padding: 40px; max-width: 800px; margin: auto; }
        h1   { color: #ff6b35; }
        h2   { color: #ffd700; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th   { background: #1a1a2e; color: #ff6b35; padding: 10px; text-align: left; }
        td   { padding: 8px 10px; border-bottom: 1px solid #333; }
        .warn  { background: #2a1a00; border-left: 4px solid #ff6b35; padding: 15px; margin: 20px 0; }
        .ok    { background: #001a00; border-left: 4px solid #00ff88; padding: 15px; margin: 20px 0; }
        .err   { background: #1a0000; border-left: 4px solid #ff0000; padding: 15px; margin: 20px 0; }
        button { background: #ff0000; color: white; border: none; padding: 12px 30px; font-size: 16px; cursor: pointer; margin-top: 20px; border-radius: 4px; }
        button:hover { background: #cc0000; }
        .safe  { color: #00ff88; }
        .danger { color: #ff4444; }
    </style>
</head>
<body>

<h1>🧹 RADIX — Limpieza de Usuarios de Prueba</h1>

<?php if ($mensaje): ?>
    <div class="ok"><?= htmlspecialchars($mensaje) ?></div>
<?php elseif ($error): ?>
    <div class="err"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<h2>Usuarios PROTEGIDOS (nunca se borran)</h2>
<table>
    <tr><th>ID</th><th>Rol</th></tr>
    <tr><td>1</td><td class="safe">👑 Dueña / Admin principal</td></tr>
    <tr><td>1000</td><td class="safe">⚙️ SISTEMA_RADIX (motor interno)</td></tr>
</table>

<?php if (count($test_users) > 0): ?>
<h2>Usuarios de prueba detectados (se eliminarán)</h2>
<div class="warn">
    ⚠️ Se encontraron <strong><?= count($test_users) ?></strong> usuarios de prueba sin patrocinador.
    Junto con ellos se borrarán sus registros en: <strong>pagos, referidos, tableros_progreso, auditoria_logs, tesoreria_movimientos</strong>.
</div>
<table>
    <tr><th>ID</th><th>Nickname</th><th>Wallet</th><th>Fecha registro</th></tr>
    <?php foreach ($test_users as $u): ?>
    <tr>
        <td class="danger"><?= $u['id'] ?></td>
        <td><?= htmlspecialchars($u['nickname'] ?? '—') ?></td>
        <td><?= htmlspecialchars(substr($u['wallet_address'], 0, 20)) ?>...</td>
        <td><?= $u['fecha_registro'] ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<form method="POST" onsubmit="return confirm('¿Confirmas que quieres borrar estos ' + <?= count($test_users) ?> + ' usuarios de prueba? Esta acción NO se puede deshacer.');">
    <input type="hidden" name="confirmar" value="SI_BORRAR">
    <button type="submit">🗑️ Eliminar <?= count($test_users) ?> usuarios de prueba</button>
</form>

<?php elseif (!$mensaje): ?>
<div class="ok">✅ No se encontraron usuarios de prueba. La base de datos está limpia.</div>
<?php endif; ?>

<hr style="border-color:#333; margin-top:40px;">
<p style="color:#666; font-size:12px;">⚠️ Recuerda borrar este archivo del servidor después de usarlo: <code>cleanup_test_users.php</code></p>

</body>
</html>
