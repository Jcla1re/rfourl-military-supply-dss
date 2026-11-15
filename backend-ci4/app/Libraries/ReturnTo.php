<?php

namespace App\Libraries;

/**
 * The page to return to after signing in. Only a page of this app is accepted:
 *  - a path ("/staff/sales?tab=receipts") or a full URL on this app's own scheme, host and port;
 *  - no "//host", no backslashes, no control characters, at most 2000 characters;
 *  - under a role area (admin/, staff/ or supplier/), and under $role's area when $role is given;
 *  - a defined GET route of the app (so a POST-only URL is refused), never login, logout or session.
 * Anything else gives null (the caller then uses the role's dashboard).
 */
final class ReturnTo
{
    private const AREAS = ['Admin' => 'admin', 'Staff' => 'staff', 'Supplier' => 'supplier'];

    public static function clean(?string $url, ?string $role = null): ?string
    {
        $url = (string) $url;
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || str_starts_with($url, '//')) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $base = parse_url(base_url());
            $port = static fn (array $p): ?int => $p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80);
            if (strtolower($parts['scheme'] ?? '') !== strtolower($base['scheme'] ?? '')
                || strtolower($parts['host'] ?? '') !== strtolower($base['host'] ?? '')
                || $port($parts) !== $port($base) || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }
        } elseif (! str_starts_with($url, '/')) {
            return null;
        }

        // The path inside the app (without the app's own base path or index.php).
        $path     = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $basePath = rtrim((string) (parse_url(base_url(), PHP_URL_PATH) ?? ''), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        }
        $route = trim(preg_replace('#^/index\.php(?=/|$)#', '', $path), '/');
        if ($route === '' || str_contains($route, '..')) {
            return null;
        }

        $area = explode('/', $route)[0];
        if (! in_array($area, self::AREAS, true)) {
            return null; // login, logout, session, the landing page, anything outside the role areas
        }
        if ($role !== null && (self::AREAS[$role] ?? strtolower($role)) !== $area) {
            return null;
        }
        if (! self::isGetRoute($route)) {
            return null;
        }

        return '/' . $route . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    /** The role's own dashboard. */
    public static function dashboard(string $role): string
    {
        return '/' . (self::AREAS[$role] ?? 'admin') . '/dashboard';
    }

    private static function isGetRoute(string $route): bool
    {
        foreach (array_keys(service('routes')->getRoutes('GET')) as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $route || @preg_match('#^' . str_replace('#', '\#', $pattern) . '$#u', $route) === 1) {
                return true;
            }
        }

        return false;
    }
}
