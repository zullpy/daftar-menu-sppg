-- Migration: Support decimal qty pada detail_pengiriman
-- Date: 2026-09-08
-- Context: Sebelumnya qty disimpan sebagai INT sehingga nilai pecahan seperti
--          0.5 atau 1.5 dibulatkan. Diubah ke DECIMAL agar pengiriman bisa
--          mencatat jumlah satuan pecahan (misal 0.5 dus, 1.5 kg, dll).

-- db_mbg: kolom qty di detail_pengiriman (INT -> DECIMAL)
ALTER TABLE detail_pengiriman
    MODIFY COLUMN qty DECIMAL(12,3) NOT NULL;
