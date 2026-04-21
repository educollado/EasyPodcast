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
