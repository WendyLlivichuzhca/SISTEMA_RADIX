<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/phase_config.php';
require_once __DIR__ . '/clon_logic.php';       // Compatibilidad con el flujo actual
require_once __DIR__ . '/notificaciones.php';   // MEJORA #6: Notificaciones Telegram
require_once __DIR__ . '/network_placement.php';

function obtenerMasterRadix(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, wallet_address
        FROM usuarios
        WHERE tipo_usuario = 'master'
        ORDER BY id ASC
        LIMIT 1
    ");
    $master = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($master) {
        return [
            'id' => (int)$master['id'],
            'wallet_address' => $master['wallet_address'] ?: RADIX_CENTRAL_WALLET,
        ];
    }

    return [
        'id' => 1,
        'wallet_address' => RADIX_CENTRAL_WALLET,
    ];
}

function obtenerTableroEnProgresoUsuario(PDO $pdo, int $usuario_id, ?int $fase_numero = null, ?int $ciclo = null): ?array
{
    $conditions = ["usuario_id = ?", "estado = 'en_progreso'"];
    $params = [$usuario_id];

    if ($fase_numero !== null) {
        $conditions[] = "fase_numero = ?";
        $params[] = $fase_numero;
    }

    if ($ciclo !== null) {
        $conditions[] = "ciclo = ?";
        $params[] = $ciclo;
    }

    $stmt = $pdo->prepare("
        SELECT id, tablero_tipo, ciclo, fase_numero
        FROM tableros_progreso
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY fase_numero DESC, ciclo DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function obtenerCicloActivoUsuarioEnFase(PDO $pdo, int $usuario_id, int $fase_numero): int
{
    $stmt = $pdo->prepare("
        SELECT ciclo
        FROM tableros_progreso
        WHERE usuario_id = ?
          AND fase_numero = ?
        ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$usuario_id, $fase_numero]);
    $ciclo = $stmt->fetchColumn();

    return $ciclo ? (int)$ciclo : 1;
}

function obtenerWalletUsuarioPorId(PDO $pdo, int $usuario_id): ?string
{
    $stmt = $pdo->prepare("SELECT wallet_address FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$usuario_id]);
    $wallet = $stmt->fetchColumn();

    return $wallet !== false ? (string)$wallet : null;
}

function matrixSchemaSoportaReferidosParalelos(PDO $pdo): bool
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $stmt = $pdo->query("
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columnas
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'referidos'
          AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
    ");

    $uniqueIndexes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uniqueIndexes[] = $row['columnas'];
    }

    $cache = in_array('id_padre,id_hijo,fase_numero,ciclo', $uniqueIndexes, true)
        && in_array('id_padre,fase_numero,ciclo,posicion', $uniqueIndexes, true);

    return $cache;
}

function resolverUbicacionRedFase(PDO $pdo, int $patrocinador_id, int $fase_numero): ?array
{
    $ciclo_red = obtenerCicloActivoUsuarioEnFase($pdo, $patrocinador_id, $fase_numero);
    return radixFindAvailablePlacement($pdo, $patrocinador_id, $fase_numero, $ciclo_red);
}

function activarSiguienteFaseParalela(PDO $pdo, int $usuario_id, ?int $patrocinador_id, int $fase_destino, float $monto_entrada): array
{
    if ($fase_destino <= 0) {
        return ['status' => 'skipped', 'message' => 'No hay una fase siguiente operativa configurada.'];
    }

    $fase_cfg = getPhaseConfig($pdo, $fase_destino);
    if ((int)($fase_cfg['activa'] ?? 0) !== 1) {
        return ['status' => 'skipped', 'message' => "La Fase $fase_destino aun no esta activa."];
    }

    if (!matrixSchemaSoportaReferidosParalelos($pdo)) {
        return ['status' => 'skipped', 'message' => 'La base de datos aun no tiene las llaves de referidos compatibles con paralelo.'];
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM tableros_progreso
        WHERE usuario_id = ?
          AND fase_numero = ?
        LIMIT 1
    ");
    $stmt->execute([$usuario_id, $fase_destino]);
    if ($stmt->fetchColumn()) {
        return ['status' => 'skipped', 'message' => "El usuario ya tiene historial en Fase $fase_destino."];
    }

    if (!$patrocinador_id) {
        $stmt = $pdo->prepare("
            INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado)
            VALUES (?, ?, 'A', 1, 'en_progreso')
        ");
        $stmt->execute([$usuario_id, $fase_destino]);

        return ['status' => 'success', 'message' => "Fase $fase_destino activada como raiz.", 'ciclo' => 1];
    }

    $stmt = $pdo->prepare("
        SELECT id, tipo_usuario
        FROM usuarios
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$patrocinador_id]);
    $patrocinador = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patrocinador || ($patrocinador['tipo_usuario'] ?? '') !== 'real') {
        $stmt = $pdo->prepare("
            INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado)
            VALUES (?, ?, 'A', 1, 'en_progreso')
        ");
        $stmt->execute([$usuario_id, $fase_destino]);

        return ['status' => 'success', 'message' => "Fase $fase_destino activada sin patrocinador operativo.", 'ciclo' => 1];
    }

    $ubicacion = resolverUbicacionRedFase($pdo, (int)$patrocinador['id'], $fase_destino);
    if (!$ubicacion) {
        throw new RuntimeException("No se encontro una ubicacion disponible en la red de Fase $fase_destino.");
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cuenta
        FROM referidos
        WHERE id_padre = ?
          AND fase_numero = ?
          AND ciclo = ?
        FOR UPDATE
    ");
    $stmt->execute([
        (int)$ubicacion['padre_id'],
        $fase_destino,
        (int)$ubicacion['ciclo'],
    ]);
    $cuenta_actual = (int)($stmt->fetch()['cuenta'] ?? 0);

    if ($cuenta_actual >= 3) {
        throw new RuntimeException("La ubicacion operativa elegida para Fase $fase_destino acaba de llenarse. Reintenta el cierre.");
    }

    $ubicacion['posicion'] = $cuenta_actual + 1;
    $wallet_destino = obtenerWalletUsuarioPorId($pdo, (int)$ubicacion['padre_id']);

    $stmt = $pdo->prepare("
        INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado)
        VALUES (?, ?, 'A', ?, 'en_progreso')
    ");
    $stmt->execute([$usuario_id, $fase_destino, (int)$ubicacion['ciclo']]);

    $stmt = $pdo->prepare("
        INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo)
        VALUES (?, ?, ?, ?, 1, ?)
    ");
    $stmt->execute([
        (int)$ubicacion['padre_id'],
        $usuario_id,
        $fase_destino,
        (int)$ubicacion['posicion'],
        (int)$ubicacion['ciclo'],
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO pagos (
            id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, propietario_flujo,
            wallet_destino_real, tablero_tipo, ciclo, origen_fondos, monto, monto_pagado,
            tipo, estado, fecha_confirmacion
        ) VALUES (?, ?, ?, ?, 'usuario', ?, 'A', ?, 'reserva_interna', ?, ?, 'regalo', 'completado', NOW())
    ");
    $stmt->execute([
        $usuario_id,
        (int)$ubicacion['padre_id'],
        (int)$ubicacion['padre_id'],
        $fase_destino,
        $wallet_destino,
        (int)$ubicacion['ciclo'],
        $monto_entrada,
        $monto_entrada,
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
        VALUES (?, ?, 'ACTIVACION_FASE_PARALELA', 'tableros_progreso', ?)
    ");
    $stmt->execute([
        $usuario_id,
        $fase_destino,
        "Ingreso interno a Fase $fase_destino. Padre: {$ubicacion['padre_id']} | Ciclo: {$ubicacion['ciclo']} | Posicion: {$ubicacion['posicion']} | Modo: {$ubicacion['modo']}",
    ]);

    verificarAvanceTablero((int)$ubicacion['padre_id'], $pdo, false, $fase_destino, (int)$ubicacion['ciclo']);
    verificarCadenaAscendente((int)$ubicacion['padre_id'], $pdo, 10, $fase_destino, (int)$ubicacion['ciclo']);

    return [
        'status' => 'success',
        'message' => "Fase $fase_destino activada en paralelo.",
        'padre_id' => (int)$ubicacion['padre_id'],
        'ciclo' => (int)$ubicacion['ciclo'],
        'posicion' => (int)$ubicacion['posicion'],
    ];
}

function asegurarReservaTableroActual($pdo, $usuario_id, $tipo_actual, $ciclo_actual, $fase_actual = 0, $propietario_flujo = 'usuario')
{
    if ($tipo_actual === 'A') {
        return true;
    }

    $desde_tablero = getPreviousBoardType($tipo_actual);
    $hacia_destino = $tipo_actual;
    $cfg_actual = getPhaseBoardConfig($pdo, (int)$fase_actual, $tipo_actual);
    $monto_reserva = (float)($cfg_actual['monto_entrada'] ?? 0.00);

    if (!$desde_tablero) {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM reservas_tablero
        WHERE usuario_id = ?
          AND fase_numero = ?
          AND desde_tablero = ?
          AND hacia_destino = ?
          AND ciclo_origen = ?
          AND estado = 'usado'
        LIMIT 1
    ");
    $stmt->execute([$usuario_id, $fase_actual, $desde_tablero, $hacia_destino, $ciclo_actual]);
    $reserva_id = $stmt->fetchColumn();

    if ($reserva_id) {
        return true;
    }

    // Compatibilidad con usuarios que avanzaron antes de existir fase_numero en reservas.
    $stmt = $pdo->prepare("
        INSERT INTO reservas_tablero (
            usuario_id, fase_numero, propietario_flujo, desde_tablero, hacia_destino, ciclo_origen,
            ciclo_destino, monto, estado, detalle, fecha_uso
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'usado', ?, NOW())
    ");
    $stmt->execute([
        $usuario_id,
        $fase_actual,
        $propietario_flujo,
        $desde_tablero,
        $hacia_destino,
        $ciclo_actual,
        $ciclo_actual,
        $monto_reserva,
        "Reserva auto-reconstruida para Tablero $tipo_actual existente",
    ]);

    error_log("RADIX reserve backfill: usuario $usuario_id fase $fase_actual tablero $tipo_actual ciclo $ciclo_actual");
    return true;
}

/**
 * Funcion para verificar y avanzar a un usuario de tablero.
 * Por ahora mantiene intacta la operacion real de Fase 0, pero ya guardando fase_numero.
 */
function verificarAvanceTablero($usuario_id, $pdo, $strict = false, ?int $fase_objetivo = null, ?int $ciclo_objetivo = null)
{
    try {
        $stmt = $pdo->prepare("SELECT tipo_usuario, patrocinador_id FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $user_data = $stmt->fetch();

        if (!$user_data || in_array($user_data['tipo_usuario'], ['master', 'sistema'], true)) {
            return false;
        }

        // Cuando la llamada conoce fase/ciclo, debemos evaluar ese flujo exacto.
        // Si no se especifica, mantenemos el fallback historico al tablero activo mas alto.
        $tablero = obtenerTableroEnProgresoUsuario(
            $pdo,
            (int)$usuario_id,
            $fase_objetivo,
            $ciclo_objetivo
        );

        if (!$tablero) {
            return false;
        }

        $tablero_id = (int)$tablero['id'];
        $tipo_actual = $tablero['tablero_tipo'];
        $ciclo_actual = (int)$tablero['ciclo'];
        $fase_actual = (int)($tablero['fase_numero'] ?? 0);
        $propietario_usuario = $user_data['tipo_usuario'] === 'clon' ? 'sistema' : 'usuario';
        $master_user = $user_data['tipo_usuario'] === 'clon' ? obtenerMasterRadix($pdo) : null;
        $receptor_ganancia_id = $master_user['id'] ?? (int)$usuario_id;
        $wallet_destino_ganancia = $master_user['wallet_address'] ?? null;

        asegurarReservaTableroActual($pdo, $usuario_id, $tipo_actual, $ciclo_actual, $fase_actual, $propietario_usuario);

        // Conteo inteligente por fase y ciclo
        $referidos = 0;

        if ($tipo_actual === 'A') {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT tp.usuario_id) AS cuenta
                FROM referidos r
                INNER JOIN tableros_progreso tp ON r.id_hijo = tp.usuario_id
                INNER JOIN pagos p ON tp.usuario_id = p.id_emisor
                WHERE r.id_padre = ?
                  AND r.fase_numero = ?
                  AND r.ciclo = ?
                  AND tp.fase_numero = ?
                  AND tp.ciclo >= ?
                  AND p.id_receptor = ?
                  AND p.fase_numero = ?
                  AND p.estado = 'completado'
                  AND p.tipo = 'regalo'
            ");
            $stmt->execute([$usuario_id, $fase_actual, $ciclo_actual, $fase_actual, $ciclo_actual, $usuario_id, $fase_actual]);
            $referidos = (int)($stmt->fetch()['cuenta'] ?? 0);
        } elseif ($tipo_actual === 'B') {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT tp.usuario_id) AS cuenta
                FROM referidos r
                INNER JOIN tableros_progreso tp ON r.id_hijo = tp.usuario_id
                WHERE r.id_padre = ?
                  AND r.fase_numero = ?
                  AND r.ciclo = ?
                  AND tp.fase_numero = ?
                  AND (tp.tablero_tipo IN ('B', 'C') OR tp.ciclo > ?)
                  AND tp.ciclo >= ?
            ");
            $stmt->execute([$usuario_id, $fase_actual, $ciclo_actual, $fase_actual, $ciclo_actual, $ciclo_actual]);
            $referidos = (int)($stmt->fetch()['cuenta'] ?? 0);
        } elseif ($tipo_actual === 'C') {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT tp.usuario_id) AS cuenta
                FROM referidos r
                INNER JOIN tableros_progreso tp ON r.id_hijo = tp.usuario_id
                WHERE r.id_padre = ?
                  AND r.fase_numero = ?
                  AND r.ciclo = ?
                  AND tp.fase_numero = ?
                  AND (tp.tablero_tipo = 'C' OR tp.ciclo > ?)
                  AND tp.ciclo >= ?
            ");
            $stmt->execute([$usuario_id, $fase_actual, $ciclo_actual, $fase_actual, $ciclo_actual, $ciclo_actual]);
            $referidos = (int)($stmt->fetch()['cuenta'] ?? 0);
        }

        error_log("AUDIT RADIX: Usuario $usuario_id fase $fase_actual en Tablero $tipo_actual (C$ciclo_actual) tiene $referidos referidos calificados.");

        if ($referidos < 3) {
            return true;
        }

        $cfg_actual = getPhaseBoardConfig($pdo, $fase_actual, $tipo_actual);
        $fase_cfg = getPhaseConfig($pdo, $fase_actual);

        $nuevo_tipo = getNextBoardType($tipo_actual);
        $finalizado = ($tipo_actual === 'C');
        $destino_reserva = $nuevo_tipo;
        $monto_reserva = (float)($cfg_actual['reserva_siguiente_tablero'] ?? 0.00);
        $monto_usuario = $finalizado
            ? (float)($cfg_actual['ganancia_bruta_cierre'] ?? 0.00)
            : (float)($cfg_actual['ganancia_directa'] ?? 0.00);
        $monto_clon = (float)($cfg_actual['aporte_tesoreria'] ?? 0.00);
        // Monto neto para notificación: en Tablero C se resta la semilla de Fase siguiente
        $monto_notif = $finalizado
            ? $monto_usuario - (float)($cfg_actual['semilla_siguiente_fase'] ?? 0.00)
            : $monto_usuario;

        $propia_tx = !$pdo->inTransaction();
        if ($propia_tx) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare("UPDATE tableros_progreso SET estado = 'completado', fecha_fin = NOW() WHERE id = ?");
        $stmt->execute([$tablero_id]);

        $stmt = $pdo->prepare("
            INSERT INTO pagos (
                id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, propietario_flujo,
                wallet_destino_real, tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
            ) VALUES (1000, ?, ?, ?, ?, ?, ?, ?, 'reserva_interna', ?, 'ganancia_tablero', 'completado')
        ");
        $stmt->execute([
            $receptor_ganancia_id,
            $usuario_id,
            $fase_actual,
            $propietario_usuario,
            $wallet_destino_ganancia,
            $tipo_actual,
            $ciclo_actual,
            $monto_usuario,
        ]);

        $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal + ? WHERE clave = 'tesoreria_balance'");
        $stmt->execute([$monto_clon]);

        $stmt = $pdo->prepare("
            INSERT INTO pagos (
                id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, propietario_flujo,
                wallet_destino_real, tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
            ) VALUES (?, 1000, 1000, ?, 'sistema', NULL, ?, ?, 'reserva_interna', ?, 'tesoreria_clon', 'completado')
        ");
        $stmt->execute([$usuario_id, $fase_actual, $tipo_actual, $ciclo_actual, $monto_clon]);

        if ($nuevo_tipo) {
            $stmt = $pdo->prepare("
                INSERT INTO reservas_tablero (
                    usuario_id, fase_numero, propietario_flujo, desde_tablero, hacia_destino,
                    ciclo_origen, ciclo_destino, monto, estado, detalle, fecha_uso
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'usado', ?, NOW())
            ");
            $stmt->execute([
                $usuario_id,
                $fase_actual,
                $propietario_usuario,
                $tipo_actual,
                $destino_reserva,
                $ciclo_actual,
                $ciclo_actual,
                $monto_reserva,
                "Reserva interna aplicada de Tablero $tipo_actual a Tablero $destino_reserva",
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$usuario_id, $fase_actual, $nuevo_tipo, $ciclo_actual]);
        } elseif ($finalizado) {
            $nuevo_ciclo    = $ciclo_actual + 1;
            // Detectar si es la FASE FINAL (fase_siguiente = NULL, ej. Fase 3).
            // En fase final no hay salto a otra fase: la semilla_siguiente_fase va a tesorería.
            $es_fase_final  = ($fase_cfg['fase_siguiente'] === null);
            $fase_siguiente = !$es_fase_final
                ? (int)$fase_cfg['fase_siguiente']
                : ($fase_actual + 1); // solo referencia en logs, no se activa
            $monto_semilla   = (float)($cfg_actual['semilla_siguiente_fase'] ?? 0.00);
            $monto_reentrada = (float)($cfg_actual['monto_reentrada'] ?? 0.00);
            $tipo_salto      = getPhaseSeedPaymentType($fase_actual);

            if (!$es_fase_final) {
                // ── Fases 0/1/2: hay fase siguiente → registrar semilla y activarla ──
                if ($tipo_salto === null) {
                    throw new RuntimeException("El cierre automatico de la Fase $fase_actual aun no esta habilitado en pagos.tipo.");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO pagos (
                        id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, propietario_flujo,
                        wallet_destino_real, tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                    ) VALUES (?, 1000, 1000, ?, ?, NULL, ?, ?, 'reserva_interna', ?, ?, 'completado')
                ");
                $stmt->execute([$usuario_id, $fase_actual, $propietario_usuario, $tipo_actual, $ciclo_actual, $monto_semilla, $tipo_salto]);

                $stmt = $pdo->prepare("
                    INSERT INTO reservas_tablero (
                        usuario_id, fase_numero, propietario_flujo, desde_tablero, hacia_destino,
                        ciclo_origen, ciclo_destino, monto, estado, detalle, fecha_uso
                    ) VALUES (?, ?, ?, 'C', ?, ?, NULL, ?, 'usado', ?, NOW())
                ");
                $stmt->execute([
                    $usuario_id,
                    $fase_actual,
                    $propietario_usuario,
                    getPhaseReserveDestination($fase_actual),
                    $ciclo_actual,
                    $monto_semilla,
                    "Semilla interna de Fase $fase_siguiente generada al cerrar ciclo $ciclo_actual",
                ]);

                $stmt = $pdo->prepare("
                    INSERT INTO tesoreria_movimientos (tipo, monto, motivo, relacion_id)
                    VALUES ('ingreso', ?, ?, ?)
                ");
                $stmt->execute([$monto_semilla, "Salto Fase $fase_siguiente - Usuario ID $usuario_id (ciclo $ciclo_actual)", $usuario_id]);

            } else {
                // ── Fase FINAL (Fase 3): no hay fase siguiente ─────────────────────
                // La semilla_siguiente_fase se dirige a tesorería como aporte final.
                // No se llama activarSiguienteFaseParalela().
                if ($monto_semilla > 0) {
                    // Actualizar saldo de tesorería operativa (el dinero fluye al balance general del sistema)
                    $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal + ? WHERE clave = 'tesoreria_balance'");
                    $stmt->execute([$monto_semilla]);

                    // Registro contable del aporte final en pagos usando la etiqueta configurada (ej. utilidad_master)
                    $stmt = $pdo->prepare("
                        INSERT INTO pagos (
                            id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, propietario_flujo,
                            wallet_destino_real, tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                        ) VALUES (?, 1000, 1000, ?, ?, NULL, ?, ?, 'reserva_interna', ?, ?, 'completado')
                    ");
                    $stmt->execute([$usuario_id, $fase_actual, $propietario_usuario, $tipo_actual, $ciclo_actual, $monto_semilla, $tipo_salto]);

                    // Registro en reservas_tablero para auditoría
                    $stmt = $pdo->prepare("
                        INSERT INTO reservas_tablero (
                            usuario_id, fase_numero, propietario_flujo, desde_tablero, hacia_destino,
                            ciclo_origen, ciclo_destino, monto, estado, detalle, fecha_uso
                        ) VALUES (?, ?, ?, 'C', ?, ?, NULL, ?, 'usado', ?, NOW())
                    ");
                    $stmt->execute([
                        $usuario_id,
                        $fase_actual,
                        $propietario_usuario,
                        ($tipo_salto === 'utilidad_master' ? 'UTILIDAD_MASTER' : 'TESORERIA_FINAL'),
                        $ciclo_actual,
                        $monto_semilla,
                        "Aporte final ($tipo_salto) al cerrar Fase $fase_actual ciclo $ciclo_actual",
                    ]);

                    // Log en tesoreria_movimientos
                    $stmt = $pdo->prepare("
                        INSERT INTO tesoreria_movimientos (tipo, monto, motivo, relacion_id)
                        VALUES ('ingreso', ?, ?, ?)
                    ");
                    $stmt->execute([$monto_semilla, "Utilidad Final ($tipo_salto) - Usuario ID $usuario_id (ciclo $ciclo_actual)", $usuario_id]);
                }
            }

            // ── Reentrada (aplica a todas las fases) ─────────────────────────────
            $stmt = $pdo->prepare("
                INSERT INTO pagos (
                    id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, propietario_flujo,
                    wallet_destino_real, tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                ) VALUES (?, 1000, ?, ?, ?, NULL, ?, ?, 'reserva_interna', ?, 'reentrada', 'completado')
            ");
            $stmt->execute([$usuario_id, $usuario_id, $fase_actual, $propietario_usuario, $tipo_actual, $ciclo_actual, $monto_reentrada]);

            $stmt = $pdo->prepare("
                INSERT INTO reservas_tablero (
                    usuario_id, fase_numero, propietario_flujo, desde_tablero, hacia_destino,
                    ciclo_origen, ciclo_destino, monto, estado, detalle, fecha_uso
                ) VALUES (?, ?, ?, 'C', 'REENTRADA_A', ?, ?, ?, 'usado', ?, NOW())
            ");
            $stmt->execute([
                $usuario_id,
                $fase_actual,
                $propietario_usuario,
                $ciclo_actual,
                $nuevo_ciclo,
                $monto_reentrada,
                "Reentrada automatica a Tablero A del ciclo $nuevo_ciclo",
            ]);

            // ── Crear Tablero A del nuevo ciclo ──────────────────────────────────
            $stmt = $pdo->prepare("
                SELECT id
                FROM tableros_progreso
                WHERE usuario_id = ? AND fase_numero = ? AND tablero_tipo = 'A' AND ciclo = ?
                LIMIT 1
            ");
            $stmt->execute([$usuario_id, $fase_actual, $nuevo_ciclo]);

            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("
                    INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo)
                    VALUES (?, ?, 'A', ?)
                ");
                $stmt->execute([$usuario_id, $fase_actual, $nuevo_ciclo]);
            }

            // === REENLACE DE REFERIDOS EN NUEVO CICLO ===
            // Cuando alguien hace reentrada al nuevo ciclo, re-establece los vinculos
            // con sus hijos directos que ya hayan reentrado, y con su padre si ya reentro.
            // Esto garantiza que el equipo original (principal + 3 referidos) continue
            // unido en cada ciclo sin tener que conseguir personas nuevas.

            // 1. Hijos directos del usuario actual que ya estan en el nuevo ciclo
            $stmt_hijos = $pdo->prepare("
                SELECT r.id_hijo, r.posicion, r.nivel_en_red
                FROM referidos r
                INNER JOIN tableros_progreso tp ON tp.usuario_id = r.id_hijo
                WHERE r.id_padre = ?
                  AND r.fase_numero = ?
                  AND r.ciclo = ?
                  AND tp.fase_numero = ?
                  AND tp.tablero_tipo = 'A'
                  AND tp.ciclo = ?
            ");
            $stmt_hijos->execute([$usuario_id, $fase_actual, $ciclo_actual, $fase_actual, $nuevo_ciclo]);
            $hijos_reentrados = $stmt_hijos->fetchAll(PDO::FETCH_ASSOC);

            foreach ($hijos_reentrados as $hijo) {
                $pdo->prepare("
                    INSERT IGNORE INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([
                    $usuario_id,
                    (int)$hijo['id_hijo'],
                    $fase_actual,
                    (int)$hijo['posicion'],
                    (int)$hijo['nivel_en_red'],
                    $nuevo_ciclo,
                ]);
            }

            // 2. El padre del usuario actual, si ya reentro al nuevo ciclo
            $stmt_padre_reentrado = $pdo->prepare("
                SELECT r.id_padre, r.posicion, r.nivel_en_red
                FROM referidos r
                INNER JOIN tableros_progreso tp ON tp.usuario_id = r.id_padre
                WHERE r.id_hijo = ?
                  AND r.fase_numero = ?
                  AND r.ciclo = ?
                  AND tp.fase_numero = ?
                  AND tp.tablero_tipo = 'A'
                  AND tp.ciclo = ?
                LIMIT 1
            ");
            $stmt_padre_reentrado->execute([$usuario_id, $fase_actual, $ciclo_actual, $fase_actual, $nuevo_ciclo]);
            $padre_reentrado = $stmt_padre_reentrado->fetch(PDO::FETCH_ASSOC);

            if ($padre_reentrado) {
                $pdo->prepare("
                    INSERT IGNORE INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([
                    (int)$padre_reentrado['id_padre'],
                    $usuario_id,
                    $fase_actual,
                    (int)$padre_reentrado['posicion'],
                    (int)$padre_reentrado['nivel_en_red'],
                    $nuevo_ciclo,
                ]);
            }
            // === FIN REENLACE ===

            // ── Apertura paralela de fase siguiente (solo si NO es fase final) ───
            // Se protege con SAVEPOINT para no romper el cierre vigente si la fase nueva aun no esta lista.
            if (!$es_fase_final && $monto_semilla > 0) {
                $activation_info = ['status' => 'skipped', 'message' => 'Sin cambios'];

                try {
                    $pdo->exec("SAVEPOINT fase_paralela");
                    $activation_info = activarSiguienteFaseParalela(
                        $pdo,
                        (int)$usuario_id,
                        isset($user_data['patrocinador_id']) ? (int)$user_data['patrocinador_id'] : null,
                        $fase_siguiente,
                        $monto_semilla
                    );
                } catch (Throwable $activationError) {
                    $pdo->exec("ROLLBACK TO SAVEPOINT fase_paralela");
                    $activation_info = [
                        'status' => 'error',
                        'message' => $activationError->getMessage(),
                    ];
                    error_log("RADIX matrix_logic phase activation ERROR (usuario $usuario_id, fase $fase_siguiente): " . $activationError->getMessage());
                }

                if (($activation_info['status'] ?? '') !== 'success') {
                    error_log("RADIX phase activation skipped (usuario $usuario_id, fase $fase_siguiente): " . ($activation_info['message'] ?? 'sin detalle'));
                }
            } elseif ($es_fase_final) {
                error_log("RADIX Fase $fase_actual FINAL completada por usuario $usuario_id (ciclo $ciclo_actual). Reentrada y aporte tesoreria procesados. No hay fase siguiente.");
            }
        }

        $accion_log = $nuevo_tipo
            ? "AVANCE_TABLERO_{$tipo_actual}_A_{$nuevo_tipo}"
            : "CICLO_COMPLETADO_C{$ciclo_actual}";
        $stmt = $pdo->prepare("
            INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
            VALUES (?, ?, ?, 'tableros_progreso', ?)
        ");
        $stmt->execute([$usuario_id, $fase_actual, $accion_log, "Tablero $tipo_actual completado. Referidos calificados: $referidos"]);

        if ($propia_tx) {
            $pdo->commit();
        }

        if ($propietario_usuario === 'usuario') {
            if ($finalizado) {
                // Para Tablero C: mostrar el saldo neto TOTAL disponible para retiro
                // (ganancias A+B+C menos semilla Fase 1 y reentrada, ya registradas en pagos)
                $stmt_bruto = $pdo->prepare("
                    SELECT COALESCE(SUM(monto),0) as t FROM pagos
                    WHERE id_receptor=? AND propietario_flujo='usuario'
                      AND estado='completado' AND tipo='ganancia_tablero' AND fase_numero=?
                ");
                $stmt_bruto->execute([$usuario_id, $fase_actual]);
                $bruto_total = (float)$stmt_bruto->fetch()['t'];

                $stmt_ded = $pdo->prepare("
                    SELECT COALESCE(SUM(monto),0) as t FROM pagos
                    WHERE id_emisor=? AND propietario_flujo='usuario'
                      AND estado='completado'
                      AND (tipo LIKE 'salto_fase_%' OR tipo='reentrada' OR tipo='utilidad_master')
                      AND fase_numero=?
                ");
                $stmt_ded->execute([$usuario_id, $fase_actual]);
                $ded_total = (float)$stmt_ded->fetch()['t'];

                $monto_notif = max(0, $bruto_total - $ded_total);
            }
            notificarAvanceTablero($pdo, $usuario_id, $tipo_actual, $monto_notif, $finalizado);
        }

        // MODO MANUAL:
        // Los clones no se activan automaticamente al completar tableros.
        // La tesoreria se acumula y el admin los activa desde el panel.
    } catch (Throwable $e) {
        if (isset($propia_tx) && $propia_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("RADIX matrix_logic ERROR (usuario $usuario_id): " . $e->getMessage());
        if ($strict) {
            throw $e;
        }
        return false;
    }

    return true;
}

function verificarCadenaAscendente($usuario_id, $pdo, $max_niveles = 10, ?int $fase_objetivo = null, ?int $ciclo_objetivo = null)
{
    try {
        $usuario_actual = (int)$usuario_id;
        $visitados = [];
        $fase_cadena = $fase_objetivo;
        $ciclo_cadena = $ciclo_objetivo;

        for ($nivel = 0; $nivel < $max_niveles; $nivel++) {
            if ($usuario_actual <= 0 || isset($visitados[$usuario_actual])) {
                break;
            }

            $visitados[$usuario_actual] = true;

            $tablero_actual = obtenerTableroEnProgresoUsuario(
                $pdo,
                $usuario_actual,
                $fase_cadena,
                $ciclo_cadena
            );

            if (!$tablero_actual) {
                break;
            }

            $fase_cadena = (int)$tablero_actual['fase_numero'];
            $ciclo_cadena = (int)$tablero_actual['ciclo'];

            $stmt = $pdo->prepare("
                SELECT id_padre
                FROM referidos
                WHERE id_hijo = ?
                  AND fase_numero = ?
                  AND ciclo = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                $usuario_actual,
                $fase_cadena,
                $ciclo_cadena,
            ]);
            $padre_id = (int)($stmt->fetchColumn() ?: 0);

            if ($padre_id <= 0) {
                break;
            }

            verificarAvanceTablero($padre_id, $pdo, false, $fase_cadena, $ciclo_cadena);
            $usuario_actual = $padre_id;
        }

        return true;
    } catch (Exception $e) {
        error_log("RADIX matrix_logic chain ERROR (usuario $usuario_id): " . $e->getMessage());
        return false;
    }
}
?>
