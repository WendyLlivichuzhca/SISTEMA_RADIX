<?php
require_once __DIR__ . '/radix_api/config.php';

$_ENV['TELEGRAM_BOT_TOKEN'] = '';
putenv('TELEGRAM_BOT_TOKEN=');

require_once __DIR__ . '/radix_api/matrix_logic.php';

$maintenance_key = $_GET['key'] ?? '';
define('RADIX_MAINTENANCE_KEY', $_ENV['RADIX_MAINTENANCE_KEY'] ?? 'radix_tools_2026');

if ($maintenance_key !== RADIX_MAINTENANCE_KEY) {
    http_response_code(403);
    die('<h2>403 - Acceso denegado.</h2><p>Usa: recheck_progress.php?key=' . htmlspecialchars(RADIX_MAINTENANCE_KEY, ENT_QUOTES, 'UTF-8') . '&user_id=1020</p>');
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$resultado = null;
$error = null;
$tableros = [];
$antes = null;
$despues = null;
$hubo_avance_real = false;

function obtenerTableroActivoUsuario(PDO $pdo, int $user_id)
{
    $stmt = $pdo->prepare("
        SELECT id, fase_numero, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
        FROM tableros_progreso
        WHERE usuario_id = ? AND estado = 'en_progreso'
        ORDER BY fase_numero DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

if ($user_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, nickname, tipo_usuario FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            throw new RuntimeException("No existe el usuario ID {$user_id}.");
        }

        $antes = obtenerTableroActivoUsuario($pdo, $user_id);
        $resultado = verificarAvanceTablero($user_id, $pdo);
        $despues = obtenerTableroActivoUsuario($pdo, $user_id);
        $hubo_avance_real = $antes !== $despues;

        $stmt = $pdo->prepare("
            SELECT id, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
            FROM tableros_progreso
            WHERE usuario_id = ?
            ORDER BY id DESC
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        $tableros = $stmt->fetchAll();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recheck Progress RADIX</title>
    <style>
        body { margin: 0; padding: 28px 18px; font-family: Arial, sans-serif; background: #0b1120; color: #edf3ff; }
        .wrap { max-width: 960px; margin: 0 auto; }
        .card { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px; padding: 20px; margin-bottom: 18px; }
        form { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: end; }
        label { display: grid; gap: 6px; }
        input { border: 1px solid rgba(255,255,255,0.14); border-radius: 12px; padding: 12px; background: rgba(5,9,18,0.8); color: #fff; }
        button { border: 0; border-radius: 12px; padding: 12px 18px; background: linear-gradient(135deg, #00c2ff, #00e676); color: #04111d; font-weight: 700; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.08); text-align: left; }
        .ok { color: #73ffb6; }
        .err { color: #ff9a9a; }
        .muted { color: #a7b7da; }
        code { background: rgba(255,255,255,0.08); padding: 2px 6px; border-radius: 6px; }
        @media (max-width: 640px) { form { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Recheck Progress RADIX</h1>
            <p class="muted">
                Esta herramienta vuelve a ejecutar <code>verificarAvanceTablero()</code> para un usuario concreto.
            </p>
            <form method="get">
                <label>
                    ID de usuario
                    <input type="number" name="user_id" value="<?php echo htmlspecialchars((string)$user_id, ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <button type="submit">Re-evaluar</button>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="card">
                <h2 class="err">Error</h2>
                <p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($user_id > 0 && !$error): ?>
            <div class="card">
                <h2 class="<?php echo $hubo_avance_real ? 'ok' : 'muted'; ?>">
                    Resultado: <?php echo $hubo_avance_real ? 'hubo avance real' : 'se evaluo, pero no hubo cambio'; ?>
                </h2>
                <p class="muted">
                    <?php if ($hubo_avance_real): ?>
                        El tablero activo del usuario cambio despues de ejecutar la verificacion.
                    <?php else: ?>
                        La funcion termino sin error, pero el usuario todavia no cumplia todas las condiciones para cambiar de tablero.
                    <?php endif; ?>
                </p>
                <p class="muted">
                    Antes:
                    <code><?php echo $antes ? htmlspecialchars($antes['tablero_tipo'] . '-C' . $antes['ciclo'] . ' (' . $antes['estado'] . ')', ENT_QUOTES, 'UTF-8') : 'sin tablero activo'; ?></code>
                    | Despues:
                    <code><?php echo $despues ? htmlspecialchars($despues['tablero_tipo'] . '-C' . $despues['ciclo'] . ' (' . $despues['estado'] . ')', ENT_QUOTES, 'UTF-8') : 'sin tablero activo'; ?></code>
                </p>
            </div>

            <div class="card">
                <h3>Tableros recientes del usuario</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tablero</th>
                            <th>Ciclo</th>
                            <th>Estado</th>
                            <th>Inicio</th>
                            <th>Fin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableros as $fila): ?>
                            <tr>
                                <td><?php echo (int)$fila['id']; ?></td>
                                <td><?php echo htmlspecialchars($fila['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int)$fila['ciclo']; ?></td>
                                <td><?php echo htmlspecialchars($fila['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$fila['fecha_inicio'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$fila['fecha_fin'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
