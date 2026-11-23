<?php

namespace Core;

use App\Models\ApiToken;
use App\Models\Setting;
use App\Services\ErrorHandler;
use App\Services\Logger;

/**
 * Entry of the token API under /api/v1.
 *
 * It runs in place of the page stack: no session, no cookie, no routes/web.php, and every answer
 * is JSON. public/index.php hands a request over right after .env is loaded.
 */
class ApiKernel
{
    public const PREFIX = '/api/v1';

    /** Largest request body, in bytes */
    private const MAX_BODY = 65536;

    /** Methods the API answers to, for the Allow header of a path no route has */
    private const METHODS = ['GET', 'HEAD', 'POST', 'PATCH', 'DELETE', 'OPTIONS'];

    private ApiToken $tokens;
    private bool $headOnly = false;

    /** Rate limit headers of the answer, once the token is known */
    private array $limitHeaders = [];

    /**
     * Whether a path belongs to the API. /api/v1x does not, and neither do the helpers of the
     * app's own pages under /api/.
     */
    public static function handles(?string $path): bool
    {
        return is_string($path) && preg_match('#^' . self::PREFIX . '(/|$)#', $path) === 1;
    }

    public function run(string $path): void
    {
        Application::configureErrorReporting();
        // PHP's own error text never goes into an answer, and stray output is thrown away
        ini_set('display_errors', '0');
        ob_start();

        try {
            [$status, $payload, $headers] = $this->handle($path);
        } catch (ApiError $e) {
            $status = $e->status;
            $payload = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
            $headers = $e->headers;
        }

        $this->send($status, $payload, $headers + $this->limitHeaders);
    }

    /**
     * @return array{0: int, 1: ?array, 2: array} Status, body and extra headers
     */
    private function handle(string $path): array
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'HEAD') {
            $method = 'GET';
            $this->headOnly = true;
        }

        // One trailing slash is ignored, nothing else about the path is tidied
        $relative = substr($path, strlen(self::PREFIX));
        if (str_ends_with($relative, '/')) {
            $relative = substr($relative, 0, -1);
        }

        // Matched in memory only. It is answered after the token is checked.
        [$route, $params, $allowed] = $this->match($method, $relative);

        if ($method === 'OPTIONS') {
            return [204, null, ['Allow' => implode(', ', $allowed ?: self::METHODS)]];
        }

        if (!Application::isInstalled()) {
            throw new ApiError(503, 'not_installed', 'Domain Monitor is not installed yet.');
        }

        $values = (new Setting())->getValues([
            'api_access', 'api_users', 'api_rate_limit', 'app_timezone', 'app_version', 'user_isolation_mode',
        ]);

        // As public/index.php does for the pages. A zone PHP does not know falls back to UTC.
        if (!@date_default_timezone_set((string)($values['app_timezone'] ?? 'UTC'))) {
            date_default_timezone_set('UTC');
        }

        $api = Setting::apiSettingsFrom($values);
        if ($api['access'] === 'off') {
            throw new ApiError(403, 'api_off', 'The API is turned off. An admin can turn it on in Settings.');
        }

        $this->tokens = new ApiToken();
        $token = $this->authenticate($api);
        $userId = (int)$token['user_id'];
        $tokenId = (int)$token['id'];
        ErrorHandler::setApiUser($userId);

        $this->countRequest($token, $route['cost'] ?? 1, $api['rate_limit']);

        if ($route === null) {
            if ($allowed) {
                throw new ApiError(405, 'method_not_allowed', 'This path does not take that method.', [
                    'Allow' => implode(', ', $allowed),
                ]);
            }
            throw new ApiError(404, 'not_found', 'There is nothing at this path.');
        }

        $logContext = [
            'method' => $method,
            'path' => self::PREFIX . $relative,
            'user_id' => $userId,
            'token_id' => $tokenId,
            'ip' => $this->clientIp(),
        ];

        try {
            if ($route['write'] && empty($token['can_write'])) {
                throw new ApiError(403, 'read_only_token', 'This token can only read.');
            }
            if ($route['write'] && $api['access'] !== 'read_write') {
                throw new ApiError(403, 'write_off', 'Write access is turned off. An admin can turn it on in Settings.');
            }

            $request = new ApiRequest(
                ['id' => $userId, 'username' => (string)$token['username'], 'role' => (string)$token['role']],
                [
                    'id' => $tokenId,
                    'name' => (string)$token['name'],
                    'can_write' => !empty($token['can_write']),
                    'expires_at' => $token['expires_at'],
                ],
                ($values['user_isolation_mode'] ?? 'shared') === 'isolated' ? $userId : null,
                $params,
                $_GET,
                $method === 'GET' ? [] : $this->readBody(),
                ['access' => $api['access'], 'app_version' => (string)($values['app_version'] ?? '')]
            );

            [$class, $action] = $route['handler'];
            $result = (new $class($request))->$action();
        } catch (ApiError $e) {
            if ($route['write']) {
                $this->log('WARNING', 'Write call refused', $logContext + ['status' => $e->status, 'reason' => $e->errorCode]);
            }
            throw $e;
        }

        $status = (int)($result['status'] ?? 200);
        if ($route['write']) {
            $this->log('INFO', 'Write call', $logContext + ['status' => $status]);
        }

        $payload = ['data' => $result['data']];
        if (isset($result['meta'])) {
            $payload['meta'] = $result['meta'];
        }

        return [$status, $payload, []];
    }

    /**
     * The token this request carries and its owner, or an error answer
     */
    private function authenticate(array $api): array
    {
        $bearer = ['WWW-Authenticate' => 'Bearer'];

        $hash = $this->tokenHash();
        if ($hash === null) {
            throw new ApiError(
                401,
                'token_missing',
                'No API token was sent. Send it as "Authorization: Bearer <token>" or "X-API-Key: <token>".',
                $bearer
            );
        }

        $token = null;
        if ($hash !== '') {
            try {
                $token = $this->tokens->findByHash($hash);
            } catch (\PDOException $e) {
                // The table comes with a database update that has not run yet
                if ($e->getCode() === '42S02') {
                    throw new ApiError(403, 'api_off', 'The API needs a database update first.');
                }
                throw $e;
            }
        }
        if (!$token) {
            // Not logged: anyone can send wrong tokens, and each line would be theirs to add
            throw new ApiError(401, 'token_invalid', 'This API token is not valid.', $bearer);
        }

        $refusal = null;
        if ($token['expires_at'] !== null && (int)$token['expires_at'] <= time()) {
            $refusal = new ApiError(401, 'token_expired', 'This API token has expired.', $bearer);
        } elseif (empty($token['is_active'])) {
            $refusal = new ApiError(401, 'user_inactive', 'The user of this API token is deactivated.', $bearer);
        } elseif ($api['users'] === 'admins' && $token['role'] !== 'admin') {
            $refusal = new ApiError(403, 'forbidden', 'The API is limited to admins.');
        }

        if ($refusal) {
            // One line a minute for each token at most, so a forgotten client cannot fill the log
            if ($this->tokens->firstInWindow((int)$token['id'])) {
                $this->log('WARNING', 'Token refused', [
                    'reason' => $refusal->errorCode,
                    'token_id' => (int)$token['id'],
                    'user_id' => (int)$token['user_id'],
                    'ip' => $this->clientIp(),
                ]);
            }
            throw $refusal;
        }

        return $token;
    }

    /**
     * SHA-256 of the token this request carries: null when it carries none, '' when what it
     * carries is not shaped like a token. The token is hashed right here and goes nowhere else,
     * so it is never an argument of one of the app's functions and cannot end up in a stack trace.
     */
    private function tokenHash(): ?string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($authorization === null && function_exists('getallheaders')) {
            foreach (getallheaders() ?: [] as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $authorization = $value;
                    break;
                }
            }
        }

        // Anything but Bearer, such as the Basic login of a proxy in front, leaves X-API-Key to speak
        $sent = null;
        if (is_string($authorization) && preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $match)) {
            $sent = $match[1];
        } elseif (is_string($_SERVER['HTTP_X_API_KEY'] ?? null)) {
            $sent = trim($_SERVER['HTTP_X_API_KEY']);
        }

        if ($sent === null || $sent === '') {
            return null;
        }

        return preg_match(ApiToken::PATTERN, $sent) === 1 ? hash('sha256', $sent) : '';
    }

    /**
     * Count the request against the token's limit, or answer 429
     */
    private function countRequest(array $token, int $cost, int $limit): void
    {
        $now = time();
        $used = (int)$token['window_start'] === $now - $now % ApiToken::WINDOW ? (int)$token['window_count'] : 0;

        if (!$this->tokens->hit((int)$token['id'], $cost, $limit, $this->clientIp())) {
            $this->limitHeaders = ['X-RateLimit-Limit' => (string)$limit, 'X-RateLimit-Remaining' => '0'];
            throw new ApiError(429, 'rate_limited', 'Too many requests. Try again in a moment.', [
                'Retry-After' => (string)ApiToken::secondsToNextWindow(),
            ]);
        }

        $this->limitHeaders = [
            'X-RateLimit-Limit' => (string)$limit,
            'X-RateLimit-Remaining' => (string)max(0, $limit - $used - $cost),
        ];
    }

    /**
     * The route for a method and path, read from routes/api.php
     *
     * @return array{0: ?array, 1: array, 2: string[]} The route, the named parts of its path, and
     *         the methods the path takes (empty when no route has this path)
     */
    private function match(string $method, string $relative): array
    {
        $segments = $relative === '' ? [] : explode('/', substr($relative, 1));
        $found = null;
        $foundParams = [];
        $allowed = [];

        foreach (require dirname(__DIR__) . '/routes/api.php' as [$routeMethod, $pattern, $handler, $write, $cost]) {
            $params = $this->matchPattern($pattern, $segments);
            if ($params === null) {
                continue;
            }
            $allowed[] = $routeMethod;
            if ($routeMethod === $method && $found === null) {
                $found = ['handler' => $handler, 'write' => (bool)$write, 'cost' => (int)$cost];
                $foundParams = $params;
            }
        }

        $allowed = array_values(array_unique($allowed));
        if ($allowed) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            $allowed[] = 'OPTIONS';
        }

        return [$found, $foundParams, $allowed];
    }

    /**
     * @return array|null The {name} parts of the pattern, URL-decoded, or null when it does not fit
     */
    private function matchPattern(string $pattern, array $segments): ?array
    {
        $parts = $pattern === '' ? [] : explode('/', substr($pattern, 1));
        if (count($parts) !== count($segments)) {
            return null;
        }

        $params = [];
        foreach ($parts as $i => $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $name)) {
                $value = rawurldecode($segments[$i]);
                if ($value === '') {
                    return null;
                }
                $params[$name[1]] = $value;
            } elseif ($part !== $segments[$i]) {
                return null;
            }
        }

        return $params;
    }

    /**
     * The request body as an array. Empty is fine, anything else has to be a JSON object.
     */
    private function readBody(): array
    {
        $tooLarge = new ApiError(413, 'body_too_large', 'The request body is larger than 64 KB.');

        $length = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (is_numeric($length) && (int)$length > self::MAX_BODY) {
            throw $tooLarge;
        }

        $raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            throw $tooLarge;
        }
        if (trim($raw) === '') {
            return [];
        }

        $type = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
        if (!str_starts_with($type, 'application/json')) {
            throw new ApiError(415, 'unsupported_media_type', 'Send the body as application/json.');
        }

        // "[]" would decode to the same empty array as "{}", so the first character decides
        if (ltrim($raw)[0] !== '{') {
            throw new ApiError(400, 'bad_request', 'The body must be a JSON object.');
        }

        $body = json_decode($raw, true, 16);
        if (!is_array($body)) {
            throw new ApiError(400, 'bad_json', 'The body is not valid JSON.');
        }

        return $body;
    }

    private function clientIp(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /**
     * A line in the api log. A log folder that cannot be written must not turn an answer into an
     * error, so warnings are muted here.
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            @(new Logger('api'))->log($level, $message, $context);
        } catch (\Throwable $e) {
            // The log is not worth failing a request for
        }
    }

    /**
     * Send the answer and stop
     */
    private function send(int $status, ?array $payload, array $headers): void
    {
        // Encoded first: when that fails, the error handler still has a clean answer to give
        $json = $payload === null ? '' : json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if (!$this->headOnly && $status !== 204) {
            echo $json;
        }

        exit;
    }
}
