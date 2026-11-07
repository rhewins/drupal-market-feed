-- Manual MariaDB fallback for the Drupal market_feed_prices schema.
-- Normal module installation should use market_feed_schema() instead.

CREATE TABLE IF NOT EXISTS market_feed_prices (
  symbol VARCHAR(16) NOT NULL,
  exchange VARCHAR(16) NOT NULL DEFAULT 'other',
  price DECIMAL(20, 6) NOT NULL,
  change_amount DECIMAL(20, 6) NOT NULL,
  volume BIGINT UNSIGNED NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (symbol),
  KEY idx_market_feed_prices_exchange (exchange)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

