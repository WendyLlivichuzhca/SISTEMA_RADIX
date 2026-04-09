<?php
require_once 'config.php';
require_once 'network_placement.php';
require_once __DIR__ . '/notificaciones.php';
require_once __DIR__ . '/master_notif.php';
session_start();

function obtenerCicloActivoUsuario(PDO $pdo, int $usuario_id): int
{
    $stmt = $pdo->prepare("
        SELECT ciclo
        FROM tableros_progreso
        WHERE usuario_id = ?
          AND fase_numero = 0
        ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$usuario_id]);
    $ciclo = $stmt->fetchColumn();
    return $ciclo ? (int)$ciclo : 1;
}

function normalizarTelegramUsername(string $value): string
{
    $value = preg_replace('/\s+/', '', trim($value));
    return ltrim($value, '@');
}

function telegramUsernameEsValido(string $value): bool
{
    return $value === '' || (bool)preg_match('/^[A-Za-z0-9_]{5,32}$/', $value);
}

function telefonoEsValido(string $value): bool
{
    $digits = preg_replace('/\D+/', '', $value);
    $length = strlen($digits);
    return $length >= 7 && $length <= 20;
}

function actualizarDatosContactoUsuario(
    PDO $pdo,
    array $contact_columns,
    int $user_id,
    string $nombre_completo,
    string $telefono,
    string $correo_electronico,
    string $telegram_username = '',
    string $password = ''
): void {
    $update_fields = [];
    $update_values = [];

    if (!empty($nombre_completo) && !empty($contact_columns['nombre_completo'])) {
        $update_fields[] = "nombre_completo = ?";
        $update_values[] = $nombre_completo;
    }
    if (!empty($telefono) && !empty($contact_columns['telefono'])) {
        $update_fields[] = "telefono = ?";
        $update_values[] = $telefono;
    }
    if (!empty($correo_electronico) && !empty($contact_columns['correo_electronico'])) {
        $update_fields[] = "correo_electronico = ?";
        $update_values[] = $correo_electronico;
    }
    if ($telegram_username !== '' && !empty($contact_columns['telegram_username'])) {
        $update_fields[] = "telegram_username = ?";
        $update_values[] = $telegram_username;
    }
    if ($password !== '' && !empty($contact_columns['password_hash'])) {
        $update_fields[] = "password_hash = ?";
        $update_values[] = password_hash($password, PASSWORD_DEFAULT);
    }

    if (empty($update_fields)) {
        return;
    }

    $update_values[] = $user_id;
    $stmt = $pdo->prepare("UPDATE usuarios SET " . implode(', ', $update_fields) . " WHERE id = ?");
    $stmt->execute($update_values);
}

function obtenerPropietarioFlujoRegalo(PDO $pdo, int $receptor_id): string
{
    $stmt = $pdo->prepare("SELECT tipo_usuario FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$receptor_id]);
    $tipo_usuario = $stmt->fetchColumn();

    return $tipo_usuario === 'clon' ? 'sistema' : 'usuario';
}

function asegurarPagoPendienteRegalo(PDO $pdo, int $emisor_id, int $receptor_id, string $wallet_destino_real): void
{
    $propietario_flujo = obtenerPropietarioFlujoRegalo($pdo, $receptor_id);

    $stmt = $pdo->prepare("
        SELECT id
        FROM pagos
        WHERE id_emisor = ?
          AND tipo = 'regalo'
          AND estado = 'pendiente'
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$emisor_id]);
    $pago_pendiente_id = $stmt->fetchColumn();

    if ($pago_pendiente_id) {
        $stmt = $pdo->prepare("
            UPDATE pagos
            SET id_receptor = ?,
                beneficiario_usuario_id = ?,
                propietario_flujo = ?,
                wallet_destino_real = ?,
                estado = 'pendiente'
            WHERE id = ?
        ");
        $stmt->execute([$receptor_id, $receptor_id, $propietario_flujo, $wallet_destino_real, $pago_pendiente_id]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO pagos (
            id_emisor, id_receptor, beneficiario_usuario_id, propietario_flujo, wallet_destino_real,
            tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
        ) VALUES (?, ?, ?, ?, ?, 'A', 1, 'externo', 10.00, 'regalo', 'pendiente')
    ");
    $stmt->execute([$emisor_id, $receptor_id, $receptor_id, $propietario_flujo, $wallet_destino_real]);
}

function radix_establecer_sesion_usuario(PDO $pdo, int $user_id): void
{
    $stmt_cols = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME = 'nombre_completo'
    ");
    $stmt_cols->execute();
    $has_nombre_completo = (bool)$stmt_cols->fetchColumn();

    $displayNameSelect = $has_nombre_completo
        ? "COALESCE(NULLIF(nombre_completo, ''), nickname) AS display_name"
        : "nickname AS display_name";

    $stmt = $pdo->prepare("
        SELECT
            id,
            wallet_address,
            nickname,
            {$displayNameSelect},
            tipo_usuario
        FROM usuarios
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new RuntimeException('No se encontro el usuario para iniciar sesion.');
    }

    $_SESSION['radix_wallet'] = $user['wallet_address'];
    $_SESSION['radix_user_id'] = $user['id'];
    $_SESSION['radix_nickname'] = $user['display_name'] ?: $user['nickname'];
    $_SESSION['tipo_usuario'] = $user['tipo_usuario'];

    unset($_SESSION['radix_wallet_verificada'], $_SESSION['radix_verificada_at']);
}

function radix_finalizar_acceso(PDO $pdo, int $user_id, string $message): void
{
    radix_establecer_sesion_usuario($pdo, $user_id);
    sendResponse(['success' => true, 'user_id' => $user_id, 'message' => $message]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Metodo no permitido'], 405);
}

$wallet = trim($_POST['wallet'] ?? '');
$nickname = trim($_POST['nickname'] ?? '');
$nombre_completo = trim($_POST['nombre_completo'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$correo_electronico = trim($_POST['correo_electronico'] ?? '');
$telegram_username = normalizarTelegramUsername((string)($_POST['telegram_username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$password_confirm = (string)($_POST['password_confirm'] ?? '');
$patrocinador_wallet = trim((string)($_POST['patrocinador'] ?? ''));
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$password_min_length = 8;

if ($patrocinador_wallet === '') {
    $patrocinador_wallet = null;
}

if ($nombre_completo === '') {
    sendResponse(['error' => 'El nombre completo es obligatorio.'], 400);
}

if (mb_strlen($nombre_completo) < 3) {
    sendResponse(['error' => 'El nombre completo debe tener al menos 3 caracteres.'], 400);
}

if ($telefono === '') {
    sendResponse(['error' => 'El telefono es obligatorio.'], 400);
}

if (!telefonoEsValido($telefono)) {
    sendResponse(['error' => 'El telefono no es valido.'], 400);
}

if ($correo_electronico === '') {
    sendResponse(['error' => 'El correo electronico es obligatorio.'], 400);
}

if ($wallet === '') {
    sendResponse(['error' => 'La wallet es obligatoria.'], 400);
}

if (!radix_is_valid_tron_wallet($wallet)) {
    sendResponse(['error' => 'La wallet debe ser una direccion valida de la red TRON.'], 400);
}

if ($patrocinador_wallet !== null && !radix_is_valid_tron_wallet($patrocinador_wallet)) {
    sendResponse(['error' => 'La wallet del patrocinador no es valida en la red TRON.'], 400);
}

if ($password === '') {
    sendResponse(['error' => 'La contrasena es obligatoria.'], 400);
}

if (strlen($password) < $password_min_length) {
    sendResponse(['error' => 'La contrasena debe tener al menos 8 caracteres.'], 400);
}

if ($password_confirm === '') {
    sendResponse(['error' => 'Debes confirmar la contrasena.'], 400);
}

if (!hash_equals($password, $password_confirm)) {
    sendResponse(['error' => 'Las contrasenas no coinciden.'], 400);
}

if (!filter_var($correo_electronico, FILTER_VALIDATE_EMAIL)) {
    sendResponse(['error' => 'El correo electronico no es valido.'], 400);
}

if (!telegramUsernameEsValido($telegram_username)) {
    sendResponse(['error' => 'El usuario de Telegram no es valido. Usa solo letras, numeros o guion bajo, entre 5 y 32 caracteres.'], 400);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios'
          AND COLUMN_NAME IN ('nombre_completo', 'telefono', 'correo_electronico', 'telegram_username', 'password_hash')
    ");
    $contact_columns = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

    if (empty($contact_columns['password_hash'])) {
        $pdo->rollBack();
        sendResponse(['error' => 'Falta habilitar el campo de contrasena en la base de datos.'], 500);
    }

    $passwordSelect = ", password_hash";
    $stmt = $pdo->prepare("SELECT id, patrocinador_id, tipo_usuario{$passwordSelect} FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $existing_user = $stmt->fetch();

    if ($existing_user) {
        $stored_hash = (string)($existing_user['password_hash'] ?? '');
        if ($stored_hash === '') {
            $pdo->rollBack();
            sendResponse(['error' => 'Esta wallet ya existe pero no tiene contrasena configurada. Contacta a soporte para recuperarla.'], 409);
        }

        if (!password_verify($password, $stored_hash)) {
            $pdo->rollBack();
            sendResponse(['error' => 'Esta wallet ya existe. Usa la contrasena correcta para continuar o inicia sesion.'], 401);
        }
    }

    if ($patrocinador_wallet && strcasecmp($wallet, $patrocinador_wallet) === 0) {
        $pdo->rollBack();
        sendResponse(['error' => 'No puedes ser tu propio patrocinador.'], 400);
    }

    if ($existing_user && in_array($existing_user['tipo_usuario'], ['master', 'sistema'], true)) {
        actualizarDatosContactoUsuario($pdo, $contact_columns, (int)$existing_user['id'], $nombre_completo, $telefono, $correo_electronico, $telegram_username, $password);
        $pdo->commit();
        radix_finalizar_acceso($pdo, (int)$existing_user['id'], 'Login exitoso');
    }

    if ($existing_user && $existing_user['patrocinador_id'] !== null) {
        actualizarDatosContactoUsuario($pdo, $contact_columns, (int)$existing_user['id'], $nombre_completo, $telefono, $correo_electronico, $telegram_username, $password);
        $pdo->commit();
        radix_finalizar_acceso($pdo, (int)$existing_user['id'], 'Login exitoso');
    }

    if ($existing_user && $existing_user['patrocinador_id'] === null) {
        $stmt_chk = $pdo->prepare("
            SELECT id, estado
            FROM pagos
            WHERE id_emisor = ?
              AND tipo = 'regalo'
              AND estado IN ('pendiente', 'completado')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt_chk->execute([$existing_user['id']]);
        $pago_existente = $stmt_chk->fetch();

        if ($pago_existente) {
            if ($pago_existente['estado'] === 'completado') {
                actualizarDatosContactoUsuario($pdo, $contact_columns, (int)$existing_user['id'], $nombre_completo, $telefono, $correo_electronico, $telegram_username, $password);
                $pdo->commit();
                radix_finalizar_acceso($pdo, (int)$existing_user['id'], 'Login exitoso');
            }

            if ($pago_existente['estado'] === 'pendiente' && empty($patrocinador_wallet)) {
                actualizarDatosContactoUsuario($pdo, $contact_columns, (int)$existing_user['id'], $nombre_completo, $telefono, $correo_electronico, $telegram_username, $password);
                $pdo->commit();
                radix_finalizar_acceso($pdo, (int)$existing_user['id'], 'Login exitoso');
            }
        }
    }

    $new_user_id = $existing_user ? (int)$existing_user['id'] : null;

    $patrocinador_id = null;
    $patrocinador_tipo = null;
    if ($patrocinador_wallet) {
        $stmt = $pdo->prepare("SELECT id, tipo_usuario FROM usuarios WHERE wallet_address = ? LIMIT 1");
        $stmt->execute([$patrocinador_wallet]);
        $res = $stmt->fetch();

        if (!$res) {
            $pdo->rollBack();
            sendResponse(['error' => 'Patrocinador no encontrado o link invalido.'], 400);
        }

        $patrocinador_id = (int)$res['id'];
        $patrocinador_tipo = $res['tipo_usuario'];

        if ($patrocinador_tipo !== 'real') {
            $pdo->rollBack();
            sendResponse(['error' => 'Solo un usuario real puede ser patrocinador.'], 400);
        }
    }

    $stmt_master = $pdo->prepare("SELECT id, wallet_address FROM usuarios WHERE tipo_usuario = 'master' LIMIT 1");
    $stmt_master->execute();
    $master_user = $stmt_master->fetch();

    if ($new_user_id) {
        $update_fields = [];
        $update_values = [];

        if ($patrocinador_id !== null) {
            $update_fields[] = "patrocinador_id = ?";
            $update_values[] = $patrocinador_id;
        }
        if ($nombre_completo !== '' && !empty($contact_columns['nombre_completo'])) {
            $update_fields[] = "nombre_completo = ?";
            $update_values[] = $nombre_completo;
        }
        if ($telefono !== '' && !empty($contact_columns['telefono'])) {
            $update_fields[] = "telefono = ?";
            $update_values[] = $telefono;
        }
        if ($correo_electronico !== '' && !empty($contact_columns['correo_electronico'])) {
            $update_fields[] = "correo_electronico = ?";
            $update_values[] = $correo_electronico;
        }
        if ($telegram_username !== '' && !empty($contact_columns['telegram_username'])) {
            $update_fields[] = "telegram_username = ?";
            $update_values[] = $telegram_username;
        }
        $update_fields[] = "password_hash = ?";
        $update_values[] = password_hash($password, PASSWORD_DEFAULT);

        $update_values[] = $new_user_id;
        $stmt = $pdo->prepare("UPDATE usuarios SET " . implode(', ', $update_fields) . " WHERE id = ?");
        $stmt->execute($update_values);
    } else {
        $insert_fields = ['wallet_address', 'nickname', 'patrocinador_id', 'ip_registro'];
        $insert_values = [$wallet, $nickname, $patrocinador_id, $ip_address];
        $insert_marks = ['?', '?', '?', '?'];

        if (!empty($contact_columns['nombre_completo'])) {
            $insert_fields[] = 'nombre_completo';
            $insert_values[] = $nombre_completo;
            $insert_marks[] = '?';
        }
        if (!empty($contact_columns['telefono'])) {
            $insert_fields[] = 'telefono';
            $insert_values[] = $telefono;
            $insert_marks[] = '?';
        }
        if (!empty($contact_columns['correo_electronico'])) {
            $insert_fields[] = 'correo_electronico';
            $insert_values[] = $correo_electronico;
            $insert_marks[] = '?';
        }
        if (!empty($contact_columns['telegram_username'])) {
            $insert_fields[] = 'telegram_username';
            $insert_values[] = $telegram_username;
            $insert_marks[] = '?';
        }
        $insert_fields[] = 'password_hash';
        $insert_values[] = password_hash($password, PASSWORD_DEFAULT);
        $insert_marks[] = '?';

        $stmt = $pdo->prepare(
            "INSERT INTO usuarios (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_marks) . ")"
        );
        $stmt->execute($insert_values);
        $new_user_id = (int)$pdo->lastInsertId();
    }

    if ($patrocinador_id) {
        if ($patrocinador_tipo !== 'real' || in_array($patrocinador_id, [1, 1000], true)) {
            $pdo->rollBack();
            sendResponse(['error' => 'No se permite usar cuentas administrativas como patrocinador.'], 400);
        }

        // Los nuevos usuarios SIEMPRE entran al ciclo=1, sin importar en qué
        // ciclo esté el patrocinador. El ciclo=2 (y superiores) solo se llena
        // mediante el mecanismo de reenlace al completar el ciclo anterior.
        $ciclo_red = 1;
        $ubicacion = radixFindAvailablePlacement($pdo, $patrocinador_id, 0, $ciclo_red);

        if (!$ubicacion) {
            $pdo->rollBack();
            sendResponse(['error' => 'Tu patrocinador ya no tiene espacios disponibles en la red activa. Pide el link de uno de sus referidos directos para unirte.'], 409);
        }

        $padre_operativo_id = (int)$ubicacion['padre_id'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cuenta
            FROM referidos
            WHERE id_padre = ?
              AND fase_numero = 0
              AND ciclo = ?
            FOR UPDATE
        ");
        $stmt->execute([$padre_operativo_id, $ciclo_red]);
        $cuenta_operativa = (int)($stmt->fetch()['cuenta'] ?? 0);

        if ($cuenta_operativa >= 3) {
            $pdo->rollBack();
            sendResponse(['error' => 'Un espacio se ocupo mientras se procesaba tu registro. Recarga e intenta de nuevo.'], 409);
        }

        $posicion_operativa = $cuenta_operativa + 1;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo)
                VALUES (?, ?, 0, ?, 1, ?)
            ");
            $stmt->execute([$padre_operativo_id, $new_user_id, $posicion_operativa, $ciclo_red]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            sendResponse(['error' => 'Posicion ocupada simultaneamente. Recarga e intenta de nuevo.'], 409);
        }

        asegurarPagoPendienteRegalo(
            $pdo,
            $new_user_id,
            $padre_operativo_id,
            $master_user['wallet_address'] ?? RADIX_CENTRAL_WALLET
        );

        $accion_auditoria = ((int)$ubicacion['depth'] === 0) ? 'REGISTRO_CON_FIRMA' : 'REGISTRO_SPILLOVER';
        $detalle_auditoria = "Registro TRON sin firma. Wallet: $wallet | Patrocinador original ID: $patrocinador_id | Padre operativo ID: $padre_operativo_id | Posicion: $posicion_operativa | Modo: {$ubicacion['modo']}";

        $stmt = $pdo->prepare("
            INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address)
            VALUES (?, ?, 'usuarios', ?, ?)
        ");
        $stmt->execute([$new_user_id, $accion_auditoria, $detalle_auditoria, $ip_address]);

        require_once 'notificaciones.php';
        notificarNuevoReferido($pdo, $padre_operativo_id, $nickname);

        require_once 'matrix_logic.php';
        verificarAvanceTablero($padre_operativo_id, $pdo, false, 0, 1);
    } else {
        $stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE tipo_usuario = 'real'
              AND patrocinador_id IS NULL
              AND id <> ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$new_user_id]);
        $fundador_existente = $stmt->fetchColumn();

        if ($fundador_existente) {
            $pdo->rollBack();
            sendResponse(['error' => 'Este registro requiere un link de referido valido. El sistema ya tiene un fundador activo.'], 400);
        }

        if ($master_user) {
            asegurarPagoPendienteRegalo(
                $pdo,
                $new_user_id,
                (int)$master_user['id'],
                $master_user['wallet_address'] ?? RADIX_CENTRAL_WALLET
            );

            $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address) VALUES (?, 'REGISTRO_FUNDADOR', 'usuarios', ?, ?)");
            $stmt->execute([
                $new_user_id,
                "Fundador registrado. Pago \$10 pendiente a RADIX_MASTER. Wallet: $wallet",
                $ip_address,
            ]);
        }
    }

    $pdo->commit();

    // Notificar al master sobre el nuevo usuario (solo en registro real, no login)
    try {
        $stmt_pat = $pdo->prepare("SELECT nickname FROM usuarios WHERE id = ? LIMIT 1");
        $stmt_pat->execute([$patrocinador_id ?? 0]);
        $pat_row = $stmt_pat->fetch();
        $pat_nick = $pat_row ? ($pat_row['nickname'] ?? 'Directo') : 'Directo';

        $stmt_total = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'real'");
        $total_reales = (int)$stmt_total->fetchColumn();

        notificarMasterNuevoUsuario($pdo, $nickname, $pat_nick, $total_reales);
    } catch (Exception $e) { /* silencioso — no interrumpir el registro */ }

    // Mensaje de bienvenida al nuevo usuario (si ya vinculó Telegram)
    try {
        notificarBienvenida($pdo, $new_user_id);
    } catch (Exception $e) { /* silencioso */ }

    radix_finalizar_acceso($pdo, $new_user_id, 'Bienvenido a RADIX. Registro exitoso.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("RADIX registro ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error interno del servidor. Por favor intenta de nuevo.'], 500);
}
?>
