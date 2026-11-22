-- Reporting (22.11.2026): acquisition / production costs (Anschaffungs- und Herstellungskosten) for yield and AfA
CREATE TABLE IF NOT EXISTS investment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  building_id INT NULL,                 -- NULL = whole property
  unit_ids VARCHAR(255) NULL,           -- comma separated unit ids; set = allocate only to these units
  kind ENUM('land','purchase','renovation','extension','other') NOT NULL DEFAULT 'purchase',
  title VARCHAR(200) NOT NULL,
  acquired_date DATE NOT NULL,          -- start of depreciation (month of acquisition / completion)
  amount DECIMAL(14,2) NOT NULL,
  afa_rate DECIMAL(5,2) NOT NULL DEFAULT 0,   -- % per year, linear; 0 = no depreciation (land)
  source VARCHAR(200),                  -- e.g. balance sheet account
  note TEXT,
  KEY (property_id),
  FOREIGN KEY (property_id) REFERENCES property(id),
  FOREIGN KEY (building_id) REFERENCES building(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
