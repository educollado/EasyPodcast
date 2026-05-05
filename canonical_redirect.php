<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/migration_runner.php';
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/admin_theme.php';
require_once __DIR__ . '/lib/csp.php';
i18n_load('es_ES');
sendContentSecurityPolicyHeaders();

/**
 * Determina si la petición actual llega por HTTPS.
 * Comprueba $_SERVER['HTTPS'], SERVER_PORT y el header X-Forwarded-Proto de proxies.
 */
function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $forwardedProto === 'https';
}

/**
 * Carga el idioma de la aplicación desde BD y lo aplica a i18n.
 * Llamada una vez tras runMigrations() para que app_language exista.
 * Silenciosa en caso de error: mantiene el idioma por defecto ya cargado.
 */
function loadAppLocale(string $dbPath): void
{
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $appLang = $pdo->query('SELECT app_language FROM podcast LIMIT 1')->fetchColumn();
        if (is_string($appLang) && $appLang !== '') {
            i18n_load($appLang);
        }
    } catch (Throwable $e) {
        // Silencioso: mantiene el idioma por defecto.
    }
}

/**
 * Aplica redirección 301 al host/esquema canónico definido en podcast.link.
 * No actúa en CLI ni si las cabeceras ya han sido enviadas.
 * Si hay error de lectura en BD, no bloquea la request y retorna silenciosamente.
 */
function enforceCanonicalHostFromPodcastLink(string $dbPath): void
{
    // Las migraciones deben ejecutarse siempre en contexto web, independientemente
    // de si las cabeceras ya se han enviado (headers_sent solo afecta al redirect).
    if (PHP_SAPI !== 'cli') {
        runMigrations($dbPath);
        loadAppLocale($dbPath);
        loadAdminTheme($dbPath);
        require_once __DIR__ . '/lib/scheduler.php';
        publishScheduledEpisodes($dbPath);
    }

    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    // Permite deshabilitar la redirección canónica en entornos locales/Docker sin HTTPS.
    if (getenv('DISABLE_HTTPS_REDIRECT') === 'true') {
        return;
    }

    $httpHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($httpHost === '') {
        return;
    }

    $currentHost = strtolower((string) preg_replace('/:\d+$/', '', $httpHost));
    $currentScheme = isHttpsRequest() ? 'https' : 'http';

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $tableExists = (bool) $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'podcast' LIMIT 1")
            ->fetchColumn();
        if (!$tableExists) {
            return;
        }

        $podcast = $pdo->query('SELECT link FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
        if (!$podcast) {
            return;
        }

        $link = trim((string) ($podcast['link'] ?? ''));
        if ($link === '') {
            return;
        }

        $parts = parse_url($link);
        if (!is_array($parts) || empty($parts['host'])) {
            return;
        }

        $canonicalHost = strtolower((string) $parts['host']);
        $canonicalScheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $canonicalPort = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        if ($canonicalHost === $currentHost && $canonicalScheme === $currentScheme) {
            return;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($requestUri === '') {
            $requestUri = '/';
        }

        $target = $canonicalScheme . '://' . $canonicalHost . $canonicalPort . $requestUri;
        header('Location: ' . $target, true, 301);
        exit;
    } catch (Throwable $e) {
        // Si hay error de lectura en BD, no bloquea la request.
        return;
    }
}
