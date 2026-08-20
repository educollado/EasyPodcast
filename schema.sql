-- podcast: metadatos del canal en una sola fila.
CREATE TABLE IF NOT EXISTS podcast (
  id INTEGER PRIMARY KEY,
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  link TEXT NOT NULL,
  language TEXT NOT NULL DEFAULT 'es-ES',
  author TEXT,
  owner_name TEXT,
  owner_email TEXT,
  category TEXT,
  explicit INTEGER NOT NULL DEFAULT 0,
  image_url TEXT,
  hero_image_url TEXT,
  copyright TEXT,
  itunes_type TEXT DEFAULT 'episodic',
  rss_item_limit INTEGER NOT NULL DEFAULT 0,
  home_items_per_page INTEGER NOT NULL DEFAULT 20,
  write_audio_metadata INTEGER NOT NULL DEFAULT 0,
  cache_enabled INTEGER NOT NULL DEFAULT 0,
  app_language TEXT NOT NULL DEFAULT 'es_ES',
  admin_theme TEXT NOT NULL DEFAULT 'easypodcast',
  public_theme_mode_auto INTEGER NOT NULL DEFAULT 0,
  last_update_check_date TEXT,
  latest_version_checked TEXT,
  slug TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_podcast_slug ON podcast(slug) WHERE slug IS NOT NULL AND slug != '';

CREATE TABLE IF NOT EXISTS app_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  multipodcast_enabled INTEGER NOT NULL DEFAULT 0,
  homepage_podcast_id INTEGER,
  summary_hero_image_url TEXT,
  summary_title TEXT,
  summary_subtitle TEXT,
  summary_theme TEXT NOT NULL DEFAULT 'easypodcast',
  primary_podcast_id INTEGER,
  FOREIGN KEY(homepage_podcast_id) REFERENCES podcast(id) ON DELETE SET NULL,
  FOREIGN KEY(primary_podcast_id) REFERENCES podcast(id) ON DELETE SET NULL
);
INSERT OR IGNORE INTO app_settings (id, multipodcast_enabled, homepage_podcast_id) VALUES (1, 0, NULL);

-- episodes: una fila por episodio.
CREATE TABLE IF NOT EXISTS episodes (
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
);

-- Acelera consultas públicas/feed por estado de publicación y fecha.
CREATE INDEX IF NOT EXISTS idx_episodes_status_pubdate
ON episodes(podcast_id, status, pub_date);

-- Permite resolución O(log n) de URLs por link guardado (/YYYY/MM/slug).
CREATE INDEX IF NOT EXISTS idx_episodes_link ON episodes(podcast_id, link);

-- social: enlaces a redes sociales del autor (fila única).
CREATE TABLE IF NOT EXISTS social (
  id INTEGER PRIMARY KEY,
  podcast_id INTEGER NOT NULL,
  blog      TEXT NOT NULL DEFAULT '',
  linkedin  TEXT NOT NULL DEFAULT '',
  mastodon  TEXT NOT NULL DEFAULT '',
  x         TEXT NOT NULL DEFAULT '',
  pixelfed  TEXT NOT NULL DEFAULT '',
  instagram TEXT NOT NULL DEFAULT '',
  youtube   TEXT NOT NULL DEFAULT '',
  github    TEXT NOT NULL DEFAULT '',
  bluesky   TEXT NOT NULL DEFAULT '',
  FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_social_podcast ON social(podcast_id);

-- pages: páginas estáticas con jerarquía de hasta 2 niveles (padre → hijo).
CREATE TABLE IF NOT EXISTS pages (
  id INTEGER PRIMARY KEY,
  podcast_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  slug TEXT NOT NULL,           -- segmento propio de la URL
  full_path TEXT NOT NULL, -- ruta completa: 'sobre' o 'sobre/equipo'
  content TEXT NOT NULL DEFAULT '',
  parent_id INTEGER,            -- NULL = página de primer nivel
  sort_order INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'draft',
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE,
  FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE RESTRICT,
  UNIQUE(podcast_id, full_path)
);

CREATE INDEX IF NOT EXISTS idx_pages_status ON pages(podcast_id, status, parent_id, sort_order);

-- api_tokens: tokens de autenticación para la API REST.
CREATE TABLE IF NOT EXISTS api_tokens (
  id INTEGER PRIMARY KEY,
  podcast_id INTEGER NOT NULL,
  token TEXT NOT NULL DEFAULT '',
  token_hash TEXT NOT NULL DEFAULT '',
  token_suffix TEXT NOT NULL DEFAULT '',
  scope TEXT NOT NULL DEFAULT 'content',
  name TEXT NOT NULL DEFAULT '',
  user_id INTEGER NOT NULL,
  expires_at TEXT,
  last_used_at TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash) WHERE token_hash != '';
CREATE INDEX IF NOT EXISTS idx_api_tokens_podcast ON api_tokens(podcast_id);

-- management: credenciales del panel de administración.
CREATE TABLE IF NOT EXISTS management (
  id INTEGER PRIMARY KEY,
  username TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  totp_secret TEXT,
  totp_enabled INTEGER NOT NULL DEFAULT 0,
  totp_recovery_codes TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

-- Estadísticas: datos brutos de descargas (solo 7 días)
CREATE TABLE IF NOT EXISTS estadisticas (
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
);
CREATE INDEX IF NOT EXISTS idx_estadisticas_date ON estadisticas(podcast_id, download_date);
CREATE INDEX IF NOT EXISTS idx_estadisticas_episode ON estadisticas(podcast_id, episode_id);

-- Estadísticas: resumen mensual (histórico)
CREATE TABLE IF NOT EXISTS estadisticas_mensuales (
  id INTEGER PRIMARY KEY,
  podcast_id INTEGER NOT NULL,
  episode_id INTEGER NOT NULL,
  episode_title TEXT NOT NULL,
  anio INTEGER NOT NULL,
  mes INTEGER NOT NULL,
  descargas INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE,
  UNIQUE(podcast_id, episode_id, anio, mes)
);

-- Estadísticas: resumen anual (histórico)
CREATE TABLE IF NOT EXISTS estadisticas_anuales (
  id INTEGER PRIMARY KEY,
  podcast_id INTEGER NOT NULL,
  episode_id INTEGER NOT NULL,
  episode_title TEXT NOT NULL,
  anio INTEGER NOT NULL,
  descargas INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(podcast_id) REFERENCES podcast(id) ON DELETE CASCADE,
  UNIQUE(podcast_id, episode_id, anio)
);

-- Triggers para actualizar resúmenes automáticamente
CREATE TRIGGER IF NOT EXISTS trg_mensual_after_insert 
AFTER INSERT ON estadisticas
FOR EACH ROW
BEGIN
  INSERT INTO estadisticas_mensuales (podcast_id, episode_id, episode_title, anio, mes, descargas)
  VALUES (
    NEW.podcast_id, NEW.episode_id, NEW.episode_title,
    CAST(STRFTIME('%Y', NEW.download_date) AS INTEGER),
    CAST(STRFTIME('%m', NEW.download_date) AS INTEGER), 1
  )
  ON CONFLICT(podcast_id, episode_id, anio, mes) DO UPDATE SET descargas = descargas + 1;
END;

CREATE TRIGGER IF NOT EXISTS trg_anual_after_insert 
AFTER INSERT ON estadisticas
FOR EACH ROW
BEGIN
  INSERT INTO estadisticas_anuales (podcast_id, episode_id, episode_title, anio, descargas)
  VALUES (
    NEW.podcast_id, NEW.episode_id, NEW.episode_title,
    CAST(STRFTIME('%Y', NEW.download_date) AS INTEGER), 1
  )
  ON CONFLICT(podcast_id, episode_id, anio) DO UPDATE SET descargas = descargas + 1;
END;

PRAGMA user_version = 24;
