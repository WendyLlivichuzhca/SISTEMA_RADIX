<?php
require_once __DIR__ . '/config.php';

function radixDefaultPhaseConfig(int $fase_numero): array
{
    $defaults = [
        0 => [
            'fase_numero' => 0,
            'nombre' => 'Fase 0',
            'descripcion' => 'Fase actual operativa del sistema RADIX.',
            'fase_siguiente' => 1,
            'activa' => 1,
        ],
        1 => [
            'fase_numero' => 1,
            'nombre' => 'Fase 1',
            'descripcion' => 'Fase x10 basada en la semilla generada al cerrar la Fase 0.',
            'fase_siguiente' => 2,
            'activa' => 0,
        ],
        2 => [
            'fase_numero' => 2,
            'nombre' => 'Fase 2',
            'descripcion' => 'Fase futura preparada a nivel de estructura.',
            'fase_siguiente' => 3,
            'activa' => 0,
        ],
        3 => [
            'fase_numero' => 3,
            'nombre' => 'Fase 3',
            'descripcion' => 'Fase futura preparada a nivel de estructura.',
            'fase_siguiente' => null,
            'activa' => 0,
        ],
    ];

    return $defaults[$fase_numero] ?? [
        'fase_numero' => $fase_numero,
        'nombre' => 'Fase ' . $fase_numero,
        'descripcion' => null,
        'fase_siguiente' => null,
        'activa' => 0,
    ];
}

function radixDefaultPhaseBoardConfig(int $fase_numero, string $tablero_tipo): array
{
    $tablero_tipo = strtoupper(trim($tablero_tipo));

    $defaults = [
        0 => [
            'A' => [
                'monto_entrada' => 10.00,
                'ganancia_directa' => 10.00,
                'aporte_tesoreria' => 10.00,
                'reserva_siguiente_tablero' => 20.00,
                'ganancia_bruta_cierre' => 0.00,
                'semilla_siguiente_fase' => 0.00,
                'monto_reentrada' => 0.00,
                'clon_permitido' => 1,
                'clon_monto' => 10.00,
            ],
            'B' => [
                'monto_entrada' => 20.00,
                'ganancia_directa' => 20.00,
                'aporte_tesoreria' => 20.00,
                'reserva_siguiente_tablero' => 40.00,
                'ganancia_bruta_cierre' => 0.00,
                'semilla_siguiente_fase' => 0.00,
                'monto_reentrada' => 0.00,
                'clon_permitido' => 1,
                'clon_monto' => 20.00,
            ],
            'C' => [
                'monto_entrada' => 40.00,
                'ganancia_directa' => 0.00,
                'aporte_tesoreria' => 40.00,
                'reserva_siguiente_tablero' => 0.00,
                'ganancia_bruta_cierre' => 120.00,
                'semilla_siguiente_fase' => 100.00,
                'monto_reentrada' => 10.00,
                'clon_permitido' => 1,
                'clon_monto' => 40.00,
            ],
        ],
        1 => [
            'A' => [
                'monto_entrada' => 100.00,
                'ganancia_directa' => 100.00,
                'aporte_tesoreria' => 100.00,
                'reserva_siguiente_tablero' => 200.00,
                'ganancia_bruta_cierre' => 0.00,
                'semilla_siguiente_fase' => 0.00,
                'monto_reentrada' => 0.00,
                'clon_permitido' => 1,
                'clon_monto' => 100.00,
            ],
            'B' => [
                'monto_entrada' => 200.00,
                'ganancia_directa' => 200.00,
                'aporte_tesoreria' => 200.00,
                'reserva_siguiente_tablero' => 400.00,
                'ganancia_bruta_cierre' => 0.00,
                'semilla_siguiente_fase' => 0.00,
                'monto_reentrada' => 0.00,
                'clon_permitido' => 1,
                'clon_monto' => 200.00,
            ],
            'C' => [
                'monto_entrada' => 400.00,
                'ganancia_directa' => 0.00,
                'aporte_tesoreria' => 400.00,
                'reserva_siguiente_tablero' => 0.00,
                'ganancia_bruta_cierre' => 1200.00,
                'semilla_siguiente_fase' => 1000.00,
                'monto_reentrada' => 100.00,
                'clon_permitido' => 1,
                'clon_monto' => 400.00,
            ],
        ],
        // ── FASE 2 — Radix High-Level ($1,000 entrada) ──────────────
        // Tablero A: P0 + 3 refs → $4,000 total | $1,000 ganancia + $1,000 tesorería + $2,000 → B
        // Tablero B: $8,000 total | $2,000 ganancia + $2,000 tesorería + $4,000 → C
        // Tablero C: $16,000 total | $12,000 bruto − $10,000 semilla F3 − $1,000 reentrada = $1,000 neto
        // Ganancia neta total ciclo completo: $4,000 (ROI 400%)
        2 => [
            'A' => [
                'monto_entrada' => 1000.00,
                'ganancia_directa' => 1000.00,
                'aporte_tesoreria' => 1000.00,
                'reserva_siguiente_tablero' => 2000.00,
                'ganancia_bruta_cierre' => 0.00,
                'semilla_siguiente_fase' => 0.00,
                'monto_reentrada' => 0.00,
                'clon_permitido' => 1,
                'clon_monto' => 1000.00,
            ],
            'B' => [
                'monto_entrada' => 2000.00,
                'ganancia_directa' => 2000.00,
                'aporte_tesoreria' => 2000.00,
                'reserva_siguiente_tablero' => 4000.00,
                'ganancia_bruta_cierre' => 0.00,
                'semilla_siguiente_fase' => 0.00,
                'monto_reentrada' => 0.00,
                'clon_permitido' => 1,
                'clon_monto' => 2000.00,
            ],
            'C' => [
                'monto_entrada' => 4000.00,
                'ganancia_directa' => 0.00,
                'aporte_tesoreria' => 4000.00,
                'reserva_siguiente_tablero' => 0.00,
                'ganancia_bruta_cierre' => 12000.00,
                'semilla_siguiente_fase' => 10000.00,
                'monto_reentrada' => 1000.00,
                'clon_permitido' => 1,
                'clon_monto' => 4000.00,
            ],
        ],
    ];

    $row = $defaults[$fase_numero][$tablero_tipo] ?? null;

    if (!$row) {
        return [
            'fase_numero' => $fase_numero,
            'tablero_tipo' => $tablero_tipo,
            'monto_entrada' => 0.00,
            'ganancia_directa' => 0.00,
            'aporte_tesoreria' => 0.00,
            'reserva_siguiente_tablero' => 0.00,
            'ganancia_bruta_cierre' => 0.00,
            'semilla_siguiente_fase' => 0.00,
            'monto_reentrada' => 0.00,
            'clon_permitido' => 1,
            'clon_monto' => 0.00,
            'activa' => 1,
        ];
    }

    return array_merge([
        'fase_numero' => $fase_numero,
        'tablero_tipo' => $tablero_tipo,
        'activa' => 1,
    ], $row);
}

function getPhaseConfig(PDO $pdo, int $fase_numero): array
{
    $stmt = $pdo->prepare("
        SELECT fase_numero, nombre, descripcion, fase_siguiente, activa
        FROM fases_config
        WHERE fase_numero = ?
        LIMIT 1
    ");
    $stmt->execute([$fase_numero]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return radixDefaultPhaseConfig($fase_numero);
    }

    return [
        'fase_numero' => (int)$row['fase_numero'],
        'nombre' => $row['nombre'],
        'descripcion' => $row['descripcion'],
        'fase_siguiente' => $row['fase_siguiente'] !== null ? (int)$row['fase_siguiente'] : null,
        'activa' => (int)$row['activa'],
    ];
}

function getPhaseBoardConfig(PDO $pdo, int $fase_numero, string $tablero_tipo): array
{
    $tablero_tipo = strtoupper(trim($tablero_tipo));

    $stmt = $pdo->prepare("
        SELECT fase_numero, tablero_tipo, monto_entrada, ganancia_directa, aporte_tesoreria,
               reserva_siguiente_tablero, ganancia_bruta_cierre, semilla_siguiente_fase,
               monto_reentrada, clon_permitido, clon_monto, activa
        FROM fases_tableros_config
        WHERE fase_numero = ? AND tablero_tipo = ?
        LIMIT 1
    ");
    $stmt->execute([$fase_numero, $tablero_tipo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return radixDefaultPhaseBoardConfig($fase_numero, $tablero_tipo);
    }

    return [
        'fase_numero' => (int)$row['fase_numero'],
        'tablero_tipo' => $row['tablero_tipo'],
        'monto_entrada' => (float)$row['monto_entrada'],
        'ganancia_directa' => (float)$row['ganancia_directa'],
        'aporte_tesoreria' => (float)$row['aporte_tesoreria'],
        'reserva_siguiente_tablero' => (float)$row['reserva_siguiente_tablero'],
        'ganancia_bruta_cierre' => (float)$row['ganancia_bruta_cierre'],
        'semilla_siguiente_fase' => (float)$row['semilla_siguiente_fase'],
        'monto_reentrada' => (float)$row['monto_reentrada'],
        'clon_permitido' => (int)$row['clon_permitido'],
        'clon_monto' => (float)$row['clon_monto'],
        'activa' => (int)$row['activa'],
    ];
}

function getNextBoardType(string $tablero_tipo): ?string
{
    $tablero_tipo = strtoupper(trim($tablero_tipo));

    if ($tablero_tipo === 'A') {
        return 'B';
    }

    if ($tablero_tipo === 'B') {
        return 'C';
    }

    return null;
}

function getPreviousBoardType(string $tablero_tipo): ?string
{
    $tablero_tipo = strtoupper(trim($tablero_tipo));

    if ($tablero_tipo === 'B') {
        return 'A';
    }

    if ($tablero_tipo === 'C') {
        return 'B';
    }

    return null;
}

function getPhaseSeedPaymentType(int $fase_numero): ?string
{
    $map = [
        0 => 'salto_fase_1',
        1 => 'salto_fase_2',
        2 => 'salto_fase_3',
    ];

    return $map[$fase_numero] ?? null;
}

function getPhaseReserveDestination(int $fase_numero): string
{
    return 'FASE' . ($fase_numero + 1);
}
