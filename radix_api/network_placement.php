<?php

function radixPlacementModeFromDepth(int $depth): string
{
    return $depth <= 0 ? 'directo' : 'spillover_n' . $depth;
}

function radixFindAvailablePlacement(PDO $pdo, int $patrocinador_id, int $fase_numero, int $ciclo): ?array
{
    $stmt = $pdo->prepare("
        WITH RECURSIVE red AS (
            SELECT u.id AS usuario_id,
                   0 AS profundidad,
                   CAST('000' AS CHAR(2048)) AS orden
            FROM usuarios u
            WHERE u.id = ?
              AND u.tipo_usuario = 'real'

            UNION ALL

            SELECT r.id_hijo AS usuario_id,
                   red.profundidad + 1 AS profundidad,
                   CONCAT(red.orden, '.', LPAD(r.posicion, 3, '0')) AS orden
            FROM red
            JOIN referidos r
              ON r.id_padre = red.usuario_id
             AND r.fase_numero = ?
             AND r.ciclo = ?
            JOIN usuarios u
              ON u.id = r.id_hijo
             AND u.tipo_usuario = 'real'
        )
        SELECT red.usuario_id AS padre_id,
               red.profundidad,
               COALESCE(hijos.cuenta, 0) AS hijos_actuales
        FROM red
        LEFT JOIN (
            SELECT id_padre, COUNT(*) AS cuenta
            FROM referidos
            WHERE fase_numero = ?
              AND ciclo = ?
            GROUP BY id_padre
        ) hijos
          ON hijos.id_padre = red.usuario_id
        WHERE COALESCE(hijos.cuenta, 0) < 3
        ORDER BY red.profundidad ASC, red.orden ASC
        LIMIT 1
    ");
    $stmt->execute([$patrocinador_id, $fase_numero, $ciclo, $fase_numero, $ciclo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $depth = (int)$row['profundidad'];
    $childrenCount = (int)$row['hijos_actuales'];

    return [
        'padre_id' => (int)$row['padre_id'],
        'fase_numero' => $fase_numero,
        'ciclo' => $ciclo,
        'posicion' => $childrenCount + 1,
        'depth' => $depth,
        'modo' => radixPlacementModeFromDepth($depth),
    ];
}
