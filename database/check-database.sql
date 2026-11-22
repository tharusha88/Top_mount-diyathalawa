-- Top Mountain Resort - check that the database has everything the website needs.
-- Only reads; changes nothing. phpMyAdmin -> select your database -> SQL tab -> paste -> Go.
-- "schema_version" should be 9, and every table and column should say "yes".
SELECT
  (SELECT value FROM settings WHERE name = 'schema_version') AS schema_version,
  IF(COUNT(CASE WHEN TABLE_NAME = 'coupons' THEN 1 END) > 0, 'yes', 'MISSING') AS coupons,
  IF(COUNT(CASE WHEN TABLE_NAME = 'employees' THEN 1 END) > 0, 'yes', 'MISSING') AS employees,
  IF(COUNT(CASE WHEN TABLE_NAME = 'staff_payments' THEN 1 END) > 0, 'yes', 'MISSING') AS staff_payments,
  IF(COUNT(CASE WHEN TABLE_NAME = 'service_shares' THEN 1 END) > 0, 'yes', 'MISSING') AS service_shares,
  IF(COUNT(CASE WHEN TABLE_NAME = 'expenses' THEN 1 END) > 0, 'yes', 'MISSING') AS expenses,
  IF(COUNT(CASE WHEN TABLE_NAME = 'bank_moves' THEN 1 END) > 0, 'yes', 'MISSING') AS bank_moves,
  IF(COUNT(CASE WHEN TABLE_NAME = 'stock_items' THEN 1 END) > 0, 'yes', 'MISSING') AS stock_items,
  IF(COUNT(CASE WHEN TABLE_NAME = 'stock_moves' THEN 1 END) > 0, 'yes', 'MISSING') AS stock_moves,
  IF(COUNT(CASE WHEN TABLE_NAME = 'closures' THEN 1 END) > 0, 'yes', 'MISSING') AS closures,
  IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'event_others') > 0, 'yes', 'MISSING') AS outdoor_parties
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
