<?php
require_once __DIR__ . '/radix_api/config.php';

$_ENV['TELEGRAM_BOT_TOKEN'] = '';
putenv('TELEGRAM_BOT_TOKEN=');

require_once __DIR__ . '/radix_api/matrix_logic.php';

$maintenance_key = $_GET['key'] ?? '';
define('RADIX_MAINTENANCE_KEY', $_ENV['RADIX_MAINTENANCE_KEY'] ?? 'radix_tools_2026');

if ($maintenance_key !== RADIX_MAINTENANCE_KEY) {
    http_response_code(403);
    die('<h2>403 - Acceso denegado.</h2><p>Usa: repair_completed_payment.php?key=' . htmlspecialchars(RADIX_MAINTENANCE_KEY, ENT_QUOTES, 'UTF-8') . '&pago_id=42</p>');
}

function obtenerTableroActivo(PDO $pdo, int $usuario_id)
{
    $stmt = $pdo->prepare("
        SELECT id, fase_numero, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
        FROM tableros_progreso
        WHERE usuario_id = ? AND estado = 'en_progreso'
        ORDER BY fase_numero DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$usuario_id]);
    return $stmt->fetch();
}

function obtenerTablerosRecientes(PDO $pdo, int $usuario_id): array
{
    $stmt = $pdo->prepare("
        SELECT id, fase_numero, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll();
}

function formatearTablero($tablero): string
{
    if (!$tablero) {
        return 'sin tablero activo';
    }

    return sprintf(
        'ID %d | Fase %d | %s | Ciclo %d | %s',
        (int)$tablero['id'],
        (int)$tablero['fase_numero'],
        $tablero['tablero_tipo'],
        (int)$tablero['ciclo'],
        $tablero['estado']
    );
}

$pago_id = isset($_GET['pago_id']) ? (int)$_GET['pago_id'] : 0;
$error = null;
$resultado = null;

if ($pago_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.id_emisor,
                p.id_receptor,
                p.beneficiario_usuario_id,
                p.fase_numero,
                p.tablero_tipo,
                p.ciclo,
                p.monto,
                p.monto_pagado,
                p.estado,
                p.tipo,
                p.tx_hash,
                p.tx_hash_2,
                p.fecha_confirmacion,
                em.nickname AS emisor_nickname,
                rc.nickname AS receptor_nickname
            FROM pagos p
            INNER JOIN usuarios em ON em.id = p.id_emisor
            LEFT JOIN usuarios rc ON rc.id = COALESCE(p.beneficiario_usuario_id, p.id_receptor)
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$pago_id]);
        $pago = $stmt->fetch();

        if (!$pago) {
            throw new RuntimeException("No existe el pago ID {$pago_id}.");
        }

        if ($pago['tipo'] !== 'regalo') {
            throw new RuntimeException("Solo se pueden reparar pagos de tipo regalo.");
        }

        if ($pago['estado'] !== 'completado') {
            throw new RuntimeException("El pago {$pago_id} no esta completado. No se puede reparar este caso con esta herramienta.");
        }

        $usuario_pagador_id = (int)$pago['id_emisor'];
        $beneficiario_logico_id = (int)($pago['beneficiario_usuario_id'] ?: $pago['id_receptor']);
        $fase_numero = (int)($pago['fase_numero'] ?? 0);
        $ciclo = max(1, (int)($pago['ciclo'] ?? 1));
        $cambios = [];

        $antes_pagador = obtenerTableroActivo($pdo, $usuario_pagador_id);
        $antes_beneficiario = obtenerTableroActivo($pdo, $beneficiario_logico_id);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id
            FROM tableros_progreso
            WHERE usuario_id = ? AND fase_numero = ?
            LIMIT 1
        ");
        $stmt->execute([$usuario_pagador_id, $fase_numero]);
        $tablero_existente = $stmt->fetchColumn();

        if (!$tablero_existente) {
            $stmt = $pdo->prepare("
                INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado)
                VALUES (?, ?, 'A', ?, 'en_progreso')
            ");
            $stmt->execute([$usuario_pagador_id, $fase_numero, $ciclo]);
            $cambios[] = "Se creo el tablero A del pagador {$usuario_pagador_id}.";
        }

        $monto_esperado = (float)$pago['monto'];
        $monto_pagado = (float)$pago['monto_pagado'];
        $tiene_hash = !empty($pago['tx_hash']) || !empty($pago['tx_hash_2']);

        if ($tiene_hash && $monto_pagado + 0.001 < $monto_esperado) {
            $stmt = $pdo->prepare("
                UPDATE pagos
                SET monto_pagado = ?, fecha_confirmacion = COALESCE(fecha_confirmacion, NOW())
                WHERE id = ?
            ");
            $stmt->execute([$monto_esperado, $pago_id]);
            $cambios[] = "Se corrigio monto_pagado del pago {$pago_id} a $" . number_format($monto_esperado, 2) . '.';
        }

        $stmt = $pdo->prepare("
            INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
            VALUES (?, ?, 'REPARACION_PAGO_MANUAL', 'pagos', ?)
        ");
        $stmt->execute([
            $usuario_pagador_id,
            $fase_numero,
            "Reparacion manual del pago ID {$pago_id}. Beneficiario logico {$beneficiario_logico_id}.",
        ]);

        $pdo->commit();

        $avance_directo = verificarAvanceTablero($beneficiario_logico_id, $pdo);
        $avance_cadena = verificarCadenaAscendente($beneficiario_logico_id, $pdo);

        $despues_pagador = obtenerTableroActivo($pdo, $usuario_pagador_id);
        $despues_beneficiario = obtenerTableroActivo($pdo, $beneficiario_logico_id);

        $resultado = [
            'pago' => $pago,
            'cambios' => $cambios,
            'avance_directo' => $avance_directo,
            'avance_cadena' => $avance_cadena,
            'antes_pagador' => $antes_pagador,
            'despues_pagador' => $despues_pagador,
            'antes_beneficiario' => $antes_beneficiario,
            'despues_beneficiario' => $despues_beneficiario,
            'tableros_pagador' => obtenerTablerosRecientes($pdo, $usuario_pagador_id),
            'tableros_beneficiario' => obtenerTablerosRecientes($pdo, $beneficiario_logico_id),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repair Completed Payment RADIX</title>
    <style>
        body { margin: 0; padding: 28px 18px; font-family: Arial, sans-serif; background: #0b1120; color: #edf3ff; }
        .wrap { max-width: 1100px; margin: 0 auto; }
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
        ul { margin: 0; padding-left: 18px; }
        @media (max-width: 700px) { form { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Repair Completed Payment RADIX</h1>
            <p class="muted">
                Repara pagos <code>regalo</code> marcados como completados fuera del flujo normal y vuelve a disparar el avance del beneficiario.
            </p>
            <form method="get">
                <label>
                    ID de pago
                    <input type="number" name="pago_id" value="<?php echo htmlspecialchars((string)$pago_id, ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <button type="submit">Reparar</button>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="card">
                <h2 class="err">Error</h2>
                <p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($resultado): ?>
            <div class="card">
                <h2 class="ok">Reparacion ejecutada</h2>
                <p class="muted">
                    Pago ID <?php echo (int)$resultado['pago']['id']; ?>
                    | Emisor <?php echo (int)$resultado['pago']['id_emisor']; ?> (<?php echo htmlspecialchars($resultado['pago']['emisor_nickname'], ENT_QUOTES, 'UTF-8'); ?>)
                    | Beneficiario logico <?php echo (int)($resultado['pago']['beneficiario_usuario_id'] ?: $resultado['pago']['id_receptor']); ?> (<?php echo htmlspecialchars((string)$resultado['pago']['receptor_nickname'], ENT_QUOTES, 'UTF-8'); ?>)
                </p>
                <p class="muted">
                    Avance directo: <strong><?php echo $resultado['avance_directo'] ? 'SI' : 'NO'; ?></strong>
                    | Cadena ascendente: <strong><?php echo $resultado['avance_cadena'] ? 'SI' : 'NO'; ?></strong>
                </p>
                <?php if ($resultado['cambios']): ?>
                    <ul>
                        <?php foreach ($resultado['cambios'] as $cambio): ?>
                            <li><?php echo htmlspecialchars($cambio, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted">No hubo cambios estructurales; solo se re-disparo el motor del beneficiario.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Tablero del pagador</h3>
                <p class="muted">Antes: <?php echo htmlspecialchars(formatearTablero($resultado['antes_pagador']), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="muted">Despues: <?php echo htmlspecialchars(formatearTablero($resultado['despues_pagador']), ENT_QUOTES, 'UTF-8'); ?></p>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fase</th>
                            <th>Tablero</th>
                            <th>Ciclo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado['tableros_pagador'] as $fila): ?>
                            <tr>
                                <td><?php echo (int)$fila['id']; ?></td>
                                <td><?php echo (int)$fila['fase_numero']; ?></td>
                                <td><?php echo htmlspecialchars($fila['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int)$fila['ciclo']; ?></td>
                                <td><?php echo htmlspecialchars($fila['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Tablero del beneficiario</h3>
                <p class="muted">Antes: <?php echo htmlspecialchars(formatearTablero($resultado['antes_beneficiario']), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="muted">Despues: <?php echo htmlspecialchars(formatearTablero($resultado['despues_beneficiario']), ENT_QUOTES, 'UTF-8'); ?></p>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fase</th>
                            <th>Tablero</th>
                            <th>Ciclo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado['tableros_beneficiario'] as $fila): ?>
                            <tr>
                                <td><?php echo (int)$fila['id']; ?></td>
                                <td><?php echo (int)$fila['fase_numero']; ?></td>
                                <td><?php echo htmlspecialchars($fila['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int)$fila['ciclo']; ?></td>
                                <td><?php echo htmlspecialchars($fila['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
