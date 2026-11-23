<?php
/**
 * Varlık ağacı ve getiri hesabı (0.8).
 *
 * İki kural buranın çekirdeği:
 *  1) YAPRAK KURALI — bir dönemin toplamı, o dönemde fotoğrafı olan EN ALT
 *     düğümlerden toplanır. Üst düğümün kendi fotoğrafı yalnız o dönemde hiçbir
 *     çocuğunun fotoğrafı yoksa sayılır. 2026 tür seviyesinde girildi, 2027'den
 *     enstrüman seviyesinde girilecek; bu kural ikisini çift saymadan yan yana tutar.
 *  2) GETİRİ, bakiye farkı DEĞİLDİR (§4.3). Katkı ve devlet katkısı düşülür.
 *
 * İç transfer: varlik_foto.transfer_karsi_varlik_id dolu olan para girişi, portföye
 * dışarıdan gelen para değildir; varlık düzeyinde getiriye girer, portföy düzeyinde
 * net girişten çıkarılır.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DHF_Varlik {

	/** Hakkediş eşikleri: sistemde geçen tam yıl => hakkedilen devlet katkısı yüzdesi. */
	public const HAKKEDIS = [ 10 => 60, 6 => 35, 3 => 15 ];

	private static function t( string $ad ): string {
		return DHF_Schema::table( $ad );
	}

	/** Tüm varlıklar, kurum adıyla. id => satır. */
	public static function varliklar(): array {
		global $wpdb;
		$v = self::t( 'varlik' );
		$k = self::t( 'kurum' );
		$s = self::t( 'bes_sozlesme' );
		$satirlar = $wpdb->get_results(
			"SELECT a.*, k.ad AS kurum_ad, k.tip AS kurum_tip, b.sozlesme_no, b.baslangic_tarihi
			 FROM {$v} a
			 LEFT JOIN {$k} k ON k.id = a.kurum_id
			 LEFT JOIN {$s} b ON b.id = a.bes_sozlesme_id
			 ORDER BY k.ad, a.tur, a.ad",
			ARRAY_A
		);
		$out = [];
		foreach ( $satirlar as $r ) {
			$out[ (int) $r['id'] ] = $r;
		}
		return $out;
	}

	/** ust_id => [çocuk id, ...] */
	public static function cocuklar( ?array $varliklar = null ): array {
		$varliklar = $varliklar ?? self::varliklar();
		$out = [];
		foreach ( $varliklar as $id => $r ) {
			if ( ! empty( $r['ust_id'] ) ) {
				$out[ (int) $r['ust_id'] ][] = $id;
			}
		}
		return $out;
	}

	/** Fotoğraflar. donem_id => varlik_id => satır. $yil null ise tamamı. */
	public static function fotolar( ?int $yil = null ): array {
		global $wpdb;
		$f = self::t( 'varlik_foto' );
		$d = self::t( 'donem' );
		$sql = "SELECT f.*, d.yil, d.ay FROM {$f} f JOIN {$d} d ON d.id = f.donem_id";
		if ( null !== $yil ) {
			$sql = $wpdb->prepare( $sql . ' WHERE d.yil = %d', $yil );
		}
		$out = [];
		foreach ( $wpdb->get_results( $sql . ' ORDER BY d.yil, d.ay', ARRAY_A ) as $r ) {
			$out[ (int) $r['donem_id'] ][ (int) $r['varlik_id'] ] = $r;
		}
		return $out;
	}

	/**
	 * Yaprak kuralı: bu dönemde sayılacak varlık id'leri.
	 * Çocuğunun fotoğrafı olan üst düğüm sayılmaz.
	 */
	public static function sayilacaklar( array $donem_fotolari, array $cocuklar ): array {
		$out = [];
		foreach ( array_keys( $donem_fotolari ) as $vid ) {
			$cocuk_var = false;
			foreach ( $cocuklar[ $vid ] ?? [] as $c ) {
				if ( isset( $donem_fotolari[ $c ] ) ) {
					$cocuk_var = true;
					break;
				}
			}
			if ( ! $cocuk_var ) {
				$out[] = $vid;
			}
		}
		return $out;
	}

	/**
	 * Bir yılın dönem dönem özeti.
	 *
	 * Getiri yalnız KIYASLANABİLİR sepet üzerinden hesaplanır: iki dönemde de
	 * fotoğrafı olan varlıklar. Sekmede bir ay boş bırakılıp ertesi ay dolan bir
	 * kalemin bakiyesi getiri değildir; o tutar 'giren_kapsam' / 'cikan_kapsam'
	 * olarak ayrı durur. Tür seviyesinden enstrüman seviyesine geçilen ay da
	 * aynı korumadan geçer, yoksa geçiş sahte bir getiri üretir.
	 */
	public static function yillik_ozet( int $yil ): array {
		global $wpdb;
		$d        = self::t( 'donem' );
		$donemler = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, yil, ay FROM {$d} WHERE yil = %d ORDER BY ay", $yil ),
			ARRAY_A
		);
		$cocuklar       = self::cocuklar();
		$fotolar        = self::fotolar( $yil );
		$out            = [];
		$onceki_say     = null;
		$onceki_fotolar = [];
		foreach ( $donemler as $dn ) {
			$id = (int) $dn['id'];
			if ( empty( $fotolar[ $id ] ) ) {
				continue; // Boş dönem rapora girmez (§5.4).
			}
			$say    = self::sayilacaklar( $fotolar[ $id ], $cocuklar );
			$toplam = 0.0;
			foreach ( $say as $vid ) {
				$toplam += (float) $fotolar[ $id ][ $vid ]['tutar'];
			}
			$dis       = 0.0;
			$ic        = 0.0;
			$dk        = 0.0;
			$kiyas_bas = 0.0;
			$kiyas_bit = 0.0;
			$giren     = 0.0;
			$cikan     = 0.0;
			$getiri    = null;
			$yuzde     = null;
			$kiyas     = [];
			$hedef     = null === $onceki_say ? $say : array_values( array_intersect( $say, $onceki_say ) );
			$kiyas     = null === $onceki_say ? [] : $hedef;
			foreach ( $hedef as $vid ) {
				$r  = $fotolar[ $id ][ $vid ];
				$g  = null === $r['para_girisi'] ? 0.0 : (float) $r['para_girisi'];
				if ( empty( $r['transfer_karsi_varlik_id'] ) ) {
					$dis += $g;
				} else {
					$ic += $g;
				}
				$dk += null === $r['devlet_katkisi'] ? 0.0 : (float) $r['devlet_katkisi'];
				if ( null !== $onceki_say ) {
					$kiyas_bit += (float) $r['tutar'];
					$kiyas_bas += (float) $onceki_fotolar[ $vid ]['tutar'];
				}
			}
			if ( null !== $onceki_say ) {
				foreach ( array_diff( $say, $onceki_say ) as $vid ) {
					$giren += (float) $fotolar[ $id ][ $vid ]['tutar'];
				}
				foreach ( array_diff( $onceki_say, $say ) as $vid ) {
					$cikan += (float) $onceki_fotolar[ $vid ]['tutar'];
				}
				$getiri = $kiyas_bit - $kiyas_bas - $dis - $ic - $dk;
				$taban  = $kiyas_bas + $dis + $ic + $dk;
				$yuzde  = 0.0 === $taban ? null : $getiri / $taban * 100;
			}
			$out[] = [
				'donem_id'       => $id,
				'yil'            => (int) $dn['yil'],
				'ay'             => (int) $dn['ay'],
				'kalem'          => count( $say ),
				'kiyas_kalem'    => count( $kiyas ),
				'toplam'         => round( $toplam, 2 ),
				'dis_giris'      => round( $dis, 2 ),
				'ic_transfer'    => round( $ic, 2 ),
				'devlet_katkisi' => round( $dk, 2 ),
				'giren_kapsam'   => round( $giren, 2 ),
				'cikan_kapsam'   => round( $cikan, 2 ),
				'getiri'         => null === $getiri ? null : round( $getiri, 2 ),
				'yuzde'          => null === $yuzde ? null : round( $yuzde, 2 ),
			];
			$onceki_say     = $say;
			$onceki_fotolar = $fotolar[ $id ];
		}
		return $out;
	}
	/** Tür bazında kırılım: iki dönem arası. */
	public static function tur_kirilimi( int $bas_donem, int $bit_donem ): array {
		$cocuklar  = self::cocuklar();
		$varliklar = self::varliklar();
		$tum       = self::fotolar();
		$ara       = self::donem_araligi( $bas_donem, $bit_donem );
		$out       = [];
		foreach ( [ $bas_donem => 'bas', $bit_donem => 'bit' ] as $did => $uc ) {
			foreach ( self::sayilacaklar( $tum[ $did ] ?? [], $cocuklar ) as $vid ) {
				$tur = self::tur_kok( $vid, $varliklar );
				$out[ $tur ][ $uc ] = ( $out[ $tur ][ $uc ] ?? 0.0 ) + (float) $tum[ $did ][ $vid ]['tutar'];
			}
		}
		foreach ( $ara as $did ) {
			if ( $did === $bas_donem ) {
				continue;
			}
			foreach ( self::sayilacaklar( $tum[ $did ] ?? [], $cocuklar ) as $vid ) {
				$r   = $tum[ $did ][ $vid ];
				$tur = self::tur_kok( $vid, $varliklar );
				$g = null === $r['para_girisi'] ? 0.0 : (float) $r['para_girisi'];
				$anahtar = empty( $r['transfer_karsi_varlik_id'] ) ? 'giris' : 'ic';
				$out[ $tur ][ $anahtar ] = ( $out[ $tur ][ $anahtar ] ?? 0.0 ) + $g;
				$out[ $tur ]['dk']    = ( $out[ $tur ]['dk'] ?? 0.0 ) + ( null === $r['devlet_katkisi'] ? 0.0 : (float) $r['devlet_katkisi'] );
			}
		}
		foreach ( $out as $tur => $x ) {
			$bas   = $x['bas'] ?? 0.0;
			$bit   = $x['bit'] ?? 0.0;
			$giris = ( $x['giris'] ?? 0.0 ) + ( $x['ic'] ?? 0.0 );
			$dk    = $x['dk'] ?? 0.0;
			$g     = $bit - $bas - $giris - $dk;
			$taban = $bas + $giris + $dk;
			$out[ $tur ] = [
				'bas'    => round( $bas, 2 ),
				'bit'    => round( $bit, 2 ),
				'giris'  => round( $x['giris'] ?? 0.0, 2 ),
				'ic'     => round( $x['ic'] ?? 0.0, 2 ),
				'dk'     => round( $dk, 2 ),
				'getiri' => round( $g, 2 ),
				'yuzde'  => 0.0 === $taban ? null : round( $g / $taban * 100, 2 ),
			];
		}
		return $out;
	}

	/** Bir varlığın tür kökü: enstrüman ise üstünün türü. */
	private static function tur_kok( int $vid, array $varliklar ): string {
		$r   = $varliklar[ $vid ] ?? null;
		$kat = 0;
		while ( $r && ! empty( $r['ust_id'] ) && $kat < 5 ) {
			$r = $varliklar[ (int) $r['ust_id'] ] ?? null;
			$kat++;
		}
		return $r ? (string) $r['tur'] : 'BILINMIYOR';
	}

	/** İki dönem arasındaki dönem id'leri, takvim sırasıyla (uçlar dahil). */
	public static function donem_araligi( int $bas, int $bit ): array {
		global $wpdb;
		$d = self::t( 'donem' );
		$u = $wpdb->get_results( "SELECT id, yil, ay FROM {$d} ORDER BY yil, ay", ARRAY_A );
		$ids = array_map( static fn( $x ) => (int) $x['id'], $u );
		$i = array_search( $bas, $ids, true );
		$j = array_search( $bit, $ids, true );
		if ( false === $i || false === $j || $j < $i ) {
			return [];
		}
		return array_slice( $ids, $i, $j - $i + 1 );
	}

	/**
	 * Hakkediş: sözleşme başlangıcından bugüne geçen süreye göre hakkedilen
	 * devlet katkısı yüzdesi. 56 yaş + 10 yıl ile %100 olur; yaş bilgisi burada
	 * tutulmadığı için en çok %60 döner ve 'tam_hak' alanı false kalır.
	 */
	public static function hakkedis( string $baslangic, ?string $tarih = null ): array {
		$bas = strtotime( $baslangic );
		$ref = strtotime( $tarih ?? current_time( 'Y-m-d' ) );
		if ( ! $bas || ! $ref || $ref < $bas ) {
			return [ 'yil' => 0.0, 'yuzde' => 0, 'sonraki_tarih' => null, 'sonraki_yuzde' => 15, 'tam_hak' => false ];
		}
		$gecen = ( $ref - $bas ) / 31557600; // 365,25 gün
		$yuzde = 0;
		foreach ( self::HAKKEDIS as $esik => $oran ) {
			if ( $gecen >= $esik ) {
				$yuzde = $oran;
				break;
			}
		}
		$sonraki_esik  = null;
		$sonraki_yuzde = null;
		foreach ( array_reverse( self::HAKKEDIS, true ) as $esik => $oran ) {
			if ( $gecen < $esik ) {
				$sonraki_esik  = $esik;
				$sonraki_yuzde = $oran;
				break;
			}
		}
		return [
			'yil'           => round( $gecen, 2 ),
			'yuzde'         => $yuzde,
			'sonraki_tarih' => null === $sonraki_esik ? null : gmdate( 'Y-m-d', (int) strtotime( '+' . $sonraki_esik . ' years', $bas ) ),
			'sonraki_yuzde' => $sonraki_yuzde,
			'tam_hak'       => false,
		];
	}
}