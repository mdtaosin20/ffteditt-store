<?php
/**
 * Fingerprint / face login for the admin (WebAuthn passkeys, no extra libraries).
 * The fingerprint never leaves the phone: the phone signs a random challenge with a key that only it holds,
 * and we check the signature with the public key saved when the device was added.
 */
declare(strict_types=1);

function pk_table(): void
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS passkeys (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id   INTEGER NOT NULL,
        cred_id    TEXT NOT NULL UNIQUE,
        public_pem TEXT NOT NULL,
        alg        INTEGER NOT NULL,
        sign_count INTEGER NOT NULL DEFAULT 0,
        name       TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL,
        last_used  INTEGER NOT NULL DEFAULT 0
    )");
    $done = true;
}

function b64u_enc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

function pk_rp_id(): string
{
    return strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
}

function pk_origin_ok(string $origin): bool
{
    $h = pk_rp_id();
    if ($origin === 'https://' . $h) return true;
    return in_array($h, ['localhost', '127.0.0.1'], true) && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);
}

/* ---------------------------------------------------------------- tiny CBOR reader */

function cbor_decode(string $d, int &$p)
{
    if ($p >= strlen($d)) throw new RuntimeException('cbor: end');
    $ib = ord($d[$p++]);
    $mt = $ib >> 5;
    $ai = $ib & 31;
    if ($ai < 24) $v = $ai;
    elseif ($ai === 24) { $v = ord($d[$p]); $p += 1; }
    elseif ($ai === 25) { $v = unpack('n', substr($d, $p, 2))[1]; $p += 2; }
    elseif ($ai === 26) { $v = unpack('N', substr($d, $p, 4))[1]; $p += 4; }
    elseif ($ai === 27) { $v = unpack('J', substr($d, $p, 8))[1]; $p += 8; }
    else throw new RuntimeException('cbor: unsupported');
    switch ($mt) {
        case 0: return $v;
        case 1: return -1 - $v;
        case 2: case 3: $s = substr($d, $p, $v); $p += $v; return $s;
        case 4: $a = []; for ($i = 0; $i < $v; $i++) $a[] = cbor_decode($d, $p); return $a;
        case 5: $m = []; for ($i = 0; $i < $v; $i++) { $k = cbor_decode($d, $p); $m[$k] = cbor_decode($d, $p); } return $m;
        case 7: return $ai === 20 ? false : ($ai === 21 ? true : null);
    }
    throw new RuntimeException('cbor: type');
}

/* ---------------------------------------------------------------- COSE key → PEM */

function der_len(int $n): string
{
    if ($n < 128) return chr($n);
    $b = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($b)) . $b;
}
function der(int $tag, string $body): string { return chr($tag) . der_len(strlen($body)) . $body; }
function der_int(string $b): string { $b = ltrim($b, "\0"); if ($b === '' || ord($b[0]) & 0x80) $b = "\0" . $b; return der(0x02, $b); }

/** Returns [pem, alg]. */
function cose_to_pem(array $k): array
{
    $kty = $k[1] ?? 0;
    $alg = $k[3] ?? 0;
    if ($kty === 2 && $alg === -7 && ($k[-1] ?? 0) === 1) {          // EC2 P-256, ES256
        $x = (string) $k[-2]; $y = (string) $k[-3];
        if (strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('bad EC key');
        $algId = der(0x30, der(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . der(0x06, "\x2a\x86\x48\xce\x3d\x03\x01\x07"));
        $spki = der(0x30, $algId . der(0x03, "\0\x04" . $x . $y));
    } elseif ($kty === 3 && $alg === -257) {                           // RSA, RS256 (Windows Hello)
        $algId = der(0x30, der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
        $rsa = der(0x30, der_int((string) $k[-1]) . der_int((string) $k[-2]));
        $spki = der(0x30, $algId . der(0x03, "\0" . $rsa));
    } else {
        throw new RuntimeException('This device uses a key type that is not supported');
    }
    return ["-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n", (int) $alg];
}

/* ---------------------------------------------------------------- checks */

function pk_challenge(string $kind): string
{
    start_session();
    $c = random_bytes(32);
    $_SESSION['pk_' . $kind] = ['c' => b64u_enc($c), 't' => time()];
    return b64u_enc($c);
}

/** Checks clientDataJSON; returns its SHA-256. */
function pk_client(string $json, string $type, string $kind): string
{
    start_session();
    $want = $_SESSION['pk_' . $kind] ?? null;
    unset($_SESSION['pk_' . $kind]);
    $c = json_decode($json, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type) throw new RuntimeException('wrong type');
    if (!$want || time() - (int) $want['t'] > 300 || !hash_equals((string) $want['c'], (string) ($c['challenge'] ?? ''))) throw new RuntimeException('expired, please try again');
    if (!pk_origin_ok((string) ($c['origin'] ?? ''))) throw new RuntimeException('wrong website');
    return hash('sha256', $json, true);
}

/** Checks authenticatorData; returns [flags, signCount]. */
function pk_auth_data(string $ad): array
{
    if (strlen($ad) < 37) throw new RuntimeException('short data');
    if (!hash_equals(hash('sha256', pk_rp_id(), true), substr($ad, 0, 32))) throw new RuntimeException('wrong website');
    $flags = ord($ad[32]);
    if (!($flags & 0x01)) throw new RuntimeException('no touch');
    if (!($flags & 0x04)) throw new RuntimeException('fingerprint / screen lock was not checked');
    return [$flags, unpack('N', substr($ad, 33, 4))[1]];
}

/** Saves a new device for this admin. */
function pk_register(int $adminId, array $in): string
{
    pk_table();
    pk_client(b64u_dec((string) ($in['clientDataJSON'] ?? '')), 'webauthn.create', 'reg');
    $p = 0;
    $att = cbor_decode(b64u_dec((string) ($in['attestationObject'] ?? '')), $p);
    $ad = (string) ($att['authData'] ?? '');
    [$flags, $count] = pk_auth_data($ad);
    if (!($flags & 0x40)) throw new RuntimeException('no key');
    $len = unpack('n', substr($ad, 53, 2))[1];
    $credId = substr($ad, 55, $len);
    $q = 0;
    $cose = cbor_decode(substr($ad, 55 + $len), $q);
    [$pem, $alg] = cose_to_pem((array) $cose);
    if (!openssl_pkey_get_public($pem)) throw new RuntimeException('key not readable');
    $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($in['name'] ?? '')) ?? ''), 0, 60) ?: 'My device';
    db()->prepare('INSERT INTO passkeys (admin_id, cred_id, public_pem, alg, sign_count, name, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$adminId, b64u_enc($credId), $pem, $alg, $count, $name, time()]);
    log_activity("Fingerprint login turned on for “{$name}”", 'ok');
    return $name;
}

/** Checks a fingerprint login; returns the admin id. */
function pk_login(array $in): int
{
    pk_table();
    $st = db()->prepare('SELECT * FROM passkeys WHERE cred_id = ?');
    $st->execute([(string) ($in['id'] ?? '')]);
    $k = $st->fetch();
    if (!$k) throw new RuntimeException('This fingerprint key is not known here — log in with the password and turn it on again');
    $hash = pk_client(b64u_dec((string) ($in['clientDataJSON'] ?? '')), 'webauthn.get', 'login');
    $ad = b64u_dec((string) ($in['authenticatorData'] ?? ''));
    [, $count] = pk_auth_data($ad);
    $ok = openssl_verify($ad . $hash, b64u_dec((string) ($in['signature'] ?? '')), $k['public_pem'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('signature check failed');
    if ((int) $k['sign_count'] > 0 && $count > 0 && $count <= (int) $k['sign_count']) throw new RuntimeException('this key looks copied — log in with the password');
    db()->prepare('UPDATE passkeys SET sign_count = ?, last_used = ? WHERE id = ?')->execute([$count, time(), (int) $k['id']]);
    $a = db()->prepare('SELECT id FROM admins WHERE id = ?');
    $a->execute([(int) $k['admin_id']]);
    if (!$a->fetchColumn()) throw new RuntimeException('admin not found');
    return (int) $k['admin_id'];
}
