-- HeizkostenV / BK extensions (21.11.2026)
ALTER TABLE cost_type
  ADD COLUMN IF NOT EXISTS tax35a ENUM('none','service','craft') NOT NULL DEFAULT 'none' AFTER weg_key,
  ADD COLUMN IF NOT EXISTS allocatable_until DATE NULL AFTER tax35a,
  ADD COLUMN IF NOT EXISTS heat_plant TINYINT(1) NOT NULL DEFAULT 0 AFTER allocatable_until;  -- cost of the central heating plant (split by para 9 HeizkostenV)
ALTER TABLE lease ADD COLUMN IF NOT EXISTS no_alloc VARCHAR(120) NULL AFTER parking_unit_id;   -- cost type codes not agreed in the lease
ALTER TABLE meter ADD COLUMN IF NOT EXISTS purpose ENUM('','ww_energy','heat_total','fuel') NOT NULL DEFAULT '' AFTER kind;

-- settings per settlement period (heating costs)
CREATE TABLE IF NOT EXISTS hk_setting (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  mode ENUM('brunata','own') NOT NULL DEFAULT 'brunata',
  advances ENUM('ist','soll') NOT NULL DEFAULT 'ist',
  fuel_kind ENUM('gas_hs','gas_hi','oil','district','heatpump','other') NOT NULL DEFAULT 'gas_hs',
  fuel_kwh DECIMAL(12,1) NULL,            -- total energy used by the plant in the period (gas bill kWh)
  ww_method ENUM('meter','formula','area','none') NOT NULL DEFAULT 'meter',
  ww_temp DECIMAL(4,1) NOT NULL DEFAULT 60,
  co2_kg DECIMAL(12,1) NULL,              -- CO2 emissions of the fuel bill (kg)
  co2_cost DECIMAL(12,2) NULL,            -- CO2 costs contained in the fuel bill (EUR)
  note TEXT,
  UNIQUE KEY (period_from, period_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- per-user results of the metering service (Brunata), imported from "Übersicht aller Nutzerabrechnungen"
CREATE TABLE IF NOT EXISTS hk_result (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  unit_id INT NOT NULL,
  seg_from DATE NOT NULL,
  seg_to DATE NOT NULL,
  user_name VARCHAR(160),
  cost_code VARCHAR(10) NOT NULL,
  label VARCHAR(120),
  basis VARCHAR(120),
  amount DECIMAL(12,2) NOT NULL,
  source VARCHAR(80),
  KEY (period_from, period_to),
  FOREIGN KEY (unit_id) REFERENCES unit(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE cost_type SET tax35a='service' WHERE code IN ('HW','GR','GA');
UPDATE cost_type SET tax35a='craft' WHERE code IN ('SK','SO','IH','HZ','WW');
UPDATE cost_type SET allocatable_until='2024-06-30' WHERE code='TV';
UPDATE cost_type SET heat_plant=1 WHERE code IN ('HZ','HPV');
UPDATE meter SET purpose='ww_energy', factor=1000 WHERE unit_id IS NULL AND kind='heat' AND note LIKE '%Warmwasserbereitung%';
