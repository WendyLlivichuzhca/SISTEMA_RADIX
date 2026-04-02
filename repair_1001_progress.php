<?php
require_once __DIR__ . '/radix_api/config.php';

$_ENV['TELEGRAM_BOT_TOKEN'] = '';
putenv('TELEGRAM_BOT_TOKEN=');

require_once __DIR__ . '/radix_api/matrix_logic.php';

$user_id = 1001;
$is_cli = php_sapi_name() === 'cli';
$lines = [];

function appendLine(&$lines, $text = '')
{
    $lines[] = $text;
}

function obtenerTableroActivo($pdo, $user_id)
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

function obtenerTablerosRecientes($pdo, $user_id)
{
    $stmt = $pdo->prepare("
        SELECT id, fase_numero, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function obtenerLogsRecientes($pdo, $user_id)
{
    $stmt = $pdo->prepare("
        SELECT id, accion, fecha
        FROM auditoria_logs
        WHERE usuario_id = ?
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

try {
    $stmt = $pdo->prepare("SELECT id, nickname, nombre_completo, tipo_usuario FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        throw new RuntimeException("No existe el usuario 1001.");
    }

    $antes = obtenerTableroActivo($pdo, $user_id);

    appendLine($lines, "Usuario: {$usuario['id']} | {$usuario['nickname']} | {$usuario['nombre_completo']}");
    appendLine($lines, "Tipo: {$usuario['tipo_usuario']}");

    if ($antes) {
        appendLine(
            $lines,
            "Tablero activo antes: ID {$antes['id']} | Fase {$antes['fase_numero']} | Tablero {$antes['tablero_tipo']} | Ciclo {$antes['ciclo']} | Estado {$antes['estado']}"
        );
    } else {
        appendLine($lines, "Tablero activo antes: no existe");
    }

    if ($antes && $antes['tablero_tipo'] === 'B') {
        $resultado = verificarAvanceTablero($user_id, $pdo);
        appendLine($lines, "Ejecucion de verificarAvanceTablero(1001): " . ($resultado ? 'OK' : 'SIN_CAMBIO'));
    } else {
        appendLine($lines, "No se ejecuto avance porque 1001 ya no esta en tablero B.");
    }

    $despues = obtenerTableroActivo($pdo, $user_id);
    if ($despues) {
        appendLine(
            $lines,
            "Tablero activo despues: ID {$despues['id']} | Fase {$despues['fase_numero']} | Tablero {$despues['tablero_tipo']} | Ciclo {$despues['ciclo']} | Estado {$despues['estado']}"
        );
    } else {
        appendLine($lines, "Tablero activo despues: no existe");
    }

    appendLine($lines);
    appendLine($lines, "Tableros recientes:");
    foreach (obtenerTablerosRecientes($pdo, $user_id) as $fila) {
        appendLine(
            $lines,
            "- ID {$fila['id']} | Fase {$fila['fase_numero']} | {$fila['tablero_tipo']} | Ciclo {$fila['ciclo']} | {$fila['estado']}"
        );
    }

    appendLine($lines);
    appendLine($lines, "Logs recientes:");
    foreach (obtenerLogsRecientes($pdo, $user_id) as $fila) {
        appendLine($lines, "- Log {$fila['id']} | {$fila['accion']} | {$fila['fecha']}");
    }
} catch (Throwable $e) {
    appendLine($lines, "ERROR: " . $e->getMessage());
}

if ($is_cli) {
    echo implode(PHP_EOL, $lines) . PHP_EOL;
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repair 1001 Progress</title>
    <style>
        body { margin: 0; padding: 24px; background: #0f172a; color: #e2e8f0; font-family: Arial, sans-serif; }
        .card { max-width: 980px; margin: 0 auto; background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 20px; }
        pre { white-space: pre-wrap; word-break: break-word; line-height: 1.5; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <pre><?php echo htmlspecialchars(implode(PHP_EOL, $lines), ENT_QUOTES, 'UTF-8'); ?></pre>
    </div>
</body>
</html>
