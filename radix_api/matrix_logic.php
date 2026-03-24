<?php
require_once 'config.php';
require_once 'clon_logic.php'; // Para activar clones automáticamente

/**
 * Función para verificar y avanzar a un usuario de tablero (Fase 0)
 * Lógica: A ($10) -> B ($20) -> C ($40) -> Salto Fase 1 ($100)
 */
function verificarAvanceTablero($usuario_id, $pdo) {
    try {
        // 1. Obtener tablero actual
        $stmt = $pdo->prepare("SELECT id, tablero_tipo, ciclo FROM tableros_progreso WHERE usuario_id = ? AND estado = 'en_progreso' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$usuario_id]);
        $tablero = $stmt->fetch();

        if (!$tablero) return false;
        $tablero_id = $tablero['id'];
        $tipo_actual = $tablero['tablero_tipo'];
        $ciclo_actual = $tablero['ciclo'];

        // 2. Contar referidos directos en el tablero actual
        $stmt = $pdo->prepare("SELECT COUNT(*) as cuenta FROM referidos WHERE id_padre = ?");
        $stmt->execute([$usuario_id]);
        $referidos = $stmt->fetch()['cuenta'];

        // 3. Lógica de avance (Se requiere 3 referidos para completar el ciclo de 4x)
        if ($referidos >= 3) {
            $nuevo_tipo = null;
            $finalizado = false;
            $monto_usuario = 0;
            $monto_clon = 0;

            if ($tipo_actual === 'A') {
                $nuevo_tipo = 'B';
                $monto_usuario = 10.00;
                $monto_clon = 10.00;
            } elseif ($tipo_actual === 'B') {
                $nuevo_tipo = 'C';
                $monto_usuario = 20.00;
                $monto_clon = 20.00;
            } elseif ($tipo_actual === 'C') {
                $finalizado = true;
                $monto_usuario = 120.00;
                $monto_clon = 40.00;
            }

            $pdo->beginTransaction();

            // Marcar actual como completado
            $stmt = $pdo->prepare("UPDATE tableros_progreso SET estado = 'completado', fecha_fin = NOW() WHERE id = ?");
            $stmt->execute([$tablero_id]);

            // A. Registrar Ganancia para el Usuario
            $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (0, ?, ?, 'ganancia_tablero', 'completado')");
            $stmt->execute([$usuario_id, $monto_usuario]);

            // B. Registrar Aporte a Tesorería (Alimentar Clones)
            $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal + ? WHERE clave = 'tesoreria_balance'");
            $stmt->execute([$monto_clon]);
            
            $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, 0, ?, 'tesoreria_clon', 'completado')");
            $stmt->execute([$usuario_id, $monto_clon]);

            if ($nuevo_tipo) {
                // AVANCE A SIGUIENTE TABLERO
                $stmt = $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo) VALUES (?, ?, ?)");
                $stmt->execute([$usuario_id, $nuevo_tipo, $ciclo_actual]);
            } elseif ($finalizado) {
                // CIERRE DE FASE 0: Liquidación final
                
                // 1. Retención Salto Fase 1 ($100)
                $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, 0, 100.00, 'salto_fase_1', 'completado')");
                $stmt->execute([$usuario_id]);

                // 2. Retención Re-entrada ($10)
                $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, 0, 10.00, 'reentrada', 'completado')");
                $stmt->execute([$usuario_id]);

                // 3. Crear nuevo Ciclo (Re-entrada automática)
                $nuevo_ciclo = $ciclo_actual + 1;
                $stmt = $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo) VALUES (?, 'A', ?)");
                $stmt->execute([$usuario_id, $nuevo_ciclo]);

                // Limpiar referidos para el nuevo ciclo
                $stmt = $pdo->prepare("DELETE FROM referidos WHERE id_padre = ?");
                $stmt->execute([$usuario_id]);
            }

            $pdo->commit();

            // Disparar activación de clon si hay fondos (IA Agent en acción)
            intentarActivarClon($pdo);

            return true;
        }
        return false;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}
?>
    }
}
?>
