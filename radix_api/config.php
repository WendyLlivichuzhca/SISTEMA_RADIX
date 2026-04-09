<?php
// Permitir conexiones desde cualquier origen (CORS)
if (php_sapi_name() !== 'cli') {
    $allowed_origins = [
        'https://corporativoqbank.com',
        'https://www.corporativoqbank.com',
    ];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
    }
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
    header("Vary: Origin");
    header('Content-Type: text/html; charset=UTF-8');
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit;
}

// ── Cargar variables de entorno desde .env (credenciales fuera del código) ─
$env_path = __DIR__ . '/../.env';
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

// ── BSCScan API Key ──────────────────────────────────────────────────────────
define('BSCSCAN_API_KEY',   $_ENV['BSCSCAN_API_KEY'] ?? '');
define('RESUMEN_DIARIO_CLAVE', $_ENV['RESUMEN_DIARIO_CLAVE'] ?? (getenv('RESUMEN_DIARIO_CLAVE') ?: ''));

// ── Red Tron (TRC-20) ────────────────────────────────────────────────────────
define('RADIX_CENTRAL_WALLET', 'TDLFwy5swL2B8stX6tgUgQr2BjB1DFdwoU');
define('USDT_TRC20_CONTRACT',  'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
define('TRON_NETWORK_NAME',    'Tron Mainnet');

// ── Credenciales de base de datos — leídas desde .env ──────────────────────
$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_name = $_ENV['DB_NAME'] ?? '';
$db_user = $_ENV['DB_USER'] ?? '';
$db_pass = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4");
} catch (PDOException $e) {
    // SEGURIDAD: NUNCA exponer el mensaje completo de PDO al cliente.
    // Puede contener host, usuario, nombre de DB o credenciales.
    error_log("RADIX DB connection error: " . $e->getMessage()); // Solo en logs del servidor
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
    }
    echo json_encode(['error' => 'Error de conexión al servidor. Por favor intenta más tarde.']);
    exit();
}

// ── Función para responder en JSON ──────────────────────────────────────────
function sendResponse(array $data, int $status = 200): void
{
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code($status);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function radix_base58_decode(string $input): ?string
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $map = array_flip(str_split($alphabet));
    $bytes = [0];

    foreach (str_split($input) as $char) {
        if (!isset($map[$char])) {
            return null;
        }

        $carry = $map[$char];
        for ($i = 0, $count = count($bytes); $i < $count; $i++) {
            $carry += $bytes[$i] * 58;
            $bytes[$i] = $carry & 0xff;
            $carry >>= 8;
        }

        while ($carry > 0) {
            $bytes[] = $carry & 0xff;
            $carry >>= 8;
        }
    }

    $leadingZeroes = 0;
    $length = strlen($input);
    while ($leadingZeroes < $length && $input[$leadingZeroes] === '1') {
        $leadingZeroes++;
    }

    $decoded = str_repeat("\x00", $leadingZeroes);
    foreach (array_reverse($bytes) as $byte) {
        $decoded .= chr($byte);
    }

    return $decoded;
}

function radix_is_valid_tron_wallet(string $wallet): bool
{
    $wallet = trim($wallet);
    if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $wallet)) {
        return false;
    }

    $decoded = radix_base58_decode($wallet);
    if ($decoded === null || strlen($decoded) !== 25) {
        return false;
    }

    $payload = substr($decoded, 0, 21);
    $checksum = substr($decoded, 21, 4);
    if ($payload === false || $checksum === false || $payload[0] !== "\x41") {
        return false;
    }

    $expectedChecksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    return hash_equals($expectedChecksum, $checksum);
}
?>
