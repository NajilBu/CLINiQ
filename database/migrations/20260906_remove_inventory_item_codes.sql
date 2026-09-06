ALTER TABLE inventory_items
  DROP INDEX uq_inventory_items_code,
  DROP COLUMN item_code;
