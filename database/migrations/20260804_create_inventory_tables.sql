USE Cliniq_db;

CREATE TABLE IF NOT EXISTS inventory_items (
  item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_code VARCHAR(40) NOT NULL,
  item_name VARCHAR(160) NOT NULL,
  item_type ENUM('Medicine', 'Equipment') NOT NULL,
  description TEXT NULL,
  unit VARCHAR(40) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  reorder_level INT UNSIGNED NOT NULL DEFAULT 0,
  expiration_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_inventory_items_code UNIQUE (item_code),
  INDEX idx_inventory_items_type_active (item_type, is_active),
  INDEX idx_inventory_items_stock (quantity, reorder_level),
  INDEX idx_inventory_items_expiration (expiration_date)
);

CREATE TABLE IF NOT EXISTS medicine_dispensings (
  dispensing_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  remarks TEXT NULL,
  dispensed_by_person_id BIGINT UNSIGNED NULL,
  dispensed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_medicine_dispensings_quantity CHECK (quantity > 0),
  CONSTRAINT fk_medicine_dispensings_entry
    FOREIGN KEY (entry_id) REFERENCES visit_entries(entry_id) ON DELETE CASCADE,
  CONSTRAINT fk_medicine_dispensings_item
    FOREIGN KEY (item_id) REFERENCES inventory_items(item_id),
  CONSTRAINT fk_medicine_dispensings_staff
    FOREIGN KEY (dispensed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_medicine_dispensings_entry (entry_id),
  INDEX idx_medicine_dispensings_item_date (item_id, dispensed_at),
  INDEX idx_medicine_dispensings_staff (dispensed_by_person_id)
);

CREATE TABLE IF NOT EXISTS equipment_loans (
  loan_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT UNSIGNED NOT NULL,
  borrower_person_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL,
  borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NULL,
  returned_at DATETIME NULL,
  status ENUM('Borrowed', 'Returned', 'Overdue', 'Cancelled')
    NOT NULL DEFAULT 'Borrowed',
  released_by_person_id BIGINT UNSIGNED NULL,
  received_by_person_id BIGINT UNSIGNED NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_equipment_loans_quantity CHECK (quantity > 0),
  CONSTRAINT fk_equipment_loans_item
    FOREIGN KEY (item_id) REFERENCES inventory_items(item_id),
  CONSTRAINT fk_equipment_loans_borrower
    FOREIGN KEY (borrower_person_id) REFERENCES patients(person_id),
  CONSTRAINT fk_equipment_loans_visit
    FOREIGN KEY (visit_id) REFERENCES visits(visit_id) ON DELETE SET NULL,
  CONSTRAINT fk_equipment_loans_released_by
    FOREIGN KEY (released_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_equipment_loans_received_by
    FOREIGN KEY (received_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_equipment_loans_item_status (item_id, status),
  INDEX idx_equipment_loans_borrower_status (borrower_person_id, status),
  INDEX idx_equipment_loans_visit (visit_id),
  INDEX idx_equipment_loans_due (status, due_at),
  INDEX idx_equipment_loans_released_by (released_by_person_id),
  INDEX idx_equipment_loans_received_by (received_by_person_id)
);

CREATE TABLE IF NOT EXISTS inventory_transactions (
  transaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT UNSIGNED NOT NULL,
  transaction_type ENUM(
    'Stock In', 'Dispensed', 'Loaned', 'Returned',
    'Adjustment', 'Expired', 'Damaged'
  ) NOT NULL,
  quantity_change INT NOT NULL,
  balance_after INT UNSIGNED NOT NULL,
  dispensing_id BIGINT UNSIGNED NULL,
  loan_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  performed_by_person_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_inventory_transactions_change CHECK (quantity_change <> 0),
  CONSTRAINT fk_inventory_transactions_item
    FOREIGN KEY (item_id) REFERENCES inventory_items(item_id),
  CONSTRAINT fk_inventory_transactions_dispensing
    FOREIGN KEY (dispensing_id) REFERENCES medicine_dispensings(dispensing_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_inventory_transactions_loan
    FOREIGN KEY (loan_id) REFERENCES equipment_loans(loan_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_inventory_transactions_staff
    FOREIGN KEY (performed_by_person_id) REFERENCES clinic_staff(person_id)
    ON DELETE SET NULL,
  INDEX idx_inventory_transactions_item_created (item_id, created_at),
  INDEX idx_inventory_transactions_type_created (transaction_type, created_at),
  INDEX idx_inventory_transactions_dispensing (dispensing_id),
  INDEX idx_inventory_transactions_loan (loan_id),
  INDEX idx_inventory_transactions_staff (performed_by_person_id)
);
