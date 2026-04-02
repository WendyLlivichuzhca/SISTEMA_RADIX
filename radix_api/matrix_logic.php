<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/phase_config.php';
require_once __DIR__ . '/clon_logic.php';       // Compatibilidad con el flujo actual
require_once __DIR__ . '/notificaciones.php';   // MEJORA #6: Notificaciones Telegram

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
function verificarAvanceTablero($usuario_id, $pdo, $strict = false)
{
    try {
        $stmt = $pdo->prepare("SELECT tipo_usuario, patrocinador_id FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $user_data = $stmt->fetch();

        if (!$user_data || in_array($user_data['tipo_usuario'], ['master', 'sistema'], true)) {
            return false;
        }

        // Obtener tablero actual priorizando la fase mas alta.
        $stmt = $pdo->prepare("
            SELECT id, tablero_tipo, ciclo, fase_numero
            FROM tableros_progreso
            WHERE usuario_id = ? AND estado = 'en_progreso'
            ORDER BY fase_numero DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$usuario_id]);
        $tablero = $stmt->fetch();

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
            $nuevo_ciclo = $ciclo_actual + 1;
            $fase_siguiente = $fase_cfg['fase_siguiente'] !== null ? (int)$fase_cfg['fase_siguiente'] : ($fase_actual + 1);
            $monto_semilla = (float)($cfg_actual['semilla_siguiente_fase'] ?? 0.00);
            $monto_reentrada = (float)($cfg_actual['monto_reentrada'] ?? 0.00);
            $tipo_salto = getPhaseSeedPaymentType($fase_actual);

            // Por ahora solo el cierre automatico de Fase 0 queda habilitado de punta a punta.
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

            $stmt = $pdo->prepare("
                INSERT INTO tesoreria_movimientos (tipo, monto, motivo, relacion_id)
                VALUES ('ingreso', ?, ?, ?)
            ");
            $stmt->execute([$monto_semilla, "Salto Fase $fase_siguiente - Usuario ID $usuario_id (ciclo $ciclo_actual)", $usuario_id]);

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
            notificarAvanceTablero($pdo, $usuario_id, $tipo_actual, $monto_usuario);
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

function verificarCadenaAscendente($usuario_id, $pdo, $max_niveles = 10)
{
    try {
        $usuario_actual = (int)$usuario_id;
        $visitados = [];

        for ($nivel = 0; $nivel < $max_niveles; $nivel++) {
            if ($usuario_actual <= 0 || isset($visitados[$usuario_actual])) {
                break;
            }

            $visitados[$usuario_actual] = true;

            $stmt = $pdo->prepare("
                SELECT fase_numero, ciclo
                FROM tableros_progreso
                WHERE usuario_id = ? AND estado = 'en_progreso'
                ORDER BY fase_numero DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$usuario_actual]);
            $tablero_actual = $stmt->fetch();

            if (!$tablero_actual) {
                break;
            }

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
                (int)$tablero_actual['fase_numero'],
                (int)$tablero_actual['ciclo'],
            ]);
            $padre_id = (int)($stmt->fetchColumn() ?: 0);

            if ($padre_id <= 0) {
                break;
            }

            verificarAvanceTablero($padre_id, $pdo);
            $usuario_actual = $padre_id;
        }

        return true;
    } catch (Exception $e) {
        error_log("RADIX matrix_logic chain ERROR (usuario $usuario_id): " . $e->getMessage());
        return false;
    }
}
?>
