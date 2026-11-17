<?php
/**
 * Veritabanı şeması.
 *
 * Tasarım notları:
 *  - Hiçbir toplam saklanmaz (§2.8). Bu yüzden hiçbir tabloda "toplam" kolonu yok.
 *  - Tutarlar DECIMAL(18,2); adet ve birim fiyat DECIMAL(24,8) (coin ondalıkları için).
 *  - Para birimi taşıyan her yerde ISO kodu + ALTIN_GRAM (§4.2).
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Schema {

	/** Tablo adı: wp_dhf_<ad> */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'dhf_' . $name;
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		foreach ( self::statements( $charset ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( 'dhf_db_version', DHF_DB_VERSION, false );
	}

	/**
	 * @return string[]
	 */
	private static function statements( string $charset ): array {
		$t = static fn( string $n ): string => self::table( $n );

		$sql = [];

		/* ---------------------------------------------------------------- */
		/* Tanımlar                                                          */
		/* ---------------------------------------------------------------- */

		$sql[] = "CREATE TABLE {$t( 'kategori' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ad varchar(120) NOT NULL,
			tur varchar(20) NOT NULL,
			ust_id bigint(20) unsigned DEFAULT NULL,
			rapor_rolu varchar(20) NOT NULL DEFAULT 'normal',
			sira int(11) NOT NULL DEFAULT 0,
			aktif tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY tur_ad_ust (tur,ad,ust_id),
			KEY aktif (aktif)
		) {$charset};";

		$sql[] = "CREATE TABLE {$t( 'kurum' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ad varchar(120) NOT NULL,
			tip varchar(30) NOT NULL DEFAULT 'diger',
			aktif tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY ad (ad)
		) {$charset};";

		$sql[] = "CREATE TABLE {$t( 'hesap' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kurum_id bigint(20) unsigned NOT NULL,
			ad varchar(120) NOT NULL,
			tip varchar(30) NOT NULL DEFAULT 'banka',
			para_birimi varchar(12) NOT NULL DEFAULT 'TRY',
			aktif tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY kurum_id (kurum_id),
			KEY aktif (aktif)
		) {$charset};";

		/* ---------------------------------------------------------------- */
		/* Kalem sözlüğü — 3.1'in çekirdek kavramı (§4.1)                    */
		/* ---------------------------------------------------------------- */

		$sql[] = "CREATE TABLE {$t( 'kalem' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ad varchar(190) NOT NULL,
			tur varchar(20) NOT NULL,
			kategori_id bigint(20) unsigned DEFAULT NULL,
			hesap_id bigint(20) unsigned DEFAULT NULL,
			tekrar_tipi varchar(20) NOT NULL DEFAULT 'degisken',
			varsayilan_gun tinyint(2) unsigned DEFAULT NULL,
			varsayilan_tutar decimal(18,2) DEFAULT NULL,
			odeme_sekli varchar(24) NOT NULL DEFAULT 'elle',
			etki_profili longtext DEFAULT NULL,
			borc_id bigint(20) unsigned DEFAULT NULL,
			varlik_id bigint(20) unsigned DEFAULT NULL,
			fiziksel_varlik_id bigint(20) unsigned DEFAULT NULL,
			kalan_taksit int(11) DEFAULT NULL,
			bitis_tarihi date DEFAULT NULL,
			para_birimi varchar(12) NOT NULL DEFAULT 'TRY',
			not_metni text DEFAULT NULL,
			aktif tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY ad_tur (ad,tur),
			KEY tekrar_tipi (tekrar_tipi),
			KEY odeme_sekli (odeme_sekli),
			KEY aktif (aktif)
		) {$charset};";

		/* ---------------------------------------------------------------- */
		/* Dönem ve işlem defteri                                            */
		/* ---------------------------------------------------------------- */

		$sql[] = "CREATE TABLE {$t( 'donem' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			yil smallint(4) unsigned NOT NULL,
			ay tinyint(2) unsigned NOT NULL,
			durum varchar(20) NOT NULL DEFAULT 'acik',
			kaynak_donem_id bigint(20) unsigned DEFAULT NULL,
			kesinlesme_tarihi datetime DEFAULT NULL,
			kesinlestiren bigint(20) unsigned DEFAULT NULL,
			not_metni text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY yil_ay (yil,ay),
			KEY durum (durum)
		) {$charset};";

		$sql[] = "CREATE TABLE {$t( 'islem' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			donem_id bigint(20) unsigned NOT NULL,
			tarih date NOT NULL,
			orijinal_gun tinyint(2) unsigned DEFAULT NULL,
			kayit_tarihi datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			kalem_id bigint(20) unsigned DEFAULT NULL,
			tur varchar(20) NOT NULL,
			kategori_id bigint(20) unsigned DEFAULT NULL,
			hesap_id bigint(20) unsigned DEFAULT NULL,
			aciklama varchar(190) DEFAULT NULL,
			not_metni text DEFAULT NULL,
			tutar decimal(18,2) NOT NULL DEFAULT 0.00,
			para_birimi varchar(12) NOT NULL DEFAULT 'TRY',
			durum varchar(20) NOT NULL DEFAULT 'taslak',
			kaynak varchar(20) NOT NULL DEFAULT 'manuel',
			kopya_kaynak_id bigint(20) unsigned DEFAULT NULL,
			fiziksel_varlik_id bigint(20) unsigned DEFAULT NULL,
			tekil_anahtar char(32) DEFAULT NULL,
			dis_ref varchar(40) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY tarih (tarih),
			KEY tur_tarih (tur,tarih),
			KEY donem_id (donem_id),
			KEY kalem_id (kalem_id),
			KEY durum (durum),
			KEY tekil_anahtar (tekil_anahtar),
			KEY dis_ref (dis_ref),
			KEY fiziksel_varlik_id (fiziksel_varlik_id)
		) {$charset};";

		// Tiplenmiş etkiler (§2.3). Çift sayımı imkânsız kılan tablo.
		$sql[] = "CREATE TABLE {$t( 'islem_etki' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			islem_id bigint(20) unsigned NOT NULL,
			etki_tipi varchar(30) NOT NULL,
			tutar decimal(18,2) NOT NULL DEFAULT 0.00,
			borc_id bigint(20) unsigned DEFAULT NULL,
			varlik_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY islem_id (islem_id),
			KEY etki_tipi (etki_tipi)
		) {$charset};";

		/* ---------------------------------------------------------------- */
		/* Borç                                                              */
		/* ---------------------------------------------------------------- */

		$sql[] = "CREATE TABLE {$t( 'borc' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kurum_id bigint(20) unsigned DEFAULT NULL,
			hesap_id bigint(20) unsigned DEFAULT NULL,
			tip varchar(30) NOT NULL,
			ad varchar(190) NOT NULL,
			para_birimi varchar(12) NOT NULL DEFAULT 'TRY',
			limit_tutar decimal(18,2) DEFAULT NULL,
			aylik_taksit decimal(18,2) DEFAULT NULL,
			taksit_no int(11) DEFAULT NULL,
			taksit_toplam int(11) DEFAULT NULL,
			baslangic_tarihi date DEFAULT NULL,
			bitis_tarihi date DEFAULT NULL,
			fiziksel_varlik_id bigint(20) unsigned DEFAULT NULL,
			not_metni text DEFAULT NULL,
			aktif tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY tip (tip),
			KEY aktif (aktif),
			KEY para_birimi (para_birimi)
		) {$charset};";

		// bakiye_tipi: ekstre_borcu | guncel_borc | kullanilan_anapara | kalan_anapara | kapama_bakiyesi
		// tl_karsiligi: altın/USD cinsi borçlar için o dönemin kuruyla hesaplanan TL değeri.
		$sql[] = "CREATE TABLE {$t( 'borc_foto' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			borc_id bigint(20) unsigned NOT NULL,
			donem_id bigint(20) unsigned NOT NULL,
			bakiye_tipi varchar(30) NOT NULL,
			tutar decimal(18,2) DEFAULT NULL,
			tl_karsiligi decimal(18,2) DEFAULT NULL,
			banka_bildirimi decimal(18,2) DEFAULT NULL,
			asgari_odeme decimal(18,2) DEFAULT NULL,
			odeme decimal(18,2) DEFAULT NULL,
			faiz decimal(18,2) DEFAULT NULL,
			son_odeme_tarihi date DEFAULT NULL,
			kontrol_tarihi datetime DEFAULT NULL,
			not_metni text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY borc_donem_tip (borc_id,donem_id,bakiye_tipi),
			KEY donem_id (donem_id)
		) {$charset};";

		/* ---------------------------------------------------------------- */
		/* Yatırım                                                           */
		/* ---------------------------------------------------------------- */

		// ust_id: BES sözleşmesi altındaki fonlar için hiyerarşi (§4.3).
		$sql[] = "CREATE TABLE {$t( 'varlik' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kurum_id bigint(20) unsigned DEFAULT NULL,
			ust_id bigint(20) unsigned DEFAULT NULL,
			bes_sozlesme_id bigint(20) unsigned DEFAULT NULL,
			tur varchar(30) NOT NULL,
			ad varchar(190) NOT NULL,
			kod varchar(40) DEFAULT NULL,
			para_birimi varchar(12) NOT NULL DEFAULT 'TRY',
			veri_kaynagi varchar(20) NOT NULL DEFAULT 'manuel',
			toplu tinyint(1) NOT NULL DEFAULT 0,
			aktif tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY kurum_tur (kurum_id,tur),
			KEY ust_id (ust_id),
			KEY kod (kod),
			KEY aktif (aktif)
		) {$charset};";

		// para_girisi burada SAKLANMAZ diye bir kural yok; ama otoriter kaynak işlem
		// defteridir (§4.3). Bu kolon hesaplanan değerin önbelleği olarak tutulur ve
		// her rapor çalıştığında yenilenir.
		$sql[] = "CREATE TABLE {$t( 'varlik_foto' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			varlik_id bigint(20) unsigned NOT NULL,
			donem_id bigint(20) unsigned NOT NULL,
			adet decimal(24,8) DEFAULT NULL,
			birim_fiyat decimal(24,8) DEFAULT NULL,
			tutar decimal(18,2) DEFAULT NULL,
			para_girisi decimal(18,2) DEFAULT NULL,
			devlet_katkisi decimal(18,2) DEFAULT NULL,
			fiyat_kaynagi varchar(20) NOT NULL DEFAULT 'manuel',
			fiyat_tarihi date DEFAULT NULL,
			not_metni text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY varlik_donem (varlik_id,donem_id),
			KEY donem_id (donem_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$t( 'bes_sozlesme' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kurum_id bigint(20) unsigned NOT NULL,
			sozlesme_no varchar(60) NOT NULL,
			baslangic_tarihi date DEFAULT NULL,
			not_metni text DEFAULT NULL,
			aktif tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY sozlesme_no (sozlesme_no),
			KEY kurum_id (kurum_id)
		) {$charset};";

		// Katkı payı tarihli: yıl içinde değişince eski satır silinmez (§4.3).
		$sql[] = "CREATE TABLE {$t( 'bes_katki' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sozlesme_id bigint(20) unsigned NOT NULL,
			gecerlilik_tarihi date NOT NULL,
			aylik_katki decimal(18,2) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sozlesme_tarih (sozlesme_id,gecerlilik_tarihi)
		) {$charset};";

		// Yılda 12 fon değişim hakkı sayacının kaynağı (§5.6).
		$sql[] = "CREATE TABLE {$t( 'fon_degisim' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sozlesme_id bigint(20) unsigned NOT NULL,
			tarih date NOT NULL,
			eski_dagilim longtext DEFAULT NULL,
			yeni_dagilim longtext DEFAULT NULL,
			not_metni text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY sozlesme_tarih (sozlesme_id,tarih)
		) {$charset};";

		$sql[] = "CREATE TABLE {$t( 'gosterge' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			donem_id bigint(20) unsigned NOT NULL,
			bist100 decimal(18,4) DEFAULT NULL,
			bist30 decimal(18,4) DEFAULT NULL,
			usdtry decimal(18,4) DEFAULT NULL,
			eurtry decimal(18,4) DEFAULT NULL,
			gram_altin decimal(18,4) DEFAULT NULL,
			tufe decimal(18,4) DEFAULT NULL,
			mevduat_faizi decimal(9,4) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY donem_id (donem_id)
		) {$charset};";

		/* ---------------------------------------------------------------- */
		/* Fiziksel varlık ve bilanço (§4.4)                                 */
		/* ---------------------------------------------------------------- */

		$sql[] = "CREATE TABLE {$t( 'fiziksel_varlik' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ad varchar(190) NOT NULL,
			tur varchar(30) NOT NULL,
			katman varchar(20) NOT NULL DEFAULT 'kullanim',
			edinme_tarihi date DEFAULT NULL,
			edinme_bedeli decimal(18,2) DEFAULT NULL,
			deger_yontemi varchar(30) NOT NULL DEFAULT 'piyasa_tahmini',
			amortisman_orani decimal(6,3) DEFAULT NULL,
			guncelleme_sikligi varchar(20) NOT NULL DEFAULT 'yillik',
			para_birimi varchar(12) NOT NULL DEFAULT 'TRY',
			ilgili_borc_id bigint(20) unsigned DEFAULT NULL,
			not_metni text DEFAULT NULL,
			aktif tinyint(1) NOT NULL DEFAULT 1,
			satis_tarihi date DEFAULT NULL,
			satis_bedeli decimal(18,2) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY tur (tur),
			KEY katman (katman),
			KEY aktif (aktif)
		) {$charset};";

		// kaynak zorunlu: ev değeri tahmindir, tahmin olduğu yazılı durmazsa
		// altı ay sonra kesin rakam gibi görünür (§4.4).
		$sql[] = "CREATE TABLE {$t( 'fiziksel_varlik_deger' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fiziksel_varlik_id bigint(20) unsigned NOT NULL,
			tarih date NOT NULL,
			deger decimal(18,2) NOT NULL,
			kaynak varchar(30) NOT NULL,
			not_metni text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY varlik_tarih (fiziksel_varlik_id,tarih),
			KEY tarih (tarih)
		) {$charset};";

		$sql[] = "CREATE TABLE {$t( 'kira_donemi' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fiziksel_varlik_id bigint(20) unsigned DEFAULT NULL,
			ad varchar(190) DEFAULT NULL,
			baslangic date NOT NULL,
			bitis date DEFAULT NULL,
			aylik_kira decimal(18,2) NOT NULL,
			kiraci_adi varchar(190) DEFAULT NULL,
			not_metni text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY varlik_baslangic (fiziksel_varlik_id,baslangic)
		) {$charset};";

		/* ---------------------------------------------------------------- */
		/* Aktarım ve log                                                    */
		/* ---------------------------------------------------------------- */

		$sql[] = "CREATE TABLE {$t( 'aktarim' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kaynak varchar(20) NOT NULL,
			dosya_adi varchar(255) DEFAULT NULL,
			sekme varchar(120) DEFAULT NULL,
			profil varchar(20) DEFAULT NULL,
			filtre_yil smallint(4) unsigned DEFAULT NULL,
			filtre_ay tinyint(2) unsigned DEFAULT NULL,
			yazilan int(11) NOT NULL DEFAULT 0,
			onay_tarihi datetime DEFAULT NULL,
			kullanici_id bigint(20) unsigned DEFAULT NULL,
			ozet_hash char(64) DEFAULT NULL,
			durum varchar(20) NOT NULL DEFAULT 'onizleme',
			tarih datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY ozet_hash (ozet_hash),
			KEY durum (durum)
		) {$charset};";

		$sql[] = "CREATE TABLE {$t( 'aktarim_satir' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			aktarim_id bigint(20) unsigned NOT NULL,
			kaynak_satir_no int(11) DEFAULT NULL,
			satir_anahtari char(32) DEFAULT NULL,
			ham_veri longtext DEFAULT NULL,
			tarih date DEFAULT NULL,
			tur varchar(20) DEFAULT NULL,
			kategori_id bigint(20) unsigned DEFAULT NULL,
			tutar decimal(18,2) DEFAULT NULL,
			kalem_id bigint(20) unsigned DEFAULT NULL,
			eslesen_islem_id bigint(20) unsigned DEFAULT NULL,
			hata varchar(255) DEFAULT NULL,
			onay_durumu varchar(20) NOT NULL DEFAULT 'bekliyor',
			karar varchar(20) DEFAULT NULL,
			gerekce varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY aktarim_id (aktarim_id),
			KEY satir_anahtari (satir_anahtari),
			KEY onay_durumu (onay_durumu)
		) {$charset};";

		// Defterdeki serbest metin -> kalem eşleştirmesi. Aktarımda bir kez seçilen eşleşme
		// hatırlanır; "İgdaş doğalgaz hb" bir daha sorulmaz (0.3).
		$sql[] = "CREATE TABLE {$t( 'kalem_eslesme' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			metin varchar(190) NOT NULL,
			tur varchar(20) NOT NULL,
			kalem_id bigint(20) unsigned NOT NULL,
			kaynak varchar(20) NOT NULL DEFAULT 'elle',
			PRIMARY KEY  (id),
			UNIQUE KEY metin_tur (metin,tur),
			KEY kalem_id (kalem_id)
		) {$charset};";

		// Yalnızca kesinleşmiş döneme dokunulduğunda yazar (§2.4).
		$sql[] = "CREATE TABLE {$t( 'log' )} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			donem_id bigint(20) unsigned DEFAULT NULL,
			islem_id bigint(20) unsigned DEFAULT NULL,
			kullanici_id bigint(20) unsigned DEFAULT NULL,
			tarih datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			alan varchar(60) DEFAULT NULL,
			eski_deger text DEFAULT NULL,
			yeni_deger text DEFAULT NULL,
			gerekce text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY donem_id (donem_id),
			KEY islem_id (islem_id)
		) {$charset};";

		return $sql;
	}

	/** Kurulu tabloların adlarını döndürür — Ayarlar ekranındaki tanı kutusu için. */
	public static function status(): array {
		global $wpdb;
		$out = [];
		foreach ( self::table_names() as $name ) {
			$full = self::table( $name );
			$out[ $name ] = [
				'tablo'  => $full,
				'var'    => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ),
				'satir'  => null,
			];
			if ( $out[ $name ]['var'] ) {
				// Tablo adı yukarıdaki beyaz listeden geldiği için interpolasyon güvenli.
				$out[ $name ]['satir'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full}" );
			}
		}
		return $out;
	}

	/** @return string[] */
	public static function table_names(): array {
		return [
			'kategori', 'kurum', 'hesap', 'kalem', 'donem', 'islem', 'islem_etki',
			'borc', 'borc_foto',
			'varlik', 'varlik_foto', 'bes_sozlesme', 'bes_katki', 'fon_degisim', 'gosterge',
			'fiziksel_varlik', 'fiziksel_varlik_deger', 'kira_donemi',
			'aktarim', 'aktarim_satir', 'kalem_eslesme', 'log',
		];
	}
}
