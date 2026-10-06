-- Lara — esquema MySQL 8 (idempotente)

CREATE TABLE IF NOT EXISTS messages (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role              ENUM('heard', 'assistant') NOT NULL,
    text              TEXT NULL,
    -- Catalogação: addressed = falou com a Lara; relevant = contexto útil; noise = ruído ao redor;
    -- pending = ainda não decidido; failed = transcrição falhou (áudio preservado)
    relevance         ENUM('pending', 'addressed', 'relevant', 'noise', 'failed') NOT NULL DEFAULT 'pending',
    category          VARCHAR(32) NULL,
    importance        DECIMAL(4, 2) NULL,
    respond_p         DECIMAL(5, 4) NULL,
    decided_by        VARCHAR(96) NULL,
    decision          JSON NULL,
    -- Flag interna de origem da transcrição. NUNCA é enviada às IAs.
    transcript_source ENUM('local', 'online') NULL,
    transcript_engine VARCHAR(96) NULL,
    audio_path        VARCHAR(255) NULL,
    duration_ms       INT UNSIGNED NULL,
    level_db          DECIMAL(5, 1) NULL,
    reply_to          BIGINT UNSIGNED NULL,
    model             VARCHAR(96) NULL,
    latency_ms        INT UNSIGNED NULL,
    spoken_at         DATETIME(3) NOT NULL,
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_relevance (relevance, id),
    KEY idx_spoken (spoken_at),
    KEY idx_reply (reply_to),
    FULLTEXT KEY ft_text (text)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    name       VARCHAR(64) NOT NULL,
    value      JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
