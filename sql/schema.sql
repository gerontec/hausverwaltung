-- Hausverwaltung / facility management - schema (MariaDB 11.8)
-- Table and column names in English, UI in German.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS app_user (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  pw_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120),
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  failed_logins TINYINT NOT NULL DEFAULT 0,
  locked TINYINT(1) NOT NULL DEFAULT 0,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS property (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  street VARCHAR(120), zip VARCHAR(10), city VARCHAR(80),
  parcel VARCHAR(80),
  land_area DECIMAL(10,2),
  note TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS person (
  id INT AUTO_INCREMENT PRIMARY KEY,
  is_tenant TINYINT(1) NOT NULL DEFAULT 0,
  is_owner TINYINT(1) NOT NULL DEFAULT 0,
  is_vendor TINYINT(1) NOT NULL DEFAULT 0,
  company VARCHAR(120), salutation VARCHAR(30),
  first_name VARCHAR(80), last_name VARCHAR(80),
  street VARCHAR(120), zip VARCHAR(10), city VARCHAR(80),
  email VARCHAR(120), phone VARCHAR(60), mobile VARCHAR(60),
  iban VARCHAR(34), bic VARCHAR(11),
  trade VARCHAR(80),             -- vendor: Gewerk
  note TEXT,
  KEY (iban)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS building (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  name VARCHAR(40) NOT NULL,        -- "Haus 6b"
  street VARCHAR(120),
  built_year SMALLINT,
  heating VARCHAR(120),
  note TEXT,
  FOREIGN KEY (property_id) REFERENCES property(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WEG units (Sondereigentum per Teilungserklaerung) with MEA
CREATE TABLE IF NOT EXISTS weg_unit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  no VARCHAR(10) NOT NULL,
  kind ENUM('apartment','whole_house','parking','garage','other') NOT NULL DEFAULT 'apartment',
  building_id INT NULL,             -- sub-community (Untergemeinschaft); NULL = property only
  mea DECIMAL(8,3) NOT NULL DEFAULT 0,
  mea_base DECIMAL(8,3) NOT NULL DEFAULT 1000,
  description VARCHAR(255),
  owner_id INT NULL,
  hausgeld_monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
  reserve_monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
  grundbuch VARCHAR(120),
  note TEXT,
  UNIQUE KEY (property_id, no),
  FOREIGN KEY (property_id) REFERENCES property(id),
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (owner_id) REFERENCES person(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rentable units: flats, parking spaces, garage, cellars
CREATE TABLE IF NOT EXISTS unit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  building_id INT NULL,
  property_id INT NOT NULL,
  weg_unit_id INT NULL,
  no VARCHAR(10) NOT NULL,
  kind ENUM('apartment','parking','garage','cellar','commercial','other') NOT NULL DEFAULT 'apartment',
  floor VARCHAR(20),
  position VARCHAR(40),
  living_area DECIMAL(8,2),          -- Wohnflaeche (WoFlV), key "area"
  net_area DECIMAL(8,2),             -- Nettogrundflaeche incl. Abseiten etc.
  rooms DECIMAL(3,1),
  mandatory_parking TINYINT(1) NOT NULL DEFAULT 0,  -- Pflichtstellplatz (Baugenehmigung)
  te_no VARCHAR(10),                 -- Nummer laut Teilungserklaerung
  ext_ref VARCHAR(80),               -- external number (metering service user, former manager account)
  note TEXT,
  UNIQUE KEY (property_id, no),
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (property_id) REFERENCES property(id),
  FOREIGN KEY (weg_unit_id) REFERENCES weg_unit(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lease (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  tenant_id INT NOT NULL,
  co_tenant VARCHAR(160),
  start_date DATE NULL,              -- NULL = unbekannt (vor Datenbestand)
  end_date DATE NULL,
  ext_account VARCHAR(20),           -- account number at the former manager
  persons TINYINT NOT NULL DEFAULT 1,
  deposit DECIMAL(10,2) NOT NULL DEFAULT 0,
  deposit_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  deposit_account VARCHAR(120),
  due_day TINYINT NOT NULL DEFAULT 3,
  parking_unit_id INT NULL,          -- Stellplatz im selben Vertrag
  note TEXT,
  FOREIGN KEY (unit_id) REFERENCES unit(id),
  FOREIGN KEY (tenant_id) REFERENCES person(id),
  FOREIGN KEY (parking_unit_id) REFERENCES unit(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- rent history (Mietentwicklung): the row valid at the 1st of a month applies
CREATE TABLE IF NOT EXISTS lease_rent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lease_id INT NOT NULL,
  valid_from DATE NOT NULL,
  base_rent DECIMAL(10,2) NOT NULL DEFAULT 0,
  operating_advance DECIMAL(10,2) NOT NULL DEFAULT 0,
  heating_advance DECIMAL(10,2) NOT NULL DEFAULT 0,
  parking_rent DECIMAL(10,2) NOT NULL DEFAULT 0,
  reason VARCHAR(160),
  FOREIGN KEY (lease_id) REFERENCES lease(id) ON DELETE CASCADE,
  UNIQUE KEY (lease_id, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bank_tx (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account VARCHAR(34),
  booking_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  counterparty VARCHAR(200),
  iban VARCHAR(34),
  purpose TEXT,
  hash CHAR(40) NOT NULL UNIQUE,
  status ENUM('open','booked','ignored') NOT NULL DEFAULT 'open',
  note VARCHAR(255),
  category VARCHAR(80),
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- personal ledger (Sollstellungen and payments) for leases and WEG units
CREATE TABLE IF NOT EXISTS posting (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lease_id INT NULL,
  weg_unit_id INT NULL,
  posting_date DATE NOT NULL,
  kind ENUM('charge','payment') NOT NULL,
  category ENUM('rent','operating_advance','heating_advance','parking','hausgeld','reserve','deposit','settlement','dunning_fee','other') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  period CHAR(7) NULL,                -- YYYY-MM for automatic charges
  text VARCHAR(255),
  bank_tx_id INT NULL,
  auto_key VARCHAR(40) NULL UNIQUE,   -- set for generated charges: L<lease>/W<weg>-YYYY-MM-category
  source VARCHAR(40),                  -- import source, e.g. 'legacy-2024'
  KEY (lease_id), KEY (weg_unit_id), KEY (period),
  FOREIGN KEY (lease_id) REFERENCES lease(id),
  FOREIGN KEY (weg_unit_id) REFERENCES weg_unit(id),
  FOREIGN KEY (bank_tx_id) REFERENCES bank_tx(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- cost types (BetrKV para 2) and allocation keys
CREATE TABLE IF NOT EXISTS cost_type (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  betrkv_no VARCHAR(10),
  allocatable TINYINT(1) NOT NULL DEFAULT 1,    -- umlagefaehig auf Mieter
  alloc_key ENUM('area','units','persons','consumption','mea','direct') NOT NULL DEFAULT 'area',
  consumption_share DECIMAL(5,2) NOT NULL DEFAULT 0,  -- % nach Verbrauch, Rest nach Flaeche (HeizkostenV)
  meter_kind VARCHAR(20) NULL,
  is_heating TINYINT(1) NOT NULL DEFAULT 0,
  weg_key ENUM('mea','units','area','direct','none') NOT NULL DEFAULT 'mea',
  sort SMALLINT NOT NULL DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  building_id INT NULL,               -- NULL = whole property
  unit_id INT NULL,                   -- direct allocation to one unit
  weg_unit_id INT NULL,               -- direct allocation to one WEG unit
  cost_type_id INT NOT NULL,
  vendor_id INT NULL,
  invoice_date DATE NOT NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,      -- gross
  labour_cost DECIMAL(12,2) NOT NULL DEFAULT 0,   -- para 35a EStG (haushaltsnahe Dienstleistungen)
  paid_date DATE NULL,
  invoice_no VARCHAR(60),
  from_reserve TINYINT(1) NOT NULL DEFAULT 0,
  text VARCHAR(255),
  bank_tx_id INT NULL,
  ticket_id INT NULL,
  source VARCHAR(40),
  KEY (period_from, period_to),
  FOREIGN KEY (property_id) REFERENCES property(id),
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (unit_id) REFERENCES unit(id),
  FOREIGN KEY (weg_unit_id) REFERENCES weg_unit(id),
  FOREIGN KEY (cost_type_id) REFERENCES cost_type(id),
  FOREIGN KEY (vendor_id) REFERENCES person(id),
  FOREIGN KEY (bank_tx_id) REFERENCES bank_tx(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WEG Wirtschaftsplan lines per year and scope (building NULL = whole property)
CREATE TABLE IF NOT EXISTS weg_budget (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  year SMALLINT NOT NULL,
  building_id INT NULL,
  cost_type_id INT NULL,             -- NULL = Erhaltungsruecklage
  amount DECIMAL(12,2) NOT NULL,
  note VARCHAR(255),
  FOREIGN KEY (property_id) REFERENCES property(id),
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (cost_type_id) REFERENCES cost_type(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meter (
  id INT AUTO_INCREMENT PRIMARY KEY,
  building_id INT NULL,
  unit_id INT NULL,                  -- NULL = main / common meter
  kind ENUM('water_cold','water_hot','heat','electricity','gas','oil','pv','other') NOT NULL,
  number VARCHAR(60),
  location VARCHAR(120),
  calibrated_until DATE NULL,        -- Eichfrist
  factor DECIMAL(10,4) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  note TEXT,
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (unit_id) REFERENCES unit(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meter_reading (
  id INT AUTO_INCREMENT PRIMARY KEY,
  meter_id INT NOT NULL,
  reading_date DATE NOT NULL,
  value DECIMAL(14,3) NOT NULL,
  reason ENUM('regular','move_in','move_out','exchange','estimate') NOT NULL DEFAULT 'regular',
  note VARCHAR(255),
  UNIQUE KEY (meter_id, reading_date),
  FOREIGN KEY (meter_id) REFERENCES meter(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- facility management
CREATE TABLE IF NOT EXISTS asset (
  id INT AUTO_INCREMENT PRIMARY KEY,
  building_id INT NULL,
  unit_id INT NULL,
  name VARCHAR(160) NOT NULL,
  category VARCHAR(60),
  manufacturer VARCHAR(80), model VARCHAR(80), serial_no VARCHAR(80),
  location VARCHAR(120),
  installed DATE NULL,
  warranty_until DATE NULL,
  note TEXT,
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (unit_id) REFERENCES unit(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maintenance_plan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  building_id INT NULL,
  asset_id INT NULL,
  title VARCHAR(160) NOT NULL,
  interval_months SMALLINT NOT NULL DEFAULT 12,
  last_done DATE NULL,
  next_due DATE NULL,
  vendor_id INT NULL,
  legal_basis VARCHAR(160),
  allocatable TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  note TEXT,
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (asset_id) REFERENCES asset(id),
  FOREIGN KEY (vendor_id) REFERENCES person(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maintenance_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  done_date DATE NOT NULL,
  vendor_id INT NULL,
  cost DECIMAL(12,2) NULL,
  result VARCHAR(255),
  expense_id INT NULL,
  FOREIGN KEY (plan_id) REFERENCES maintenance_plan(id) ON DELETE CASCADE,
  FOREIGN KEY (vendor_id) REFERENCES person(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket (
  id INT AUTO_INCREMENT PRIMARY KEY,
  building_id INT NULL,
  unit_id INT NULL,
  asset_id INT NULL,
  reported_by INT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT,
  priority ENUM('low','normal','high','emergency') NOT NULL DEFAULT 'normal',
  status ENUM('open','in_progress','waiting','done','rejected') NOT NULL DEFAULT 'open',
  vendor_id INT NULL,
  created_at DATE NOT NULL,
  due_date DATE NULL,
  closed_at DATE NULL,
  cost DECIMAL(12,2) NULL,
  bearer ENUM('community','owner','tenant','insurance','warranty') NULL,
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (unit_id) REFERENCES unit(id),
  FOREIGN KEY (asset_id) REFERENCES asset(id),
  FOREIGN KEY (reported_by) REFERENCES person(id),
  FOREIGN KEY (vendor_id) REFERENCES person(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS note_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(30) NOT NULL,
  entity_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  author VARCHAR(60),
  text TEXT NOT NULL,
  KEY (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(30) NOT NULL,
  entity_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100),
  size INT,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  title VARCHAR(255),
  KEY (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- recurring invoices that are expected (e.g. a yearly energy bill)
CREATE TABLE IF NOT EXISTS expected_invoice (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  building_id INT NULL,
  vendor_id INT NOT NULL,
  cost_type_id INT NOT NULL,
  title VARCHAR(160) NOT NULL,
  interval_months SMALLINT NOT NULL DEFAULT 12,
  next_expected DATE NOT NULL,
  typical_amount DECIMAL(12,2) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  note TEXT,
  FOREIGN KEY (property_id) REFERENCES property(id),
  FOREIGN KEY (building_id) REFERENCES building(id),
  FOREIGN KEY (vendor_id) REFERENCES person(id),
  FOREIGN KEY (cost_type_id) REFERENCES cost_type(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- bank matching rules: a pattern in counterparty/purpose -> lease (payment) or cost type (expense) or ignore
CREATE TABLE IF NOT EXISTS bank_rule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pattern VARCHAR(120) NOT NULL,        -- case-insensitive substring
  direction ENUM('in','out','any') NOT NULL DEFAULT 'any',
  action ENUM('payment','expense','ignore') NOT NULL,
  lease_id INT NULL,
  weg_unit_id INT NULL,
  cost_type_id INT NULL,
  vendor_id INT NULL,
  building_id INT NULL,
  priority SMALLINT NOT NULL DEFAULT 100,
  note VARCHAR(255),
  FOREIGN KEY (lease_id) REFERENCES lease(id),
  FOREIGN KEY (weg_unit_id) REFERENCES weg_unit(id),
  FOREIGN KEY (cost_type_id) REFERENCES cost_type(id),
  FOREIGN KEY (vendor_id) REFERENCES person(id),
  FOREIGN KEY (building_id) REFERENCES building(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_setting (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Posteingang: documents delivered by Mayan (cabinet of the property and below)
CREATE TABLE IF NOT EXISTS inbox_doc (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mayan_id INT NOT NULL UNIQUE,
  label VARCHAR(255),
  received_at DATETIME NULL,
  cabinet VARCHAR(160),
  mail_from VARCHAR(255), mail_subject VARCHAR(255),
  filename VARCHAR(255), stored_name VARCHAR(255), mime VARCHAR(100), size INT,
  text MEDIUMTEXT,
  status ENUM('new','assigned','ignored') NOT NULL DEFAULT 'new',
  assigned_entity VARCHAR(30) NULL, assigned_id INT NULL, document_id INT NULL,
  fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
