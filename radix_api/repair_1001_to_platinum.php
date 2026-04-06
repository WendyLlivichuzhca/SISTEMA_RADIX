<?php
/**
 * repair_1001_to_platinum.php
 * Transfiere al usuario 1001 a la Fase 3 Platinum simulando el éxito en fases previas.
 */
require_once 'config.php';

header('Content-Type: text/plain');

$target_user_id = 1001;

try {
    $pdo->beginTransaction();

    // 1. Verificar existencia
    $stmt = $pdo->prepare("SELECT nickname FROM usuarios WHERE id = ?");
    $stmt->execute([$target_user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("Usuario 1001 no encontrado en la base de datos.");
    }

    echo "Iniciando teletransporte para: " . $user['nickname'] . " (ID $target_user_id)\n";

    // 2. Limpiar rastros de fases 1, 2 y 3 para empezar de cero la simulación
    $pdo->prepare("DELETE FROM tableros_progreso WHERE usuario_id = ? AND fase_numero >= 1")->execute([$target_user_id]);
    $pdo->prepare("DELETE FROM referidos WHERE id_padre = ? AND fase_numero >= 1")->execute([$target_user_id]);
    $pdo->prepare("DELETE FROM pagos WHERE (id_emisor = ? OR id_receptor = ?) AND fase_numero >= 1")->execute([$target_user_id, $target_user_id]);

    // --- SIMULACIÓN FASE 1 ($100 entrada) ---
    echo "Simulando Fase 1...\n";
    // Tableros A, B y C completados
    $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado) VALUES (?, 1, 'A', 1, 'completado'), (?, 1, 'B', 1, 'completado'), (?, 1, 'C', 1, 'completado')")->execute([$target_user_id, $target_user_id, $target_user_id]);
    
    // Ganancias F1 ($100 + $200 + $1200 = $1500 brutos)
    $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, monto, tipo, estado) VALUES (1000, ?, ?, 1, 'A', 100, 'ganancia_tablero', 'completado'), (1000, ?, ?, 1, 'B', 200, 'ganancia_tablero', 'completado'), (1000, ?, ?, 1, 'C', 1200, 'ganancia_tablero', 'completado')")->execute([$target_user_id, $target_user_id, $target_user_id, $target_user_id, $target_user_id, $target_user_id]);

    // Deducciones F1 ($1000 salto F2 + $100 reentrada = $1100 deducciones) -> Neto $400
    $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, monto, tipo, estado) VALUES (?, 1000, 1000, 1, 'C', 1000, 'salto_fase_2', 'completado'), (?, 1000, 1000, 1, 'C', 100, 'reentrada', 'completado')")->execute([$target_user_id, $target_user_id]);


    // --- SIMULACIÓN FASE 2 ($1000 entrada) ---
    echo "Simulando Fase 2...\n";
    // Tableros A, B y C completados
    $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado) VALUES (?, 2, 'A', 1, 'completado'), (?, 2, 'B', 1, 'completado'), (?, 2, 'C', 1, 'completado')")->execute([$target_user_id, $target_user_id, $target_user_id]);
    
    // Ganancias F2 ($1000 + $2000 + $12000 = $15000 brutos)
    $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, monto, tipo, estado) VALUES (1000, ?, ?, 2, 'A', 1000, 'ganancia_tablero', 'completado'), (1000, ?, ?, 2, 'B', 2000, 'ganancia_tablero', 'completado'), (1000, ?, ?, 2, 'C', 12000, 'ganancia_tablero', 'completado')")->execute([$target_user_id, $target_user_id, $target_user_id, $target_user_id, $target_user_id, $target_user_id]);

    // Deducciones F2 ($10000 salto F3 + $1000 reentrada = $11000 deducciones) -> Neto $4000
    $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, monto, tipo, estado) VALUES (?, 1000, 1000, 2, 'C', 10000, 'salto_fase_3', 'completado'), (?, 1000, 1000, 2, 'C', 1000, 'reentrada', 'completado')")->execute([$target_user_id, $target_user_id]);


    // --- INSERTAR EN FASE 3 PLATINUM (TABLERO C) ---
    echo "Colocando en Fase 3 Tablero C...\n";
    $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado) VALUES (?, 3, 'C', 1, 'en_progreso')")->execute([$target_user_id]);

    $pdo->commit();
    echo "\n✅ EXITOSO: El usuario 1001 ahora está en Fase 3 Tablero C.\n";
    echo "Debería tener acumulados aproximadamente \$4,440 en ganancias de fases previas (F0 + F1 + F2).\n";
    echo "Entra a su Dashboard para verificar.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n❌ ERROR: " . $e->getMessage();
}
