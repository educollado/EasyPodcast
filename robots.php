<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/seo_helpers.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';

// Obtiene la URL base desde podcast.link; cae en el host actual como fallback.
$podcastLink = null;
try {
    $pdo = new PDO('sqlite:' . $dbPath, '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $row = $pdo->query('SELECT link FROM podcast ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $podcastLink = $row['link'] ?? null;
} catch (Throwable) {
    // Si la BD no está disponible, resolveSeoBaseUrl() usará el host actual.
}

$baseUrl = rtrim(resolveSeoBaseUrl($podcastLink), '/');

header('Content-Type: text/plain; charset=UTF-8');
echo <<<ROBOTS
# Reglas generales para todos los rastreadores
User-agent: *

# Mantener acceso a la parte publica
Allow: /

# Reducir frecuencia de rastreo (en segundos)
Crawl-delay: 120

# Bloquear rutas de administracion
Disallow: /admin.php
Disallow: /add_episode.php
Disallow: /episodes_management.php
Disallow: /podcast_management.php
Disallow: /backups.php

# Mapa del sitio para indexacion
Sitemap: {$baseUrl}/sitemap.xml
ROBOTS;
