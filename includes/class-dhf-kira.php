<?php
/**
 * Kira dönemleri ve yıllık kira artışı hatırlatıcısı (plan §4.4, §5.3 Bölge 1).
 *
 * Artış ayı koda gömülü değil: her kira döneminin kendi `yenileme_ay` alanında
 * durur (şema 6). Yeni bir ev eklenince kod değişmez, veri değişir.
 *
 * Uyarı penceresi yenileme ayından **bir ay önce** açılır ve yenileme ayı geldiğinde
 * "gecikti"ye döner. Kendi kendini kapatır: artış işlenip eski dönem kapatılıp yeni
 * tutarla yeni dönem açılınca sıradaki yenileme bir yıl ileriye gider, uyarı düşer.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Kira {

	/**
	 * Bir kira döneminin sıradaki yenilemesi — saf, WordPress gerektirmez.
	 *
	 * Sıradaki yenileme, dönemin başlangıcından **sonraki** ilk yenileme ayıdır:
	 * 01.07.2026'da başlayan dönemin temmuz yenilemesi 01.07.2027'dir, bugün değil.
	 *
	 * @return array{yenileme:string,uyari:string,durum:string} durum: uzak|yakin|gecikti
	 */
	public static function hesapla( string $baslangic, int $yenileme_ay, string $bugun ): array {
		$ay       = max( 1, min( 12, $yenileme_ay ) );
		$yil      = (int) substr( $baslangic, 0, 4 );
		$yenileme = sprintf( '%04d-%02d-01', $yil, $ay );
		if ( $yenileme <= $baslangic ) {
			$yenileme = sprintf( '%04d-%02d-01', $yil + 1, $ay );
		}
		[ $uy, $ua ] = DHF_Takvim::onceki( (int) substr( $yenileme, 0, 4 ), $ay );
		$uyari       = sprintf( '%04d-%02d-01', $uy, $ua );

		return [
			'yenileme' => $yenileme,
			'uyari'    => $uyari,
			'durum'    => $bugun >= $yenileme ? 'gecikti' : ( $bugun >= $uyari ? 'yakin' : 'uzak' ),
		];
	}

	/**
	 * Uyarı penceresine girmiş açık kira dönemleri, yenileme tarihine göre sıralı.
	 * Kapanmış dönem (bitis dolu) ve yenileme ayı yazılmamış dönem hiç bakılmaz.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function yenilemeler( ?string $bugun = null ): array {
		global $wpdb;
		$bugun = $bugun ?? (string) current_time( 'Y-m-d' );
		$t     = DHF_Schema::table( 'kira_donemi' );
		$rows  = $wpdb->get_results( "SELECT id, ad, baslangic, aylik_kira, yenileme_ay FROM {$t} WHERE bitis IS NULL AND yenileme_ay IS NOT NULL" );

		$out = [];
		foreach ( $rows as $r ) {
			$h = self::hesapla( (string) $r->baslangic, (int) $r->yenileme_ay, $bugun );
			if ( 'uzak' === $h['durum'] ) {
				continue;
			}
			$out[] = $h + [
				'id'        => (int) $r->id,
				'ad'        => (string) ( $r->ad ?: '(ad yok)' ),
				'kira'      => (float) $r->aylik_kira,
				'baslangic' => (string) $r->baslangic,
			];
		}
		usort( $out, static fn( array $a, array $b ): int => $a['yenileme'] <=> $b['yenileme'] );
		return $out;
	}

	/**
	 * Uyarı cümlesi. Pano kutusunda tutarlar gizli olabileceği için $tutar false gelir
	 * — gizliyken rakam HTML'e hiç yazılmaz (§5.11).
	 */
	public static function metin( array $y, bool $tutar = true ): string {
		$ad   = DHF_Takvim::ay_adi( (int) substr( $y['yenileme'], 0, 4 ), (int) substr( $y['yenileme'], 5, 2 ) );
		$kira = $tutar ? number_format_i18n( (float) $y['kira'], 2 ) : null;

		if ( 'gecikti' === $y['durum'] ) {
			return null === $kira
				? sprintf( '%s · kira artışı zamanı geldi: %s', $y['ad'], $ad )
				: sprintf( '%s — kira artışı zamanı geldi: %s. Dönem hâlâ %s ile açık.', $y['ad'], $ad, $kira );
		}
		return null === $kira
			? sprintf( '%s · kira artışı yaklaşıyor: %s', $y['ad'], $ad )
			: sprintf( '%s — kira artışı yaklaşıyor: %s. Şu anki kira %s.', $y['ad'], $ad, $kira );
	}
}
