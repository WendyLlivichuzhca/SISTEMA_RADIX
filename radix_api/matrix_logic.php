<?php
require_once 'config.php';
require_once 'clon_logic.php';       // Para activar clones automáticamente
require_once 'notificaciones.php';   // MEJORA #6: Notificaciones Telegram

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
                  AND tp.ciclo >= ?
                  AND p.id_receptor = ?
                  AND p.estado = 'completado'
                  AND p.tipo = 'regalo'
            ");
            $stmt->execute([$usuario_id, $ciclo_actual, $usuario_id]);
            $referidos = $stmt->fetch()['cuenta'];
        } elseif ($tipo_actual === 'B') {
            // Para salir de B: 3 referidos que ya estén en B o superior del ciclo actual
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT tp.usuario_id) as cuenta
                FROM referidos r
                INNER JOIN tableros_progreso tp ON r.id_hijo = tp.usuario_id
                WHERE r.id_padre = ?
                  AND (tp.tablero_tipo IN ('B', 'C') OR tp.ciclo > ?)
                  AND tp.ciclo >= ?
            ");
            $stmt->execute([$usuario_id, $ciclo_actual, $ciclo_actual]);
            $referidos = $stmt->fetch()['cuenta'];
        } elseif ($tipo_actual === 'C') {
            // Para salir de C: 3 referidos que ya estén en C o superior del ciclo actual
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT tp.usuario_id) as cuenta
                FROM referidos r
                INNER JOIN tableros_progreso tp ON r.id_hijo = tp.usuario_id
                WHERE r.id_padre = ?
                  AND (tp.tablero_tipo = 'C' OR tp.ciclo > ?)
                  AND tp.ciclo >= ?
            ");
            $stmt->execute([$usuario_id, $ciclo_actual, $ciclo_actual]);
            $referidos = $stmt->fetch()['cuenta'];
        }

        error_log("AUDIT RADIX: Usuario $usuario_id en Tablero $tipo_actual (C$ciclo_actual) tiene $referidos referidos calificados.");

        if ($referidos >= 3) {
            $nuevo_tipo   = null;
            $finalizado   = false;
            $monto_usuario = 0;
            $monto_clon   = 0;

            if ($tipo_actual === 'A') {
                $nuevo_tipo    = 'B';
                $monto_usuario = 10.00;
                $monto_clon    = 10.00;
            } elseif ($tipo_actual === 'B') {
                $nuevo_tipo    = 'C';
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
            $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (1000, ?, ?, 'ganancia_tablero', 'completado')");
            $stmt->execute([$usuario_id, $monto_usuario]);

            // Aporte a tesorería para futuros clones
            $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal + ? WHERE clave = 'tesoreria_balance'");
            $stmt->execute([$monto_clon]);

            $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, 1000, ?, 'tesoreria_clon', 'completado')");
            $stmt->execute([$usuario_id, $monto_clon]);

            if ($nuevo_tipo) {
                // Avanzar al siguiente tablero del mismo ciclo
                $stmt = $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo) VALUES (?, ?, ?)");
                $stmt->execute([$usuario_id, $nuevo_tipo, $ciclo_actual]);

            } elseif ($finalizado) {
                // Deducción Salto Fase 1: $100 → fondo de participación en Fase 1
                $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, 1000, 100.00, 'salto_fase_1', 'completado')");
                $stmt->execute([$usuario_id]);

                // Deducción Re-entrada: $10 → el usuario vuelve a participar en Fase 1
                $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, 1000, 10.00, 'reentrada', 'completado')");
                $stmt->execute([$usuario_id]);

                // Registrar ingreso de Fase 1 en libro mayor de tesorería
                $stmt = $pdo->prepare("INSERT INTO tesoreria_movimientos (tipo, monto, motivo, relacion_id) VALUES ('ingreso', 100.00, ?, ?)");
                $stmt->execute(["Salto Fase 1 - Usuario ID $usuario_id (ciclo $ciclo_actual)", $usuario_id]);

                // Re-entrada automática: nuevo ciclo en Tablero A
                $nuevo_ciclo = $ciclo_actual + 1;
                $stmt = $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo) VALUES (?, 'A', ?)");
                $stmt->execute([$usuario_id, $nuevo_ciclo]);
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
