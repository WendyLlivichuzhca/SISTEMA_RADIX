<?php
/**
 * limpiar_fundador.php
 * Elimina el registro del usuario fundador (patrocinador_id = NULL, tipo_usuario = 'real')
 * para permitir un re-registro limpio con el nuevo flujo de pago a RADIX_MASTER.
 *
 * USAR UNA SOLA VEZ y luego ELIMINAR este archivo del servidor.
 */
require_once 'config.php';

// ── Seguridad: solo desde IP local o con clave secreta ──────────────────────
$clave = $_GET['clave'] ?? '';
if ($clave !== 'RADIX_RESET_2024') {
    http_response_code(403);
    die(json_encode(['error' => 'Acceso denegado. Debes pasar ?clave=RADIX_RESET_2024']));
}

try {
    // Buscar el usuario fundador: tipo_usuario = 'real' y patrocinador_id IS NULL
    $stmt = $pdo->prepare("SELECT id, wallet_address, nickname FROM usuarios WHERE patrocinador_id IS NULL AND tipo_usuario = 'real' LIMIT 1");
    $stmt->execute();
    $fundador = $stmt->fetch();

    if (!$fundador) {
        die(json_encode(['mensaje' => 'No se encontró ningún usuario fundador (real sin patrocinador). Nada que borrar.']));
    }

    $uid = $fundador['id'];

    $pdo->beginTransaction();

    // 1. Borrar pagos relacionados
    $stmt = $pdo->prepare("DELETE FROM pagos WHERE id_emisor = ? OR id_receptor = ?");
    $stmt->execute([$uid, $uid]);
    $pagos_borrados = $stmt->rowCount();

    // 2. Borrar progreso de tableros
    $stmt = $pdo->prepare("DELETE FROM tableros_progreso WHERE usuario_id = ?");
    $stmt->execute([$uid]);
    $tableros_borrados = $stmt->rowCount();

    // 3. Borrar referidos (como padre o hijo)
    $stmt = $pdo->prepare("DELETE FROM referidos WHERE id_padre = ? OR id_hijo = ?");
    $stmt->execute([$uid, $uid]);
    $referidos_borrados = $stmt->rowCount();

    // 4. Borrar logs de auditoría
    $stmt = $pdo->prepare("DELETE FROM auditoria_logs WHERE usuario_id = ?");
    $stmt->execute([$uid]);
    $logs_borrados = $stmt->rowCount();

    // 5. Finalmente borrar el usuario
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$uid]);

    $pdo->commit();

    echo json_encode([
        'success'           => true,
        'mensaje'           => '✅ Registro del fundador eliminado correctamente. Ya puedes registrarte de nuevo.',
        'usuario_eliminado' => [
            'id'             => $uid,
            'wallet'         => $fundador['wallet_address'],
            'nickname'       => $fundador['nickname'],
        ],
        'registros_borrados' => [
            'pagos'          => $pagos_borrados,
            'tableros'       => $tableros_borrados,
            'referidos'      => $referidos_borrados,
            'auditoria_logs' => $logs_borrados,
        ],
        'importante'        => '⚠️ Elimina el archivo limpiar_fundador.php del servidor ahora.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("limpiar_fundador ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error al limpiar. Revisa los logs del servidor.']);
}
?>
