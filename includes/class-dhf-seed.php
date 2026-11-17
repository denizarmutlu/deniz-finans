<?php
/**
 * İlk kurulum verisi.
 *
 * Yalnızca bir kez çalışır (dhf_seed_done). Hiçbir işlem/tutar satırı yazmaz.
 * Depoda yalnızca genel kategoriler vardır. Kişisel tanımlar (kurum, borç,
 * BES sözleşmesi, fiziksel varlık) includes/data/kisisel.php dosyasından
 * yüklenir; bu dosya .gitignore'dadır ve depoya girmez.
 * Şablon: includes/data/kisisel.ornek.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Seed {

	public static function run_once(): void {
		if ( get_option( 'dhf_seed_done' ) ) {
			return;
		}
		self::kategoriler();
		self::ayarlar();
		$kisisel = DHF_DIR . 'includes/data/kisisel.php';
		if ( is_readable( $kisisel ) ) {
			require_once $kisisel;
			if ( class_exists( 'DHF_Seed_Kisisel' ) ) {
				DHF_Seed_Kisisel::run();
			}
		}
		update_option( 'dhf_seed_done', 1, false );
	}

	/**
	 * Kalem sözlüğü ayrı bir bayrakla yüklenir — 0.1 kurulumundan
	 * yükseltenlerde tanımlar zaten var, kalemler yok.
	 */
	public static function kalemler_once(): void {
		if ( get_option( 'dhf_kalem_seed_done' ) ) {
			return;
		}
		$n = DHF_Kalem::seed();
		update_option( 'dhf_kalem_seed_done', $n, false );
	}

	public static function ins( string $table, array $data ): int {
		global $wpdb;
		$wpdb->insert( DHF_Schema::table( $table ), $data );
		return (int) $wpdb->insert_id;
	}

	/* ------------------------------------------------------------------ */

	private static function kategoriler(): void {
		$map = [
			'gelir'   => [ 'Maaş', 'Ek Ders', 'Ek İşler', 'Kira' ],
			'gider'   => [ 'Ev', 'Çocuk', 'Araç', 'Kredi', 'Kredi Kartı', 'Sağlık', 'Diğer' ],
			'yatirim' => [ 'BES', 'Fon', 'Hisse', 'Yurtdışı', 'Eurobond', 'Coin', 'Altın' ],
		];
		foreach ( $map as $tur => $adlar ) {
			$sira = 0;
			foreach ( $adlar as $ad ) {
				self::ins( 'kategori', [
					'ad'         => $ad,
					'tur'        => $tur,
					'rapor_rolu' => 'normal',
					'sira'       => $sira += 10,
					'aktif'      => 1,
				] );
			}
		}
	}

	private static function ayarlar(): void {
		add_option( 'dhf_bes_devlet_katkisi_orani', [ '2026' => 25.0 ], '', false );
		add_option( 'dhf_rapor_varsayilan_gorunum', 'tuketim', '', false );
		add_option( 'dhf_para_birimi', 'TRY', '', false );
	}
}
