<?php
require_once __DIR__ . '/radix_api/config.php';

$_ENV['TELEGRAM_BOT_TOKEN'] = '';
putenv('TELEGRAM_BOT_TOKEN=');

require_once __DIR__ . '/radix_api/matrix_logic.php';

function srGetActiveCycle(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("
        SELECT ciclo
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $cycle = $stmt->fetchColumn();
    return $cycle ? (int)$cycle : 1;
}

function srGetCurrentBoard(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function srEnsureBoard(PDO $pdo, int $userId, string $board, int $cycle): void
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM tableros_progreso
        WHERE usuario_id = ? AND tablero_tipo = ? AND ciclo = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $board, $cycle]);
    if (!$stmt->fetchColumn()) {
        $stmt = $pdo->prepare("
            INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo, estado)
            VALUES (?, ?, ?, 'en_progreso')
        ");
        $stmt->execute([$userId, $board, $cycle]);
    }
}

function srGetChildren(PDO $pdo, int $parentId, int $cycle): array
{
    $stmt = $pdo->prepare("
        SELECT r.id_hijo, r.posicion, u.nickname, u.tipo_usuario
        FROM referidos r
        JOIN usuarios u ON u.id = r.id_hijo
        WHERE r.id_padre = ? AND r.ciclo = ?
        ORDER BY r.posicion ASC
    ");
    $stmt->execute([$parentId, $cycle]);
    return $stmt->fetchAll();
}

function srCreatePaidChild(PDO $pdo, int $parentId, string $tag, int $index): array
{
    $cycle = srGetActiveCycle($pdo, $parentId);
    $children = srGetChildren($pdo, $parentId, $cycle);
    $position = count($children) + 1;

    if ($position > 3) {
        throw new RuntimeException("El usuario {$parentId} ya tiene 3 hijos en ciclo {$cycle}.");
    }

    $stmt = $pdo->query("SELECT wallet_address FROM usuarios WHERE tipo_usuario = 'master' LIMIT 1");
    $walletMaster = $stmt->fetchColumn() ?: RADIX_CENTRAL_WALLET;

    $wallet = sprintf('SIMR_%s_%02d_%s', strtoupper($tag), $index, substr(strtoupper(bin2hex(random_bytes(6))), 0, 10));
    $nickname = sprintf('SIMR_%s_%02d', strtoupper($tag), $index);
    $txHash = substr(hash('sha256', $wallet . '|' . microtime(true) . '|' . random_int(1000, 999999)), 0, 64);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (
                wallet_address, nickname, tipo_usuario, patrocinador_id,
                estado, credito_saldo, ip_registro
            ) VALUES (?, ?, 'real', ?, 'activo', 0.00, 'SIM_REENTRY')
        ");
        $stmt->execute([$wallet, $nickname, $parentId]);
        $childId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red, ciclo)
            VALUES (?, ?, ?, 1, ?)
        ");
        $stmt->execute([$parentId, $childId, $position, $cycle]);

        $stmt = $pdo->prepare("
            INSERT INTO pagos (
                id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                tablero_tipo, ciclo, origen_fondos, monto, monto_pagado,
                tipo, estado, tx_hash, fecha_confirmacion, fecha_pago
            ) VALUES (?, ?, ?, ?, 'A', ?, 'externo', 10.00, 10.00, 'regalo', 'completado', ?, NOW(), NOW())
        ");
        $stmt->execute([$childId, $parentId, $parentId, $walletMaster, $cycle, $txHash]);

        srEnsureBoard($pdo, $childId, 'A', $cycle);

        $stmt = $pdo->prepare("
            INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address)
            VALUES (?, 'SIM_REENTRY_CHILD', 'usuarios', ?, 'SIM_REENTRY')
        ");
        $stmt->execute([$childId, "Padre {$parentId}, ciclo {$cycle}, tx {$txHash}"]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    verificarAvanceTablero($parentId, $pdo);

    return [
        'child_id' => $childId,
        'nickname' => $nickname,
        'parent_id' => $parentId,
        'cycle' => $cycle,
        'position' => $position,
    ];
}

function srGrowUserToB(PDO $pdo, int $userId, string $tag, array &$log): void
{
    $cycle = srGetActiveCycle($pdo, $userId);
    srEnsureBoard($pdo, $userId, 'A', $cycle);

    $children = srGetChildren($pdo, $userId, $cycle);
    $index = count($children) + 1;
    while (count($children) < 3) {
        $created = srCreatePaidChild($pdo, $userId, "{$tag}_P{$userId}", $index++);
        $log[] = "Creado hijo {$created['child_id']} bajo {$userId} para completar A.";
        $children = srGetChildren($pdo, $userId, $cycle);
    }

    verificarAvanceTablero($userId, $pdo);
    verificarAvanceTablero($userId, $pdo);
}

function srPromoteLeaderToC(PDO $pdo, int $leaderId, string $tag, array &$log): void
{
    $board = srGetCurrentBoard($pdo, $leaderId);
    if (!$board) {
        throw new RuntimeException("El líder {$leaderId} no tiene tablero actual.");
    }

    $cycle = (int)$board['ciclo'];
    $children = srGetChildren($pdo, $leaderId, $cycle);
    $index = count($children) + 1;

    while (count($children) < 3) {
        $created = srCreatePaidChild($pdo, $leaderId, "{$tag}_L{$leaderId}", $index++);
        $log[] = "Creado hijo {$created['child_id']} bajo líder {$leaderId}.";
        $children = srGetChildren($pdo, $leaderId, $cycle);
    }

    foreach ($children as $child) {
        srGrowUserToB($pdo, (int)$child['id_hijo'], "{$tag}_GC", $log);
    }

    verificarAvanceTablero($leaderId, $pdo);
    verificarAvanceTablero($leaderId, $pdo);
}

function srSummaries(PDO $pdo, int $rootId): array
{
    $stmt = $pdo->prepare("
        SELECT id, tablero_tipo, ciclo, estado, fecha_inicio, fecha_fin
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY id DESC
        LIMIT 20
    ");
    $stmt->execute([$rootId]);
    $boards = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT tipo, monto, tablero_tipo, ciclo, estado, fecha_pago
        FROM pagos
        WHERE id_emisor = ? OR beneficiario_usuario_id = ?
        ORDER BY id DESC
        LIMIT 30
    ");
    $stmt->execute([$rootId, $rootId]);
    $payments = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT desde_tablero, hacia_destino, ciclo_origen, ciclo_destino, monto, estado, fecha_uso
        FROM reservas_tablero
        WHERE usuario_id = ?
        ORDER BY id DESC
        LIMIT 30
    ");
    $stmt->execute([$rootId]);
    $reserves = $stmt->fetchAll();

    return [
        'boards' => $boards,
        'payments' => $payments,
        'reserves' => $reserves,
    ];
}

function srPromoteRootTowardReentry(PDO $pdo, int $rootId): array
{
    $log = [];
    $maxPasses = 5;

    for ($pass = 1; $pass <= $maxPasses; $pass++) {
        $rootCycle = srGetActiveCycle($pdo, $rootId);
        $leaders = srGetChildren($pdo, $rootId, $rootCycle);

        if (count($leaders) < 3) {
            throw new RuntimeException("El root {$rootId} necesita 3 hijos directos en ciclo {$rootCycle}.");
        }

        $log[] = "Pasada {$pass}: procesando líderes del root {$rootId}.";

        foreach ($leaders as $leader) {
            $leaderId = (int)$leader['id_hijo'];
            srPromoteLeaderToC($pdo, $leaderId, "ROOT{$rootId}_P{$pass}", $log);
            $leaderBoard = srGetCurrentBoard($pdo, $leaderId);
            $log[] = "Líder {$leaderId}: " . ($leaderBoard['tablero_tipo'] ?? '?') . " ciclo " . ($leaderBoard['ciclo'] ?? '?') . " estado " . ($leaderBoard['estado'] ?? '?') . ".";
        }

        verificarAvanceTablero($rootId, $pdo);
        verificarAvanceTablero($rootId, $pdo);
        $rootBoard = srGetCurrentBoard($pdo, $rootId);
        $log[] = "Root {$rootId}: " . ($rootBoard['tablero_tipo'] ?? '?') . " ciclo " . ($rootBoard['ciclo'] ?? '?') . " estado " . ($rootBoard['estado'] ?? '?') . ".";

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM tableros_progreso
            WHERE usuario_id = ? AND tablero_tipo = 'A' AND ciclo >= 2 AND estado = 'en_progreso'
        ");
        $stmt->execute([$rootId]);
        if ((int)$stmt->fetchColumn() > 0) {
            $log[] = "Reentrada detectada para el root {$rootId}.";
            break;
        }
    }

    return array_merge(['log' => $log], srSummaries($pdo, $rootId));
}

$rootId = isset($_REQUEST['root_id']) ? (int)$_REQUEST['root_id'] : 1001;
$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = srPromoteRootTowardReentry($pdo, $rootId);
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
    <title>Simular Reentrada RADIX</title>
    <style>
        body { margin:0; padding:28px 18px; font-family:Arial,sans-serif; background:#0b1120; color:#edf3ff; }
        .wrap { max-width:1100px; margin:0 auto; }
        .card { background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:18px; padding:20px; margin-bottom:18px; }
        form { display:grid; grid-template-columns:1fr auto; gap:12px; align-items:end; }
        label { display:grid; gap:6px; }
        input { border:1px solid rgba(255,255,255,0.14); border-radius:12px; padding:12px; background:rgba(5,9,18,0.8); color:#fff; }
        button { border:0; border-radius:12px; padding:12px 18px; background:linear-gradient(135deg,#00c2ff,#00e676); color:#04111d; font-weight:700; cursor:pointer; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px 12px; border-bottom:1px solid rgba(255,255,255,0.08); text-align:left; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .ok { color:#73ffb6; }
        .err { color:#ff9a9a; }
        .muted { color:#a7b7da; }
        code { background:rgba(255,255,255,0.08); padding:2px 6px; border-radius:6px; }
        @media (max-width:800px) { form, .grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Simular Cierre de C y Reentrada</h1>
            <p class="muted">
                Esta herramienta crea la profundidad faltante debajo de los líderes del root e intenta empujar
                automáticamente el cierre de <code>C</code> y la reentrada.
            </p>
            <form method="post">
                <label>
                    ID root
                    <input type="number" name="root_id" value="<?php echo htmlspecialchars((string)$rootId, ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <button type="submit">Simular profundidad</button>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="card">
                <h2 class="err">Error</h2>
                <p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="card">
                <h2 class="ok">Proceso ejecutado</h2>
                <?php foreach ($result['log'] as $line): ?>
                    <p><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endforeach; ?>
            </div>

            <div class="grid">
                <div class="card">
                    <h3>Tableros del root</h3>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Tablero</th><th>Ciclo</th><th>Estado</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['boards'] as $row): ?>
                                <tr>
                                    <td><?php echo (int)$row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)$row['ciclo']; ?></td>
                                    <td><?php echo htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h3>Pagos recientes del root</h3>
                    <table>
                        <thead>
                            <tr><th>Tipo</th><th>Monto</th><th>Tablero</th><th>Ciclo</th><th>Estado</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['payments'] as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo number_format((float)$row['monto'], 2); ?></td>
                                    <td><?php echo htmlspecialchars((string)$row['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)$row['ciclo']; ?></td>
                                    <td><?php echo htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h3>Reservas recientes del root</h3>
                <table>
                    <thead>
                        <tr><th>Desde</th><th>Hacia</th><th>Ciclo origen</th><th>Ciclo destino</th><th>Monto</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['reserves'] as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['desde_tablero'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['hacia_destino'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int)$row['ciclo_origen']; ?></td>
                                <td><?php echo $row['ciclo_destino'] === null ? '-' : (int)$row['ciclo_destino']; ?></td>
                                <td><?php echo number_format((float)$row['monto'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
