<?php
declare(strict_types=1);

/**
 * Helpers for the FixMate end-to-end test: a tiny HTTP client (cookie jar per user,
 * CSRF-token handling, multipart uploads, manual redirect following) plus reporting.
 */

final class T
{
    public static string $base = 'http://fixmate.local';
    public static string $root = '';
    public static string $run = '';
    public static float $start = 0.0;
    public static ?PDO $pdo = null;
    public static int $pass = 0;
    public static int $fail = 0;
    public static int $skip = 0;
    public static array $failures = [];
    public static array $tmpFiles = [];
}

// ---------------------------------------------------------------- reporting

function color(string $c, string $s): string
{
    static $tty = null;
    $tty ??= function_exists('posix_isatty') && @posix_isatty(STDOUT);
    if (!$tty) {
        return $s;
    }
    $codes = ['g' => '32', 'r' => '31', 'y' => '33', 'b' => '1', 'd' => '2'];
    return "\033[" . $codes[$c] . 'm' . $s . "\033[0m";
}

function section(string $title): void
{
    echo "\n" . color('b', '== ' . $title) . "\n";
}

function check(string $label, bool $ok, string $detail = ''): bool
{
    if ($ok) {
        T::$pass++;
        echo '  ' . color('g', 'PASS') . '  ' . $label . "\n";
    } else {
        T::$fail++;
        T::$failures[] = $label;
        echo '  ' . color('r', 'FAIL') . '  ' . $label . ($detail !== '' ? color('d', '  (' . $detail . ')') : '') . "\n";
    }
    return $ok;
}

function skip(string $label, string $why): void
{
    T::$skip++;
    echo '  ' . color('y', 'SKIP') . '  ' . $label . color('d', '  (' . $why . ')') . "\n";
}

/** Case-insensitive "page text contains" (HTML entities decoded first). */
function has(string $body, string $needle): bool
{
    return stripos(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $needle) !== false;
}

// ---------------------------------------------------------------- database helpers

function sql_rows(string $sql, array $params = []): array
{
    $st = T::$pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function sql_row(string $sql, array $params = []): ?array
{
    $rows = sql_rows($sql, $params);
    return $rows[0] ?? null;
}

function sql_val(string $sql, array $params = []): mixed
{
    $st = T::$pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function sql_exec(string $sql, array $params = []): void
{
    T::$pdo->prepare($sql)->execute($params);
}

/** Expect a MySQL error with the given code (3819 = CHECK, 1062 = UNIQUE, 1452 = FOREIGN KEY). */
function expectDbError(string $label, callable $fn, int $code): void
{
    try {
        $fn();
        check($label, false, 'no error was raised');
    } catch (PDOException $e) {
        $got = (int) ($e->errorInfo[1] ?? 0);
        check($label, $got === $code, 'MySQL error ' . $got . ', expected ' . $code);
    }
}

// ---------------------------------------------------------------- test images

function makeTmp(string $prefix): string
{
    $p = tempnam(sys_get_temp_dir(), $prefix);
    T::$tmpFiles[] = $p;
    return $p;
}

/** A real, tiny PNG or JPEG for upload tests. */
function img(string $kind = 'png', int $seed = 1): CURLFile
{
    $path = makeTmp('fmimg_');
    $im = imagecreatetruecolor(60, 40);
    $col = imagecolorallocate($im, ($seed * 53) % 256, ($seed * 97) % 256, ($seed * 29) % 256);
    imagefill($im, 0, 0, $col);
    $kind === 'jpg' ? imagejpeg($im, $path, 80) : imagepng($im, $path);
    imagedestroy($im);
    return new CURLFile($path, $kind === 'jpg' ? 'image/jpeg' : 'image/png', 'test' . $seed . '.' . $kind);
}

/** A text file pretending to be a JPG. */
function fakeImg(): CURLFile
{
    $path = makeTmp('fmfake_');
    file_put_contents($path, 'this is definitely not an image');
    return new CURLFile($path, 'image/jpeg', 'fake.jpg');
}

function uploadPath(string $rel): string
{
    return T::$root . '/public/uploads/' . $rel;
}

function uploadExists(string $rel): bool
{
    clearstatcache(true);   // PHP caches file lookups; Apache may have deleted the file since
    return $rel !== '' && is_file(uploadPath($rel));
}

function allFilesExist(array $rels): bool
{
    clearstatcache(true);
    if (!$rels) {
        return false;
    }
    foreach ($rels as $r) {
        if (!is_file(uploadPath((string) $r))) {
            return false;
        }
    }
    return true;
}

function noFilesExist(array $rels): bool
{
    clearstatcache(true);
    foreach ($rels as $r) {
        if (is_file(uploadPath((string) $r))) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------- HTTP client

final class Client
{
    public string $jar;
    public string $lastHeaders = '';

    public function __construct(public string $name)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'fmjar_');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** Flatten nested arrays to name[0] style keys; use multipart only if a file is present. */
    public static function encode(array $fields): array|string
    {
        $flat = [];
        $hasFile = false;
        $walk = function (string $key, mixed $v) use (&$walk, &$flat, &$hasFile): void {
            if (is_array($v)) {
                foreach ($v as $k => $item) {
                    $walk($key . '[' . $k . ']', $item);
                }
                return;
            }
            if ($v instanceof CURLFile) {
                $hasFile = true;
            } else {
                $v = (string) $v;
            }
            $flat[$key] = $v;
        };
        foreach ($fields as $k => $v) {
            $walk((string) $k, $v);
        }
        return $hasFile ? $flat : http_build_query($flat);
    }

    /**
     * @return array{status:int, first:int, url:string, body:string, location:string}
     *   status   = final HTTP status after following redirects
     *   first    = status of the very first response (302 means "was redirected")
     *   url      = final path (with query) after redirects
     *   location = where the first response redirected to ('' if it did not)
     */
    public function request(string $method, string $path, array $fields = [], int $maxRedirects = 6): array
    {
        $url = $path;
        $first = null;
        $firstLocation = '';
        $status = 0;
        $body = '';

        for ($i = 0; $i <= $maxRedirects; $i++) {
            [$status, $headers, $body] = $this->once($method, $url, $i === 0 ? $fields : []);
            $this->lastHeaders = $headers;
            $loc = $this->location($headers);
            if ($first === null) {
                $first = $status;
                $firstLocation = $loc;
            }
            if ($status >= 300 && $status < 400 && $loc !== '') {
                $url = $loc;
                $method = 'GET';
                continue;
            }
            break;
        }
        return ['status' => $status, 'first' => (int) $first, 'url' => $url, 'body' => $body, 'location' => $firstLocation];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** POST with a fresh CSRF token (taken from this client's own session) unless disabled. */
    public function post(string $path, array $fields = [], bool $withToken = true): array
    {
        if ($withToken) {
            $fields = ['csrf_token' => $this->token()] + $fields;
        }
        return $this->request('POST', $path, $fields);
    }

    /** Read this session's CSRF token from any page that contains a form. */
    public function token(): string
    {
        foreach (['/about.php', '/login.php'] as $p) {
            $r = $this->request('GET', $p);
            if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m)) {
                return $m[1];
            }
        }
        throw new RuntimeException('Could not find a CSRF token for client ' . $this->name);
    }

    public function sessionId(): string
    {
        $value = '';
        foreach (explode("\n", (string) @file_get_contents($this->jar)) as $line) {
            $line = trim((string) preg_replace('/^#HttpOnly_/', '', trim($line)));
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $p = explode("\t", $line);
            if (count($p) >= 7 && $p[5] === 'FIXMATE_SESSION') {
                $value = $p[6];
            }
        }
        return $value;
    }

    private function once(string $method, string $path, array $fields): array
    {
        $ch = curl_init(T::$base . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Expect:'],
            CURLOPT_USERAGENT      => 'FixMate-E2E/1.0',
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = self::encode($fields);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP error for ' . $this->name . ' ' . $method . ' ' . $path . ': ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [$status, substr($resp, 0, $size), substr($resp, $size)];
    }

    private function location(string $headers): string
    {
        if (!preg_match_all('/^Location:\s*(\S+)/mi', $headers, $m)) {
            return '';
        }
        $loc = (string) end($m[1]);
        if (stripos($loc, T::$base) === 0) {
            $loc = substr($loc, strlen(T::$base));
        }
        return $loc === '' ? '/' : $loc;
    }
}

/**
 * Fire several POSTs at the same time (each job: [Client, path, fields]).
 * Returns the first-response status code of each, in order.
 */
function parallelPost(array $jobs): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($jobs as $i => [$client, $path, $fields]) {
        $ch = curl_init(T::$base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => Client::encode($fields),
            CURLOPT_COOKIEJAR      => $client->jar,
            CURLOPT_COOKIEFILE     => $client->jar,
            CURLOPT_HTTPHEADER     => ['Expect:'],
            CURLOPT_TIMEOUT        => 60,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $st === CURLM_OK);

    $codes = [];
    foreach ($handles as $i => $ch) {
        $codes[$i] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $codes;
}
