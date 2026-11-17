<?php
/**
 * Örnek kalem sözlüğü — gerçek veri değildir.
 *
 * Kendi sözlüğünü kurmak için bu dosyayı kalemler.php adıyla kopyala ve düzenle.
 * kalemler.php .gitignore'dadır; varsa ilk kurulumda bu dosyanın yerine o yüklenir.
 *
 * Alanlar:
 *   tur           gelir | gider | yatirim
 *   kategori      class-dhf-seed.php'deki kategori adlarından biri
 *   tekrar_tipi   sabit (tutar dolu gelir) | degisken (tutar boş gelir) | tek_seferlik (kopyalanmaz)
 *   odeme_sekli   otomatik_talimat | karta_otomatik | elle
 *   etki_profili  gelir | tuketim | kredi_taksit | kart_odeme | kart_faiz | yatirim | borc_artisi | transfer
 *   kurum         kurum tablosundaki ad (hesaba bağlamak için) veya null
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	[ 'ad' => 'Maaş', 'tur' => 'gelir', 'kategori' => 'Maaş', 'tekrar_tipi' => 'degisken', 'varsayilan_gun' => 15, 'varsayilan_tutar' => null, 'odeme_sekli' => 'elle', 'etki_profili' => 'gelir', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
	[ 'ad' => 'Kira geliri', 'tur' => 'gelir', 'kategori' => 'Kira', 'tekrar_tipi' => 'sabit', 'varsayilan_gun' => 5, 'varsayilan_tutar' => 10000.00, 'odeme_sekli' => 'elle', 'etki_profili' => 'gelir', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => 'Örnek kalem' ],
	[ 'ad' => 'Elektrik', 'tur' => 'gider', 'kategori' => 'Ev', 'tekrar_tipi' => 'degisken', 'varsayilan_gun' => 3, 'varsayilan_tutar' => null, 'odeme_sekli' => 'otomatik_talimat', 'etki_profili' => 'tuketim', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
	[ 'ad' => 'Doğalgaz', 'tur' => 'gider', 'kategori' => 'Ev', 'tekrar_tipi' => 'degisken', 'varsayilan_gun' => 23, 'varsayilan_tutar' => null, 'odeme_sekli' => 'otomatik_talimat', 'etki_profili' => 'tuketim', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
	[ 'ad' => 'İnternet', 'tur' => 'gider', 'kategori' => 'Ev', 'tekrar_tipi' => 'degisken', 'varsayilan_gun' => 15, 'varsayilan_tutar' => null, 'odeme_sekli' => 'karta_otomatik', 'etki_profili' => 'tuketim', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
	[ 'ad' => 'Market', 'tur' => 'gider', 'kategori' => 'Ev', 'tekrar_tipi' => 'degisken', 'varsayilan_gun' => 28, 'varsayilan_tutar' => null, 'odeme_sekli' => 'elle', 'etki_profili' => 'tuketim', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
	[ 'ad' => 'Okul taksiti', 'tur' => 'gider', 'kategori' => 'Çocuk', 'tekrar_tipi' => 'sabit', 'varsayilan_gun' => 10, 'varsayilan_tutar' => 5000.00, 'odeme_sekli' => 'elle', 'etki_profili' => 'tuketim', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
	[ 'ad' => 'Yakıt', 'tur' => 'gider', 'kategori' => 'Araç', 'tekrar_tipi' => 'degisken', 'varsayilan_gun' => 20, 'varsayilan_tutar' => null, 'odeme_sekli' => 'elle', 'etki_profili' => 'tuketim', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
	[ 'ad' => 'Konut kredisi', 'tur' => 'gider', 'kategori' => 'Kredi', 'tekrar_tipi' => 'sabit', 'varsayilan_gun' => 16, 'varsayilan_tutar' => 10000.00, 'odeme_sekli' => 'otomatik_talimat', 'etki_profili' => 'kredi_taksit', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => 'Taksit tüketim gideri yaratmaz, yalnızca nakit çıkışı.' ],
	[ 'ad' => 'Kredi kartı ödemesi', 'tur' => 'gider', 'kategori' => 'Kredi Kartı', 'tekrar_tipi' => 'degisken', 'varsayilan_gun' => 25, 'varsayilan_tutar' => null, 'odeme_sekli' => 'elle', 'etki_profili' => 'kart_odeme', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => 'Harcamalar zaten kendi kategorilerinde girildiği için ödeme borç azaltımıdır.' ],
	[ 'ad' => 'Kredi kartı faizi', 'tur' => 'gider', 'kategori' => 'Kredi Kartı', 'tekrar_tipi' => 'degisken', 'varsayilan_gun' => 25, 'varsayilan_tutar' => null, 'odeme_sekli' => 'elle', 'etki_profili' => 'kart_faiz', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
	[ 'ad' => 'BES katkı payı', 'tur' => 'yatirim', 'kategori' => 'BES', 'tekrar_tipi' => 'sabit', 'varsayilan_gun' => 12, 'varsayilan_tutar' => 2000.00, 'odeme_sekli' => 'otomatik_talimat', 'etki_profili' => 'yatirim', 'kurum' => null, 'aktif' => 1, 'bitis_tarihi' => null, 'not_metni' => null ],
];
