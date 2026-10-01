<?php

declare(strict_types=1);

namespace FieldPulse\Testing;

use FieldPulse\Support\Paths;

/**
 * A real HTTP server for a contract suite.
 *
 * WHY NOT CALL THE HANDLERS DIRECTLY
 *
 * integration.php invokes controllers in-process, which cannot observe a status
 * code, cannot populate $_FILES, and cannot produce the Set-Cookie header that
 * decides whether a refresh cookie actually reaches a client. Every one of
 * those is a contract the browser half of this application depends on, and each
 * has been wrong here in a way that in-process tests would have passed: a
 * handler returning the right JSON with the wrong status, is_uploaded_file()
 * returning false for a file that was never genuinely uploaded, and a cookie
 * missing Secure or SameSite. So the suites that assert the wire contract speak
 * HTTP to a real PHP process, and this class is the part they share.
 *
 * It is a built-in server for two reasons. It is a genuine SAPI, so multipart
 * uploads and header handling behave as they do under Apache or LiteSpeed; and
 * it binds an OS-assigned free port on 127.0.0.1, so parallel runs cannot
 * collide and nothing is exposed off-host.
 *
 * WHAT IS STUBBED, AND WHY IT IS ONLY THIS
 *
 * $_SERVER['HTTPS'] is set to 'on'. The suites speak plaintext to localhost and
 * Kernel requires TLS. The TLS deployment itself is asserted by
 * bin/healthcheck.php, which reads the real .htaccess rather than a test router,
 * so asserting it here as well would only re-test the stub. Nothing else about
 * the request is rewritten: no header is injected, no body is altered, and no
 * authentication is faked. The keys, device rows, JWTs and ECDSA signatures that
 * reach the server are all genuine, because "does the server reject a forged
 * request" is the thing under test and a stubbed key would make the assertion
 * about the harness.
 */
final class HttpServer
{
    private mixed $process = null;

    private function __construct(
        public readonly string $baseUrl,
        public readonly string $logPath,
        public readonly string $workDir,
        private readonly bool $keep,
    ) {
    }

    /**
     * Boot a server serving the real public_html tree.
     *
     * The router is written to a scratch directory rather than committed: it is
     * a fixture for a server that does not exist in production, and a committed
     * router at any path reachable from the document root would be one more
     * thing to forget to lock down.
     *
     * @param array<string,string> $configOverrides Config keys to change for the
     *        duration of this run, e.g. raising a rate limit so a suite making
     *        dozens of logins is not throttled. They are applied to a COPY of
     *        the real .env in the scratch directory, never to .env itself: a
     *        test run has no business rewriting the deployment's configuration,
     *        and a run interrupted before cleanup would leave it rewritten.
     * @param array<string,string> $env Extra process environment
     * @throws \RuntimeException if the server does not come up
     */
    public static function start(
        bool $keep = false,
        array $configOverrides = [],
        array $env = [],
        int $maxUploadBytes = 16_000_000,
        ?string $docRoot = null
    ): self {
        $repoRoot   = dirname(__DIR__, 3);
        $publicRoot = $docRoot ?? ($repoRoot . '/public_html');
        $workDir    = sys_get_temp_dir() . '/fieldpulse-http-' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

        if (!is_dir($publicRoot)) {
            throw new \RuntimeException('No document root to serve: ' . $publicRoot);
        }

        if (!is_dir($workDir) && !mkdir($workDir, 0o777, true) && !is_dir($workDir)) {
            throw new \RuntimeException('Could not create the HTTP scratch directory ' . $workDir);
        }

        $routerPath = $workDir . '/router.php';
        file_put_contents($routerPath, self::routerSource());

        $envFile = null;

        if ($configOverrides !== []) {
            $envFile = self::writeEnvCopy($workDir, $configOverrides);
        }

        $port    = self::allocatePort();
        $logPath = $workDir . '/server.log';
        $slash   = static fn (string $p): string => str_replace('\\', '/', $p);

        $childEnv = ['FP_DOCROOT' => $slash($publicRoot)] + $env;

        if ($envFile !== null) {
            $childEnv['FP_ENV_FILE'] = $slash($envFile);
        }

        /*
         * proc_open with an array, not a shell string: the paths carry a drive
         * letter and backslashes, and every shell between here and PHP would get
         * a chance to reinterpret them.
         */
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']];

        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'post_max_size=' . (int) ceil($maxUploadBytes / 1_048_576) . 'M',
                '-d', 'upload_max_filesize=' . (int) ceil($maxUploadBytes / 1_048_576) . 'M',
                '-S', '127.0.0.1:' . $port,
                '-t', $slash($publicRoot),
                $slash($routerPath),
            ],
            $descriptors,
            $pipes,
            $repoRoot,
            $childEnv + getenv()
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the built-in web server.');
        }

        $server = new self('http://127.0.0.1:' . $port, $logPath, $workDir, $keep);
        $server->process = $process;

        if (!$server->waitForListener()) {
            $log = (string) @file_get_contents($logPath);
            $server->stop();

            throw new \RuntimeException(
                'The built-in web server did not start listening on port ' . $port . "\n" . $log
            );
        }

        return $server;
    }

    /**
     * Wait for the listener rather than sleeping a fixed interval.
     *
     * A fixed sleep is either slower than necessary or flaky, and flaky only on
     * a loaded machine — i.e. rarely, and expensively.
     */
    private function waitForListener(): bool
    {
        $port    = (int) substr((string) parse_url($this->baseUrl, PHP_URL_PORT), 0);
        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.5);

            if ($probe !== false) {
                fclose($probe);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * Copy the deployment's .env into the scratch directory with some keys
     * replaced, and return the copy's path.
     *
     * The copy inherits everything that matters — the database credentials, the
     * JWT secret, the cookie settings — so the server under test is configured
     * exactly as it is in production apart from the keys the caller named. A
     * missing key is appended rather than silently ignored, because a typo in an
     * override name would otherwise leave the real limit in force and produce a
     * suite that fails for a reason the caller cannot see.
     *
     * @param array<string,string> $overrides
     */
    private static function writeEnvCopy(string $workDir, array $overrides): string
    {
        $source = dirname(__DIR__, 2) . '/.env';

        if (!is_file($source) || !is_readable($source)) {
            throw new \RuntimeException('Cannot copy the environment file: ' . $source . ' is not readable.');
        }

        $lines  = [];
        $seen   = [];

        foreach (explode("\n", (string) file_get_contents($source)) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $lines[] = $line;

                continue;
            }

            $eq  = strpos($line, '=');
            $key = $eq === false ? '' : trim(substr($line, 0, $eq));

            if (isset($overrides[$key])) {
                $lines[]    = $key . '=' . $overrides[$key];
                $seen[$key] = true;

                continue;
            }

            $lines[] = $line;
        }

        foreach ($overrides as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . $value;
            }
        }

        $path = $workDir . '/env.override';

        if (file_put_contents($path, implode("\n", $lines)) === false) {
            throw new \RuntimeException('Could not write the environment override to ' . $path);
        }

        return $path;
    }

    /** Ask the OS for a free port, then release it for the server to bind. */
    private static function allocatePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($sock === false) {
            throw new \RuntimeException('Could not allocate a port: ' . $errstr);
        }

        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);

        if ($port <= 0) {
            throw new \RuntimeException('Could not determine a free port.');
        }

        return $port;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            // proc_terminate reaches the server; closing stdin first lets the
            // built-in server's own shutdown path run instead of killing it.
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        if ($this->keep) {
            \FieldPulse\Console\Cli::warn('Scratch directory kept at ' . $this->workDir);
            \FieldPulse\Console\Cli::warn('It contains a copy of the .env, including the JWT secret and database password.');

            return;
        }

        foreach (['router.php', 'server.log', 'env.override'] as $file) {
            $path = $this->workDir . '/' . $file;

            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (is_dir($this->workDir)) {
            @rmdir($this->workDir);
        }
    }

    /**
     * Snapshot the storage tree, so a run can delete exactly what it created.
     *
     * @return array<string,true>
     */
    public static function snapshotStorage(): array
    {
        $baseline = [];

        foreach (self::storageDirs() as $dir) {
            foreach ((array) glob($dir . '/*') as $path) {
                $baseline[(string) $path] = true;
            }
        }

        return $baseline;
    }

    /**
     * Delete any storage file not present in the pre-run snapshot.
     *
     * @param array<string,true> $baseline
     */
    public static function removeNewStorage(array $baseline): void
    {
        foreach (self::storageDirs() as $dir) {
            foreach ((array) glob($dir . '/*') as $path) {
                $path = (string) $path;

                if (!isset($baseline[$path])) {
                    @unlink($path);
                }
            }
        }
    }

    /** @return list<string> */
    public static function storageDirs(): array
    {
        return [
            Paths::quarantineDir(),
            Paths::verifiedDir(),
            Paths::reviewDir(),
            Paths::rejectedDir(),
        ];
    }

    /**
     * A signed request over a real socket.
     *
     * The canonical string is assembled here rather than in each suite, so the
     * signature the client produces and the string the server verifies are
     * guaranteed to come from one definition. Every field the server checks can
     * be overridden individually, which is what the negative cases need: a
     * wrong device_uuid, a stale timestamp and a reused nonce are each a one-key
     * difference from a request that succeeds, and a negative case expressed as
     * a reimplementation of the signer is a negative case that can fail for the
     * wrong reason.
     *
     * @param  array{privateKey:\OpenSSLAsymmetricKey,deviceUuid:string,token?:string} $principal
     * @param  array<string,string> $overrides Replaces individual headers outright
     * @param  string|null $jar Path to a cURL cookie jar
     * @return array{status:int,body:mixed,raw:string,headers:array<string,list<string>>}
     */
    /**
     * Merge caller overrides over the default headers, replacing by NAME.
     *
     * A plain array_merge is wrong here and silently so: a key of
     * 'X-Request-Signature: ' does not equal 'X-Request-Signature: <value>', so
     * both survive, the forged one is appended, and cURL sends the original
     * first. Every tamper test then passed against an untouched request — the
     * exact failure these tests exist to prevent.
     *
     * @param  array<int,string> $defaults
     * @param  array<int,string> $overrides Header lines, each "Name: value".
     * @return array<int,string>
     */
    private static function mergeHeaders(array $defaults, array $overrides): array
    {
        if ($overrides === []) {
            return $defaults;
        }

        $replaced = [];

        foreach ($overrides as $line) {
            $sep = strpos($line, ':');

            if ($sep === false) {
                continue;
            }

            $replaced[strtolower(trim(substr($line, 0, $sep)))] = true;
        }

        $merged = [];

        foreach ($defaults as $line) {
            $sep = strpos($line, ':');

            if ($sep !== false && isset($replaced[strtolower(trim(substr($line, 0, $sep)))])) {
                continue;
            }

            $merged[] = $line;
        }

        foreach ($overrides as $line) {
            $merged[] = $line;
        }

        return $merged;
    }

    public static function sendSigned(
        string $baseUrl,
        string $appUrl,
        array $principal,
        string $method,
        string $path,
        string $body,
        ?string $token = null,
        array $overrides = [],
        ?string $jar = null,
        ?string $signPath = null,
        ?\OpenSSLAsymmetricKey $signingKey = null
    ): array {
        $timestamp = (string) time();
        $nonce     = \FieldPulse\Support\Str::base64UrlEncode(random_bytes(18));

        /*
         * $signPath lets a test sign for one route and send to another, which is
         * the only way to show the path is inside the signed string rather than
         * merely travelling beside it. It defaults to the requested path, so
         * every ordinary call is unaffected.
         */
        $canonical = implode("\n", [
            $method,
            $signPath ?? $path,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        $signature = '';

        if (!openssl_sign($canonical, $signature, $signingKey ?? $principal['privateKey'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The suite could not sign its own request.');
        }

        $headers = self::mergeHeaders([
            'Authorization: Bearer ' . ($token ?? ($principal['token'] ?? '')),
            'X-Device-UUID: ' . $principal['deviceUuid'],
            'X-Request-Timestamp: ' . $timestamp,
            'X-Request-Nonce: ' . $nonce,
            'X-Request-Signature: ' . \FieldPulse\Support\Str::base64UrlEncode($signature),
            'Content-Type: application/json',
            'Origin: ' . $appUrl,
            'Expect:',
        ], $overrides);

        $raw    = '';
        $status = 0;
        $err    = '';
        $seen   = [];

        $ch = curl_init($baseUrl . $path);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => array_values($headers),
            /*
             * Set-Cookie is the contract under test for the auth suites: a
             * refresh token that never reaches the browser is indistinguishable
             * from one that was never issued, and no in-process assertion can
             * see it. Collected here rather than parsed out of the body.
             */
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$seen): int {
                $len = strlen($line);
                $sep = strpos($line, ':');

                if ($sep !== false) {
                    $name = strtolower(trim(substr($line, 0, $sep)));
                    $seen[$name][] = trim(substr($line, $sep + 1));
                }

                return $len;
            },
        ]);

        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }

        if ($method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $out = curl_exec($ch);

        if ($out !== false) {
            $raw    = (string) $out;
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } else {
            $err = curl_error($ch);
        }

        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('The suite could not reach the server: ' . $err);
        }

        return [
            'status'  => $status,
            'body'    => json_decode($raw, true),
            'raw'     => $raw,
            'headers' => $seen,
        ];
    }

    /**
     * Pull one cookie's value out of a response's Set-Cookie header.
     *
     * cURL's cookie jar is not usable for the auth suites over plain HTTP: the
     * server sets the refresh cookie with Secure, which is correct in
     * production and which cURL honours by refusing to send the cookie back
     * over http://. Left to the cookie engine, every refresh test would report
     * "no refresh token was presented" and prove nothing.
     *
     * So the suites carry the value explicitly and assert the Secure attribute
     * from the header text instead. The browser behaviour that matters — that
     * the cookie is opaque to script, is not sent cross-site, and is not sent
     * in cleartext — is asserted on the attribute string, which is where a
     * browser would read it from.
     *
     * @param array{status:int,body:mixed,raw:string,headers:array<string,list<string>>} $response
     */
    public static function cookieValue(array $response, string $name): ?string
    {
        foreach ($response['headers']['set-cookie'] ?? [] as $line) {
            foreach (explode(';', (string) $line) as $part) {
                $part = trim($part);

                if (!str_starts_with($part, $name . '=')) {
                    continue;
                }

                return substr($part, strlen($name) + 1);
            }
        }

        return null;
    }

    /**
     * A `Cookie:` request header for one or more name/value pairs.
     *
     * @param array<string,string> $cookies
     * @return array<string,string> shaped for the $overrides argument
     */
    public static function cookieHeader(array $cookies): array
    {
        $pairs = [];

        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return ['Cookie: ' . implode('; ', $pairs)];
    }

    /**
     * A JSON request that carries no device signature.
     *
     * For the login and registration routes, which are reached with a bearer
     * token alone. Reusing sendSigned() here would attach a device signature
     * those routes neither require nor look at, which would make the test
     * assert something other than what the browser sends.
     *
     * @param  array<string,string> $overrides
     * @return array{status:int,body:mixed,raw:string,headers:array<string,list<string>>}
     */
    /**
     * POST a real multipart/form-data submission, signed the way a browser signs.
     *
     * submit.php is the one endpoint where the signed bytes are NOT the request
     * body: PHP has already consumed the stream to populate $_POST/$_FILES by
     * the time the application runs, so php://input is empty and
     * SHA256(REQUEST_BODY) is not computable server-side. The client therefore
     * signs the verbatim `payload` part (see CanonicalPayload), and this helper
     * reproduces that exactly — including sending the part bytes it hashed,
     * unmodified, rather than letting curl re-encode anything.
     *
     * The boundary is generated rather than passed in so a test cannot
     * accidentally agree with the server on a boundary that also appears in the
     * payload, which is the failure mode the §10 note warns about.
     *
     * @param  array{privateKey:\OpenSSLAsymmetricKey,deviceUuid:string,token?:string} $principal
     * @param  string                                                          $payload   Verbatim bytes of the payload part.
     * @param  string                                                          $fileName  Original filename for the file part.
     * @param  string                                                          $fileBytes Exact bytes to upload.
     * @param  array<string,string>                                            $tamper    Headers to override, e.g. a forged signature.
     * @return array{status:int,body:mixed,raw:string,headers:array<string,array<int,string>>}
     */
    public static function sendMultipart(
        string $baseUrl,
        string $appUrl,
        array $principal,
        string $path,
        string $payload,
        string $fileName,
        string $fileBytes,
        array $tamper = [],
        ?\OpenSSLAsymmetricKey $signingKey = null,
        ?string $signPath = null,
        ?string $nonce = null
    ): array {
        $boundary  = '----fieldpulse' . bin2hex(random_bytes(12));
        $timestamp = (string) time();

        /*
         * A test-supplied nonce has to be used for BOTH the canonical string and
         * the header. Tampering the header alone produces a valid signature over
         * a nonce the server never sees, which fails as a bad signature and
         * quietly stops testing the replay path.
         */
        $nonce = $nonce ?? \FieldPulse\Support\Str::base64UrlEncode(random_bytes(18));

        $canonical = implode("\n", [
            'POST',
            $signPath ?? $path,
            $timestamp,
            $nonce,
            hash('sha256', $payload),
        ]);

        $signature = '';

        if (!openssl_sign($canonical, $signature, $signingKey ?? $principal['privateKey'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The suite could not sign its own multipart request.');
        }

        $body = '';

        foreach ([['payload', $payload, null], ['file', $fileBytes, $fileName]] as [$field, $value, $name]) {
            $body .= '--' . $boundary . "\r\n";

            $body .= 'Content-Disposition: form-data; name="' . $field . '"';

            if ($name !== null) {
                $body .= '; filename="' . $name . '"';
            }

            $body .= "\r\n\r\n" . $value . "\r\n";
        }

        $body .= '--' . $boundary . "--\r\n";

        $headers = [
            'Authorization: Bearer ' . ($principal['token'] ?? ''),
            'X-Device-UUID: ' . $principal['deviceUuid'],
            'X-Request-Timestamp: ' . $timestamp,
            'X-Request-Nonce: ' . $nonce,
            'X-Request-Signature: ' . \FieldPulse\Support\Str::base64UrlEncode($signature),
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Origin: ' . $appUrl,
            'Expect:',
        ];

        $headers = self::mergeHeaders($headers, $tamper);

        $raw    = '';
        $status = 0;
        $err    = '';

        $ch = curl_init($baseUrl . $path);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array_values($headers),
        ]);

        $out = curl_exec($ch);

        if ($out !== false) {
            $raw    = (string) $out;
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } else {
            $err = curl_error($ch);
        }

        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('The suite could not reach the server: ' . $err);
        }

        return [
            'status' => $status,
            'body'   => json_decode($raw, true),
            'raw'    => $raw,
        ];
    }

    public static function sendJson(
        string $baseUrl,
        string $appUrl,
        string $method,
        string $path,
        array $body,
        ?string $token = null,
        array $overrides = [],
        ?string $jar = null
    ): array {
        $headers = array_merge([
            'Content-Type: application/json',
            'Origin: ' . $appUrl,
            'Expect:',
        ], $token === null ? [] : ['Authorization: Bearer ' . $token], $overrides);

        $raw    = '';
        $status = 0;
        $err    = '';
        $seen   = [];

        $ch = curl_init($baseUrl . $path);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => array_values($headers),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$seen): int {
                $len = strlen($line);
                $sep = strpos($line, ':');

                if ($sep !== false) {
                    $name = strtolower(trim(substr($line, 0, $sep)));
                    $seen[$name][] = trim(substr($line, $sep + 1));
                }

                return $len;
            },
        ]);

        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }

        if ($method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $out = curl_exec($ch);

        if ($out !== false) {
            $raw    = (string) $out;
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } else {
            $err = curl_error($ch);
        }

        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('The suite could not reach the server: ' . $err);
        }

        return [
            'status'  => $status,
            'body'    => json_decode($raw, true),
            'raw'     => $raw,
            'headers' => $seen,
        ];
    }

    private static function routerSource(): string
    {
        return <<<'ROUTER'
<?php

declare(strict_types=1);

/*
 * Contract-suite router. Two jobs, both narrowly scoped:
 *
 *   1. Mark the request as HTTPS. The suite speaks plaintext HTTP to
 *      localhost, and Kernel requires TLS. The TLS deployment itself is
 *      asserted by bin/healthcheck.php against the real .htaccess, so stubbing
 *      it here does not duplicate or weaken that check.
 *
 *   2. Hand the real public_html file back to the server, so the request is
 *      served by the same shim that runs in production. Returning false is
 *      what makes the built-in server execute the .php file rather than
 *      serve it as source.
 */

$_SERVER['HTTPS'] = 'on';

/*
 * Point configuration at the suite's copy of the environment file, if the
 * harness made one. Config::instance() boots lazily on first use, and the
 * shim below is the only other thing in the request, so booting here means the
 * whole request sees the overridden values and the deployment's own .env is
 * never opened for writing.
 */
$envFile = (string) getenv('FP_ENV_FILE');

if ($envFile !== '') {
    require_once getenv('FP_DOCROOT') . '/../private_storage/app/bootstrap.php';
    \FieldPulse\Config\Config::boot($envFile);
}

$docRoot = (string) getenv('FP_DOCROOT');
$path    = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$target  = $docRoot . $path;

// Contain path traversal: the resolved target must stay under the document root.
$realDocRoot = realpath($docRoot);
$realTarget  = realpath($target);

if ($realDocRoot === false || $realTarget === false) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":{"code":"NOT_FOUND","message":"Not found."}}';

    return true;
}

$realDocRoot = rtrim(str_replace('\\', '/', $realDocRoot), '/') . '/';
$realTarget  = str_replace('\\', '/', $realTarget);

if (!str_starts_with($realTarget, $realDocRoot)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":{"code":"NOT_FOUND","message":"Not found."}}';

    return true;
}

return false;
ROUTER;
    }
}
