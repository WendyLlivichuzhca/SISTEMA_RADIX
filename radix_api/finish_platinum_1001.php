<?php
/**
 * finish_platinum_1001.php
 * Simula la llegada de 3 referidos al Tablero C de la Fase 3 para el usuario 1001
 * y dispara la liquidación final Platinum.
 */
require_once 'config.php';
require_once 'matrix_logic.php';

header('Content-Type: text/plain');

$target_user_id = 1001;

try {
    $pdo->beginTransaction();

    echo "--- SIMULACIÓN DE CIERRE PLATINUM (USER 1001) ---\n";

    // 1. Asegurar que está en Fase 3 Tablero C
    $stmt = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = 3 AND tablero_tipo = 'C' AND estado = 'en_progreso'");
    $stmt->execute([$target_user_id]);
    if (!$stmt->fetch()) {
        throw new Exception("El usuario 1001 no tiene un Tablero C de Fase 3 'en_progreso'. Ejecuta primero el script de teletransporte.");
    }

    // 2. Simular pagos previos de Fase 3 (A y B) para que el balance sea real ($10k + $20k)
    echo "Simulando pagos previos de Fase 3 (A y B)...\n";
    $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, ciclo, monto, tipo, estado) VALUES (1000, ?, ?, 3, 'A', 1, 10000, 'ganancia_tablero', 'completado')")->execute([$target_user_id, $target_user_id]);
    $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, ciclo, monto, tipo, estado) VALUES (1000, ?, ?, 3, 'B', 1, 20000, 'ganancia_tablero', 'completado')")->execute([$target_user_id, $target_user_id]);

    // 3. Crear 3 referidos Platinum para el Tablero C
    echo "Registrando 3 referidos calificados para el cierre...\n";
    for ($i = 1; $i <= 3; $i++) {
        $ref_nick = "PLATINUM_REF_FINAL_{$i}";
        
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE nickname = ?");
        $stmt->execute([$ref_nick]);
        $ref = $stmt->fetch();
        if (!$ref) {
            $pdo->prepare("INSERT INTO usuarios (nickname, wallet_address, tipo_usuario) VALUES (?, ?, 'real')")
                ->execute([$ref_nick, "0xWALLET_REF_PLAT_{$i}"]);
            $ref_id = $pdo->lastInsertId();
        } else {
            $ref_id = $ref['id'];
        }

        // Limpiar para evitar duplicados en la prueba
        $pdo->prepare("DELETE FROM referidos WHERE id_hijo = ? AND fase_numero = 3")->execute([$ref_id]);
        $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo) VALUES (?, ?, 3, ?, 1, 1)")
            ->execute([$target_user_id, $ref_id, $i]);

        // --- CLAVE: El referido también debe estar en el Tablero C para calificar ---
        $pdo->prepare("DELETE FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = 3")->execute([$ref_id]);
        $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado) VALUES (?, 3, 'C', 1, 'completado')")
            ->execute([$ref_id]);

        // Simular que cada uno paga $40k
        $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, ciclo, monto, tipo, estado) VALUES (?, ?, ?, 3, 'C', 1, 40000, 'regalo', 'completado')")
            ->execute([$ref_id, $target_user_id, $target_user_id]);
    }

    // 4. DISPARAR EL MOTOR DE AVANCE
    echo "Ejecutando motor de cierre Platinum...\n";
    $res = verificarAvanceTablero($target_user_id, $pdo, true); // Usar la versión simplificada del motor

    if (!$res) {
        throw new Exception("El motor devolvió FALSE. Algo falló en la lógica de cierre.");
    }

    // 4. RESULTADOS CONTABLES EN PAGO
    echo "\n--- AUDITORÍA DE RESULTADOS ---\n";
    
    // Buscar la Utilidad Master
    $stmt = $pdo->prepare("SELECT monto FROM pagos WHERE id_emisor = ? AND tipo = 'utilidad_master' AND fase_numero = 3 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$target_user_id]);
    $u_master = $stmt->fetchColumn();
    echo "🏆 Utilidad Master Generada: " . ($u_master ? "\$$u_master (OK)" : "ERROR: NO GENERADA") . "\n";

    // Buscar la Reentrada
    $stmt = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = 3 AND tablero_tipo = 'A' AND ciclo = 2 LIMIT 1");
    $stmt->execute([$target_user_id]);
    $reent = $stmt->fetch();
    echo "🔁 Reentrada Ciclo 2: " . ($reent ? "EXITOSA (OK)" : "ERROR: NO ENCONTRADA") . "\n";

    // Verificar Estado del Tablero C
    $stmt = $pdo->prepare("SELECT estado FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = 3 AND tablero_tipo = 'C' AND ciclo = 1");
    $stmt->execute([$target_user_id]);
    $est_c = $stmt->fetchColumn();
    echo "🌑 Estado Tablero C1: " . ($est_c === 'completado' ? "COMPLETADO (OK)" : "ERROR: $est_c") . "\n";

    $pdo->commit();
    echo "\n✅ CIERRE FINALIZADO CON ÉXITO.\n";
    echo "Entra al Dashboard del 1001 y verás el balance histórico de \$100k+.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n❌ ERROR CRÍTICO: " . $e->getMessage();
}
