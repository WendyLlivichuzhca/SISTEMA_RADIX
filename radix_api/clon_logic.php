<?php
require_once 'config.php';
require_once 'phase_config.php';
require_once 'matrix_logic.php';
require_once 'notificaciones.php'; // MEJORA #6: Notificaciones Telegram

/**
 * Intenta activar un clon usando los fondos de la tesoreria.
 * Sigue la regla actual manual del sistema, pero ya guardando fase_numero.
 */
function intentarActivarClon($pdo)
{
    try {
        $stmt = $pdo->prepare("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
        $stmt->execute();
        $balance = (float)($stmt->fetch()['valor_decimal'] ?? 0);

        if ($balance < 10) {
            return "Fondos insuficientes en tesoreria ($balance).";
        }

        $stmt = $pdo->prepare("
            SELECT tp.usuario_id, tp.fase_numero, tp.tablero_tipo, tp.ciclo, u.wallet_address,
                   (
                     SELECT COUNT(*)
                     FROM referidos r
                     WHERE r.id_padre = tp.usuario_id
                       AND r.fase_numero = tp.fase_numero
                       AND r.ciclo = tp.ciclo
                   ) AS cuenta_referidos
            FROM tableros_progreso tp
            JOIN usuarios u ON tp.usuario_id = u.id
            WHERE tp.estado = 'en_progreso'
              AND u.tipo_usuario NOT IN ('master', 'sistema')
              AND (
                SELECT COUNT(*)
                FROM referidos r_padre
                JOIN usuarios u_padre ON r_padre.id_hijo = u_padre.id
                WHERE r_padre.id_padre = u.patrocinador_id
                  AND r_padre.fase_numero = tp.fase_numero
                  AND u_padre.tipo_usuario = 'real'
              ) >= 3
              AND (
                SELECT COUNT(*)
                FROM referidos r
                WHERE r.id_padre = tp.usuario_id
                  AND r.fase_numero = tp.fase_numero
                  AND r.ciclo = tp.ciclo
              ) < 3
              AND (
                SELECT COUNT(*)
                FROM referidos r2
                JOIN usuarios u2 ON r2.id_hijo = u2.id
                WHERE r2.id_padre = tp.usuario_id
                  AND r2.fase_numero = tp.fase_numero
                  AND r2.ciclo = tp.ciclo
                  AND u2.tipo_usuario = 'clon'
                  AND r2.fecha_union >= tp.fecha_inicio
              ) = 0
            ORDER BY tp.fase_numero DESC, tp.id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $beneficiario = $stmt->fetch();

        if (!$beneficiario) {
            return "No hay usuarios elegibles para recibir clones en este momento.";
        }

        $padre_id = (int)$beneficiario['usuario_id'];
        $fase_actual = (int)($beneficiario['fase_numero'] ?? 0);
        $ciclo_actual = (int)$beneficiario['ciclo'];
        $tablero_tipo = $beneficiario['tablero_tipo'];
        $cfg_tablero = getPhaseBoardConfig($pdo, $fase_actual, $tablero_tipo);
        $monto_clon = (float)($cfg_tablero['clon_monto'] ?? 0);

        if ($balance < $monto_clon) {
            return "Tesoreria tiene $balance, pero el clon para Tablero $tablero_tipo necesita $monto_clon.";
        }

        $clon_wallet = "0xCLON_" . bin2hex(random_bytes(4));
        $clon_nickname = "RADIX_CLON_" . rand(1000, 9999);

        $propia_tx = !$pdo->inTransaction();
        if ($propia_tx) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare("INSERT INTO usuarios (wallet_address, nickname, tipo_usuario, patrocinador_id) VALUES (?, ?, 'clon', ?)");
        $stmt->execute([$clon_wallet, $clon_nickname, $padre_id]);
        $clon_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado)
            VALUES (?, ?, ?, ?, 'en_progreso')
        ");
        $stmt->execute([$clon_id, $fase_actual, $tablero_tipo, $ciclo_actual]);

        $posicion = ((int)$beneficiario['cuenta_referidos']) + 1;
        $stmt = $pdo->prepare("
            INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo)
            VALUES (?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$padre_id, $clon_id, $fase_actual, $posicion, $ciclo_actual]);

        $stmt = $pdo->prepare("
            INSERT INTO pagos (
                id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, wallet_destino_real,
                tablero_tipo, ciclo, origen_fondos, monto, monto_pagado,
                tipo, estado, fecha_confirmacion
            ) VALUES (?, ?, ?, ?, NULL, ?, ?, 'tesoreria', ?, ?, 'regalo', 'completado', NOW())
        ");
        $stmt->execute([$clon_id, $padre_id, $padre_id, $fase_actual, $tablero_tipo, $ciclo_actual, $monto_clon, $monto_clon]);

        $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal - ? WHERE clave = 'tesoreria_balance'");
        $stmt->execute([$monto_clon]);

        $stmt = $pdo->prepare("
            INSERT INTO tesoreria_movimientos (tipo, monto, motivo, relacion_id)
            VALUES ('egreso', ?, ?, ?)
        ");
        $stmt->execute([$monto_clon, "Activacion de Clon $clon_nickname para Usuario ID $padre_id en fase $fase_actual", $padre_id]);

        $stmt = $pdo->prepare("
            INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
            VALUES (?, ?, 'ACTIVACION_CLON', 'usuarios', ?)
        ");
        $stmt->execute([$padre_id, $fase_actual, "Clon $clon_nickname generado con \$$monto_clon de tesoreria."]);

        if ($propia_tx) {
            $pdo->commit();
        }

        notificarClonActivado($pdo, $padre_id, (float)$monto_clon);
        verificarAvanceTablero($padre_id, $pdo);

        return "Clon $clon_nickname activado para usuario ID $padre_id (\$$monto_clon USDT).";
    } catch (Exception $e) {
        if (isset($propia_tx) && $propia_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("RADIX clon_logic ERROR: " . $e->getMessage());
        return "Error: " . $e->getMessage();
    }
}
?>
