-- Before recording: restore the snapshot, then age today's open orders so the board tells the story (Table 7 waiting 41 minutes).
SET time_zone = '+08:00';
UPDATE tickets SET created_at = NOW() - INTERVAL 41 MINUTE WHERE status = 'open' AND table_label = '7' AND business_date = CURDATE();
UPDATE tickets SET created_at = NOW() - INTERVAL 12 MINUTE WHERE status = 'open' AND table_label = 'Patio 2' AND business_date = CURDATE();
UPDATE tickets SET created_at = NOW() - INTERVAL 6 MINUTE WHERE status = 'open' AND order_type = 'delivery' AND business_date = CURDATE();
