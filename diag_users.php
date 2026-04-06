<?php
require_once 'radix_api/config.php';

$emails = ['yolandamoraj@gmail.com', 'ronaldpv66@gmail.com'];

echo "=== DIAGNOSTICO DE USUARIOS ===\n";

foreach ($emails as $email) {
    echo "\nBuscando: $email\n";
    $stmt = $pdo->prepare("SELECT id, nickname, wallet_address, fecha_registro, tipo_usuario FROM usuarios WHERE correo_electronico = ? OR nickname LIKE ?");
    $stmt->execute([$email, "%".explode('@', $email)[0]."%"]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$users) {
        echo "No se encontro ningun registro.\n";
        continue;
    }

    foreach ($users as $u) {
        echo "[ID: {$u['id']}] Nickname: {$u['nickname']} | Wallet: {$u['wallet_address']} | Fecha: {$u['fecha_registro']}\n";
        
        // Ver pagos
        $stmt_p = $pdo->prepare("SELECT id, monto, tipo, estado, fecha_pago FROM pagos WHERE id_emisor = ? ORDER BY id DESC LIMIT 3");
        $stmt_p->execute([$u['id']]);
        $pagos = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
        echo "  - Ultimos Pagos: " . (empty($pagos) ? "Ninguno" : "") . "\n";
        foreach ($pagos as $p) {
            echo "    - Pago ID {$p['id']}: \${$p['monto']} ({$p['tipo']}) -> ESTADO: {$p['estado']} [{$p['fecha_pago']}]\n";
        }

        // Ver tablero
        $stmt_t = $pdo->prepare("SELECT id, fase_numero, tablero_tipo, estado FROM tableros_progreso WHERE usuario_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_t->execute([$u['id']]);
        $tablero = $stmt_t->fetch(PDO::FETCH_ASSOC);
        echo "  - Tablero Actual: " . ($tablero ? "Fase {$tablero['fase_numero']} {$tablero['tablero_tipo']} ({$tablero['estado']})" : "NINGUNO (INACTIVO)") . "\n";
    }
}
?>
