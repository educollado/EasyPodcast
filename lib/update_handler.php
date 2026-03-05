<?php

declare(strict_types=1);

require_once __DIR__ . '/version.php';

/**
 * Consulta la API de GitHub para obtener la información de la última release.
 * Usa cURL si está disponible, file_get_contents como alternativa.
 *
 * @return array{version: string, tar_url: string, error: string}
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
            return ['version' => '', 'tar_url' => '', 'error' => 'No se pudo conectar con GitHub (HTTP ' . $httpCode . ').'];
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
            return ['version' => '', 'tar_url' => '', 'error' => 'No se pudo conectar con GitHub.'];
        }
    }

    $data = json_decode((string) $json, true);
    if (!is_array($data) || !isset($data['tag_name'])) {
        return ['version' => '', 'tar_url' => '', 'error' => 'Respuesta inesperada de GitHub.'];
    }

    // tag_name es "v0.9" → extraemos "0.9"
    $tagName = (string) $data['tag_name'];
    $version = ltrim($tagName, 'v');

    if (!preg_match('/^\d+\.\d+/', $version)) {
        return ['version' => '', 'tar_url' => '', 'error' => 'Versión no reconocida: ' . $tagName . '.'];
    }

    // Buscar el asset .tar.gz subido manualmente a la release
    $tarUrl = '';
    foreach ($data['assets'] ?? [] as $asset) {
        $name = (string) ($asset['name'] ?? '');
        if (str_ends_with($name, '.tar.gz')) {
            $tarUrl = (string) ($asset['browser_download_url'] ?? '');
            break;
        }
    }

    // Fallback: archivo fuente generado automáticamente por GitHub
    if ($tarUrl === '') {
        $tarUrl = (string) ($data['tarball_url'] ?? '');
    }

    if ($tarUrl === '') {
        return ['version' => $version, 'tar_url' => '', 'error' => 'No se encontró el archivo .tar.gz en la release.'];
    }

    return ['version' => $version, 'tar_url' => $tarUrl, 'error' => ''];
}

/**
 * Descarga una URL a un fichero local.
 * Usa cURL si está disponible, file_get_contents como alternativa.
 */
function _epDownloadTar(string $url, string $dest): bool
{
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
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $httpCode === 200 && filesize($dest) > 100;
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
    if ($data === false || strlen($data) < 100) {
        return false;
    }
    return file_put_contents($dest, $data) !== false;
}

/**
 * Descarga el paquete desde GitHub, lo extrae sobre el directorio de la app
 * con --strip-components=1 y elimina el .tar.gz temporal.
 *
 * @param string $tarUrl URL del .tar.gz (debe provenir de GitHub)
 * @param string $appDir Directorio raíz de la aplicación
 * @return array{ok: bool, message: string}
 */
function performUpdate(string $tarUrl, string $appDir): array
{
    // Validar que la URL provenga de GitHub
    $allowed = [
        'https://github.com/',
        'https://objects.githubusercontent.com/',
        'https://codeload.github.com/',
        'https://api.github.com/',
    ];
    $ok = false;
    foreach ($allowed as $prefix) {
        if (str_starts_with($tarUrl, $prefix)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        return ['ok' => false, 'message' => 'URL de descarga no permitida.'];
    }

    if (!function_exists('exec')) {
        return ['ok' => false, 'message' => 'La función exec() está deshabilitada en este servidor. Actualiza manualmente.'];
    }

    // Fichero temporal para el .tar.gz
    $tmpBase = tempnam(sys_get_temp_dir(), 'ep_update_');
    if ($tmpBase === false) {
        return ['ok' => false, 'message' => 'No se pudo crear el archivo temporal.'];
    }
    $tmpFile = $tmpBase . '.tar.gz';
    @unlink($tmpBase); // tempnam crea el fichero base; lo renombramos con extensión

    if (!_epDownloadTar($tarUrl, $tmpFile)) {
        @unlink($tmpFile);
        return ['ok' => false, 'message' => 'No se pudo descargar el archivo de actualización.'];
    }

    // Extraer sobre el directorio de la app, eliminando el nivel raíz del tar
    $escapedTmp = escapeshellarg($tmpFile);
    $escapedDir = escapeshellarg($appDir);
    exec("tar -xzf {$escapedTmp} --strip-components=1 -C {$escapedDir} 2>&1", $output, $exitCode);

    // Eliminar el tar temporal siempre
    @unlink($tmpFile);

    if ($exitCode !== 0) {
        $detail = trim(implode(' ', $output));
        return ['ok' => false, 'message' => 'Error al extraer el paquete: ' . $detail];
    }

    // Limpiar caché de opcodes para que el nuevo código entre en vigor de inmediato
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    return ['ok' => true, 'message' => 'Actualización completada.'];
}

/**
 * Carga todos los datos necesarios para la vista de actualización.
 *
 * @return array{currentVersion: string, latestVersion: string, tarUrl: string, updateAvailable: bool, fetchError: string}
 */
function loadUpdateData(): array
{
    $currentVersion = APP_VERSION;
    $info           = getLatestReleaseInfo();
    $latestVersion  = $info['version'];
    $tarUrl         = $info['tar_url'];
    $fetchError     = $info['error'];

    $updateAvailable = false;
    if ($latestVersion !== '' && $fetchError === '') {
        $updateAvailable = version_compare($latestVersion, $currentVersion, '>');
    }

    return compact('currentVersion', 'latestVersion', 'tarUrl', 'updateAvailable', 'fetchError');
}
