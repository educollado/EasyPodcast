<?php

declare(strict_types=1);

/**
 * Ejecuta las migraciones de esquema pendientes usando PRAGMA user_version.
 * Se llama una vez por request antes del primer acceso a BD.
 */
function runMigrations(string $dbPath): void
{
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

    if ($version < 1) {
        migration_v1($pdo);
        $pdo->exec('PRAGMA user_version = 1');
        $version = 1;
    }

    if ($version < 2) {
        migration_v2($pdo);
        $pdo->exec('PRAGMA user_version = 2');
        $version = 2;
    }

    if ($version < 3) {
        migration_v3($pdo);
        $pdo->exec('PRAGMA user_version = 3');
        $version = 3;
    }

    if ($version < 4) {
        migration_v4($pdo);
        $pdo->exec('PRAGMA user_version = 4');
        $version = 4;
    }

    if ($version < 5) {
        migration_v5($pdo);
        $pdo->exec('PRAGMA user_version = 5');
        $version = 5;
    }

    if ($version < 6) {
        migration_v6($pdo);
        $pdo->exec('PRAGMA user_version = 6');
        $version = 6;
    }

    if ($version < 7) {
        migration_v7($pdo);
        $pdo->exec('PRAGMA user_version = 7');
        $version = 7;
    }

    if ($version < 8) {
        migration_v8($pdo);
        $pdo->exec('PRAGMA user_version = 8');
        $version = 8;
    }

    if ($version < 9) {
        migration_v9($pdo);
        $pdo->exec('PRAGMA user_version = 9');
        $version = 9;
    }

    if ($version < 10) {
        migration_v10($pdo);
        $pdo->exec('PRAGMA user_version = 10');
        $version = 10;
    }

    if ($version < 11) {
        migration_v11($pdo);
        $pdo->exec('PRAGMA user_version = 11');
        $version = 11;
    }

    if ($version < 12) {
        migration_v12($pdo);
        $pdo->exec('PRAGMA user_version = 12');
        $version = 12;
    }

    if ($version < 13) {
        migration_v13($pdo);
        $pdo->exec('PRAGMA user_version = 13');
        $version = 13;
    }

    if ($version < 14) {
        migration_v14($pdo);
        $pdo->exec('PRAGMA user_version = 14');
        $version = 14;
    }

    if ($version < 15) {
        migration_v15($pdo);
        $pdo->exec('PRAGMA user_version = 15');
        $version = 15;
    }

    if ($version < 16) {
        migration_v16($pdo);
        $pdo->exec('PRAGMA user_version = 16');
        $version = 16;
    }

    if ($version < 17) {
        migration_v17($pdo);
        $pdo->exec('PRAGMA user_version = 17');
        $version = 17;
    }

    if ($version < 18) {
        migration_v18($pdo);
        $pdo->exec('PRAGMA user_version = 18');
        $version = 18;
    }

    if ($version < 19) {
        migration_v19($pdo);
        $pdo->exec('PRAGMA user_version = 19');
        $version = 19;
    }

    if ($version < 20) {
        migration_v20($pdo);
        $pdo->exec('PRAGMA user_version = 20');
        $version = 20;
    }

    if ($version < 21) {
        migration_v21($pdo);
        $pdo->exec('PRAGMA user_version = 21');
        $version = 21;
    }

    if ($version < 22) {
        migration_v22($pdo);
        $pdo->exec('PRAGMA user_version = 22');
        $version = 22;
    }
}

/**
 * Migración v13: garantía de reparación — añade admin_theme si no existe.
 * Algunos usuarios que actualizaron desde versiones con user_version = 12 en
 * schema.sql (antes de que se añadiera la columna) se quedaron sin ella.
 * Esta migración es un no-op para instalaciones correctas.
 */
function migration_v13(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
        'name'
    );
    if (!in_array('admin_theme', $existing, true)) {
        $pdo->exec("ALTER TABLE podcast ADD COLUMN admin_theme TEXT NOT NULL DEFAULT 'default'");
    }
}

/**
 * Migración v12: añade columna admin_theme a podcast para el tema visual del sitio.
 */
function migration_v12(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
        'name'
    );
    if (!in_array('admin_theme', $existing, true)) {
        $pdo->exec("ALTER TABLE podcast ADD COLUMN admin_theme TEXT NOT NULL DEFAULT 'default'");
    }
}

/**
 * Migración v11: renombra la columna description a content en episodes.
 * El campo contenía HTML del episodio; 'content' refleja mejor su propósito.
 */
function migration_v11(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(episodes)')->fetchAll(),
        'name'
    );
    if (in_array('description', $existing, true) && !in_array('content', $existing, true)) {
        $pdo->exec('ALTER TABLE episodes RENAME COLUMN description TO content');
    }
}

/**
 * Migración v10: añade short_description a episodes para descripción en texto plano.
 * Si se rellena, se muestra en portada en lugar del excerpt del contenido HTML.
 */
function migration_v10(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(episodes)')->fetchAll(),
        'name'
    );
    if (!in_array('short_description', $existing, true)) {
        $pdo->exec('ALTER TABLE episodes ADD COLUMN short_description TEXT');
    }
}

/**
 * Migración v9: añade columnas name y last_used_at a api_tokens.
 * name identifica el token con un nombre legible; last_used_at registra el último uso.
 *
 * Nota de compatibilidad: en upgrades desde versiones anteriores a 1.6.0 la
 * numeración de migraciones cambió y migration_v2 (que crea api_tokens) puede
 * haberse saltado si el PRAGMA user_version ya era >= 2. En ese caso se crea
 * la tabla completa aquí para no dejar la instalación rota.
 */
function migration_v9(PDO $pdo): void
{
    $tableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='api_tokens' LIMIT 1")
        ->fetchColumn();

    if (!$tableExists) {
        // Upgrade desde esquema pre-1.6.0: crear tabla con todas las columnas de una vez.
        $pdo->exec(
            "CREATE TABLE api_tokens (
              id INTEGER PRIMARY KEY,
              token TEXT NOT NULL,
              user_id INTEGER NOT NULL,
              expires_at TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              name TEXT NOT NULL DEFAULT '',
              last_used_at TEXT
            )"
        );
        return;
    }

    $existing = array_column(
        $pdo->query('PRAGMA table_info(api_tokens)')->fetchAll(),
        'name'
    );
    $pending = [
        'name'         => "ALTER TABLE api_tokens ADD COLUMN name TEXT NOT NULL DEFAULT ''",
        'last_used_at' => 'ALTER TABLE api_tokens ADD COLUMN last_used_at TEXT',
    ];
    foreach ($pending as $col => $sql) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec($sql);
        }
    }
}

/**
 * Migración v8: añade columna app_language a podcast para el idioma de la interfaz.
 */
function migration_v8(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
        'name'
    );
    if (!in_array('app_language', $existing, true)) {
        $pdo->exec("ALTER TABLE podcast ADD COLUMN app_language TEXT NOT NULL DEFAULT 'es_ES'");
    }
}

/**
 * Migración v7: añade índice sobre episodes(link) para resolución O(log n) de URLs.
 */
function migration_v7(PDO $pdo): void
{
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_episodes_link ON episodes(link)'
    );
}

/**
 * Migración v6: crea la tabla pages para páginas estáticas con jerarquía (padre/hijo).
 */
function migration_v6(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pages (
          id INTEGER PRIMARY KEY,
          title TEXT NOT NULL,
          slug TEXT NOT NULL,
          full_path TEXT NOT NULL UNIQUE,
          content TEXT NOT NULL DEFAULT '',
          parent_id INTEGER,
          sort_order INTEGER NOT NULL DEFAULT 0,
          status TEXT NOT NULL DEFAULT 'draft',
          created_at TEXT DEFAULT (datetime('now')),
          updated_at TEXT DEFAULT (datetime('now')),
          FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        "CREATE INDEX IF NOT EXISTS idx_pages_status ON pages(status, parent_id, sort_order)"
    );
}

/**
 * Migración v3: hace pub_date nullable en episodes para que los borradores puedan
 * no tener fecha de publicación. SQLite no soporta ALTER COLUMN, así que se
 * recrea la tabla preservando todos los datos dentro de una transacción.
 */
function migration_v3(PDO $pdo): void
{
    // Si la tabla episodes no existe aún, se creará con el esquema correcto; nada que hacer.
    $tableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='episodes' LIMIT 1")
        ->fetchColumn();
    if (!$tableExists) {
        return;
    }

    // Comprueba si pub_date ya es nullable (notnull = 0 → nullable).
    $columns = $pdo->query('PRAGMA table_info(episodes)')->fetchAll();
    foreach ($columns as $col) {
        if ($col['name'] === 'pub_date' && (int) $col['notnull'] === 0) {
            return; // Ya es nullable, nada que hacer.
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec(
            "CREATE TABLE episodes_v3 (
              id INTEGER PRIMARY KEY,
              guid TEXT NOT NULL UNIQUE,
              title TEXT NOT NULL,
              description TEXT NOT NULL,
              link TEXT,
              pub_date TEXT,
              audio_url TEXT NOT NULL,
              audio_mime_type TEXT NOT NULL,
              audio_size_bytes INTEGER NOT NULL,
              duration TEXT,
              explicit INTEGER,
              season_number INTEGER,
              episode_number INTEGER,
              episode_type TEXT,
              image_url TEXT,
              author TEXT,
              status TEXT NOT NULL DEFAULT 'draft',
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            )"
        );
        $pdo->exec('INSERT INTO episodes_v3 SELECT * FROM episodes');
        $pdo->exec('DROP TABLE episodes');
        $pdo->exec('ALTER TABLE episodes_v3 RENAME TO episodes');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_episodes_status_pubdate ON episodes(status, pub_date)');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Migración v5: crea la tabla social para los enlaces a redes sociales del autor.
 */
function migration_v5(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS social (
          id INTEGER PRIMARY KEY,
          blog      TEXT NOT NULL DEFAULT '',
          linkedin  TEXT NOT NULL DEFAULT '',
          mastodon  TEXT NOT NULL DEFAULT '',
          x         TEXT NOT NULL DEFAULT '',
          pixelfed  TEXT NOT NULL DEFAULT '',
          instagram TEXT NOT NULL DEFAULT '',
          youtube   TEXT NOT NULL DEFAULT '',
          github    TEXT NOT NULL DEFAULT '',
          bluesky   TEXT NOT NULL DEFAULT ''
        )"
    );
}

/**
 * Migración v4: añade columnas TOTP a la tabla management para soporte de 2FA.
 */
function migration_v4(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(management)')->fetchAll(),
        'name'
    );
    $pending = [
        'totp_secret'         => 'ALTER TABLE management ADD COLUMN totp_secret TEXT',
        'totp_enabled'        => 'ALTER TABLE management ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0',
        'totp_recovery_codes' => 'ALTER TABLE management ADD COLUMN totp_recovery_codes TEXT',
    ];
    foreach ($pending as $col => $sql) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec($sql);
        }
    }
}

/**
 * Migración v2: crea la tabla api_tokens para autenticación de la API REST.
 */
function migration_v2(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS api_tokens (
          id INTEGER PRIMARY KEY,
          token TEXT NOT NULL,
          user_id INTEGER NOT NULL,
          expires_at TEXT,
          created_at TEXT DEFAULT (datetime('now'))
        )"
    );
}

/**
 * Migración v1: añade columnas introducidas tras el esquema inicial.
 * PRAGMA table_info devuelve [] si la tabla no existe → no se ejecuta ningún ALTER.
 */
function migration_v1(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
        'name'
    );
    $pending = [
        'rss_item_limit'       => 'ALTER TABLE podcast ADD COLUMN rss_item_limit INTEGER NOT NULL DEFAULT 0',
        'home_items_per_page'  => 'ALTER TABLE podcast ADD COLUMN home_items_per_page INTEGER NOT NULL DEFAULT 20',
        'write_audio_metadata' => 'ALTER TABLE podcast ADD COLUMN write_audio_metadata INTEGER NOT NULL DEFAULT 0',
        'cache_enabled'        => 'ALTER TABLE podcast ADD COLUMN cache_enabled INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($pending as $col => $sql) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec($sql);
        }
    }
}

/**
 * Migración v14: añade tablas de estadísticas de descargas.
 */
function migration_v14(PDO $pdo): void
{
    // Tabla principal de estadísticas (datos brutos)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS estadisticas (
          id INTEGER PRIMARY KEY,
          episode_id INTEGER NOT NULL,
          episode_guid TEXT NOT NULL,
          episode_title TEXT NOT NULL,
          ip_address TEXT NOT NULL,
          user_agent TEXT,
          referer TEXT,
          download_date TEXT DEFAULT (datetime('now')),
          FOREIGN KEY(episode_id) REFERENCES episodes(id) ON DELETE CASCADE
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_estadisticas_date ON estadisticas(download_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_estadisticas_episode ON estadisticas(episode_id)');

    // Tabla de resumen mensual
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS estadisticas_mensuales (
          id INTEGER PRIMARY KEY,
          episode_id INTEGER NOT NULL,
          episode_title TEXT NOT NULL,
          anio INTEGER NOT NULL,
          mes INTEGER NOT NULL,
          descargas INTEGER NOT NULL DEFAULT 0,
          UNIQUE(episode_id, anio, mes)
        )"
    );

    // Tabla de resumen anual
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS estadisticas_anuales (
          id INTEGER PRIMARY KEY,
          episode_id INTEGER NOT NULL,
          episode_title TEXT NOT NULL,
          anio INTEGER NOT NULL,
          descargas INTEGER NOT NULL DEFAULT 0,
          UNIQUE(episode_id, anio)
        )"
    );

    // Trigger para resumen mensual
    $pdo->exec(
        "CREATE TRIGGER IF NOT EXISTS trg_mensual_after_insert 
         AFTER INSERT ON estadisticas
         FOR EACH ROW
         BEGIN
           INSERT INTO estadisticas_mensuales (episode_id, episode_title, anio, mes, descargas)
           VALUES (
             NEW.episode_id, NEW.episode_title,
             CAST(STRFTIME('%Y', NEW.download_date) AS INTEGER),
             CAST(STRFTIME('%m', NEW.download_date) AS INTEGER), 1
           )
           ON CONFLICT(episode_id, anio, mes) DO UPDATE SET descargas = descargas + 1;
         END"
    );

    // Trigger para resumen anual
    $pdo->exec(
        "CREATE TRIGGER IF NOT EXISTS trg_anual_after_insert 
         AFTER INSERT ON estadisticas
         FOR EACH ROW
         BEGIN
           INSERT INTO estadisticas_anuales (episode_id, episode_title, anio, descargas)
           VALUES (
             NEW.episode_id, NEW.episode_title,
             CAST(STRFTIME('%Y', NEW.download_date) AS INTEGER), 1
           )
           ON CONFLICT(episode_id, anio) DO UPDATE SET descargas = descargas + 1;
         END"
    );
}

/**
 * Migración v15: añade columna action_type a estadisticas para diferenciar descargas de reproducciones.
 */
function migration_v15(PDO $pdo): void
{
    $existingColumns = array_column(
        $pdo->query('PRAGMA table_info(estadisticas)')->fetchAll(),
        'name'
    );
    if (!in_array('action_type', $existingColumns, true)) {
        $pdo->exec("ALTER TABLE estadisticas ADD COLUMN action_type TEXT NOT NULL DEFAULT 'download'");
    }
}

/**
 * Migración v16: deja de guardar tokens API en claro y añade alcance explícito.
 */
function migration_v16(PDO $pdo): void
{
    $tableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='api_tokens' LIMIT 1")
        ->fetchColumn();

    if (!$tableExists) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS api_tokens (
              id INTEGER PRIMARY KEY,
              token TEXT NOT NULL DEFAULT '',
              token_hash TEXT NOT NULL DEFAULT '',
              token_suffix TEXT NOT NULL DEFAULT '',
              scope TEXT NOT NULL DEFAULT 'content',
              name TEXT NOT NULL DEFAULT '',
              user_id INTEGER NOT NULL,
              expires_at TEXT,
              last_used_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            )"
        );
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash) WHERE token_hash != ''");
        return;
    }

    $legacyRows = $pdo->query('SELECT * FROM api_tokens')->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        $pdo->exec('DROP TABLE IF EXISTS api_tokens_v16_new');
        $pdo->exec(
            "CREATE TABLE api_tokens_v16_new (
              id INTEGER PRIMARY KEY,
              token TEXT NOT NULL DEFAULT '',
              token_hash TEXT NOT NULL DEFAULT '',
              token_suffix TEXT NOT NULL DEFAULT '',
              scope TEXT NOT NULL DEFAULT 'content',
              name TEXT NOT NULL DEFAULT '',
              user_id INTEGER NOT NULL,
              expires_at TEXT,
              last_used_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            )"
        );

        $insert = $pdo->prepare(
            'INSERT INTO api_tokens_v16_new (
                id, token, token_hash, token_suffix, scope, name, user_id, expires_at, last_used_at, created_at
            ) VALUES (
                :id, :token, :token_hash, :token_suffix, :scope, :name, :user_id, :expires_at, :last_used_at, :created_at
            )'
        );

        foreach ($legacyRows as $row) {
            $token = (string) ($row['token'] ?? '');
            $tokenHash = trim((string) ($row['token_hash'] ?? ''));
            $tokenSuffix = trim((string) ($row['token_suffix'] ?? ''));
            $scope = (string) ($row['scope'] ?? 'content');
            $normalizedScope = $scope === 'admin' ? 'admin' : 'content';

            if ($tokenHash === '' && $token !== '') {
                $tokenHash = hash('sha256', $token);
            }
            if ($tokenSuffix === '' && $token !== '') {
                $tokenSuffix = substr($token, -8);
            }

            $insert->execute([
                ':id' => (int) ($row['id'] ?? 0),
                ':token' => '',
                ':token_hash' => $tokenHash,
                ':token_suffix' => $tokenSuffix,
                ':scope' => $normalizedScope,
                ':name' => (string) ($row['name'] ?? ''),
                ':user_id' => (int) ($row['user_id'] ?? 1),
                ':expires_at' => $row['expires_at'] ?? null,
                ':last_used_at' => $row['last_used_at'] ?? null,
                ':created_at' => $row['created_at'] ?? null,
            ]);
        }

        $pdo->exec('DROP TABLE api_tokens');
        $pdo->exec('ALTER TABLE api_tokens_v16_new RENAME TO api_tokens');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash) WHERE token_hash != ''");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash) WHERE token_hash != ''");
}

/**
 * Migración v17: añade public_theme_mode_auto a podcast para aplicar el modo público
 * "según sistema" como preferencia global gestionada por el administrador.
 */
function migration_v17(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
        'name'
    );
    if (!in_array('public_theme_mode_auto', $existing, true)) {
        $pdo->exec("ALTER TABLE podcast ADD COLUMN public_theme_mode_auto INTEGER NOT NULL DEFAULT 0");
    }
}

/**
 * Migración v18: activa la nueva identidad visual de EasyPodcast en instalaciones
 * que todavía conservan el tema predeterminado histórico. Los temas elegidos
 * explícitamente por el administrador no se modifican.
 */
function migration_v18(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'UPDATE podcast SET admin_theme = :new_theme WHERE admin_theme = :legacy_theme'
    );
    $stmt->execute([
        ':new_theme' => 'easypodcast',
        ':legacy_theme' => 'default',
    ]);
}

/**
 * Migración v19: añade una imagen hero opcional para la cabecera pública.
 */
function migration_v19(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
        'name'
    );
    if (!in_array('hero_image_url', $existing, true)) {
        $pdo->exec("ALTER TABLE podcast ADD COLUMN hero_image_url TEXT");
    }
}

/**
 * Migración v20: recuerda la comprobación diaria de actualizaciones para no
 * consultar GitHub en cada entrada al panel de administración.
 */
function migration_v20(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
        'name'
    );
    if (!in_array('last_update_check_date', $existing, true)) {
        $pdo->exec("ALTER TABLE podcast ADD COLUMN last_update_check_date TEXT");
    }
    if (!in_array('latest_version_checked', $existing, true)) {
        $pdo->exec("ALTER TABLE podcast ADD COLUMN latest_version_checked TEXT");
    }
}

/**
 * Migración v21: convierte el modelo de un único podcast en un modelo multi-tenant.
 * La configuración de la instalación y las credenciales siguen siendo globales;
 * el contenido y sus estadísticas quedan asociados explícitamente a un podcast.
 */
function migration_v21(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();

    try {
        $podcastColumns = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(), 'name');
        if (!in_array('slug', $podcastColumns, true)) {
            $pdo->exec('ALTER TABLE podcast ADD COLUMN slug TEXT');
        }
        if (!in_array('created_at', $podcastColumns, true)) {
            $pdo->exec("ALTER TABLE podcast ADD COLUMN created_at TEXT");
            $pdo->exec("UPDATE podcast SET created_at = datetime('now') WHERE created_at IS NULL");
        }
        if (!in_array('updated_at', $podcastColumns, true)) {
            $pdo->exec("ALTER TABLE podcast ADD COLUMN updated_at TEXT");
            $pdo->exec("UPDATE podcast SET updated_at = datetime('now') WHERE updated_at IS NULL");
        }
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_podcast_slug ON podcast(slug) WHERE slug IS NOT NULL AND slug != ''");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_settings (
              id INTEGER PRIMARY KEY CHECK (id = 1),
              multipodcast_enabled INTEGER NOT NULL DEFAULT 0,
              homepage_podcast_id INTEGER,
              FOREIGN KEY(homepage_podcast_id) REFERENCES podcast(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec('INSERT OR IGNORE INTO app_settings (id, multipodcast_enabled, homepage_podcast_id) VALUES (1, 0, NULL)');

        $firstPodcastId = (int) ($pdo->query('SELECT id FROM podcast ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);

        migrationV21RebuildEpisodes($pdo, $firstPodcastId);
        migrationV21RebuildPages($pdo, $firstPodcastId);
        migrationV21AddPodcastId($pdo, 'social', $firstPodcastId);
        migrationV21AddPodcastId($pdo, 'api_tokens', $firstPodcastId);
        migrationV21RebuildStatistics($pdo, $firstPodcastId);

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_social_podcast ON social(podcast_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_podcast ON api_tokens(podcast_id)');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

/**
 * Migración v22: añade la imagen hero propia de la portada-resumen Multipodcast.
 */
function migration_v22(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(app_settings)')->fetchAll(),
        'name'
    );
    if (!in_array('summary_hero_image_url', $existing, true)) {
        $pdo->exec('ALTER TABLE app_settings ADD COLUMN summary_hero_image_url TEXT');
    }
}

function migrationV21AddPodcastId(PDO $pdo, string $table, int $podcastId): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
    if (!in_array('podcast_id', $columns, true)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN podcast_id INTEGER');
    }
    $stmt = $pdo->prepare('UPDATE ' . $table . ' SET podcast_id = :podcast_id WHERE podcast_id IS NULL');
    $stmt->execute([':podcast_id' => $podcastId]);
}

function migrationV21RebuildEpisodes(PDO $pdo, int $podcastId): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(episodes)')->fetchAll(), 'name');
    if (in_array('podcast_id', $columns, true)) {
        return;
    }

    $pdo->exec('ALTER TABLE episodes RENAME TO episodes_v20');
    $pdo->exec(
        "CREATE TABLE episodes (
          id INTEGER PRIMARY KEY,
          podcast_id INTEGER NOT NULL,
          guid TEXT NOT NULL,
          title TEXT NOT NULL,
          content TEXT NOT NULL,
          short_description TEXT,
          link TEXT,
          pub_date TEXT,
          audio_url TEXT NOT NULL,
          audio_mime_type TEXT NOT NULL,
          audio_size_bytes INTEGER NOT NULL,
          duration TEXT,
          explicit INTEGER,
          season_number INTEGER,
          episode_number INTEGER,
          episode_type TEXT,
          image_url TEXT,
          author TEXT,
          status TEXT NOT NULL DEFAULT 'draft',
          created_at TEXT DEFAULT (datetime('now')),
          updated_at TEXT DEFAULT (datetime('now')),
          FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE,
          UNIQUE(podcast_id, guid)
        )"
    );
    $stmt = $pdo->prepare(
        'INSERT INTO episodes (id, podcast_id, guid, title, content, short_description, link, pub_date, audio_url, audio_mime_type, audio_size_bytes, duration, explicit, season_number, episode_number, episode_type, image_url, author, status, created_at, updated_at)
         SELECT id, :podcast_id, guid, title, content, short_description, link, pub_date, audio_url, audio_mime_type, audio_size_bytes, duration, explicit, season_number, episode_number, episode_type, image_url, author, status, created_at, updated_at FROM episodes_v20'
    );
    $stmt->execute([':podcast_id' => $podcastId]);
    $pdo->exec('DROP TABLE episodes_v20');
    $pdo->exec('CREATE INDEX idx_episodes_status_pubdate ON episodes(podcast_id, status, pub_date)');
    $pdo->exec('CREATE INDEX idx_episodes_link ON episodes(podcast_id, link)');
}

function migrationV21RebuildPages(PDO $pdo, int $podcastId): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(pages)')->fetchAll(), 'name');
    if (in_array('podcast_id', $columns, true)) {
        return;
    }

    $pdo->exec('ALTER TABLE pages RENAME TO pages_v20');
    $pdo->exec(
        "CREATE TABLE pages (
          id INTEGER PRIMARY KEY,
          podcast_id INTEGER NOT NULL,
          title TEXT NOT NULL,
          slug TEXT NOT NULL,
          full_path TEXT NOT NULL,
          content TEXT NOT NULL DEFAULT '',
          parent_id INTEGER,
          sort_order INTEGER NOT NULL DEFAULT 0,
          status TEXT NOT NULL DEFAULT 'draft',
          created_at TEXT DEFAULT (datetime('now')),
          updated_at TEXT DEFAULT (datetime('now')),
          FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE,
          FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE RESTRICT,
          UNIQUE(podcast_id, full_path)
        )"
    );
    $stmt = $pdo->prepare(
        'INSERT INTO pages (id, podcast_id, title, slug, full_path, content, parent_id, sort_order, status, created_at, updated_at)
         SELECT id, :podcast_id, title, slug, full_path, content, parent_id, sort_order, status, created_at, updated_at FROM pages_v20'
    );
    $stmt->execute([':podcast_id' => $podcastId]);
    $pdo->exec('DROP TABLE pages_v20');
    $pdo->exec('CREATE INDEX idx_pages_status ON pages(podcast_id, status, parent_id, sort_order)');
}

function migrationV21RebuildStatistics(PDO $pdo, int $podcastId): void
{
    foreach (['trg_mensual_after_insert', 'trg_anual_after_insert'] as $trigger) {
        $pdo->exec('DROP TRIGGER IF EXISTS ' . $trigger);
    }
    $columns = array_column($pdo->query('PRAGMA table_info(estadisticas)')->fetchAll(), 'name');
    if (!in_array('podcast_id', $columns, true)) {
        $pdo->exec('ALTER TABLE estadisticas RENAME TO estadisticas_v20');
        $pdo->exec('ALTER TABLE estadisticas_mensuales RENAME TO estadisticas_mensuales_v20');
        $pdo->exec('ALTER TABLE estadisticas_anuales RENAME TO estadisticas_anuales_v20');

        $pdo->exec(
            "CREATE TABLE estadisticas (
              id INTEGER PRIMARY KEY,
              podcast_id INTEGER NOT NULL,
              episode_id INTEGER NOT NULL,
              episode_guid TEXT NOT NULL,
              episode_title TEXT NOT NULL,
              ip_address TEXT NOT NULL,
              user_agent TEXT,
              referer TEXT,
              action_type TEXT NOT NULL DEFAULT 'download',
              download_date TEXT DEFAULT (datetime('now')),
              FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE,
              FOREIGN KEY(episode_id) REFERENCES episodes(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec(
            "CREATE TABLE estadisticas_mensuales (
              id INTEGER PRIMARY KEY,
              podcast_id INTEGER NOT NULL,
              episode_id INTEGER NOT NULL,
              episode_title TEXT NOT NULL,
              anio INTEGER NOT NULL,
              mes INTEGER NOT NULL,
              descargas INTEGER NOT NULL DEFAULT 0,
              FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE,
              UNIQUE(podcast_id, episode_id, anio, mes)
            )"
        );
        $pdo->exec(
            "CREATE TABLE estadisticas_anuales (
              id INTEGER PRIMARY KEY,
              podcast_id INTEGER NOT NULL,
              episode_id INTEGER NOT NULL,
              episode_title TEXT NOT NULL,
              anio INTEGER NOT NULL,
              descargas INTEGER NOT NULL DEFAULT 0,
              FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE,
              UNIQUE(podcast_id, episode_id, anio)
            )"
        );

        $stmt = $pdo->prepare('INSERT INTO estadisticas (id, podcast_id, episode_id, episode_guid, episode_title, ip_address, user_agent, referer, action_type, download_date) SELECT id, :podcast_id, episode_id, episode_guid, episode_title, ip_address, user_agent, referer, action_type, download_date FROM estadisticas_v20');
        $stmt->execute([':podcast_id' => $podcastId]);
        $stmt = $pdo->prepare('INSERT INTO estadisticas_mensuales (id, podcast_id, episode_id, episode_title, anio, mes, descargas) SELECT id, :podcast_id, episode_id, episode_title, anio, mes, descargas FROM estadisticas_mensuales_v20');
        $stmt->execute([':podcast_id' => $podcastId]);
        $stmt = $pdo->prepare('INSERT INTO estadisticas_anuales (id, podcast_id, episode_id, episode_title, anio, descargas) SELECT id, :podcast_id, episode_id, episode_title, anio, descargas FROM estadisticas_anuales_v20');
        $stmt->execute([':podcast_id' => $podcastId]);

        $pdo->exec('DROP TABLE estadisticas_v20');
        $pdo->exec('DROP TABLE estadisticas_mensuales_v20');
        $pdo->exec('DROP TABLE estadisticas_anuales_v20');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_estadisticas_date ON estadisticas(podcast_id, download_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_estadisticas_episode ON estadisticas(podcast_id, episode_id)');
    $pdo->exec(
        "CREATE TRIGGER trg_mensual_after_insert AFTER INSERT ON estadisticas FOR EACH ROW BEGIN
          INSERT INTO estadisticas_mensuales (podcast_id, episode_id, episode_title, anio, mes, descargas)
          VALUES (NEW.podcast_id, NEW.episode_id, NEW.episode_title, CAST(STRFTIME('%Y', NEW.download_date) AS INTEGER), CAST(STRFTIME('%m', NEW.download_date) AS INTEGER), 1)
          ON CONFLICT(podcast_id, episode_id, anio, mes) DO UPDATE SET descargas = descargas + 1;
        END"
    );
    $pdo->exec(
        "CREATE TRIGGER trg_anual_after_insert AFTER INSERT ON estadisticas FOR EACH ROW BEGIN
          INSERT INTO estadisticas_anuales (podcast_id, episode_id, episode_title, anio, descargas)
          VALUES (NEW.podcast_id, NEW.episode_id, NEW.episode_title, CAST(STRFTIME('%Y', NEW.download_date) AS INTEGER), 1)
          ON CONFLICT(podcast_id, episode_id, anio) DO UPDATE SET descargas = descargas + 1;
        END"
    );
}
