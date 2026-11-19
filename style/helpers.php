<?php
declare(strict_types=1);

/**
 * Shared helpers for Lateo Tier 2 server-rendered PHP pages.
 * Pure PHP 8.1+ standard library; zero external dependencies.
 */

/**
 * Escapes any scalar or null value for safe HTML5 text and attribute output.
 */
function lt_esc(string|int|float|bool|null $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Builds an HTML attribute string from an associative array.
 * Filters out inline style and event-handler attributes to preserve strict CSP.
 *
 * @param array<string, string|int|float|bool|null> $attributes
 */
function lt_attrs(array $attributes): string
{
    $parts = [];
    foreach ($attributes as $name => $value) {
        if (!is_string($name) || $value === null || $value === false) {
            continue;
        }
        $cleanName = trim($name);
        if (!preg_match('/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/', $cleanName)) {
            continue;
        }
        $lower = strtolower($cleanName);
        if ($lower === 'style' || str_starts_with($lower, 'on')) {
            continue;
        }
        if ($value === true) {
            if (str_starts_with($lower, 'aria-')) {
                $parts[] = $cleanName . '="true"';
            } else {
                $parts[] = $cleanName;
            }
            continue;
        }
        $parts[] = $cleanName . '="' . lt_esc($value) . '"';
    }
    return $parts === [] ? '' : ' ' . implode(' ', $parts);
}

/**
 * Joins non-empty CSS class names with single spaces.
 */
function lt_classes(?string ...$classes): string
{
    $out = [];
    foreach ($classes as $item) {
        if ($item === null) {
            continue;
        }
        foreach (preg_split('/\s+/', trim($item)) ?: [] as $token) {
            if ($token !== '') {
                $out[] = $token;
            }
        }
    }
    return implode(' ', $out);
}

/**
 * Normalizes a URL path for current-page comparison.
 */
function lt_normalize_path(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }
    if ($path === '/index.php') {
        return '/';
    }
    return rtrim($path, '/') ?: '/';
}

/**
 * Checks whether a target URL path matches the current request path.
 */
function lt_is_current(string $targetUrl, string $currentPath): bool
{
    if ($currentPath === '') {
        return false;
    }
    return lt_normalize_path($targetUrl) === lt_normalize_path($currentPath);
}

/**
 * Returns ' aria-current="page"' when the target matches the current path.
 */
function lt_current_attr(string $targetUrl, string $currentPath): string
{
    return lt_is_current($targetUrl, $currentPath) ? ' aria-current="page"' : '';
}

/**
 * Loads the product registry from products.json.
 *
 * @return array<int, array<string, mixed>>
 */
function lt_load_products(?string $path = null): array
{
    static $cache = [];
    $resolved = $path ?? dirname(__DIR__) . '/products.json';
    if (isset($cache[$resolved])) {
        return $cache[$resolved];
    }
    if (!is_file($resolved) || !is_readable($resolved)) {
        return [];
    }
    $raw = file_get_contents($resolved);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $list = $decoded['products'] ?? $decoded;
    if (!is_array($list)) {
        return [];
    }
    $products = array_values(array_filter($list, 'is_array'));
    $cache[$resolved] = $products;
    return $products;
}

/**
 * Renders a partial from php/partials/<name>.php with isolated $props scope.
 *
 * @param array<string, mixed> $props
 */
function lt_partial(string $name, array $props = []): string
{
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        throw new InvalidArgumentException('Invalid partial name.');
    }
    $file = __DIR__ . '/partials/' . $name . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Partial not found: ' . $name);
    }
    $render = static function (string $__file, array $props): void {
        require $__file;
    };
    ob_start();
    try {
        $render($file, $props);
        return (string) ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/**
 * Sends strict Content Security Policy and defensive HTTP headers.
 */
function lt_send_security_headers(bool $allowFormPost = true, bool $noStore = false): void
{
    if (headers_sent()) {
        return;
    }
    $formAction = $allowFormPost ? "'self'" : "'none'";
    header(
        "Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action " .
            $formAction .
            "; frame-ancestors 'none'"
    );
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    if ($noStore) {
        header('Cache-Control: no-store');
    }
}

/**
 * Starts a PHP session with defensive cookie parameters when available.
 */
function lt_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
    ]);
    session_start();
}

/**
 * Returns or creates a CSRF token scoped to a specific form.
 */
function lt_csrf_token(string $scope = 'default'): string
{
    lt_start_session();
    if (!isset($_SESSION['lt_csrf']) || !is_array($_SESSION['lt_csrf'])) {
        $_SESSION['lt_csrf'] = [];
    }
    if (!isset($_SESSION['lt_csrf'][$scope]) || !is_string($_SESSION['lt_csrf'][$scope])) {
        $_SESSION['lt_csrf'][$scope] = bin2hex(random_bytes(32));
    }
    return $_SESSION['lt_csrf'][$scope];
}

/**
 * Verifies a submitted CSRF token in constant time.
 */
function lt_verify_csrf(?string $submitted, string $scope = 'default'): bool
{
    lt_start_session();
    $expected = $_SESSION['lt_csrf'][$scope] ?? null;
    if (!is_string($expected) || !is_string($submitted) || $submitted === '') {
        return false;
    }
    return hash_equals($expected, $submitted);
}

/**
 * Stores a one-time post/redirect/get flash message in the session.
 */
function lt_set_flash(string $tone, string $message): void
{
    lt_start_session();
    $allowed = ['info', 'success', 'warning', 'danger'];
    $safeTone = in_array($tone, $allowed, true) ? $tone : 'info';
    $_SESSION['lt_flash'] = [
        'tone' => $safeTone,
        'message' => $message,
    ];
}

/**
 * Consumes and returns the current one-time flash message, if any.
 *
 * @return array{tone: string, message: string}|null
 */
function lt_take_flash(): ?array
{
    lt_start_session();
    $flash = $_SESSION['lt_flash'] ?? null;
    unset($_SESSION['lt_flash']);
    if (!is_array($flash) || !isset($flash['message']) || !is_string($flash['message'])) {
        return null;
    }
    $tone = is_string($flash['tone'] ?? null) ? $flash['tone'] : 'info';
    return ['tone' => $tone, 'message' => $flash['message']];
}

/**
 * Sends a 303 See Other redirect for Post/Redirect/Get flows and exits.
 */
function lt_redirect_see_other(string $location): never
{
    if (!headers_sent()) {
        http_response_code(303);
        header('Location: ' . $location);
    }
    exit;
}

/**
 * Builds a URL with filtered query parameters.
 *
 * @param array<string, string|int|null> $params
 */
function lt_query_url(string $basePath, array $params): string
{
    $filtered = [];
    foreach ($params as $key => $val) {
        if ($val === null || $val === '') {
            continue;
        }
        $filtered[$key] = (string) $val;
    }
    if ($filtered === []) {
        return $basePath;
    }
    return $basePath . '?' . http_build_query($filtered);
}

/**
 * Reads a scalar string input safely from $_GET or $_POST without array-to-string notices.
 *
 * @param array<string, mixed> $source
 */
function lt_input_string(array $source, string $key, string $default = ''): string
{
    $val = $source[$key] ?? null;
    return is_string($val) ? $val : $default;
}

/**
 * Reads an integer input safely from $_GET or $_POST without array-to-string notices.
 *
 * @param array<string, mixed> $source
 */
function lt_input_int(array $source, string $key, int $default = 0): int
{
    $val = $source[$key] ?? null;
    if (!is_string($val) && !is_int($val)) {
        return $default;
    }
    $parsed = filter_var($val, FILTER_VALIDATE_INT);
    return $parsed === false ? $default : $parsed;
}
