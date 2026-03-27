<?php
require_once __DIR__ . '/radix_api/config.php';

$_ENV['TELEGRAM_BOT_TOKEN'] = '';
putenv('TELEGRAM_BOT_TOKEN=');

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$error = null;
$summary = null;
$children = [];

if ($userId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nickname, u.tipo_usuario, tp.tablero_tipo, tp.ciclo, tp.estado
            FROM usuarios u
            LEFT JOIN tableros_progreso tp
              ON tp.usuario_id = u.id
             AND tp.id = (
                    SELECT tp2.id
                    FROM tableros_progreso tp2
                    WHERE tp2.usuario_id = u.id
                    ORDER BY (tp2.estado = 'en_progreso') DESC, tp2.ciclo DESC, tp2.id DESC
                    LIMIT 1
             )
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $summary = $stmt->fetch();

        if (!$summary) {
            throw new RuntimeException("No existe el usuario {$userId}.");
        }

        $currentBoard = $summary['tablero_tipo'] ?? '';
        $currentCycle = (int)($summary['ciclo'] ?? 1);

        $stmt = $pdo->prepare("
            SELECT
                r.id_hijo,
                r.posicion,
                r.ciclo AS ciclo_red,
                u.nickname,
                u.tipo_usuario,
                tp.tablero_tipo,
                tp.ciclo AS ciclo_tablero,
                tp.estado,
                CASE
                    WHEN ? = 'A'
                         AND tp.ciclo >= ?
                         AND EXISTS (
                            SELECT 1
                            FROM pagos p
                            WHERE p.id_emisor = r.id_hijo
                              AND p.id_receptor = ?
                              AND p.estado = 'completado'
                              AND p.tipo = 'regalo'
                         )
                    THEN 1
                    WHEN ? = 'B'
                         AND (tp.tablero_tipo IN ('B','C') OR tp.ciclo > ?)
                         AND tp.ciclo >= ?
                    THEN 1
                    WHEN ? = 'C'
                         AND (tp.tablero_tipo = 'C' OR tp.ciclo > ?)
                         AND tp.ciclo >= ?
                    THEN 1
                    ELSE 0
                END AS califica
            FROM referidos r
            JOIN usuarios u ON u.id = r.id_hijo
            LEFT JOIN tableros_progreso tp
              ON tp.usuario_id = r.id_hijo
             AND tp.id = (
                    SELECT tp2.id
                    FROM tableros_progreso tp2
                    WHERE tp2.usuario_id = r.id_hijo
                    ORDER BY (tp2.estado = 'en_progreso') DESC, tp2.ciclo DESC, tp2.id DESC
                    LIMIT 1
             )
            WHERE r.id_padre = ?
              AND r.ciclo = ?
            ORDER BY r.posicion ASC
        ");
        $stmt->execute([
            $currentBoard, $currentCycle, $userId,
            $currentBoard, $currentCycle, $currentCycle,
            $currentBoard, $currentCycle, $currentCycle,
            $userId, $currentCycle
        ]);
        $children = $stmt->fetchAll();

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
    <title>Diagnóstico de Avance RADIX</title>
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
        .ok { color:#73ffb6; font-weight:700; }
        .bad { color:#ff9a9a; font-weight:700; }
        .muted { color:#a7b7da; }
        code { background:rgba(255,255,255,0.08); padding:2px 6px; border-radius:6px; }
        @media (max-width: 800px) { form { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Diagnóstico de Avance</h1>
            <p class="muted">Muestra exactamente cuáles hijos directos de un usuario sí están calificando para su avance actual.</p>
            <form method="get">
                <label>
                    ID de usuario
                    <input type="number" name="user_id" value="<?php echo htmlspecialchars((string)$userId, ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <button type="submit">Diagnosticar</button>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="card"><p class="bad"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p></div>
        <?php endif; ?>

        <?php if ($summary && !$error): ?>
            <div class="card">
                <h2>Usuario <?php echo (int)$summary['id']; ?> - <?php echo htmlspecialchars($summary['nickname'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="muted">
                    Tipo: <code><?php echo htmlspecialchars((string)$summary['tipo_usuario'], ENT_QUOTES, 'UTF-8'); ?></code>
                    | Tablero actual: <code><?php echo htmlspecialchars((string)$summary['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></code>
                    | Ciclo: <code><?php echo (int)($summary['ciclo'] ?? 0); ?></code>
                    | Estado: <code><?php echo htmlspecialchars((string)$summary['estado'], ENT_QUOTES, 'UTF-8'); ?></code>
                </p>
            </div>

            <div class="card">
                <h3>Hijos directos que cuentan para el avance</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Posición</th>
                            <th>ID hijo</th>
                            <th>Nickname</th>
                            <th>Tipo</th>
                            <th>Tablero hijo</th>
                            <th>Ciclo hijo</th>
                            <th>Estado hijo</th>
                            <th>¿Califica?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($children as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['posicion']; ?></td>
                                <td><?php echo (int)$row['id_hijo']; ?></td>
                                <td><?php echo htmlspecialchars($row['nickname'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['tipo_usuario'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['tablero_tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo $row['ciclo_tablero'] === null ? '-' : (int)$row['ciclo_tablero']; ?></td>
                                <td><?php echo htmlspecialchars((string)$row['estado'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="<?php echo ((int)$row['califica'] === 1) ? 'ok' : 'bad'; ?>">
                                    <?php echo ((int)$row['califica'] === 1) ? 'SI' : 'NO'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
