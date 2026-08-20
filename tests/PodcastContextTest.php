<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/podcast_context.php';

test('podcastStorageDirectory mantiene un almacén multimedia global en modo Multipodcast', function () {
    assert_eq('/srv/app/audios', podcastStorageDirectory('/srv/app', 'audios', ['slug' => 'aratos'], true));
    assert_eq('/srv/app/images', podcastStorageDirectory('/srv/app', 'images', ['slug' => 'otro'], false));
});

test('normalizePodcastSlug translitera y normaliza el directorio', function () {
    assert_eq('aratos-tecnologia', normalizePodcastSlug('  Aratós Tecnología  '));
    assert_eq('mi-podcast-2', normalizePodcastSlug('Mi Podcast 2'));
});

test('validatePodcastSlug rechaza rutas reservadas e inválidas', function () {
    assert_not_null(validatePodcastSlug('admin'));
    assert_not_null(validatePodcastSlug('2026'));
    assert_not_null(validatePodcastSlug('Podcast_Malo'));
    assert_null(validatePodcastSlug('podcast-valido'));
});

test('podcastPath añade el directorio únicamente en modo multipodcast', function () {
    $podcast = ['slug' => 'redes'];
    assert_eq('/redes/feed.xml', podcastPath($podcast, 'feed.xml', true));
    assert_eq('/feed.xml', podcastPath($podcast, 'feed.xml', false));
});

test('resolvePublicPodcast usa el podcast principal cuando Multipodcast está desactivado', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec("INSERT INTO podcast VALUES (1, 'Primero'), (2, 'Principal')");
    $pdo->exec('CREATE TABLE app_settings (id INTEGER PRIMARY KEY, multipodcast_enabled INTEGER, homepage_podcast_id INTEGER, summary_hero_image_url TEXT, summary_title TEXT, summary_subtitle TEXT, summary_theme TEXT, primary_podcast_id INTEGER)');
    $pdo->exec("INSERT INTO app_settings VALUES (1, 0, NULL, NULL, NULL, NULL, 'easypodcast', 2)");

    assert_eq(2, (int) (resolvePublicPodcast($pdo)['id'] ?? 0));
});

test('resolveFeedPodcast usa el principal en la raíz y respeta el directorio solicitado', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, title TEXT, slug TEXT)');
    $pdo->exec("INSERT INTO podcast VALUES (1, 'Principal', 'principal'), (2, 'Secundario', 'secundario')");
    $pdo->exec('CREATE TABLE app_settings (id INTEGER PRIMARY KEY, multipodcast_enabled INTEGER, homepage_podcast_id INTEGER, summary_hero_image_url TEXT, summary_title TEXT, summary_subtitle TEXT, summary_theme TEXT, primary_podcast_id INTEGER)');
    $pdo->exec("INSERT INTO app_settings VALUES (1, 1, 2, NULL, NULL, NULL, 'easypodcast', 1)");

    assert_eq(1, (int) (resolveFeedPodcast($pdo)['id'] ?? 0));
    assert_eq(2, (int) (resolveFeedPodcast($pdo, 'secundario')['id'] ?? 0));
    assert_null(resolveFeedPodcast($pdo, 'inexistente'));
});
