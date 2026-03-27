<?php
require_once 'config.php';
require_once 'clon_logic.php';       // Para activar clones automáticamente
require_once 'notificaciones.php';   // MEJORA #6: Notificaciones Telegram

function asegurarReservaTableroActual($pdo, $usuario_id, $tipo_actual, $ciclo_actual) {
    if ($tipo_actual === 'A') {
        return true;
    }

    $desde_tablero = null;
    $hacia_destino = null;
    $monto_reserva = 0.00;

    if ($tipo_actual === 'B') {
        $desde_tablero = 'A';
        $hacia_destino = 'B';
        $monto_reserva = 20.00;
    } elseif ($tipo_actual === 'C') {
        $desde_tablero = 'B';
        $hacia_destino = 'C';
        $monto_reserva = 40.00;
    }

    if (!$desde_tablero) {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM reservas_tablero
        WHERE usuario_id = ?
          AND desde_tablero = ?
          AND hacia_destino = ?
          AND ciclo_origen = ?
          AND estado = 'usado'
        LIMIT 1
    ");
    $stmt->execute([$usuario_id, $desde_tablero, $hacia_destino, $ciclo_actual]);
    $reserva_id = $stmt->fetchColumn();

    if ($reserva_id) {
        return true;
    }

    // Compatibilidad con usuarios que avanzaron antes de existir la tabla de reservas.
    $stmt = $pdo->prepare("
        INSERT INTO reservas_tablero (
            usuario_id, desde_tablero, hacia_destino, ciclo_origen, ciclo_destino,
            monto, estado, detalle, fecha_uso
        ) VALUES (?, ?, ?, ?, ?, ?, 'usado', ?, NOW())
    ");
    $stmt->execute([
        $usuario_id,
        $desde_tablero,
        $hacia_destino,
        $ciclo_actual,
        $ciclo_actual,
        $monto_reserva,
        "Reserva auto-reconstruida para Tablero $tipo_actual existente"
    ]);

    error_log("RADIX reserve backfill: usuario $usuario_id tablero $tipo_actual ciclo $ciclo_actual");
    return true;
}

/**
 * Función para verificar y avanzar a un usuario de tablero (Fase 0)
 * Lógica: A ($10) -> B ($20) -> C ($40) -> Salto Fase 1 ($100)
 */
function verificarAvanceTablero($usuario_id, $pdo) {
    try {
        // 0. Seguridad: Cuentas maestras y de sistema NO participan en la matriz
        $stmt = $pdo->prepare("SELECT tipo_usuario, patrocinador_id FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $user_data = $stmt->fetch();

        if (!$user_data || in_array($user_data['tipo_usuario'], ['master', 'sistema'])) {
            return false;
        }

        $patrocinador_id = $user_data['patrocinador_id'] ?? 1;

        // 1. Obtener tablero actual
        $stmt = $pdo->prepare("SELECT id, tablero_tipo, ciclo FROM tableros_progreso WHERE usuario_id = ? AND estado = 'en_progreso' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$usuario_id]);
        $tablero = $stmt->fetch();

        if (!$tablero) return false;
        $tablero_id   = $tablero['id'];
        $tipo_actual  = $tablero['tablero_tipo'];
        $ciclo_actual = intval($tablero['ciclo']);

        asegurarReservaTableroActual($pdo, $usuario_id, $tipo_actual, $ciclo_actual);

        // 2. CONTEO INTELIGENTE (CASCADA & CICLOS)
        $referidos = 0;
        if ($tipo_actual === 'A') {
            // Para salir de A: 3 referidos que ya tengan al menos un 'regalo' completado en este ciclo o superior
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT tp.usuario_id) as cuenta
                FROM referidos r
                INNER JOIN tableros_progreso tp ON r.id_hijo = tp.usuario_id
                INNER JOIN pagos p ON tp.usuario_id = p.id_emisor
                WHERE r.id_padre = ?
                  AND r.ciclo = ?
                  AND tp.ciclo >= ?
                  AND p.id_receptor = ?
                  AND p.estado = 'completado'
                  AND p.tipo = 'regalo'
            ");
            $stmt->execute([$usuario_id, $ciclo_actual, $ciclo_actual, $usuario_id]);
            $referidos = $stmt->fetch()['cuenta'];
        } elseif ($tipo_actual === 'B') {
            // Para salir de B: 3 referidos que ya estén en B o superior del ciclo actual
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT tp.usuario_id) as cuenta
                FROM referidos r
                INNER JOIN tableros_progreso tp ON r.id_hijo = tp.usuario_id
                WHERE r.id_padre = ?
                  AND r.ciclo = ?
                  AND (tp.tablero_tipo IN ('B', 'C') OR tp.ciclo > ?)
                  AND tp.ciclo >= ?
            ");
            $stmt->execute([$usuario_id, $ciclo_actual, $ciclo_actual, $ciclo_actual]);
            $referidos = $stmt->fetch()['cuenta'];
        } elseif ($tipo_actual === 'C') {
            // Para salir de C: 3 referidos que ya estén en C o superior del ciclo actual
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT tp.usuario_id) as cuenta
                FROM referidos r
                INNER JOIN tableros_progreso tp ON r.id_hijo = tp.usuario_id
                WHERE r.id_padre = ?
                  AND r.ciclo = ?
                  AND (tp.tablero_tipo = 'C' OR tp.ciclo > ?)
                  AND tp.ciclo >= ?
            ");
            $stmt->execute([$usuario_id, $ciclo_actual, $ciclo_actual, $ciclo_actual]);
            $referidos = $stmt->fetch()['cuenta'];
        }

        error_log("AUDIT RADIX: Usuario $usuario_id en Tablero $tipo_actual (C$ciclo_actual) tiene $referidos referidos calificados.");

        if ($referidos >= 3) {
            $nuevo_tipo   = null;
            $finalizado   = false;
            $monto_reserva = 0;
            $destino_reserva = null;
            $monto_usuario = 0;
            $monto_clon   = 0;

            if ($tipo_actual === 'A') {
                $nuevo_tipo    = 'B';
                $destino_reserva = 'B';
                $monto_reserva = 20.00;
                $monto_usuario = 10.00;
                $monto_clon    = 10.00;
            } elseif ($tipo_actual === 'B') {
                $nuevo_tipo    = 'C';
                $destino_reserva = 'C';
                $monto_reserva = 40.00;
                $monto_usuario = 20.00;
                $monto_clon    = 20.00;
            } elseif ($tipo_actual === 'C') {
                $finalizado    = true;
                $monto_usuario = 120.00; // Bruto antes de deducciones
                $monto_clon    = 40.00;
            }

            // Usar transacción propia solo si no hay una activa (evita PDOException en llamadas anidadas)
            $propia_tx = !$pdo->inTransaction();
            if ($propia_tx) $pdo->beginTransaction();

            // Marcar tablero actual como completado
            $stmt = $pdo->prepare("UPDATE tableros_progreso SET estado = 'completado', fecha_fin = NOW() WHERE id = ?");
            $stmt->execute([$tablero_id]);

            // Registrar ganancia bruta para el usuario
            $stmt = $pdo->prepare("
                INSERT INTO pagos (
                    id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                    tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                ) VALUES (1000, ?, ?, NULL, ?, ?, 'reserva_interna', ?, 'ganancia_tablero', 'completado')
            ");
            $stmt->execute([$usuario_id, $usuario_id, $tipo_actual, $ciclo_actual, $monto_usuario]);

            // Aporte a tesorería para futuros clones
            $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal + ? WHERE clave = 'tesoreria_balance'");
            $stmt->execute([$monto_clon]);

            $stmt = $pdo->prepare("
                INSERT INTO pagos (
                    id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                    tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                ) VALUES (?, 1000, 1000, NULL, ?, ?, 'reserva_interna', ?, 'tesoreria_clon', 'completado')
            ");
            $stmt->execute([$usuario_id, $tipo_actual, $ciclo_actual, $monto_clon]);

            if ($nuevo_tipo) {
                // Registrar la reserva interna que financia el siguiente tablero.
                // En el flujo actual la reserva se usa inmediatamente al avanzar.
                $stmt = $pdo->prepare("
                    INSERT INTO reservas_tablero (
                        usuario_id, desde_tablero, hacia_destino, ciclo_origen, ciclo_destino,
                        monto, estado, detalle, fecha_uso
                    ) VALUES (?, ?, ?, ?, ?, ?, 'usado', ?, NOW())
                ");
                $stmt->execute([
                    $usuario_id,
                    $tipo_actual,
                    $destino_reserva,
                    $ciclo_actual,
                    $ciclo_actual,
                    $monto_reserva,
                    "Reserva interna aplicada de Tablero $tipo_actual a Tablero $destino_reserva"
                ]);

                // Avanzar al siguiente tablero del mismo ciclo
                $stmt = $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo) VALUES (?, ?, ?)");
                $stmt->execute([$usuario_id, $nuevo_tipo, $ciclo_actual]);

            } elseif ($finalizado) {
                $nuevo_ciclo = $ciclo_actual + 1;

                // Deducción Salto Fase 1: $100 → fondo de participación en Fase 1
                $stmt = $pdo->prepare("
                    INSERT INTO pagos (
                        id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                        tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                    ) VALUES (?, 1000, 1000, NULL, ?, ?, 'reserva_interna', 100.00, 'salto_fase_1', 'completado')
                ");
                $stmt->execute([$usuario_id, $tipo_actual, $ciclo_actual]);

                $stmt = $pdo->prepare("
                    INSERT INTO reservas_tablero (
                        usuario_id, desde_tablero, hacia_destino, ciclo_origen, ciclo_destino,
                        monto, estado, detalle, fecha_uso
                    ) VALUES (?, 'C', 'FASE1', ?, NULL, 100.00, 'usado', ?, NOW())
                ");
                $stmt->execute([
                    $usuario_id,
                    $ciclo_actual,
                    "Semilla interna de Fase 1 generada al cerrar ciclo $ciclo_actual"
                ]);

                // Deducción Re-entrada: $10 → el usuario vuelve a participar en Fase 1
                $stmt = $pdo->prepare("
                    INSERT INTO pagos (
                        id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                        tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                    ) VALUES (?, 1000, ?, NULL, ?, ?, 'reserva_interna', 10.00, 'reentrada', 'completado')
                ");
                $stmt->execute([$usuario_id, $usuario_id, $tipo_actual, $ciclo_actual]);

                $stmt = $pdo->prepare("
                    INSERT INTO reservas_tablero (
                        usuario_id, desde_tablero, hacia_destino, ciclo_origen, ciclo_destino,
                        monto, estado, detalle, fecha_uso
                    ) VALUES (?, 'C', 'REENTRADA_A', ?, ?, 10.00, 'usado', ?, NOW())
                ");
                $stmt->execute([
                    $usuario_id,
                    $ciclo_actual,
                    $nuevo_ciclo,
                    "Reentrada automática a Tablero A del ciclo $nuevo_ciclo"
                ]);

                // Registrar ingreso de Fase 1 en libro mayor de tesorería
                $stmt = $pdo->prepare("INSERT INTO tesoreria_movimientos (tipo, monto, motivo, relacion_id) VALUES ('ingreso', 100.00, ?, ?)");
                $stmt->execute(["Salto Fase 1 - Usuario ID $usuario_id (ciclo $ciclo_actual)", $usuario_id]);

                // Re-entrada automática: nuevo ciclo en Tablero A
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM tableros_progreso
                    WHERE usuario_id = ? AND tablero_tipo = 'A' AND ciclo = ?
                    LIMIT 1
                ");
                $stmt->execute([$usuario_id, $nuevo_ciclo]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo) VALUES (?, 'A', ?)");
                    $stmt->execute([$usuario_id, $nuevo_ciclo]);
                }
            }

            // Auditoría
            $accion_log = $nuevo_tipo
                ? "AVANCE_TABLERO_{$tipo_actual}_A_{$nuevo_tipo}"
                : "CICLO_COMPLETADO_C{$ciclo_actual}";
            $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (?, ?, 'tableros_progreso', ?)");
            $stmt->execute([$usuario_id, $accion_log, "Tablero $tipo_actual completado. Referidos calificados: $referidos"]);

            if ($propia_tx) $pdo->commit();

            // Notificar al usuario (fuera de la transacción para no bloquear si Telegram falla)
            notificarAvanceTablero($pdo, $usuario_id, $tipo_actual, $monto_usuario);

            // Intentar activar clones con fondos de tesorería (después del commit)
            intentarActivarClon($pdo);
        }

    } catch (Exception $e) {
        if (isset($propia_tx) && $propia_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("RADIX matrix_logic ERROR (usuario $usuario_id): " . $e->getMessage());
        return false;
    }

    return true;
}
?>
