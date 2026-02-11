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
  copyright TEXT,
  itunes_type TEXT DEFAULT 'episodic'
);

-- episodes: una fila por episodio.
CREATE TABLE IF NOT EXISTS episodes (
  id INTEGER PRIMARY KEY,
  guid TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  link TEXT,
  pub_date TEXT NOT NULL,
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
);

-- Acelera consultas públicas/feed por estado de publicación y fecha.
CREATE INDEX IF NOT EXISTS idx_episodes_status_pubdate
ON episodes(status, pub_date);

-- management: credenciales del panel de administración.
CREATE TABLE IF NOT EXISTS management (
  id INTEGER PRIMARY KEY,
  username TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);
