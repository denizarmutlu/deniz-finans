<?php
/**
 * E-posta hatırlatıcısı (plan açık konu 13 — hatırlatıcının kanalı).
 *
 * Panel uyarısı ancak WordPress'e girildiğinde görülür; bu sınıf aynı uyarıyı
 * dışarı taşır. Günlük çalışır ama **her gün posta atmaz**: uyarıların durumu bir
 * önceki çalışmaya göre değişmediyse sessiz kalır. Pratikte kira başına iki posta
 * gelir — biri pencere açılınca (bir ay önce), biri yenileme ayı gelince.
 *
 * Alıcı `dhf_hatirlatici_eposta` ayarında durur; boşsa sitenin yönetici e-postasına
 * düşer. `dhf_hatirlatici_alici` filtresi ikisini de ezer.
 * Tutar postaya yazılmaz — mektup kutusu güvenli bir yer değil (§8 mantığı).
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Hatirlatici {

	public const HOOK  = 'dhf_gunluk_hatirlatici';
	private const DURUM = 'dhf_hatirlatici_son';
	private const ALICI = 'dhf_hatirlatici_eposta';

	/** plugins_loaded'dan çağrılır: olayı bağlar, zamanlanmamışsa zamanlar. */
	public static function baslat(): void {
		add_action( self::HOOK, [ self::class, 'calistir' ] );
		self::kur();
	}

	public static function kur(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( self::ilk_zaman(), 'daily', self::HOOK );
		}
	}

	public static function kaldir(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Bir sonraki 09:00 (site saati), UTC damgası olarak. */
	private static function ilk_zaman(): int {
		$simdi  = (int) current_time( 'timestamp' );
		$dokuz  = (int) strtotime( gmdate( 'Y-m-d', $simdi ) . ' 09:00:00 UTC' );
		$hedef  = $simdi < $dokuz ? $dokuz : $dokuz + DAY_IN_SECONDS;
		return $hedef - (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}

	/**
	 * Uyarıların o anki hali — posta yalnızca bu değişince gider.
	 * Anahtar dönem + yenileme tarihi + durumdan kurulur, yani "yakin" → "gecikti"
	 * geçişi yeni bir postayı hak eder, aynı durumun 30. günü etmez.
	 */
	private static function anahtar( array $yenilemeler ): string {
		return implode( '|', array_map(
			static fn( array $y ): string => $y['id'] . ':' . $y['yenileme'] . ':' . $y['durum'],
			$yenilemeler
		) );
	}

	public static function calistir(): bool {
		$yenilemeler = DHF_Kira::yenilemeler();
		$anahtar     = self::anahtar( $yenilemeler );

		if ( $anahtar === (string) get_option( self::DURUM, '' ) ) {
			return false; // Durum aynı: posta yok.
		}
		update_option( self::DURUM, $anahtar, false );

		if ( '' === $anahtar ) {
			return false; // Uyarı kalmadı (artış işlendi): sessizce sıfırlanır.
		}
		return self::gonder( $yenilemeler );
	}

	private static function gonder( array $yenilemeler ): bool {
		$kayitli = (string) get_option( self::ALICI, '' );
		$alici   = (string) apply_filters( 'dhf_hatirlatici_alici',
			is_email( $kayitli ) ? $kayitli : (string) get_option( 'admin_email' ) );
		if ( ! is_email( $alici ) ) {
			return false;
		}

		$gecikmis = array_filter( $yenilemeler, static fn( array $y ): bool => 'gecikti' === $y['durum'] );
		$konu     = $gecikmis ? 'Deniz Finans — kira artışının zamanı geldi' : 'Deniz Finans — kira artışı yaklaşıyor';

		$satirlar = [];
		foreach ( $yenilemeler as $y ) {
			$satirlar[] = '- ' . DHF_Kira::metin( $y, false );
		}
		$govde = implode( "\n", $satirlar ) . "\n\n"
			. "Artışı işledikten sonra eski kira dönemini kapatıp yeni tutarla yenisini açınca\n"
			. "bu hatırlatma kendiliğinden düşer.\n\n"
			. admin_url( 'admin.php?page=dhf' ) . "\n";

		return (bool) wp_mail( $alici, $konu, $govde );
	}
}
