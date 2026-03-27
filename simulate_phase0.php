<?php
require_once __DIR__ . '/radix_api/config.php';

// Desactiva notificaciones Telegram durante simulaciones.
$_ENV['TELEGRAM_BOT_TOKEN'] = '';
putenv('TELEGRAM_BOT_TOKEN=');

require_once __DIR__ . '/radix_api/matrix_logic.php';

function simObtenerCicloActivoUsuario(PDO $pdo, int $usuario_id): int
{
    $stmt = $pdo->prepare("
        SELECT ciclo
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$usuario_id]);
    $ciclo = $stmt->fetchColumn();
    return $ciclo ? (int)$ciclo : 1;
}

function simObtenerWalletMaster(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT wallet_address FROM usuarios WHERE tipo_usuario = 'master' LIMIT 1");
    $wallet = $stmt->fetchColumn();
    return $wallet ?: RADIX_CENTRAL_WALLET;
}

function simResolverUbicacion(PDO $pdo, int $patrocinador_id): array
{
    $ciclo_red = simObtenerCicloActivoUsuario($pdo, $patrocinador_id);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE id_padre = ? AND ciclo = ?");
    $stmt->execute([$patrocinador_id, $ciclo_red]);
    $cuenta = (int)$stmt->fetchColumn();

    if ($cuenta < 3) {
        return [
            'padre_real_id' => $patrocinador_id,
            'ciclo_red' => $ciclo_red,
            'nivel' => 'directo',
        ];
    }

    $stmt = $pdo->prepare("
        SELECT r.id_hijo AS nuevo_patron_id
        FROM referidos r
        JOIN usuarios u ON r.id_hijo = u.id
        WHERE r.id_padre = ?
          AND r.ciclo = ?
          AND u.tipo_usuario = 'real'
          AND (
                SELECT COUNT(*)
                FROM referidos r2
                WHERE r2.id_padre = r.id_hijo
                  AND r2.ciclo = ?
          ) < 3
        ORDER BY r.posicion ASC
        LIMIT 1
    ");
    $stmt->execute([$patrocinador_id, $ciclo_red, $ciclo_red]);
    $nuevo_patron_id = $stmt->fetchColumn();

    if ($nuevo_patron_id) {
        return [
            'padre_real_id' => (int)$nuevo_patron_id,
            'ciclo_red' => $ciclo_red,
            'nivel' => 'spillover_n1',
        ];
    }

    $stmt = $pdo->prepare("
        SELECT r2.id_hijo AS nuevo_patron_id
        FROM referidos r1
        JOIN referidos r2 ON r2.id_padre = r1.id_hijo
        JOIN usuarios u1 ON r1.id_hijo = u1.id
        JOIN usuarios u2 ON r2.id_hijo = u2.id
        WHERE r1.id_padre = ?
          AND r1.ciclo = ?
          AND r2.ciclo = ?
          AND u1.tipo_usuario = 'real'
          AND u2.tipo_usuario = 'real'
          AND (
                SELECT COUNT(*)
                FROM referidos r3
                WHERE r3.id_padre = r2.id_hijo
                  AND r3.ciclo = ?
          ) < 3
        ORDER BY r1.posicion ASC, r2.posicion ASC
        LIMIT 1
    ");
    $stmt->execute([$patrocinador_id, $ciclo_red, $ciclo_red, $ciclo_red]);
    $nuevo_patron_n2 = $stmt->fetchColumn();

    if ($nuevo_patron_n2) {
        return [
            'padre_real_id' => (int)$nuevo_patron_n2,
            'ciclo_red' => $ciclo_red,
            'nivel' => 'spillover_n2',
        ];
    }

    throw new RuntimeException('La red del patrocinador ya esta llena en niveles 1 y 2 para este ciclo.');
}

function simCrearUsuarioPagado(PDO $pdo, int $patrocinador_id, string $tag, int $indice): array
{
    $resolucion = simResolverUbicacion($pdo, $patrocinador_id);
    $padre_real_id = $resolucion['padre_real_id'];
    $ciclo_red = $resolucion['ciclo_red'];

    $wallet_master = simObtenerWalletMaster($pdo);
    $wallet = sprintf('SIM_%s_%02d_%s', strtoupper($tag), $indice, substr(strtoupper(bin2hex(random_bytes(6))), 0, 12));
    $nickname = sprintf('SIM_%s_%02d', strtoupper($tag), $indice);
    $tx_hash = substr(hash('sha256', $wallet . '|' . microtime(true) . '|' . random_int(1000, 999999)), 0, 64);

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (
                wallet_address, nickname, tipo_usuario, telegram_chat_id,
                patrocinador_id, estado, credito_saldo, ip_registro
            ) VALUES (?, ?, 'real', NULL, ?, 'activo', 0.00, 'SIMULACION')
        ");
        $stmt->execute([$wallet, $nickname, $patrocinador_id]);
        $nuevo_usuario_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE id_padre = ? AND ciclo = ?");
        $stmt->execute([$padre_real_id, $ciclo_red]);
        $posicion = (int)$stmt->fetchColumn() + 1;

        $stmt = $pdo->prepare("
            INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red, ciclo)
            VALUES (?, ?, ?, 1, ?)
        ");
        $stmt->execute([$padre_real_id, $nuevo_usuario_id, $posicion, $ciclo_red]);

        $stmt = $pdo->prepare("
            INSERT INTO pagos (
                id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                tablero_tipo, ciclo, origen_fondos, monto, monto_pagado,
                hash_transaccion, tipo, estado, tx_hash, tx_hash_2,
                fecha_confirmacion, fecha_pago
            ) VALUES (
                ?, ?, ?, ?, 'A', 1, 'externo', 10.00, 10.00,
                NULL, 'regalo', 'completado', ?, NULL, NOW(), NOW()
            )
        ");
        $stmt->execute([$nuevo_usuario_id, $padre_real_id, $padre_real_id, $wallet_master, $tx_hash]);

        $stmt = $pdo->prepare("
            INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo, estado)
            VALUES (?, 'A', 1, 'en_progreso')
        ");
        $stmt->execute([$nuevo_usuario_id]);

        $stmt = $pdo->prepare("
            INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address)
            VALUES (?, 'SIMULACION_PAGO_CONFIRMADO', 'pagos', ?, 'SIMULACION')
        ");
        $stmt->execute([
            $nuevo_usuario_id,
            "Simulado: root={$patrocinador_id}, padre_real={$padre_real_id}, nivel={$resolucion['nivel']}, tx={$tx_hash}"
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    verificarAvanceTablero($padre_real_id, $pdo);

    return [
        'nuevo_usuario_id' => $nuevo_usuario_id,
        'nickname' => $nickname,
        'wallet' => $wallet,
        'padre_link_id' => $patrocinador_id,
        'padre_real_id' => $padre_real_id,
        'nivel' => $resolucion['nivel'],
        'ciclo_red' => $ciclo_red,
        'tx_hash' => $tx_hash,
    ];
}

function simResumenUsuario(PDO $pdo, int $usuario_id): array
{
    $stmt = $pdo->prepare("
        SELECT tablero_tipo, ciclo, estado
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
        LIMIT 3
    ");
    $stmt->execute([$usuario_id]);
    $tableros = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT r.id_hijo, r.posicion, r.ciclo, u.nickname
        FROM referidos r
        JOIN usuarios u ON u.id = r.id_hijo
        WHERE r.id_padre = ?
        ORDER BY r.ciclo DESC, r.posicion ASC
        LIMIT 12
    ");
    $stmt->execute([$usuario_id]);
    $referidos = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT tipo, monto, tablero_tipo, ciclo, estado, fecha_pago
        FROM pagos
        WHERE id_emisor = ? OR beneficiario_usuario_id = ?
        ORDER BY id DESC
        LIMIT 12
    ");
    $stmt->execute([$usuario_id, $usuario_id]);
    $pagos = $stmt->fetchAll();

    return [
        'tableros' => $tableros,
        'referidos' => $referidos,
        'pagos' => $pagos,
    ];
}

$root_id = isset($_REQUEST['root_id']) ? (int)$_REQUEST['root_id'] : 1001;
$count = isset($_REQUEST['count']) ? max(1, min(50, (int)$_REQUEST['count'])) : 3;
$tag = trim($_REQUEST['tag'] ?? 'fase0');
$run = ($_SERVER['REQUEST_METHOD'] === 'POST');
$resultados = [];
$errores = [];
$resumen = null;

if ($run) {
    try {
        $stmt = $pdo->prepare("SELECT id, nickname, tipo_usuario FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$root_id]);
        $root_user = $stmt->fetch();

        if (!$root_user) {
            throw new RuntimeException("No existe el usuario raiz ID {$root_id}.");
        }

        if (in_array($root_user['tipo_usuario'], ['master', 'sistema'], true)) {
            throw new RuntimeException('El usuario raiz de simulacion debe ser un usuario real, no master/sistema.');
        }

        for ($i = 1; $i <= $count; $i++) {
            $resultados[] = simCrearUsuarioPagado($pdo, $root_id, $tag, $i);
        }

        $resumen = simResumenUsuario($pdo, $root_id);
    } catch (Throwable $e) {
        $errores[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulador Fase 0 RADIX</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #0b1020;
            color: #eaf1ff;
            padding: 32px 18px;
        }
        .wrap {
            max-width: 1100px;
            margin: 0 auto;
        }
        .card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 18px;
            box-shadow: 0 18px 40px rgba(0,0,0,0.22);
        }
        h1, h2, h3 {
            margin-top: 0;
        }
        form {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr)) auto;
            gap: 12px;
            align-items: end;
        }
        label {
            display: grid;
            gap: 6px;
            font-size: 0.9rem;
            color: #b7c4ea;
        }
        input {
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 12px;
            padding: 12px;
            background: rgba(5,9,18,0.8);
            color: #fff;
        }
        button {
            border: 0;
            border-radius: 12px;
            padding: 12px 18px;
            background: linear-gradient(135deg, #00c2ff, #00e676);
            color: #04111d;
            font-weight: 700;
            cursor: pointer;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }
        th, td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            text-align: left;
        }
        .ok { color: #6cffb0; }
        .err { color: #ff8f8f; }
        .muted { color: #9fb0d5; }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        code {
            background: rgba(255,255,255,0.08);
            padding: 2px 6px;
            border-radius: 6px;
        }
        @media (max-width: 900px) {
            form, .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Simulador RADIX Fase 0</h1>
            <p class="muted">
                Este simulador crea usuarios de prueba ya pagados usando la logica actual de spillover,
                asignacion de beneficiario y avance de tableros. No usa Tron ni firma wallet.
                Las notificaciones Telegram quedan desactivadas dentro de esta simulacion.
            </p>
            <form method="post">
                <label>
                    ID raiz
                    <input type="number" name="root_id" value="<?php echo htmlspecialchars((string)$root_id, ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label>
                    Cantidad a crear
                    <input type="number" name="count" min="1" max="50" value="<?php echo htmlspecialchars((string)$count, ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label>
                    Tag
                    <input type="text" name="tag" value="<?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <button type="submit">Ejecutar simulacion</button>
            </form>
        </div>

        <?php if ($errores): ?>
            <div class="card">
                <h2 class="err">Errores</h2>
                <?php foreach ($errores as $error): ?>
                    <p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($resultados): ?>
            <div class="card">
                <h2 class="ok">Usuarios simulados creados</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID nuevo</th>
                            <th>Nickname</th>
                            <th>ID link</th>
                            <th>ID padre real</th>
                            <th>Modo</th>
                            <th>Ciclo red</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $fila): ?>
                            <tr>
                                <td><?php echo (int)$fila['nuevo_usuario_id']; ?></td>
                                <td><?php echo htmlspecialchars($fila['nickname'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int)$fila['padre_link_id']; ?></td>
                                <td><?php echo (int)$fila['padre_real_id']; ?></td>
                                <td><?php echo htmlspecialchars($fila['nivel'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int)$fila['ciclo_red']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($resumen): ?>
            <div class="grid">
                <div class="card">
                    <h3>Resumen del usuario raiz</h3>
                    <h4>Tableros</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Tablero</th>
                                <th>Ciclo</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resumen['tableros'] as $fila): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fila['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)$fila['ciclo']; ?></td>
                                    <td><?php echo htmlspecialchars($fila['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h3>Referidos recientes del usuario raiz</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Hijo</th>
                                <th>Posicion</th>
                                <th>Ciclo</th>
                                <th>Nickname</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resumen['referidos'] as $fila): ?>
                                <tr>
                                    <td><?php echo (int)$fila['id_hijo']; ?></td>
                                    <td><?php echo (int)$fila['posicion']; ?></td>
                                    <td><?php echo (int)$fila['ciclo']; ?></td>
                                    <td><?php echo htmlspecialchars($fila['nickname'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h3>Movimientos recientes vinculados al usuario raiz</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Monto</th>
                            <th>Tablero</th>
                            <th>Ciclo</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resumen['pagos'] as $fila): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fila['tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((float)$fila['monto'], 2); ?></td>
                                <td><?php echo htmlspecialchars((string)$fila['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int)$fila['ciclo']; ?></td>
                                <td><?php echo htmlspecialchars($fila['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$fila['fecha_pago'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Uso recomendado</h3>
            <p>1. Pon como raiz el ID de tu principal real de pruebas, por ejemplo <code>1001</code>.</p>
            <p>2. Corre primero <code>3</code> usuarios para completar nivel 1.</p>
            <p>3. Luego corre otros <code>9</code> para forzar spillover y avance.</p>
            <p>4. Revisa despues <code>tableros_progreso</code>, <code>referidos</code>, <code>pagos</code> y <code>reservas_tablero</code>.</p>
        </div>
    </div>
</body>
</html>
