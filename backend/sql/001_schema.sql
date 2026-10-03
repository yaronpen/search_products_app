SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id            VARCHAR(16)   NOT NULL PRIMARY KEY,
    name          VARCHAR(255)  NOT NULL,
    brand         VARCHAR(100)  NULL,
    category      VARCHAR(100)  NOT NULL,
    subcategory   VARCHAR(100)  NOT NULL,
    description   TEXT          NOT NULL,
    attributes    TEXT          NOT NULL,
    image_url     VARCHAR(500)  NOT NULL,
    price         DECIMAL(10,2) NOT NULL,
    -- float32 little-endian vector from the embeddings model; NULL until embedded
    embedding     MEDIUMBLOB    NULL,
    -- hash of the text that was embedded, so re-imports only re-embed changed rows
    embedding_src CHAR(40)      NULL,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category, subcategory)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Query embeddings are cached so repeated searches skip the API call
CREATE TABLE IF NOT EXISTS query_embeddings (
    query_hash CHAR(40)    NOT NULL PRIMARY KEY,
    query      VARCHAR(255) NOT NULL,
    embedding  BLOB        NOT NULL,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta (
    k VARCHAR(64)  NOT NULL PRIMARY KEY,
    v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
