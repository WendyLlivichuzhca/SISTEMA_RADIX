<?php

function radix_proc_open_available(): bool
{
    if (!function_exists('proc_open')) {
        return false;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('proc_open', $disabled, true);
}

function radix_gmp_available(): bool
{
    return function_exists('gmp_init')
        && function_exists('gmp_add')
        && function_exists('gmp_powm')
        && function_exists('gmp_invert')
        && function_exists('gmp_xor');
}

function radix_insecure_signature_fallback_enabled(): bool
{
    $value = strtolower(trim((string)(
        $_ENV['RADIX_ALLOW_INSECURE_SIGNATURE_FALLBACK']
        ?? getenv('RADIX_ALLOW_INSECURE_SIGNATURE_FALLBACK')
        ?? '0'
    )));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function radix_normalize_message(string $message): string
{
    return str_replace(["\r\n", "\r"], "\n", trim($message));
}

function radix_expected_nonce_message(string $nonce, string $wallet): string
{
    return "Bienvenido a RADIX.\n\nFirma este mensaje para verificar tu identidad.\n\nNonce: {$nonce}\nWallet: {$wallet}";
}

function radix_gmp_uint64_mask()
{
    static $mask = null;

    if ($mask === null) {
        $mask = gmp_init('FFFFFFFFFFFFFFFF', 16);
    }

    return $mask;
}

function radix_gmp_zero()
{
    static $zero = null;

    if ($zero === null) {
        $zero = gmp_init(0, 10);
    }

    return $zero;
}

function radix_gmp_one()
{
    static $one = null;

    if ($one === null) {
        $one = gmp_init(1, 10);
    }

    return $one;
}

function radix_gmp_two()
{
    static $two = null;

    if ($two === null) {
        $two = gmp_init(2, 10);
    }

    return $two;
}

function radix_gmp_pow2(int $exp)
{
    static $cache = [];

    if (!isset($cache[$exp])) {
        $cache[$exp] = gmp_pow(2, $exp);
    }

    return $cache[$exp];
}

function radix_gmp_hex(string $hex)
{
    $hex = strtolower(trim($hex));
    $hex = preg_replace('/^0x/', '', $hex);

    if ($hex === '') {
        return radix_gmp_zero();
    }

    return gmp_init($hex, 16);
}

function radix_gmp_to_hex($value, int $padLength = 0): string
{
    $hex = gmp_strval($value, 16);

    if ($hex[0] === '-') {
        throw new RuntimeException('No se puede convertir un GMP negativo a hexadecimal.');
    }

    if ($padLength > 0) {
        $hex = str_pad($hex, $padLength, '0', STR_PAD_LEFT);
    }

    return strtolower($hex);
}

function radix_gmp_mod($value, $modulus)
{
    $result = gmp_mod($value, $modulus);

    if (gmp_cmp($result, 0) < 0) {
        $result = gmp_add($result, $modulus);
    }

    return $result;
}

function radix_gmp_rotl64($value, int $shift)
{
    $shift %= 64;

    if ($shift === 0) {
        return gmp_and($value, radix_gmp_uint64_mask());
    }

    $mask = radix_gmp_uint64_mask();
    $left = gmp_and(gmp_mul($value, radix_gmp_pow2($shift)), $mask);
    $right = gmp_div_q($value, radix_gmp_pow2(64 - $shift));

    return gmp_and(gmp_or($left, $right), $mask);
}

function radix_gmp_not64($value)
{
    return gmp_xor($value, radix_gmp_uint64_mask());
}

function radix_gmp_from_le_bytes(string $bytes)
{
    if ($bytes === '') {
        return radix_gmp_zero();
    }

    return radix_gmp_hex(bin2hex(strrev($bytes)));
}

function radix_gmp_to_le_bytes($value, int $length): string
{
    return strrev(hex2bin(radix_gmp_to_hex($value, $length * 2)));
}

function radix_secp256k1_prime()
{
    static $value = null;

    if ($value === null) {
        $value = radix_gmp_hex('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F');
    }

    return $value;
}

function radix_secp256k1_order()
{
    static $value = null;

    if ($value === null) {
        $value = radix_gmp_hex('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141');
    }

    return $value;
}

function radix_secp256k1_generator(): array
{
    static $generator = null;

    if ($generator === null) {
        $generator = [
            'x' => radix_gmp_hex('79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798'),
            'y' => radix_gmp_hex('483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8'),
        ];
    }

    return $generator;
}

function radix_keccak_round_constants(): array
{
    static $constants = null;

    if ($constants !== null) {
        return $constants;
    }

    $constants = array_map(
        'radix_gmp_hex',
        [
            '0000000000000001', '0000000000008082', '800000000000808A', '8000000080008000',
            '000000000000808B', '0000000080000001', '8000000080008081', '8000000000008009',
            '000000000000008A', '0000000000000088', '0000000080008009', '000000008000000A',
            '000000008000808B', '800000000000008B', '8000000000008089', '8000000000008003',
            '8000000000008002', '8000000000000080', '000000000000800A', '800000008000000A',
            '8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008',
        ]
    );

    return $constants;
}

function radix_keccak_rotation_offsets(): array
{
    return [
        [0, 36, 3, 41, 18],
        [1, 44, 10, 45, 2],
        [62, 6, 43, 15, 61],
        [28, 55, 25, 21, 56],
        [27, 20, 39, 8, 14],
    ];
}

function radix_keccak_f1600(array &$state): void
{
    $rotations = radix_keccak_rotation_offsets();
    $roundConstants = radix_keccak_round_constants();

    foreach ($roundConstants as $roundConstant) {
        $c = [];
        $d = [];
        $b = array_fill(0, 25, radix_gmp_zero());

        for ($x = 0; $x < 5; $x++) {
            $column = $state[$x];
            for ($y = 1; $y < 5; $y++) {
                $column = gmp_xor($column, $state[$x + (5 * $y)]);
            }
            $c[$x] = gmp_and($column, radix_gmp_uint64_mask());
        }

        for ($x = 0; $x < 5; $x++) {
            $d[$x] = gmp_xor(
                $c[($x + 4) % 5],
                radix_gmp_rotl64($c[($x + 1) % 5], 1)
            );
        }

        for ($x = 0; $x < 5; $x++) {
            for ($y = 0; $y < 5; $y++) {
                $idx = $x + (5 * $y);
                $state[$idx] = gmp_and(gmp_xor($state[$idx], $d[$x]), radix_gmp_uint64_mask());
            }
        }

        for ($x = 0; $x < 5; $x++) {
            for ($y = 0; $y < 5; $y++) {
                $idx = $x + (5 * $y);
                $newX = $y;
                $newY = (2 * $x + 3 * $y) % 5;
                $b[$newX + (5 * $newY)] = radix_gmp_rotl64($state[$idx], $rotations[$x][$y]);
            }
        }

        for ($x = 0; $x < 5; $x++) {
            for ($y = 0; $y < 5; $y++) {
                $idx = $x + (5 * $y);
                $state[$idx] = gmp_and(
                    gmp_xor(
                        $b[$idx],
                        gmp_and(
                            radix_gmp_not64($b[(($x + 1) % 5) + (5 * $y)]),
                            $b[(($x + 2) % 5) + (5 * $y)]
                        )
                    ),
                    radix_gmp_uint64_mask()
                );
            }
        }

        $state[0] = gmp_and(gmp_xor($state[0], $roundConstant), radix_gmp_uint64_mask());
    }
}

function radix_keccak256_bytes(string $data): string
{
    $rateBytes = 136;
    $outputLength = 32;
    $state = [];

    for ($i = 0; $i < 25; $i++) {
        $state[$i] = radix_gmp_zero();
    }

    $offset = 0;
    $dataLength = strlen($data);

    while (($offset + $rateBytes) <= $dataLength) {
        $block = substr($data, $offset, $rateBytes);

        for ($lane = 0; $lane < 17; $lane++) {
            $laneBytes = substr($block, $lane * 8, 8);
            $state[$lane] = gmp_and(
                gmp_xor($state[$lane], radix_gmp_from_le_bytes($laneBytes)),
                radix_gmp_uint64_mask()
            );
        }

        radix_keccak_f1600($state);
        $offset += $rateBytes;
    }

    $remaining = substr($data, $offset);
    $remainingLength = strlen($remaining);
    $block = array_fill(0, $rateBytes, 0);

    for ($i = 0; $i < $remainingLength; $i++) {
        $block[$i] = ord($remaining[$i]);
    }

    $block[$remainingLength] ^= 0x01;
    $block[$rateBytes - 1] ^= 0x80;

    $blockString = '';
    foreach ($block as $byte) {
        $blockString .= chr($byte);
    }

    for ($lane = 0; $lane < 17; $lane++) {
        $laneBytes = substr($blockString, $lane * 8, 8);
        $state[$lane] = gmp_and(
            gmp_xor($state[$lane], radix_gmp_from_le_bytes($laneBytes)),
            radix_gmp_uint64_mask()
        );
    }

    radix_keccak_f1600($state);

    $output = '';
    for ($lane = 0; strlen($output) < $outputLength; $lane++) {
        $output .= substr(radix_gmp_to_le_bytes($state[$lane], 8), 0, $outputLength - strlen($output));
    }

    return substr($output, 0, $outputLength);
}

function radix_keccak256_hex(string $data): string
{
    return bin2hex(radix_keccak256_bytes($data));
}

function radix_tron_hash_message(string $message): string
{
    $messageBytes = $message;
    $prefix = "\x19TRON Signed Message:\n";

    return radix_keccak256_hex(
        $prefix . (string)strlen($messageBytes) . $messageBytes
    );
}

function radix_parse_signature(string $signature): array
{
    $signature = strtolower(trim($signature));
    $signature = preg_replace('/^0x/', '', $signature);

    if (!preg_match('/^[0-9a-f]{130}$/', $signature)) {
        throw new InvalidArgumentException('La firma TRON no tiene un formato válido.');
    }

    $r = radix_gmp_hex(substr($signature, 0, 64));
    $s = radix_gmp_hex(substr($signature, 64, 64));
    $v = hexdec(substr($signature, 128, 2));

    if ($v >= 27) {
        $v -= 27;
    }

    if ($v < 0 || $v > 3) {
        throw new InvalidArgumentException('El recovery id de la firma TRON es inválido.');
    }

    return [
        'r' => $r,
        's' => $s,
        'v' => $v,
    ];
}

function radix_mod_inverse($value, $modulus)
{
    $inverse = gmp_invert(radix_gmp_mod($value, $modulus), $modulus);

    if ($inverse === false) {
        throw new InvalidArgumentException('No fue posible invertir el escalar de la firma.');
    }

    return $inverse;
}

function radix_point_is_infinity(?array $point): bool
{
    return $point === null;
}

function radix_point_negate(?array $point, $prime): ?array
{
    if ($point === null) {
        return null;
    }

    return [
        'x' => $point['x'],
        'y' => radix_gmp_mod(gmp_neg($point['y']), $prime),
    ];
}

function radix_point_add(?array $pointA, ?array $pointB, $prime): ?array
{
    if ($pointA === null) {
        return $pointB;
    }

    if ($pointB === null) {
        return $pointA;
    }

    if (gmp_cmp($pointA['x'], $pointB['x']) === 0) {
        if (gmp_cmp(radix_gmp_mod(gmp_add($pointA['y'], $pointB['y']), $prime), 0) === 0) {
            return null;
        }

        return radix_point_double($pointA, $prime);
    }

    $slope = radix_gmp_mod(
        gmp_mul(
            gmp_sub($pointB['y'], $pointA['y']),
            radix_mod_inverse(gmp_sub($pointB['x'], $pointA['x']), $prime)
        ),
        $prime
    );

    $x = radix_gmp_mod(
        gmp_sub(
            gmp_sub(gmp_powm($slope, 2, $prime), $pointA['x']),
            $pointB['x']
        ),
        $prime
    );

    $y = radix_gmp_mod(
        gmp_sub(
            gmp_mul($slope, gmp_sub($pointA['x'], $x)),
            $pointA['y']
        ),
        $prime
    );

    return ['x' => $x, 'y' => $y];
}

function radix_point_double(?array $point, $prime): ?array
{
    if ($point === null) {
        return null;
    }

    if (gmp_cmp($point['y'], 0) === 0) {
        return null;
    }

    $numerator = gmp_mul(3, gmp_powm($point['x'], 2, $prime));
    $denominator = radix_mod_inverse(gmp_mul(2, $point['y']), $prime);
    $slope = radix_gmp_mod(gmp_mul($numerator, $denominator), $prime);

    $x = radix_gmp_mod(
        gmp_sub(gmp_powm($slope, 2, $prime), gmp_mul(2, $point['x'])),
        $prime
    );

    $y = radix_gmp_mod(
        gmp_sub(
            gmp_mul($slope, gmp_sub($point['x'], $x)),
            $point['y']
        ),
        $prime
    );

    return ['x' => $x, 'y' => $y];
}

function radix_point_multiply($scalar, ?array $point, $prime): ?array
{
    if ($point === null || gmp_cmp($scalar, 0) === 0) {
        return null;
    }

    $result = null;
    $addend = $point;

    while (gmp_cmp($scalar, 0) > 0) {
        if (gmp_testbit($scalar, 0)) {
            $result = radix_point_add($result, $addend, $prime);
        }

        $addend = radix_point_double($addend, $prime);
        $scalar = gmp_div_q($scalar, 2);
    }

    return $result;
}

function radix_point_is_on_curve(?array $point, $prime): bool
{
    if ($point === null) {
        return true;
    }

    $left = radix_gmp_mod(gmp_powm($point['y'], 2, $prime), $prime);
    $right = radix_gmp_mod(
        gmp_add(gmp_powm($point['x'], 3, $prime), 7),
        $prime
    );

    return gmp_cmp($left, $right) === 0;
}

function radix_mod_sqrt_secp256k1($value, $prime)
{
    $exponent = gmp_div_q(gmp_add($prime, 1), 4);
    $candidate = gmp_powm($value, $exponent, $prime);

    if (gmp_cmp(radix_gmp_mod(gmp_powm($candidate, 2, $prime), $prime), radix_gmp_mod($value, $prime)) !== 0) {
        throw new InvalidArgumentException('No se pudo descomprimir el punto de la firma.');
    }

    return $candidate;
}

function radix_recover_public_key_from_signature(string $hashHex, string $signature): ?array
{
    $sig = radix_parse_signature($signature);
    $r = $sig['r'];
    $s = $sig['s'];
    $v = $sig['v'];

    $prime = radix_secp256k1_prime();
    $order = radix_secp256k1_order();
    $generator = radix_secp256k1_generator();

    if (gmp_cmp($r, 1) < 0 || gmp_cmp($r, $order) >= 0 || gmp_cmp($s, 1) < 0 || gmp_cmp($s, $order) >= 0) {
        return null;
    }

    $recId = $v % 2;
    $recoveryGroup = intdiv($v, 2);
    $x = gmp_add($r, gmp_mul($recoveryGroup, $order));

    if (gmp_cmp($x, $prime) >= 0) {
        return null;
    }

    $alpha = radix_gmp_mod(gmp_add(gmp_powm($x, 3, $prime), 7), $prime);
    $beta = radix_mod_sqrt_secp256k1($alpha, $prime);
    $betaIsOdd = gmp_intval(radix_gmp_mod($beta, 2));
    $y = ($betaIsOdd === $recId) ? $beta : radix_gmp_mod(gmp_neg($beta), $prime);

    $pointR = ['x' => $x, 'y' => $y];

    if (!radix_point_is_on_curve($pointR, $prime)) {
        return null;
    }

    $check = radix_point_multiply($order, $pointR, $prime);
    if ($check !== null) {
        return null;
    }

    $z = radix_gmp_mod(radix_gmp_hex($hashHex), $order);
    $rInverse = radix_mod_inverse($r, $order);

    $sR = radix_point_multiply($s, $pointR, $prime);
    $zG = radix_point_multiply($z, $generator, $prime);
    $candidate = radix_point_add($sR, radix_point_negate($zG, $prime), $prime);

    if ($candidate === null) {
        return null;
    }

    $publicKey = radix_point_multiply($rInverse, $candidate, $prime);

    if ($publicKey === null || !radix_point_is_on_curve($publicKey, $prime)) {
        return null;
    }

    return $publicKey;
}

function radix_base58_encode(string $bytes): string
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $leadingZeroes = 0;
    $length = strlen($bytes);

    while ($leadingZeroes < $length && $bytes[$leadingZeroes] === "\x00") {
        $leadingZeroes++;
    }

    $number = radix_gmp_hex(bin2hex($bytes));
    $encoded = '';

    while (gmp_cmp($number, 0) > 0) {
        $division = gmp_div_qr($number, 58);
        $number = $division[0];
        $remainder = gmp_intval($division[1]);
        $encoded = $alphabet[$remainder] . $encoded;
    }

    return str_repeat('1', $leadingZeroes) . ($encoded !== '' ? $encoded : '');
}

function radix_base58check_encode(string $payload): string
{
    $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    return radix_base58_encode($payload . $checksum);
}

function radix_public_key_to_tron_address(array $publicKey): string
{
    $publicKeyHex =
        radix_gmp_to_hex($publicKey['x'], 64) .
        radix_gmp_to_hex($publicKey['y'], 64);

    $ethAddress = substr(radix_keccak256_hex(hex2bin($publicKeyHex)), -40);
    $tronHex = '41' . $ethAddress;

    return radix_base58check_encode(hex2bin($tronHex));
}

function radix_verify_tron_signature_with_gmp(string $wallet, string $message, string $signature): bool
{
    if (!radix_gmp_available()) {
        throw new RuntimeException('La extensión GMP no está disponible.');
    }

    $messageHash = radix_tron_hash_message($message);
    $publicKey = radix_recover_public_key_from_signature($messageHash, $signature);

    if ($publicKey === null) {
        return false;
    }

    $recoveredWallet = radix_public_key_to_tron_address($publicKey);
    return hash_equals($wallet, $recoveredWallet);
}

function radix_verify_tron_signature_with_node(string $wallet, string $message, string $signature): bool
{
    if (!radix_proc_open_available()) {
        throw new RuntimeException('proc_open no está disponible.');
    }

    $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'verify_tron_signature.cjs';

    if (!file_exists($scriptPath)) {
        throw new RuntimeException('Falta el verificador Node de firma TRON en el servidor.');
    }

    $payload = base64_encode(json_encode([
        'wallet' => $wallet,
        'message' => $message,
        'signature' => $signature,
    ], JSON_UNESCAPED_UNICODE));

    $nodeBinaries = array_values(array_unique(array_filter([
        trim((string)($_ENV['NODE_BINARY'] ?? getenv('NODE_BINARY') ?? '')),
        'node',
        '/usr/local/bin/node',
        '/usr/bin/node',
        '/opt/cpanel/ea-nodejs22/bin/node',
        '/opt/cpanel/ea-nodejs20/bin/node',
        '/opt/cpanel/ea-nodejs18/bin/node',
        '/opt/cpanel/ea-nodejs16/bin/node',
    ])));

    $lastError = 'No se pudo iniciar el verificador seguro de firma.';

    foreach ($nodeBinaries as $nodeBinary) {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = [$nodeBinary, $scriptPath, $payload];
        $process = @proc_open($command, $descriptorSpec, $pipes, __DIR__, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            $lastError = "No se pudo iniciar Node usando: {$nodeBinary}";
            continue;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $result = json_decode($stdout ?: '', true);

        if ($exitCode === 0 && is_array($result) && !empty($result['success'])) {
            return !empty($result['matches']);
        }

        $lastError = trim((string)$stderr) ?: 'No se pudo validar la firma TRON de forma segura.';
    }

    throw new RuntimeException($lastError);
}

function radix_verify_tron_signature(string $wallet, string $message, string $signature): bool
{
    $wallet = trim($wallet);
    $message = radix_normalize_message($message);
    $signature = trim($signature);

    if ($wallet === '' || $message === '' || $signature === '') {
        return false;
    }

    if (radix_gmp_available()) {
        try {
            return radix_verify_tron_signature_with_gmp($wallet, $message, $signature);
        } catch (InvalidArgumentException $error) {
            return false;
        } catch (RuntimeException $error) {
            error_log('RADIX WARNING: backend GMP de firma TRON falló: ' . $error->getMessage());
        }
    }

    try {
        return radix_verify_tron_signature_with_node($wallet, $message, $signature);
    } catch (RuntimeException $error) {
        if (radix_insecure_signature_fallback_enabled()) {
            error_log('RADIX WARNING: verificador seguro de firma no disponible; usando fallback inseguro temporal. ERROR: ' . $error->getMessage());
            return true;
        }

        throw new RuntimeException(
            'El servidor no puede verificar la firma de forma segura. Se requiere GMP o Node.js. Detalle: ' .
            $error->getMessage()
        );
    }
}
