<?php
/**
 * Kartlar görünümü (0.6.3).
 *
 * Kredi kartı hesabına bağlı satırlardan kart başına ay ay harcama, faiz ve
 * karta yapılan ödemeyi hesaplar. Toplamlar saklanmaz, her seferinde
 * defterden hesaplanır (plan §2.8). Ekstrede yazan borç burada yoktur; o
 * rakam 0.7'de aylık fotoğraf olarak girilecek.
 *
 * Karta yapılan ödeme satırı hangi hesaptan yapıldığını taşır (vadesiz/KMH),
 * hangi karta gittiğini değil. Ödeme alt kategorisi karta adından bağlanır:
 * "YKB kart asgari ödemesi" → kurumu YapıKredi olan kart.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Kart {

	/** Kurum adlarının kısa yazımları. */
	private const TAKMA = [
		'ykb'      => 'yapikredi',
		'yapi'     => 'yapikredi',
		'hb'       => 'halkbank',
		'halk'     => 'halkbank',
		'akb'      => 'akbank',
		'is'       => 'isbankasi',
		'isbank'   => 'isbankasi',
	];

	/** Bir adın ilk kelimesini kurum anahtarına çevirir ("YKB kart…" → "yapikredi"). */
	public static function kurum_anahtari( string $ad ): string {
		$n = DHF_Ayristirici::normalize( $ad );
		$n = preg_replace( '/[^a-z0-9 ]/', '', $n ) ?? $n;
		$ilk = (string) strtok( $n, ' ' );
		if ( 'is' === $ilk && str_contains( $n, 'is bankasi' ) ) {
			return 'isbankasi';
		}
		return self::TAKMA[ $ilk ] ?? $ilk;
	}

	/**
	 * Saf toplama: satırlar → ay ay özet.
	 *
	 * @param array<int,array{ay:int,tutar:float,tur:string}> $satirlar tur: harcama|faiz|odeme
	 * @return array<int,array{harcama:float,faiz:float,odeme:float,fark:float}>
	 */
	public static function aylik( array $satirlar ): array {
		$out = [];
		for ( $a = 1; $a <= 12; $a++ ) {
			$out[ $a ] = [ 'harcama' => 0.0, 'faiz' => 0.0, 'odeme' => 0.0, 'fark' => 0.0 ];
		}
		foreach ( $satirlar as $s ) {
			$a = (int) $s['ay'];
			if ( isset( $out[ $a ][ $s['tur'] ] ) ) {
				$out[ $a ][ $s['tur'] ] += (float) $s['tutar'];
			}
		}
		foreach ( $out as $a => $r ) {
			$out[ $a ] = array_map( static fn( float $v ): float => round( $v, 2 ), $r );
			$out[ $a ]['fark'] = round( $r['harcama'] + $r['faiz'] - $r['odeme'], 2 );
		}
		return $out;
	}

	/** @return object[] Kredi kartı hesapları (kurum adıyla). */
	public static function kartlar(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT h.id, h.ad, h.aktif, k.ad AS kurum FROM ' . DHF_Schema::table( 'hesap' ) . ' h
			 LEFT JOIN ' . DHF_Schema::table( 'kurum' ) . " k ON k.id = h.kurum_id
			 WHERE h.tip = 'kredi_karti' ORDER BY h.aktif DESC, k.ad, h.ad"
		);
	}

	/** Karta ödeme alt kategorileri (kart_odeme profilli) → kart hesabı id. @return array<int,int> */
	public static function odeme_kalemleri(): array {
		global $wpdb;
		$kart_kurum = [];
		foreach ( self::kartlar() as $k ) {
			$kart_kurum[ self::kurum_anahtari( (string) ( $k->kurum ?: $k->ad ) ) ][] = (int) $k->id;
		}
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT id, ad FROM ' . DHF_Schema::table( 'kalem' ) . " WHERE etki_profili = 'kart_odeme'" ) as $kl ) {
			$anahtar = self::kurum_anahtari( (string) $kl->ad );
			if ( isset( $kart_kurum[ $anahtar ] ) && 1 === count( $kart_kurum[ $anahtar ] ) ) {
				$out[ (int) $kl->id ] = $kart_kurum[ $anahtar ][0];
			}
		}
		return $out;
	}

	/** Kartın yıl içindeki satırları (ham). @return object[] */
	public static function satirlar( int $hesap_id, int $yil ): array {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		$k = DHF_Schema::table( 'kalem' );
		$c = DHF_Schema::table( 'kategori' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.tarih, s.tutar, s.aciklama, s.not_metni, kl.ad AS kalem_ad, kl.etki_profili, kt.ad AS kategori_ad
			 FROM {$i} s LEFT JOIN {$k} kl ON kl.id = s.kalem_id LEFT JOIN {$c} kt ON kt.id = s.kategori_id
			 WHERE s.hesap_id = %d AND s.tarih BETWEEN %s AND %s AND s.durum IN ('gerceklesti','taslak')
			 ORDER BY s.tarih, s.id",
			$hesap_id, sprintf( '%04d-01-01', $yil ), sprintf( '%04d-12-31', $yil )
		) );
	}

	/** Karta yapılan ödemeler (hangi hesaptan yapılmış olursa olsun). @return object[] */
	public static function odemeler( int $hesap_id, int $yil ): array {
		global $wpdb;
		$kalemler = array_keys( array_filter( self::odeme_kalemleri(), static fn( int $h ): bool => $h === $hesap_id ) );
		if ( ! $kalemler ) {
			return [];
		}
		$yer = implode( ',', array_fill( 0, count( $kalemler ), '%d' ) );
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT s.id, s.tarih, s.tutar, s.aciklama, s.not_metni, kl.ad AS kalem_ad FROM ' . DHF_Schema::table( 'islem' ) . ' s
			 LEFT JOIN ' . DHF_Schema::table( 'kalem' ) . " kl ON kl.id = s.kalem_id
			 WHERE s.kalem_id IN ({$yer}) AND s.tarih BETWEEN %s AND %s AND s.durum IN ('gerceklesti','taslak')
			 ORDER BY s.tarih, s.id",
			...array_merge( $kalemler, [ sprintf( '%04d-01-01', $yil ), sprintf( '%04d-12-31', $yil ) ] )
		) );
	}

	/** Kart özeti: ay ay tablo, alt kategori dağılımı, yıl toplamı. */
	public static function ozet( int $hesap_id, int $yil ): array {
		$ham = [];
		$dagilim = [];
		foreach ( self::satirlar( $hesap_id, $yil ) as $s ) {
			$tur   = 'kart_faiz' === $s->etki_profili ? 'faiz' : 'harcama';
			$ham[] = [ 'ay' => (int) substr( (string) $s->tarih, 5, 2 ), 'tutar' => (float) $s->tutar, 'tur' => $tur ];
			if ( 'harcama' === $tur ) {
				$ad = (string) ( $s->kalem_ad ?: '—' );
				$dagilim[ $ad ] = ( $dagilim[ $ad ] ?? 0.0 ) + (float) $s->tutar;
			}
		}
		foreach ( self::odemeler( $hesap_id, $yil ) as $o ) {
			$ham[] = [ 'ay' => (int) substr( (string) $o->tarih, 5, 2 ), 'tutar' => (float) $o->tutar, 'tur' => 'odeme' ];
		}
		$aylik = self::aylik( $ham );
		$top   = [ 'harcama' => 0.0, 'faiz' => 0.0, 'odeme' => 0.0, 'fark' => 0.0 ];
		foreach ( $aylik as $r ) {
			foreach ( $top as $k => $v ) {
				$top[ $k ] = round( $v + $r[ $k ], 2 );
			}
		}
		arsort( $dagilim );
		return [ 'aylik' => $aylik, 'toplam' => $top, 'dagilim' => array_map( static fn( float $v ): float => round( $v, 2 ), $dagilim ) ];
	}

	/**
	 * Taksitli alışverişlerin planı (0.6.4). Defterdeki açıklamadan okunur:
	 * "EKSA ELEKTRONIK A.S. (90,000.00 TL) 12/2.taksit" → 12 taksitin 2'si ödenmiş.
	 * Ödenmemiş taksit defterde yoktur (ekstresi gelmemiştir), bu yüzden kalan yük
	 * buradan hesaplanır. Tahmindir: taksit tutarının sabit kaldığı varsayılır.
	 *
	 * @return array<int,array{ad:string,adet:int,odenen:int,kalan:int,aylik:float,kalan_toplam:float,toplam:?float,son_tarih:string}>
	 */
	public static function taksitler( int $hesap_id ): array {
		global $wpdb;
		$i    = DHF_Schema::table( 'islem' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.tarih, s.tutar, s.aciklama FROM {$i} s
			 WHERE s.hesap_id = %d AND s.aciklama LIKE %s AND s.durum IN ('gerceklesti','taslak')
			 ORDER BY s.tarih",
			$hesap_id, '%taksit%'
		) );

		$plan = [];
		foreach ( $rows as $r ) {
			if ( ! preg_match( '~^(.*?)\s*(?:\(\s*([0-9.,]+)\s*TL\s*\))?\s*(\d+)\s*/\s*(\d+)\s*\.?\s*taksit~ui', (string) $r->aciklama, $m ) ) {
				continue;
			}
			$adet = (int) $m[3];
			$no   = (int) $m[4];
			if ( $adet < 2 || $no < 1 || $no > $adet ) {
				continue;
			}
			$ad  = trim( (string) $m[1] ) ?: (string) $r->aciklama;
			$key = mb_strtolower( $ad ) . '|' . $adet;

			$toplam = null;
			if ( isset( $m[2] ) && '' !== trim( (string) $m[2] ) ) {
				$h = trim( (string) $m[2] );
				// Ekstre metni İngilizce biçimde yazıyor: 90,000.00
				$toplam = preg_match( '/^\d{1,3}(,\d{3})+(\.\d+)?$/', $h )
					? (float) str_replace( ',', '', $h )
					: DHF_Ayristirici::tutar( $h );
			}

			if ( ! isset( $plan[ $key ] ) || $no > $plan[ $key ]['odenen'] ) {
				$plan[ $key ] = [
					'ad'        => $ad,
					'adet'      => $adet,
					'odenen'    => $no,
					'aylik'     => round( (float) $r->tutar, 2 ),
					'toplam'    => $toplam,
					'son_tarih' => (string) $r->tarih,
				];
			}
		}

		foreach ( $plan as $k => $p ) {
			$kalan                      = max( 0, $p['adet'] - $p['odenen'] );
			$plan[ $k ]['kalan']        = $kalan;
			$plan[ $k ]['kalan_toplam'] = round( $kalan * $p['aylik'], 2 );
		}
		uasort( $plan, static fn( array $a, array $b ): int => $b['kalan_toplam'] <=> $a['kalan_toplam'] );

		return array_values( $plan );
	}

	/**
	 * Önümüzdeki aylara düşen taksit yükü (0.6.4). Son ödenen taksidin gününden
	 * ay ay ilerler; ayın o günü yoksa ay sonuna çekilir (DHF_Takvim).
	 *
	 * @return array{aylar:array<string,float>,kalan_toplam:float,planlar:array<int,array<string,mixed>>}
	 */
	public static function gelecek( int $hesap_id, int $pencere = 12 ): array {
		$planlar = self::taksitler( $hesap_id );
		$aylar   = [];
		$toplam  = 0.0;

		foreach ( $planlar as $p ) {
			if ( $p['kalan'] < 1 ) {
				continue;
			}
			$toplam += $p['kalan_toplam'];
			$yil     = (int) substr( $p['son_tarih'], 0, 4 );
			$ay      = (int) substr( $p['son_tarih'], 5, 2 );
			for ( $n = 1; $n <= min( $p['kalan'], $pencere ); $n++ ) {
				[ $yil, $ay ]      = DHF_Takvim::sonraki( $yil, $ay );
				$anahtar           = sprintf( '%04d-%02d', $yil, $ay );
				$aylar[ $anahtar ] = round( ( $aylar[ $anahtar ] ?? 0.0 ) + $p['aylik'], 2 );
			}
		}
		ksort( $aylar );

		return [ 'aylar' => $aylar, 'kalan_toplam' => round( $toplam, 2 ), 'planlar' => $planlar ];
	}

}
