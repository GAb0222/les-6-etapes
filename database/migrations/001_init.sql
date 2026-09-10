CREATE TABLE IF NOT EXISTS establishments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  establishment_id BIGINT UNSIGNED NOT NULL,
  username VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','teacher') NOT NULL DEFAULT 'teacher',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY users_establishment_username_unique (establishment_id, username),
  CONSTRAINT users_establishment_fk FOREIGN KEY (establishment_id) REFERENCES establishments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_themes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  establishment_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  logo_path VARCHAR(255) NULL,
  palette_json JSON NULL,
  theme_json JSON NOT NULL,
  settings_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT brand_themes_establishment_fk FOREIGN KEY (establishment_id) REFERENCES establishments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS games (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  establishment_id BIGINT UNSIGNED NOT NULL,
  brand_theme_id BIGINT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  settings_json JSON NOT NULL,
  ui_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY games_establishment_slug_unique (establishment_id, slug),
  CONSTRAINT games_establishment_fk FOREIGN KEY (establishment_id) REFERENCES establishments(id) ON DELETE CASCADE,
  CONSTRAINT games_brand_theme_fk FOREIGN KEY (brand_theme_id) REFERENCES brand_themes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL DEFAULT 1,
  label VARCHAR(190) NOT NULL DEFAULT 'Version 1',
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY game_versions_number_unique (game_id, version_number),
  CONSTRAINT game_versions_game_fk FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_steps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_version_id BIGINT UNSIGNED NOT NULL,
  step_key INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  short_title VARCHAR(190) NOT NULL,
  color CHAR(7) NOT NULL,
  sort_order INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY game_steps_version_key_unique (game_version_id, step_key),
  CONSTRAINT game_steps_version_fk FOREIGN KEY (game_version_id) REFERENCES game_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_version_id BIGINT UNSIGNED NOT NULL,
  step_id BIGINT UNSIGNED NOT NULL,
  card_key INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  card_type ENUM('simple','trap','bonus','case') NOT NULL DEFAULT 'simple',
  difficulty ENUM('easy','normal','expert') NOT NULL DEFAULT 'normal',
  competency VARCHAR(80) NOT NULL DEFAULT 'general',
  success_explanation TEXT NULL,
  error_explanation TEXT NULL,
  case_prompt TEXT NULL,
  sort_order INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY game_cards_version_key_unique (game_version_id, card_key),
  CONSTRAINT game_cards_version_fk FOREIGN KEY (game_version_id) REFERENCES game_versions(id) ON DELETE CASCADE,
  CONSTRAINT game_cards_step_fk FOREIGN KEY (step_id) REFERENCES game_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  code CHAR(6) NOT NULL UNIQUE,
  teacher_pin CHAR(4) NOT NULL,
  title VARCHAR(190) NOT NULL,
  status ENUM('waiting','running','ended') NOT NULL DEFAULT 'waiting',
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT game_sessions_game_fk FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_participants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  public_id CHAR(16) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  anonymized_at DATETIME NULL,
  UNIQUE KEY session_participants_public_unique (session_id, public_id),
  CONSTRAINT session_participants_session_fk FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_progress (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  phase ENUM('waiting','order','cards','case','completed') NOT NULL DEFAULT 'waiting',
  payload_json JSON NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT session_progress_participant_fk FOREIGN KEY (participant_id) REFERENCES session_participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  score INT UNSIGNED NOT NULL DEFAULT 0,
  seconds INT UNSIGNED NOT NULL DEFAULT 0,
  errors INT UNSIGNED NOT NULL DEFAULT 0,
  cards_played INT UNSIGNED NOT NULL DEFAULT 0,
  badges_json JSON NULL,
  competencies_json JSON NULL,
  mistakes_json JSON NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY session_results_participant_unique (participant_id),
  CONSTRAINT session_results_participant_fk FOREIGN KEY (participant_id) REFERENCES session_participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_exports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  export_type ENUM('print','cards','board','correction','session_report','student_report','json','csv') NOT NULL,
  file_path VARCHAR(255) NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT game_exports_game_fk FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  establishment_id BIGINT UNSIGNED NULL,
  game_id BIGINT UNSIGNED NULL,
  session_id BIGINT UNSIGNED NULL,
  actor VARCHAR(120) NOT NULL DEFAULT 'system',
  event_type VARCHAR(120) NOT NULL,
  details_json JSON NULL,
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY activity_events_type_created_idx (event_type, created_at),
  CONSTRAINT activity_events_establishment_fk FOREIGN KEY (establishment_id) REFERENCES establishments(id) ON DELETE SET NULL,
  CONSTRAINT activity_events_game_fk FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL,
  CONSTRAINT activity_events_session_fk FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
