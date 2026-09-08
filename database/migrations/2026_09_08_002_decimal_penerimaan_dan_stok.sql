-- Migration: Support decimal pada detail_penerimaan, stok_barang, dan pengambilan_barang_detail
-- Date: 2026-09-08
-- Context: Menyelaraskan presisi desimal dengan detail_pengiriman.qty DECIMAL(12,3)
--          agar kuantitas penerimaan, stok gudang, dan pengambilan barang
--          dapat menyimpan nilai pecahan (misal 1.500 dus, 0.250 kg) secara konsisten.

-- db_mbg: kolom qty_diterima pada detail_penerimaan
ALTER TABLE detail_penerimaan
    MODIFY COLUMN qty_diterima DECIMAL(12,3) DEFAULT NULL;

-- db_mbg: kolom stok pada stok_barang
ALTER TABLE stok_barang
    MODIFY COLUMN qty_grosir DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    MODIFY COLUMN qty_eceran DECIMAL(12,3) NOT NULL DEFAULT 0.000;

-- db_mbg: kolom qty pada pengambilan_barang_detail
ALTER TABLE pengambilan_barang_detail
    MODIFY COLUMN qty DECIMAL(12,3) NOT NULL;
