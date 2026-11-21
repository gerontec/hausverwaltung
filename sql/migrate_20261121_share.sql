-- password-free read-only share links for facility records (lib/share.php, pages/share.php)
CREATE TABLE IF NOT EXISTS share_link (
  id INT AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  entity VARCHAR(30) NOT NULL,
  entity_id INT NOT NULL,
  note VARCHAR(255) NULL,                 -- recipient
  created_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  last_used DATETIME NULL,
  uses INT NOT NULL DEFAULT 0,
  UNIQUE KEY (token_hash),
  KEY (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
