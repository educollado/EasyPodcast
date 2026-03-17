<?php

declare(strict_types=1);

/**
 * Handlers de la API para operaciones de sistema:
 * consultar versión y ejecutar actualización automática.
 */

/**
 * GET /api/v1/system/version
 * Consulta la versión actual y la última disponible en GitHub.
 */
function apiGetSystemVersion(): void
{
    require_once dirname(__DIR__) . '/lib/update_handler.php';

    $data = loadUpdateData();

    apiJsonResponse(['success' => true, 'data' => [
        'current_version'  => $data['currentVersion'],
        'latest_version'   => $data['latestVersion'],
        'update_available' => $data['updateAvailable'],
        'fetch_error'      => $data['fetchError'],
    ]]);
}

/**
 * POST /api/v1/system/update
 * Descarga e instala la última release disponible en GitHub.
 */
function apiSystemUpdate(): void
{
    require_once dirname(__DIR__) . '/lib/update_handler.php';

    $info = getLatestReleaseInfo();

    if ($info['error'] !== '') {
        apiError($info['error'], 503);
    }

    $latestVersion  = $info['version'];
    $tarUrl         = $info['tar_url'];
    $currentVersion = APP_VERSION;

    if (!version_compare($latestVersion, $currentVersion, '>')) {
        apiError('Ya estás en la versión más reciente.', 409);
    }

    $appDir = dirname(__DIR__);
    $result = performUpdate($tarUrl, $appDir);

    if ($result['ok'] === false) {
        apiError($result['message'], 500);
    }

    apiJsonResponse(['success' => true, 'data' => [
        'message'      => 'Actualización completada.',
        'updated_from' => $currentVersion,
        'updated_to'   => $latestVersion,
    ]]);
}
