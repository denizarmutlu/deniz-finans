<?php
/**
 * Rapor veri katmanı (plan §5.4, 0.5).
 *
 * Hiçbir toplam saklanmaz (§2.8). Aylık toplamlar DHF_Islem::toplamlar() üzerinden
 * alınır — Hareketler ekranı, Gösterge Paneli ve Pano kutusu aynı fonksiyonu
 * kullandığı için bir ayın rakamı hiçbir ekranda farklı çıkamaz.
 *
 * Tablo üreticileri (tablo_*) ekrandan ve CSV dışa aktarımdan aynı şekilde çağrılır:
 *   [ 'baslik' => string, 'kolonlar' => string[], 'satirlar' => array[], 'alt' => ?array, 'not' => ?string ]
 * Hücre değeri float ise tutar, null ise "veri yok", string ise metin olarak basılır.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Rapor {

	/**
	 * Üç görünüm — "gider" tek bir rakam değildir.
	 * etiket, toplanan etki tipleri, cevapladığı soru.
	 */
	public const GORUNUMLER = [
		'tuketim' => [ 'Tüketim', [ 'tuketim_gideri' ], 'Gerçekten ne harcadım?' ],
		'nakit'   => [ 'Nakit', [ 'nakit_cikisi' ], 'Hesabımdan ne çıktı?' ],
		'maliyet' => [ 'Maliyet', [ 'tuketim_gideri', 'faiz_masraf' ], 'Bu ay bana neye mal oldu?' ],
	];

	/* ---------------------------------------------------------------- */
	/* Saf yardımcılar — WordPress gerektirmez, testli                   */
	/* ---------------------------------------------------------------- */

	public static function gorunum( string $g ): string {
		return isset( self::GORUNUMLER[ $g ] ) ? $g : 'tuketim';
	}

	/** Bir ayın toplamlarından görünümün rakamı. */
	public static function gorunum_tutar( array $t, string $g ): float {
		$sum = 0.0;
		foreach ( self::GORUNUMLER[ self::gorunum( $g ) ][1] as $tip ) {
			$sum += (float) ( $t[ $tip ] ?? 0 );
		}
		return round( $sum, 2 );
	}

	/**
	 * CSV hücresi — formül enjeksiyonu temizliği (§8). Hesap tablosu programları
	 * =, +, -, @, sekme ve satır başıyla başlayan hücreyi formül sayar.
	 */
	public static function csv_guvenli( string $s ): string {
		return ( '' !== $s && str_contains( "=+-@\t\r", $s[0] ) ) ? "'" . $s : $s;
	}

	/** Tutar CSV'de Türk biçiminde, ama binlik ayırıcısız: 1234,56 — Excel sayı olarak okur. */
	public static function csv_tutar( ?float $v ): string {
		return null === $v ? '' : number_format( $v, 2, ',', '' );
	}

	/**
	 * Önümüzdeki $pencere gün içinde vadesi gelen kalemler.
	 *
	 * @param array<int,int> $gunler kalem_id => ödeme günü
	 * @return array<int,string> kalem_id => vade tarihi (Y-m-d), tarihe göre sıralı.
	 *         Bu ayın günü geçmişse gelecek ayın vadesine bakılır.
	 */
	public static function vadeler( array $gunler, string $bugun, int $pencere ): array {
		[ $y, $a ] = array_map( 'intval', explode( '-', $bugun ) );
		[ $sy, $sa ] = DHF_Takvim::sonraki( $y, $a );
		$son = gmdate( 'Y-m-d', strtotime( $bugun . ' +' . $pencere . ' days UTC' ) );
		$out = [];
		foreach ( $gunler as $id => $gun ) {
			foreach ( [ [ $y, $a ], [ $sy, $sa ] ] as [ $yy, $aa ] ) {
				$t = DHF_Takvim::tarih( $yy, $aa, (int) $gun );
				if ( $t >= $bugun && $t <= $son ) {
					$out[ (int) $id ] = $t;
					break;
				}
			}
		}
		asort( $out );
		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* Veri                                                              */
	/* ---------------------------------------------------------------- */

	/**
	 * Yılın ayları: dönemi olan her ay için durum + toplamlar.
	 *
	 * @return array<int,array{donem:object,t:array<string,float>,satir:int}>
	 */
	public static function aylik( int $yil ): array {
		global $wpdb;
		$d = DHF_Schema::table( 'donem' );
		$i = DHF_Schema::table( 'islem' );

		$donemler = $wpdb->get_results( $wpdb->prepare(
			"SELECT dn.*, (SELECT COUNT(*) FROM {$i} s WHERE s.donem_id = dn.id AND s.durum <> 'planlanan') AS satir
			 FROM {$d} dn WHERE dn.yil = %d ORDER BY dn.ay",
			$yil
		) );

		$out = [];
		foreach ( $donemler as $dn ) {
			if ( ! (int) $dn->satir ) {
				continue; // Boş dönem (yalnızca açılmış) rapora girmez.
			}
			$out[ (int) $dn->ay ] = [
				'donem' => $dn,
				't'     => DHF_Islem::toplamlar( (int) $dn->id ),
				'satir' => (int) $dn->satir,
			];
		}
		return $out;
	}

	/** Verisi olan yıllar — seçici için. */
	public static function yillar(): array {
		global $wpdb;
		$d = DHF_Schema::table( 'donem' );
		$i = DHF_Schema::table( 'islem' );
		$y = $wpdb->get_col( "SELECT DISTINCT dn.yil FROM {$d} dn WHERE EXISTS (SELECT 1 FROM {$i} s WHERE s.donem_id = dn.id) ORDER BY dn.yil DESC" );
		$y = array_map( 'intval', $y );
		$bu = (int) current_time( 'Y' );
		if ( ! in_array( $bu, $y, true ) ) {
			array_unshift( $y, $bu );
		}
		return $y;
	}

	/** "Ek kaynak" rolündeki gelir kategorileri (aile desteği gibi) aylık toplamı. */
	private static function ek_kaynak( int $yil ): array {
		global $wpdb;
		$e = DHF_Schema::table( 'islem_etki' );
		$i = DHF_Schema::table( 'islem' );
		$d = DHF_Schema::table( 'donem' );
		$c = DHF_Schema::table( 'kategori' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT dn.ay, SUM(e.tutar) AS t
			 FROM {$e} e INNER JOIN {$i} s ON s.id = e.islem_id INNER JOIN {$d} dn ON dn.id = s.donem_id
			 INNER JOIN {$c} kt ON kt.id = s.kategori_id
			 WHERE dn.yil = %d AND s.durum <> 'planlanan' AND e.etki_tipi = 'nakit_girisi' AND kt.rapor_rolu = 'ek_kaynak'
			 GROUP BY dn.ay",
			$yil
		) );
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->ay ] = (float) $r->t;
		}
		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* Tablolar                                                          */
	/* ---------------------------------------------------------------- */

	private static function ay_kisa( int $ay ): string {
		return mb_substr( DHF_Takvim::AYLAR[ $ay ], 0, 3 );
	}

	/** Üç görünümlü özet: her ay tüketim / maliyet / nakit yan yana. */
	public static function tablo_ozet( int $yil ): array {
		$aylik = self::aylik( $yil );
		$kol   = [ 'Ay', 'Durum', 'Gelir', 'Tüketim', 'Faiz', 'Maliyet', 'Kart borcu azaltımı', 'Yatırım', 'Nakit çıkışı', 'Nakit ihtiyacı' ];
		$alan  = static fn( array $t ): array => [
			$t['gelir'], $t['tuketim_gideri'], $t['faiz_masraf'],
			self::gorunum_tutar( $t, 'maliyet' ), $t['borc_azaltimi'], $t['yatirim_alimi'],
			self::gorunum_tutar( $t, 'nakit' ), $t['nakit_ihtiyaci'],
		];

		$satirlar = [];
		$top      = array_fill( 0, 8, 0.0 );
		foreach ( range( 1, 12 ) as $ay ) {
			if ( ! isset( $aylik[ $ay ] ) ) {
				$satirlar[] = array_merge( [ DHF_Takvim::AYLAR[ $ay ], '' ], array_fill( 0, 8, null ) );
				continue;
			}
			$v = $alan( $aylik[ $ay ]['t'] );
			foreach ( $v as $k => $x ) {
				$top[ $k ] += $x;
			}
			$satirlar[] = array_merge(
				[ DHF_Takvim::AYLAR[ $ay ], DHF_Donem::DURUMLAR[ $aylik[ $ay ]['donem']->durum ] ?? '' ],
				$v
			);
		}

		return [
			'baslik'   => sprintf( '%d — üç görünümlü özet', $yil ),
			'kolonlar' => $kol,
			'satirlar' => $satirlar,
			'alt'      => $aylik ? array_merge( [ sprintf( 'Toplam (%d ay)', count( $aylik ) ), '' ], $top ) : null,
			'not'      => 'Tüketim = gerçekten harcanan. Maliyet = tüketim + faiz. Nakit çıkışı = hesaptan çıkan her şey (kredi taksiti, kart ödemesi ve yatırım dahil). Kart ödemesi tüketime yazılmaz: harcama zaten kendi kategorisinde girildi.',
		];
	}

	/**
	 * Ay × kategori (veya alt kategori) pivotu, seçili görünümde.
	 * Alt satır, özet tablosundaki aynı görünüm toplamıyla karşılaştırılır.
	 */
	public static function tablo_pivot( int $yil, string $gorunum, string $seviye = 'kategori' ): array {
		global $wpdb;
		$gorunum = self::gorunum( $gorunum );
		$alt     = 'alt' === $seviye;
		$tipler  = self::GORUNUMLER[ $gorunum ][1];

		$e = DHF_Schema::table( 'islem_etki' );
		$i = DHF_Schema::table( 'islem' );
		$d = DHF_Schema::table( 'donem' );
		$c = DHF_Schema::table( 'kategori' );
		$k = DHF_Schema::table( 'kalem' );

		$in   = implode( ',', array_fill( 0, count( $tipler ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT dn.ay, COALESCE(kt.ad, '(kategorisiz)') AS kategori, COALESCE(kt.tur, s.tur) AS kat_tur,
					COALESCE(kl.ad, s.aciklama) AS alt_ad, SUM(e.tutar) AS t
			 FROM {$e} e
			 INNER JOIN {$i} s ON s.id = e.islem_id
			 INNER JOIN {$d} dn ON dn.id = s.donem_id
			 LEFT JOIN {$c} kt ON kt.id = s.kategori_id
			 LEFT JOIN {$k} kl ON kl.id = s.kalem_id
			 WHERE dn.yil = %d AND s.durum <> 'planlanan' AND e.etki_tipi IN ({$in})
			 GROUP BY dn.ay, kategori, kat_tur, alt_ad
			 HAVING t <> 0",
			$yil, ...$tipler
		) );

		$aylik = self::aylik( $yil );
		$aylar = array_keys( $aylik );

		$grup = [];
		foreach ( $rows as $r ) {
			$anahtar = $r->kat_tur . "\0" . $r->kategori . ( $alt ? "\0" . $r->alt_ad : "" );
			$grup[ $anahtar ] ??= [ 'kategori' => $r->kategori, 'alt' => $r->alt_ad, 'tur' => $r->kat_tur, 'ay' => [] ];
			$grup[ $anahtar ]['ay'][ (int) $r->ay ] = ( $grup[ $anahtar ]['ay'][ (int) $r->ay ] ?? 0.0 ) + (float) $r->t;
		}
		// En büyük toplam üstte.
		uasort( $grup, static fn( $x, $y ) => array_sum( $y['ay'] ) <=> array_sum( $x['ay'] ) );

		$kol = $alt ? [ 'Kategori', 'Alt kategori' ] : [ 'Kategori' ];
		foreach ( $aylar as $ay ) {
			$kol[] = self::ay_kisa( $ay );
		}
		$kol[] = 'Toplam';

		$satirlar = [];
		foreach ( $grup as $g ) {
			$s = $alt ? [ $g['kategori'], $g['alt'] ] : [ $g['kategori'] ];
			foreach ( $aylar as $ay ) {
				$s[] = isset( $g['ay'][ $ay ] ) ? round( $g['ay'][ $ay ], 2 ) : null;
			}
			$s[]        = round( array_sum( $g['ay'] ), 2 );
			$satirlar[] = $s;
		}

		$altsatir = $alt ? [ 'Toplam', '' ] : [ 'Toplam' ];
		$genel    = 0.0;
		foreach ( $aylar as $ay ) {
			$v          = self::gorunum_tutar( $aylik[ $ay ]['t'], $gorunum );
			$altsatir[] = $v;
			$genel     += $v;
		}
		$altsatir[] = round( $genel, 2 );

		// Kendi kendini doğrulama: pivotun satır toplamı özetteki görünüm toplamına eşit olmalı.
		$pivot_top = 0.0;
		foreach ( $grup as $g ) {
			$pivot_top += array_sum( $g['ay'] );
		}
		$tutarsiz = abs( $pivot_top - $genel ) > 0.005;

		[ $etiket, , $soru ] = self::GORUNUMLER[ $gorunum ];
		return [
			'baslik'   => sprintf( '%d — %s görünümü, %s bazında', $yil, $etiket, $alt ? 'alt kategori' : 'kategori' ),
			'kolonlar' => $kol,
			'satirlar' => $satirlar,
			'alt'      => $aylar ? $altsatir : null,
			'not'      => $soru . ( 'nakit' === $gorunum ? ' Kredi taksiti, kart ödemesi ve yatırım dahil.' : '' ),
			'hata'     => $tutarsiz ? sprintf( 'Pivot toplamı (%s) özet toplamıyla (%s) tutmuyor.', number_format( $pivot_top, 2, ',', '.' ), number_format( $genel, 2, ',', '.' ) ) : null,
		];
	}

	/** Nakit ihtiyacı (§3.7): gelir − nakit çıkışı, ek kaynak ve yıl içi kümülatif. */
	public static function tablo_nakit( int $yil ): array {
		$aylik = self::aylik( $yil );
		$ek    = self::ek_kaynak( $yil );
		$ek_var = (bool) $ek;

		$kol = [ 'Ay', 'Gelir', 'Nakit çıkışı (yatırım hariç)', 'Yatırım', 'Fark' ];
		if ( $ek_var ) {
			$kol[] = 'Ek kaynak';
			$kol[] = 'Aylık nakit ihtiyacı';
		}
		$kol[] = 'Kümülatif';
		$kol[] = 'Nakitsiz tüketim (karta)';

		$satirlar = [];
		$kum      = 0.0;
		$top      = [ 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0 ];
		foreach ( $aylik as $ay => $a ) {
			$t     = $a['t'];
			$disi  = $t['nakit_cikisi'] - $t['yatirim_alimi'];
			$fark  = $t['nakit_ihtiyaci'];
			$e     = $ek[ $ay ] ?? 0.0;
			$net   = $fark - $e;
			$kum  += $ek_var ? $net : $fark;
			$s     = [ DHF_Takvim::AYLAR[ $ay ], $t['gelir'], round( $disi, 2 ), $t['yatirim_alimi'], $fark ];
			if ( $ek_var ) {
				$s[] = $e;
				$s[] = round( $net, 2 );
			}
			$s[]        = round( $kum, 2 );
			$s[]        = $t['borc_artisi'];
			$satirlar[] = $s;

			$top[0] += $t['gelir'];
			$top[1] += $disi;
			$top[2] += $t['yatirim_alimi'];
			$top[3] += $fark;
			$top[4] += $e;
			$top[5] += $net;
			$top[6] += $t['borc_artisi'];
		}

		$alt = [ sprintf( 'Toplam (%d ay)', count( $aylik ) ), $top[0], $top[1], $top[2], $top[3] ];
		if ( $ek_var ) {
			$alt[] = $top[4];
			$alt[] = $top[5];
		}
		$alt[] = round( $kum, 2 );
		$alt[] = $top[6];

		return [
			'baslik'   => sprintf( '%d — nakit ihtiyacı', $yil ),
			'kolonlar' => $kol,
			'satirlar' => $satirlar,
			'alt'      => $aylik ? $alt : null,
			'not'      => 'Fark = gelir − hesaptan çıkan her şey (yatırım dahil). Eksi rakam, o ay gelirin yetmediği ve açığın borçla ya da birikimle kapandığı anlamına gelir. "Nakitsiz tüketim": kartla ödenen ve o ay hesaptan para çıkarmayan harcama — kart borcuna eklenir.',
		];
	}

	/** İki yılın aynı görünümde ay ay karşılaştırması. */
	public static function tablo_yil( int $yil, int $onceki, string $gorunum ): array {
		$gorunum = self::gorunum( $gorunum );
		$a = self::aylik( $onceki );
		$b = self::aylik( $yil );

		$satirlar = [];
		$ta = $tb = 0.0;
		$ortak = 0;
		foreach ( range( 1, 12 ) as $ay ) {
			$va = isset( $a[ $ay ] ) ? self::gorunum_tutar( $a[ $ay ]['t'], $gorunum ) : null;
			$vb = isset( $b[ $ay ] ) ? self::gorunum_tutar( $b[ $ay ]['t'], $gorunum ) : null;
			$fark = ( null !== $va && null !== $vb ) ? round( $vb - $va, 2 ) : null;
			$yuzde = ( null !== $fark && abs( (float) $va ) > 0.005 ) ? sprintf( '%+.1f%%', $fark / $va * 100 ) : '';
			if ( null !== $fark ) {
				$ta += $va;
				$tb += $vb;
				++$ortak;
			}
			$satirlar[] = [ DHF_Takvim::AYLAR[ $ay ], $va, $vb, $fark, $yuzde ];
		}

		[ $etiket, , $soru ] = self::GORUNUMLER[ $gorunum ];
		return [
			'baslik'   => sprintf( '%d / %d — %s görünümü', $onceki, $yil, $etiket ),
			'kolonlar' => [ 'Ay', (string) $onceki, (string) $yil, 'Fark', '%' ],
			'satirlar' => $satirlar,
			'alt'      => $ortak ? [ sprintf( 'İki yılda da verisi olan %d ay', $ortak ), round( $ta, 2 ), round( $tb, 2 ), round( $tb - $ta, 2 ),
				abs( $ta ) > 0.005 ? sprintf( '%+.1f%%', ( $tb - $ta ) / $ta * 100 ) : '' ] : null,
			'not'      => $soru . ' Toplam yalnızca iki yılda da verisi olan ayları karşılaştırır. ' . $onceki . ' verisi 0.6\'daki tam aktarımla gelecek.',
		];
	}

	/* ---------------------------------------------------------------- */
	/* Pano ve Gösterge Paneli — "bu ay ne yapmam lazım"                 */
	/* ---------------------------------------------------------------- */

	/**
	 * Önümüzdeki günlerde elle ödenecek giderler (§5.3 Bölge 1).
	 * Otomatik talimatlılar listede yok. Bu ay zaten gerçekleşmiş olan kalem düşer.
	 *
	 * @return array<int,array{ad:string,tarih:string,tutar:?float}>
	 */
	public static function elle_odenecekler( int $pencere = 10, int $limit = 4 ): array {
		global $wpdb;
		$bugun = current_time( 'Y-m-d' );
		$y     = (int) substr( $bugun, 0, 4 );
		$a     = (int) substr( $bugun, 5, 2 );

		$adaylar = [];
		foreach ( DHF_Kalem::donem_icin( $y, $a ) as $k ) {
			if ( 'elle' === $k->odeme_sekli && 'gelir' !== $k->tur && $k->varsayilan_gun ) {
				$adaylar[ (int) $k->id ] = $k;
			}
		}
		if ( ! $adaylar ) {
			return [];
		}
		$vadeler = self::vadeler( array_map( static fn( $k ) => (int) $k->varsayilan_gun, $adaylar ), $bugun, $pencere );

		$i = DHF_Schema::table( 'islem' );
		$out = [];
		foreach ( $vadeler as $id => $tarih ) {
			// Vadenin ayında zaten ödendiyse listede yok.
			$odendi = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$i} WHERE kalem_id = %d AND durum = 'gerceklesti' AND tutar > 0
				   AND tarih BETWEEN %s AND LAST_DAY(%s)",
				$id, substr( $tarih, 0, 8 ) . '01', $tarih
			) );
			if ( $odendi ) {
				continue;
			}
			$k     = $adaylar[ $id ];
			$tutar = null !== $k->varsayilan_tutar ? (float) $k->varsayilan_tutar : null;
			if ( null === $tutar ) {
				$son   = $wpdb->get_var( $wpdb->prepare(
					"SELECT tutar FROM {$i} WHERE kalem_id = %d AND durum = 'gerceklesti' AND tutar > 0 ORDER BY tarih DESC LIMIT 1", $id ) );
				$tutar = null === $son ? null : (float) $son;
			}
			$out[] = [ 'ad' => (string) $k->ad, 'tarih' => $tarih, 'tutar' => $tutar ];
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Pano kutusu ve Gösterge Paneli'nin ortak verisi.
	 * Sorgular: dönem, toplamlar, açık kalan aylar, bekleyen aktarım, elle ödenecekler.
	 */
	public static function bu_ay(): array {
		global $wpdb;
		$y = (int) current_time( 'Y' );
		$a = (int) current_time( 'n' );

		$donem    = DHF_Islem::donem( $y, $a, false );
		$satirlar = $donem ? DHF_Islem::satirlar( (int) $donem->id ) : [];
		$satir    = count( $satirlar );
		// Deniz Hesap aynası dışındaki satırlar: aylık taslak üretilmiş mi?
		$defter   = count( array_filter( $satirlar, static fn( $s ) => 'deniz_hesap' !== $s->kaynak ) );

		$d = DHF_Schema::table( 'donem' );
		$i = DHF_Schema::table( 'islem' );
		// Bu aydan önceki, içinde satır olan ama kesinleşmemiş aylar.
		$acik = $wpdb->get_results( $wpdb->prepare(
			"SELECT dn.yil, dn.ay FROM {$d} dn
			 WHERE (dn.yil < %d OR (dn.yil = %d AND dn.ay < %d)) AND dn.durum <> 'kesinlesti'
			   AND EXISTS (SELECT 1 FROM {$i} s WHERE s.donem_id = dn.id)
			 ORDER BY dn.yil, dn.ay",
			$y, $y, $a
		) );

		// Bu ayda satır yoksa rakamlar için son verili ayı göster (etiketiyle).
		$rakam_donem = ( $donem && $satir ) ? $donem : $wpdb->get_row(
			"SELECT dn.* FROM {$d} dn WHERE EXISTS (SELECT 1 FROM {$i} s WHERE s.donem_id = dn.id AND s.durum <> 'planlanan')
			 ORDER BY dn.yil DESC, dn.ay DESC LIMIT 1"
		);

		$t = $rakam_donem ? DHF_Islem::toplamlar( (int) $rakam_donem->id ) : null;
		$gecen = null;
		if ( $rakam_donem ) {
			[ $gy, $ga ] = DHF_Takvim::onceki( (int) $rakam_donem->yil, (int) $rakam_donem->ay );
			$gd    = DHF_Islem::donem( $gy, $ga, false );
			$gecen = $gd ? DHF_Islem::toplamlar( (int) $gd->id ) : null;
			if ( $gecen && ! array_filter( $gecen ) ) {
				$gecen = null;
			}
		}

		$aktarim = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DHF_Schema::table( 'aktarim' ) . " WHERE durum = 'onizleme'" );

		return [
			'yil'         => $y,
			'ay'          => $a,
			'donem'       => $donem,
			'satir'       => $satir,
			'defter'      => $defter,
			'bekleyen'    => ( $donem && $satir ) ? DHF_Islem::bekleyen_sayisi( (int) $donem->id ) : 0,
			'mukerrer'    => ( $donem && $satir ) ? DHF_Islem::mukerrer_sayisi( (int) $donem->id ) : 0,
			'acik_aylar'  => $acik,
			'rakam_donem' => $rakam_donem,
			't'           => $t,
			'gecen'       => $gecen,
			'aktarim'     => $aktarim,
			'elle'        => self::elle_odenecekler(),
			'alacak'      => DHF_Deniz_Hesap::bekleyen_alacak(),
			'tarihsiz'    => DHF_Deniz_Hesap::tarihsiz(),
		];
	}
}
