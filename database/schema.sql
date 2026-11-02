-- Schéma de la base du site Corder — MySQL 5.7+ / MariaDB 10.4+
-- Import : phpMyAdmin (onglet Importer) ou `php database/seed.php` qui l'exécute avant les données.
-- Toutes les dates (DATETIME) sont en heure belge (Europe/Brussels).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Actualités et alertes
-- Une alerte est un article de la catégorie « alertes » avec un niveau (alert_level).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS categories (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug      VARCHAR(80)  NOT NULL,
  label     VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(160) NOT NULL,
  title         VARCHAR(255) NOT NULL,
  excerpt       TEXT         NOT NULL,
  body          MEDIUMTEXT   NOT NULL,
  category_id   INT UNSIGNED NOT NULL,
  pole          ENUM('clinique','crp','phytolicence','recherche','formation') NULL,
  image_url     VARCHAR(500) NULL,
  image_alt     VARCHAR(255) NOT NULL DEFAULT '',
  featured      TINYINT(1)   NOT NULL DEFAULT 0,
  alert_level   ENUM('urgent','info') NULL,
  -- Brouillon tant que published_at est NULL ; publication programmée si la date est future
  published_at  DATETIME     NULL,
  updated_at    DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_articles_slug (slug),
  KEY idx_articles_published (published_at),
  CONSTRAINT fk_articles_category FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Formations et inscriptions
-- Les places restantes sont calculées : capacity - nombre d'inscriptions.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS trainings (
  id                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  slug                   VARCHAR(160)  NOT NULL,
  title                  VARCHAR(255)  NOT NULL,
  summary                TEXT          NOT NULL,
  audience               TEXT          NOT NULL,
  objectives             TEXT          NOT NULL COMMENT 'Liste JSON de textes',
  program                TEXT          NOT NULL COMMENT 'Liste JSON de { time, title }',
  starts_at              DATETIME      NOT NULL,
  ends_at                DATETIME      NOT NULL,
  location               VARCHAR(120)  NOT NULL COMMENT 'Ville, ou « En ligne »',
  licence_type           ENUM('NP','P1','P2','P3') NOT NULL,
  price                  DECIMAL(8,2)  NOT NULL DEFAULT 0,
  capacity               INT UNSIGNED  NOT NULL,
  registration_deadline  DATETIME      NOT NULL,
  venue_name             VARCHAR(255)  NULL COMMENT 'NULL = formation en ligne',
  venue_address          VARCHAR(500)  NULL,
  online_url             VARCHAR(500)  NULL COMMENT 'Lien de connexion envoyé dans la confirmation',
  is_published           TINYINT(1)    NOT NULL DEFAULT 1,
  created_at             DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trainings_slug (slug),
  KEY idx_trainings_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  training_id     INT UNSIGNED NOT NULL,
  first_name      VARCHAR(100) NOT NULL,
  last_name       VARCHAR(100) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  phone           VARCHAR(30)  NOT NULL,
  licence_number  VARCHAR(50)  NULL,
  organization    VARCHAR(190) NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  -- Une personne ne s'inscrit qu'une fois à la même formation
  UNIQUE KEY uq_registrations_training_email (training_id, email),
  CONSTRAINT fk_registrations_training FOREIGN KEY (training_id) REFERENCES trainings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Contenus éditables (ordre d'affichage choisi dans l'administration : position)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS faq_items (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question  VARCHAR(255) NOT NULL,
  answer    TEXT         NOT NULL,
  position  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS key_figures (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  value     VARCHAR(20)  NOT NULL,
  label     VARCHAR(120) NOT NULL,
  position  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_members (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name  VARCHAR(100) NOT NULL,
  last_name   VARCHAR(100) NOT NULL,
  role        VARCHAR(190) NOT NULL,
  member_group ENUM('board','staff') NOT NULL DEFAULT 'staff',
  email       VARCHAR(190) NULL,
  photo_url   VARCHAR(500) NULL,
  position    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Formulaires
-- ---------------------------------------------------------------------------

-- Copie de chaque message (si l'e-mail se perd, rien n'est perdu)
CREATE TABLE IF NOT EXISTS contact_messages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(190) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  phone       VARCHAR(30)  NULL,
  profile     VARCHAR(100) NOT NULL,
  pole        ENUM('clinique','crp','phytolicence','recherche','formation') NULL,
  message     TEXT         NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Double confirmation : l'abonnement n'est actif qu'une fois confirmed_at rempli
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email          VARCHAR(190) NOT NULL,
  token          CHAR(64)     NOT NULL,
  confirmed_at   DATETIME     NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_newsletter_email (email),
  UNIQUE KEY uq_newsletter_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  action      VARCHAR(40)  NOT NULL,
  ip_hash     CHAR(64)     NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rate_limits_lookup (action, ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
