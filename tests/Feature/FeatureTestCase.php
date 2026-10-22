<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Tests\Integration\DbTestCase;

/**
 * End-to-end HTTP tests against the PHP built-in server running with WAIMART_ENV=testing,
 * so every request hits the isolated waimart_test database. Each test gets a fresh cookie jar.
 */
abstract class FeatureTestCase extends DbTestCase
{
    private const PORT = 8089;
    /** @var resource|null */
    private static $server = null;

    protected string $base = 'http://127.0.0.1:8089';
    private string $jar = '';
    protected string $lastBody = '';
    /** User-Agent sent with requests (null: none), e.g. to act as a link-preview crawler. */
    protected ?string $userAgent = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$server !== null) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $env = array_merge(getenv(), ['WAIMART_ENV' => 'testing', 'WAIMART_BASE_PATH' => '/']);
        $cmd = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', PHP_BINARY, self::PORT, $root . '/public', $root . '/tests/server-router.php');
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root, $env, ['bypass_shell' => true]);
        register_shutdown_function(static function () {
            if (is_resource(self::$server)) {
                $status = proc_get_status(self::$server);
                if (PHP_OS_FAMILY === 'Windows' && !empty($status['pid'])) {
                    exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
                }
                proc_terminate(self::$server);
            }
        });
        // Wait until the server accepts connections.
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        throw new \RuntimeException('Test server did not start.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->jar = tempnam(sys_get_temp_dir(), 'wmjar');
    }

    protected function tearDown(): void
    {
        @unlink($this->jar);
        parent::tearDown();
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    protected function http(string $method, string $path, array $data = [], array $files = [], ?string $referer = null): array
    {
        $ch = curl_init($this->base . $path);
        $headers = [];
        if ($referer !== null) {
            // Browsers send the form page as Referer on same-origin POSTs; "back" redirects rely on it.
            curl_setopt($ch, CURLOPT_REFERER, $this->base . $referer);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $headers[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        if ($this->userAgent !== null) {
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        }
        if ($method === 'POST') {
            if ($files) {
                $fields = $data;
                foreach ($files as $name => $path) {
                    $fields[$name] = new \CURLFile($path);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->lastBody = $body;
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    protected function get(string $path): array
    {
        return $this->http('GET', $path);
    }

    /** POSTs with the CSRF token taken from $formPage (fetched first). */
    protected function post(string $path, array $data = [], ?string $formPage = null, array $files = []): array
    {
        if (!array_key_exists('_csrf', $data)) {
            $page = $this->get($formPage ?? '/')['body'];
            $data['_csrf'] = preg_match('/name="_csrf" value="([^"]+)"/', $page, $m) ? html_entity_decode($m[1]) : '';
        }
        return $this->http('POST', $path, $data, $files, $formPage ?? '/');
    }

    /** Follows a redirect response and returns the next page. */
    protected function follow(array $response): array
    {
        $loc = $response['headers']['location'] ?? '/';
        return $this->get(preg_replace('#^https?://[^/]+#', '', $loc));
    }

    protected function makeStaff(string $role, string $password = 'Secret123'): array
    {
        $email = $role . '-' . bin2hex(random_bytes(3)) . '@waimart.test';
        $id = $this->db->insert('users', [
            'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'first_name' => ucfirst($role), 'last_name' => 'Tester', 'is_staff' => 1,
        ]);
        $this->db->exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $role]);
        return ['id' => $id, 'email' => $email, 'password' => $password];
    }

    protected function loginStaff(string $role): array
    {
        $u = $this->makeStaff($role);
        $r = $this->post('/admin/login', ['email' => $u['email'], 'password' => $u['password']], '/admin/login');
        $this->assertSame(303, $r['status'], 'staff login should redirect');
        $this->assertStringEndsWith('/admin', $r['headers']['location'] ?? '');
        return $u;
    }
}
