-- JSON API tokens (public/api.php, tools/api_token.php)
CREATE TABLE IF NOT EXISTS api_token (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used DATETIME NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
