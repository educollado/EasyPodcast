<?php

declare(strict_types=1);

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/i18n.php';

/**
 * Consulta la API de GitHub para obtener la información de la última release.
 * Usa cURL si está disponible, file_get_contents como alternativa.
 *
 * @return array{version: string, tar_url: string, checksum_url: string, error: string}
 */
function getLatestReleaseInfo(): array
{
    $apiUrl = 'https://api.github.com/repos/educollado/EasyPodcast/releases/latest';

    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'EasyPodcast-Updater/' . APP_VERSION,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $json     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($json === false || $httpCode !== 200) {
            return [
                'version' => '',
                'tar_url' => '',
                'checksum_url' => '',
                'error' => __('No se pudo conectar con GitHub (HTTP %d).', $httpCode),
            ];
        }
    } else {
        $ctx  = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => 'User-Agent: EasyPodcast-Updater/' . APP_VERSION . "\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 10,
            ],
        ]);
        $json = @file_get_contents($apiUrl, false, $ctx);
        if ($json === false) {
            return [
                'version' => '',
                'tar_url' => '',
                'checksum_url' => '',
                'error' => __('No se pudo conectar con GitHub.'),
            ];
        }
    }

    $data = json_decode((string) $json, true);
    if (!is_array($data)) {
        return [
            'version' => '',
            'tar_url' => '',
            'checksum_url' => '',
            'error' => __('Respuesta inesperada de GitHub.'),
        ];
    }

    return parseLatestReleaseData($data);
}

/**
 * Convierte una versión comprobada en el estado mínimo que necesita admin.php.
 *
 * @return array{available: bool, version: string}
 */
function buildAdminUpdateStatus(string $latestVersion): array
{
    $validVersion = preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $latestVersion) === 1;

    return [
        'available' => $validVersion && version_compare($latestVersion, APP_VERSION, '>'),
        'version' => $validVersion ? $latestVersion : '',
    ];
}

/**
 * Comprueba actualizaciones como máximo una vez por día natural para toda la
 * instalación. La fecha se reserva bajo BEGIN IMMEDIATE antes de contactar con
 * GitHub, evitando consultas duplicadas por peticiones concurrentes.
 *
 * @param null|callable():array{version:string,tar_url:string,checksum_url:string,error:string} $fetchRelease
 * @return array{available: bool, version: string}
 */
function loadDailyAdminUpdateStatus(
    string $dbPath,
    ?callable $fetchRelease = null,
    ?string $today = null
): array {
    $empty = ['available' => false, 'version' => ''];
    $today = $today !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1
        ? $today
        : date('Y-m-d');
    $transactionOpen = false;

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;

        $row = $pdo->query(
            'SELECT id, last_update_check_date, latest_version_checked
             FROM podcast ORDER BY id ASC LIMIT 1'
        )->fetch();
        if (!is_array($row)) {
            $pdo->exec('COMMIT');
            $transactionOpen = false;
            return $empty;
        }

        if ((string) ($row['last_update_check_date'] ?? '') === $today) {
            $latestVersion = (string) ($row['latest_version_checked'] ?? '');
            $pdo->exec('COMMIT');
            $transactionOpen = false;
            return buildAdminUpdateStatus($latestVersion);
        }

        $reserve = $pdo->prepare(
            'UPDATE podcast
             SET last_update_check_date = :check_date, latest_version_checked = :version
             WHERE id = :id'
        );
        $reserve->execute([
            ':check_date' => $today,
            ':version' => '',
            ':id' => (int) $row['id'],
        ]);
        $pdo->exec('COMMIT');
        $transactionOpen = false;

        $releaseInfo = $fetchRelease !== null ? $fetchRelease() : getLatestReleaseInfo();
        $latestVersion = (string) ($releaseInfo['version'] ?? '');
        if ((string) ($releaseInfo['error'] ?? '') !== '') {
            return $empty;
        }

        $status = buildAdminUpdateStatus($latestVersion);
        $save = $pdo->prepare(
            'UPDATE podcast SET latest_version_checked = :version
             WHERE id = :id AND last_update_check_date = :check_date'
        );
        $save->execute([
            ':version' => $status['version'],
            ':id' => (int) $row['id'],
            ':check_date' => $today,
        ]);

        return $status;
    } catch (Throwable) {
        if ($transactionOpen && isset($pdo) && $pdo instanceof PDO) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // El aviso nunca debe impedir la carga del panel.
            }
        }
        return $empty;
    }
}

/**
 * Valida la respuesta de GitHub y localiza el paquete junto a su checksum.
 *
 * @return array{version: string, tar_url: string, checksum_url: string, error: string}
 */
function parseLatestReleaseData(array $data): array
{
    if (!isset($data['tag_name'])) {
        return [
            'version' => '',
            'tar_url' => '',
            'checksum_url' => '',
            'error' => __('Respuesta inesperada de GitHub.'),
        ];
    }

    // tag_name es "v1.9.4" → extraemos "1.9.4".
    $tagName = (string) $data['tag_name'];
    $version = str_starts_with($tagName, 'v') ? substr($tagName, 1) : $tagName;

    if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
        return [
            'version' => '',
            'tar_url' => '',
            'checksum_url' => '',
            'error' => __('Versión no reconocida: %s.', $tagName),
        ];
    }

    $archiveName = 'EasyPodcast-' . $version . '.tar.gz';
    $checksumName = $archiveName . '.sha256';
    $tarUrl = '';
    $checksumUrl = '';

    foreach ($data['assets'] ?? [] as $asset) {
        $name = (string) ($asset['name'] ?? '');
        if ($name === $archiveName) {
            $tarUrl = (string) ($asset['browser_download_url'] ?? '');
        } elseif ($name === $checksumName) {
            $checksumUrl = (string) ($asset['browser_download_url'] ?? '');
        }
    }

    if ($tarUrl === '') {
        return [
            'version' => $version,
            'tar_url' => '',
            'checksum_url' => '',
            'error' => __('La release no contiene el paquete verificable %s.', $archiveName),
        ];
    }

    if ($checksumUrl === '') {
        return [
            'version' => $version,
            'tar_url' => '',
            'checksum_url' => '',
            'error' => __('La release no contiene el checksum SHA-256 de %s.', $archiveName),
        ];
    }

    return [
        'version' => $version,
        'tar_url' => $tarUrl,
        'checksum_url' => $checksumUrl,
        'error' => '',
    ];
}

/**
 * Comprueba que una URL HTTPS apunta a un host de descarga de GitHub.
 */
function isAllowedGithubDownloadUrl(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user'])
        || isset($parts['pass'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)
    ) {
        return false;
    }

    return in_array(strtolower((string) ($parts['host'] ?? '')), [
        'github.com',
        'objects.githubusercontent.com',
        'codeload.github.com',
        'api.github.com',
    ], true);
}

/**
 * Comprueba que el checksum corresponde al mismo asset que el paquete.
 */
function checksumMatchesArchiveUrl(string $tarUrl, string $checksumUrl): bool
{
    if (!isAllowedGithubDownloadUrl($tarUrl) || !isAllowedGithubDownloadUrl($checksumUrl)) {
        return false;
    }

    $tarPath = (string) parse_url($tarUrl, PHP_URL_PATH);
    $checksumPath = (string) parse_url($checksumUrl, PHP_URL_PATH);

    return dirname($tarPath) === dirname($checksumPath)
        && basename($checksumPath) === basename($tarPath) . '.sha256';
}

/**
 * Extrae y valida un checksum SHA-256 en formato sha256sum.
 */
function parseSha256Checksum(string $content, string $archiveName): ?string
{
    if (!preg_match('/\A([a-fA-F0-9]{64})(?:[ \t]+\*?([^\r\n]+))?\s*\z/', $content, $matches)) {
        return null;
    }

    if (isset($matches[2]) && basename(trim($matches[2])) !== $archiveName) {
        return null;
    }

    return strtolower($matches[1]);
}

/**
 * Compara el SHA-256 de un archivo descargado en tiempo constante.
 */
function archiveMatchesSha256(string $path, string $expectedHash): bool
{
    $actualHash = @hash_file('sha256', $path);

    return is_string($actualHash) && hash_equals(strtolower($expectedHash), strtolower($actualHash));
}

/**
 * Descarga una URL a un fichero local.
 * Usa cURL si está disponible, file_get_contents como alternativa.
 */
function _epDownloadFile(string $url, string $dest, int $minBytes = 1): bool
{
    if (!isAllowedGithubDownloadUrl($url)) {
        return false;
    }

    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if ($fp === false) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_USERAGENT      => 'EasyPodcast-Updater/' . APP_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $downloaded = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        $size = @filesize($dest);
        return $downloaded !== false && $httpCode === 200 && $size !== false && $size >= $minBytes;
    }

    $ctx  = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => 'User-Agent: EasyPodcast-Updater/' . APP_VERSION . "\r\n",
            'timeout'         => 120,
            'follow_location' => 1,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < $minBytes) {
        return false;
    }
    return file_put_contents($dest, $data) !== false;
}

/**
 * Elimina recursivamente un directorio o fichero temporal.
 */
function _epDeleteRecursive(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (is_dir($path)) {
        foreach (array_diff((array) scandir($path), ['.', '..']) as $child) {
            _epDeleteRecursive($path . DIRECTORY_SEPARATOR . $child);
        }
        @rmdir($path);
    }
}

/**
 * Copia recursivamente un directorio sobre otro.
 */
function _epCopyDir(string $src, string $dst): void
{
    foreach (array_diff((array) scandir($src), ['.', '..']) as $item) {
        $s = $src . DIRECTORY_SEPARATOR . $item;
        $d = $dst . DIRECTORY_SEPARATOR . $item;
        if (is_dir($s)) {
            @mkdir($d, 0755, true);
            _epCopyDir($s, $d);
        } else {
            copy($s, $d);
        }
    }
}

/**
 * Descarga el paquete y su checksum desde GitHub, verifica SHA-256 y después
 * lo extrae sobre el directorio de la app con strip-components=1.
 *
 * @param string $tarUrl URL del .tar.gz (debe provenir de GitHub)
 * @param string $checksumUrl URL del checksum SHA-256 asociado
 * @param string $appDir Directorio raíz de la aplicación
 * @return array{ok: bool, message: string}
 */
function performUpdate(string $tarUrl, string $checksumUrl, string $appDir): array
{
    if (!checksumMatchesArchiveUrl($tarUrl, $checksumUrl)) {
        return ['ok' => false, 'message' => __('Las URLs del paquete y su checksum no son válidas.')];
    }

    if (!class_exists('PharData')) {
        return ['ok' => false, 'message' => __('La extensión phar no está disponible en este servidor. Actualiza manualmente.')];
    }

    // Fichero temporal para el .tar.gz
    $tmpBase = tempnam(sys_get_temp_dir(), 'ep_update_');
    if ($tmpBase === false) {
        return ['ok' => false, 'message' => __('No se pudo crear el archivo temporal.')];
    }
    $tmpFile = $tmpBase . '.tar.gz';
    $checksumFile = $tmpFile . '.sha256';
    @unlink($tmpBase); // tempnam crea el fichero base; lo renombramos con extensión

    if (!_epDownloadFile($checksumUrl, $checksumFile, 64)) {
        @unlink($checksumFile);
        return ['ok' => false, 'message' => __('No se pudo descargar el checksum de la actualización.')];
    }

    $checksumContent = @file_get_contents($checksumFile);
    $archiveName = basename((string) parse_url($tarUrl, PHP_URL_PATH));
    $expectedHash = $checksumContent !== false && strlen($checksumContent) <= 4096
        ? parseSha256Checksum($checksumContent, $archiveName)
        : null;
    if ($expectedHash === null) {
        @unlink($checksumFile);
        return ['ok' => false, 'message' => __('El checksum de la actualización no es válido.')];
    }

    if (!_epDownloadFile($tarUrl, $tmpFile, 100)) {
        @unlink($tmpFile);
        @unlink($checksumFile);
        return ['ok' => false, 'message' => __('No se pudo descargar el archivo de actualización.')];
    }

    if (!archiveMatchesSha256($tmpFile, $expectedHash)) {
        @unlink($tmpFile);
        @unlink($checksumFile);
        return ['ok' => false, 'message' => __('La verificación SHA-256 del paquete ha fallado. No se ha instalado ningún archivo.')];
    }

    // Extraer en directorio temporal y copiar sobre $appDir (equivalente a --strip-components=1)
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ep_update_' . uniqid();
    @mkdir($tempDir, 0755, true);

    try {
        $phar = new PharData($tmpFile);
        $phar->extractTo($tempDir, null, true);

        // Identificar el directorio raíz dentro del tar (strip-components=1)
        $items  = array_values(array_diff((array) scandir($tempDir), ['.', '..']));
        $srcDir = (count($items) === 1 && is_dir($tempDir . DIRECTORY_SEPARATOR . $items[0]))
            ? $tempDir . DIRECTORY_SEPARATOR . $items[0]
            : $tempDir;

        _epCopyDir($srcDir, $appDir);
    } catch (Throwable $ex) {
        _epDeleteRecursive($tempDir);
        @unlink($tmpFile);
        @unlink($checksumFile);
        return ['ok' => false, 'message' => __('Error al extraer el paquete: %s', $ex->getMessage())];
    }

    // Limpiar temporales siempre
    _epDeleteRecursive($tempDir);
    @unlink($tmpFile);
    @unlink($checksumFile);

    // Si DISABLE_HTTPS_REDIRECT está activo (Docker sin terminación TLS directa),
    // asegurarse de que el fichero de señal existe para que .htaccess no active
    // el redirect HTTPS después de que el updater haya copiado los nuevos ficheros.
    if (getenv('DISABLE_HTTPS_REDIRECT') === 'true') {
        @touch($appDir . '/docker/.disable_https_redirect');
    }

    // Limpiar caché de opcodes para que el nuevo código entre en vigor de inmediato
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    return ['ok' => true, 'message' => __('Actualización completada.')];
}

/**
 * Extrae las notas de la versión indicada desde CHANGELOG.md.
 *
 * Devuelve el bloque de texto entre el encabezado "## X.Y.Z" de esa versión
 * y el siguiente encabezado "## ", o cadena vacía si no se encuentra.
 *
 * @param string $version Versión a buscar (p.ej. "1.6.3")
 * @param string $appDir  Directorio raíz de la aplicación
 */
function getChangelogForVersion(string $version, string $appDir): string
{
    $path = $appDir . '/CHANGELOG.md';
    if (!is_file($path)) {
        return '';
    }
    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return '';
    }
    $pattern = '/^## ' . preg_quote($version, '/') . '[^\n]*\n(.*?)(?=^## |\Z)/ms';
    if (preg_match($pattern, $content, $m)) {
        return trim((string) $m[1]);
    }
    return '';
}

/**
 * Carga todos los datos necesarios para la vista de actualización.
 *
 * @param string $appDir Directorio raíz de la aplicación (para leer CHANGELOG.md)
 * @return array{currentVersion: string, latestVersion: string, tarUrl: string, checksumUrl: string, updateAvailable: bool, fetchError: string, changelogNotes: string}
 */
function loadUpdateData(string $appDir = ''): array
{
    $currentVersion = APP_VERSION;
    $info           = getLatestReleaseInfo();
    $latestVersion  = $info['version'];
    $tarUrl         = $info['tar_url'];
    $checksumUrl    = $info['checksum_url'];
    $fetchError     = $info['error'];

    $updateAvailable = false;
    if ($latestVersion !== '' && $fetchError === '') {
        $updateAvailable = version_compare($latestVersion, $currentVersion, '>');
    }

    // Notas del CHANGELOG para la versión instalada actualmente (útil tras actualizar).
    $changelogNotes = $appDir !== '' ? getChangelogForVersion($currentVersion, $appDir) : '';

    return compact('currentVersion', 'latestVersion', 'tarUrl', 'checksumUrl', 'updateAvailable', 'fetchError', 'changelogNotes');
}
