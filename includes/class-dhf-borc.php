<?php
/**
 * Borç modülü (0.7).
 *
 * Üç tür borç tek yerde toplanır: kredi kartları, kredili mevduat hesapları
 * (KMH / ek hesap) ve krediler. Plan §4.2 ve §5.5.
 *
 * İki rakam kaynağı var ve BİRBİRİNE KARIŞTIRILMAZ:
 *
 *   hesaplanan  — defterdeki satırlardan yürütülen bakiye. Kart için anlamlı:
 *                 açılış + harcama + faiz − ödeme. Her görüntülemede yeniden
 *                 hesaplanır, hiçbir yere yazılmaz (plan §2.8).
 *   bildirilen  — bankanın söylediği rakam; `borc_foto` tablosuna elle girilir.
 *                 Ekstre borcu, asgari ödeme, son ödeme tarihi buradan gelir.
 *
 * Ekran ikisini yan yana gösterir ve farkı yazar. Sistem kendi bakiyesini
 * bankanın yerine koymaz; fark varsa bu bir bilgidir, hata değil: kart
 * ekstresinin kesim günü ayın 7'si, 13'ü, 14'ü, 15'i veya 19'u olabilir,
 * defter ise takvim ayına göre toplar.
 *
 * KMH'de bakiye defterden yürütülemez: hesaba giren para (maaş, kira, kendi
 * hesapları arası aktarım) defterin tamamında yok. Bu yüzden KMH yalnızca
 * bildirilen rakamla izlenir.
 *
 * `bakiye_tipi` değerlerine 0.7'de `acilis` eklendi: yılın devir bakiyesi.
 * Şema değişmedi — `borc_foto.bakiye_tipi` zaten varchar.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Borc {

	/** Bakiye tipleri (plan §4.2) + 0.7'de eklenen `acilis`. */
	public const BAKIYE_TIPLERI = [
		'acilis'             => 'Açılış (devir)',
		'ekstre_borcu'       => 'Ekstre borcu',
		'guncel_borc'        => 'Güncel borç',
		'kullanilan_anapara' => 'Kullanılan anapara',
		'kalan_anapara'      => 'Kalan anapara',
		'kapama_bakiyesi'    => 'Kapama bakiyesi',
	];

	/** Hangi borç tipinde hangi bakiye tipleri anlamlı. */
	public const TIP_BAKIYE = [
		// Sıra önemli: ozet() tersten dener, yani en sondaki en çok tercih edilir.
		// Ekstre borcu bankanın resmî rakamıdır; Sheets'ten gelen "güncel borç"
		// ondan sonra gelir (0.7.3).
		'kredi_karti' => [ 'acilis', 'guncel_borc', 'ekstre_borcu' ],
		'kmh'         => [ 'acilis', 'guncel_borc', 'kullanilan_anapara' ],
		'kredi'       => [ 'kalan_anapara', 'kapama_bakiyesi' ],
		'sahis'       => [ 'guncel_borc' ],
	];

	public const TIPLER = [
		'kredi_karti' => 'Kredi kartı',
		'kmh'         => 'KMH / ek hesap',
		'kredi'       => 'Kredi',
		'sahis'       => 'Şahıs borcu',
	];

	/* ------------------------------------------------------------------ */
	/* Saf fonksiyonlar — WordPress'e ihtiyaç duymaz, testlidir            */
	/* ------------------------------------------------------------------ */

	/**
	 * Aylık hareketten bakiye yürütür: açılış + harcama + faiz − ödeme.
	 *
	 * @param array<int,array{harcama:float,faiz:float,odeme:float}> $aylik DHF_Kart::aylik() çıktısı
	 * @return array<int,array{acilis:float,harcama:float,faiz:float,odeme:float,kapanis:float,hareket:bool}>
	 */
	public static function yuru( array $aylik, float $acilis ): array {
		$out     = [];
		$bakiye  = round( $acilis, 2 );
		for ( $a = 1; $a <= 12; $a++ ) {
			$r   = $aylik[ $a ] ?? [ 'harcama' => 0.0, 'faiz' => 0.0, 'odeme' => 0.0 ];
			$h   = round( (float) ( $r['harcama'] ?? 0 ), 2 );
			$f   = round( (float) ( $r['faiz'] ?? 0 ), 2 );
			$o   = round( (float) ( $r['odeme'] ?? 0 ), 2 );
			$kap = round( $bakiye + $h + $f - $o, 2 );

			$out[ $a ] = [
				'acilis'  => $bakiye,
				'harcama' => $h,
				'faiz'    => $f,
				'odeme'   => $o,
				'kapanis' => $kap,
				'hareket' => (bool) ( $h || $f || $o ),
			];
			$bakiye = $kap;
		}
		return $out;
	}

	/**
	 * Faiz alt kategorisinin türü. Kart faizi ile kredili mevduat faizini
	 * ayırmak için: ikisi aynı toplamda görünürse "hangi borç pahalı"
	 * sorusu cevaplanamaz.
	 *
	 * @return string kart|kmh|avans|diger
	 */
	public static function faiz_turu( string $ad ): string {
		$n = mb_strtolower( $ad, 'UTF-8' );
		if ( str_contains( $n, 'avans' ) ) {
			return 'avans';
		}
		if ( str_contains( $n, 'kmh' ) || str_contains( $n, 'ek hesap' ) ) {
			return 'kmh';
		}
		if ( str_contains( $n, 'kart' ) ) {
			return 'kart';
		}
		return 'diger';
	}

	/**
	 * Kredilerin önümüzdeki aylara düşen taksit yükü.
	 *
	 * @param array<int,array{ad:string,aylik_taksit:float,kalan:int}> $krediler
	 * @return array{aylar:array<string,float>,toplam:float}
	 */
	public static function kredi_takvimi( array $krediler, int $yil, int $ay, int $pencere = 12 ): array {
		$aylar  = [];
		$toplam = 0.0;
		foreach ( $krediler as $k ) {
			$kalan = max( 0, (int) $k['kalan'] );
			$tut   = round( (float) $k['aylik_taksit'], 2 );
			if ( ! $kalan || $tut <= 0 ) {
				continue;
			}
			$toplam += $kalan * $tut;
			$y       = $yil;
			$a       = $ay;
			for ( $n = 1; $n <= min( $kalan, $pencere ); $n++ ) {
				$anahtar           = sprintf( '%04d-%02d', $y, $a );
				$aylar[ $anahtar ] = round( ( $aylar[ $anahtar ] ?? 0.0 ) + $tut, 2 );
				[ $y, $a ]         = DHF_Takvim::sonraki( $y, $a );
			}
		}
		ksort( $aylar );
		return [ 'aylar' => $aylar, 'toplam' => round( $toplam, 2 ) ];
	}

	/**
	 * Kalan taksit sayısı: bitiş tarihinden ve ödenen taksit numarasından.
	 * İkisi de yoksa null döner — tahmin edilmez (plan §2.3).
	 */
	public static function kalan_taksit( ?int $taksit_no, ?int $taksit_toplam, ?string $bitis, string $bugun ): ?int {
		if ( $taksit_no && $taksit_toplam && $taksit_toplam >= $taksit_no ) {
			return $taksit_toplam - $taksit_no;
		}
		if ( $bitis && $bitis >= $bugun ) {
			$b = new DateTimeImmutable( substr( $bitis, 0, 10 ) );
			$g = new DateTimeImmutable( substr( $bugun, 0, 10 ) );
			$d = $g->diff( $b );
			return (int) ( $d->y * 12 + $d->m ) + ( $d->d > 0 ? 1 : 0 );
		}
		return null;
	}

	/**
	 * Hangi rakam gösterilecek? Sıra: bankanın bildirdiği → defterden
	 * hesaplanan → kredide kalan taksitten tahmin.
	 *
	 * Hesaplanan bakiye EKSİYSE bu bir bakiye değil, eksik açılış işaretidir:
	 * o kartın yıl içindeki ödemeleri harcamasından fazla, yani yıla devreden
	 * bir borçla başlamış ama devir girilmemiş. Eksi rakamı mutlak değeriyle
	 * borca eklemek toplamı şişirir, sıfır saymak da gizler — bu yüzden
	 * "veri yok" sayılır ve ekranda açılışın girilmesi istenir.
	 *
	 * @return array{0:?float,1:string}
	 */
	public static function bakiye_sec( ?float $bildirilen, ?float $hesaplanan, ?float $tahmin ): array {
		if ( null !== $bildirilen ) {
			return [ $bildirilen, 'bildirilen' ];
		}
		if ( null !== $hesaplanan ) {
			return $hesaplanan < 0 ? [ null, 'acilis_eksik' ] : [ $hesaplanan, 'hesaplanan' ];
		}
		if ( null !== $tahmin ) {
			return [ $tahmin, 'tahmin' ];
		}
		return [ null, 'yok' ];
	}

	/**
	 * Kalan taksidin geçmiş/gelecek bir aydaki hali.
	 *
	 * `kalan_taksit()` bugünün rakamını verir. Geçmiş bir ayın borcunu bugünün
	 * taksit sayısıyla hesaplamak yanlış olur: Ocak 2026'da konut kredisinin
	 * 77 değil 87 taksidi kalmıştı. Taksitler aylık olduğu için fark, iki ay
	 * arasındaki ay sayısıdır. Toplam taksit biliniyorsa onunla sınırlanır.
	 */
	public static function kalan_taksit_ayinda( int $kalan_bugun, ?int $taksit_toplam, int $yil, int $ay, int $bugun_yil, int $bugun_ay ): int {
		$fark  = ( $bugun_yil - $yil ) * 12 + ( $bugun_ay - $ay );
		$kalan = $kalan_bugun + $fark;
		if ( $taksit_toplam ) {
			$kalan = min( $kalan, $taksit_toplam );
		}
		return max( 0, $kalan );
	}

	/** Limit kullanımı yüzdesi; limit yoksa null. */
	public static function kullanim( ?float $bakiye, ?float $limit ): ?float {
		if ( null === $bakiye || ! $limit || $limit <= 0 ) {
			return null;
		}
		return round( 100 * abs( $bakiye ) / $limit, 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Veritabanı                                                          */
	/* ------------------------------------------------------------------ */

	/** @return object[] */
	public static function borclar( bool $sadece_aktif = false ): array {
		global $wpdb;
		$b = DHF_Schema::table( 'borc' );
		$k = DHF_Schema::table( 'kurum' );
		$h = DHF_Schema::table( 'hesap' );
		$where = $sadece_aktif ? 'WHERE b.aktif = 1' : '';
		return $wpdb->get_results(
			"SELECT b.*, k.ad AS kurum, h.tip AS hesap_tip, h.ad AS hesap_ad
			 FROM {$b} b LEFT JOIN {$k} k ON k.id = b.kurum_id LEFT JOIN {$h} h ON h.id = b.hesap_id
			 {$where}
			 ORDER BY b.aktif DESC, FIELD(b.tip,'kredi_karti','kmh','kredi','sahis'), k.ad, b.ad"
		);
	}

	public static function bul( int $id ): ?object {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DHF_Schema::table( 'borc' ) . ' WHERE id = %d', $id ) );
		return $r ?: null;
	}

	/**
	 * Bir borcun yıl içindeki fotoğrafları.
	 *
	 * @return array<int,array<string,object>> [ay][bakiye_tipi] => satır
	 */
	public static function fotolar( int $borc_id, int $yil ): array {
		global $wpdb;
		$f = DHF_Schema::table( 'borc_foto' );
		$d = DHF_Schema::table( 'donem' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.*, dn.ay, dn.yil FROM {$f} f JOIN {$d} dn ON dn.id = f.donem_id
			 WHERE f.borc_id = %d AND dn.yil = %d ORDER BY dn.ay",
			$borc_id, $yil
		) );
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->ay ][ (string) $r->bakiye_tipi ] = $r;
		}
		return $out;
	}

	/** Bütün borçların o yıldaki fotoğrafları: [borc_id][ay][tip]. */
	public static function fotolar_hepsi( int $yil ): array {
		global $wpdb;
		$f = DHF_Schema::table( 'borc_foto' );
		$d = DHF_Schema::table( 'donem' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.*, dn.ay FROM {$f} f JOIN {$d} dn ON dn.id = f.donem_id WHERE dn.yil = %d ORDER BY dn.ay",
			$yil
		) );
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->borc_id ][ (int) $r->ay ][ (string) $r->bakiye_tipi ] = $r;
		}
		return $out;
	}

	/**
	 * Fotoğraf yazar (upsert). Tutar null ise satır silinir — boş hücre
	 * "veri yok" demektir, sıfır demek değildir (plan §5.5).
	 *
	 * @param array{tutar:?float,banka_bildirimi:?float,asgari_odeme:?float,odeme:?float,faiz:?float,son_odeme_tarihi:?string,not:?string} $veri
	 */
	public static function foto_yaz( int $borc_id, int $donem_id, string $bakiye_tipi, array $veri ): bool {
		global $wpdb;
		if ( ! isset( self::BAKIYE_TIPLERI[ $bakiye_tipi ] ) || ! $borc_id || ! $donem_id ) {
			return false;
		}
		$f    = DHF_Schema::table( 'borc_foto' );
		$var  = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$f} WHERE borc_id = %d AND donem_id = %d AND bakiye_tipi = %s",
			$borc_id, $donem_id, $bakiye_tipi
		) );

		$bos = null === ( $veri['tutar'] ?? null ) && null === ( $veri['banka_bildirimi'] ?? null )
			&& null === ( $veri['asgari_odeme'] ?? null ) && null === ( $veri['faiz'] ?? null );
		if ( $bos ) {
			if ( $var ) {
				$wpdb->delete( $f, [ 'id' => (int) $var ], [ '%d' ] );
			}
			return true;
		}

		$satir = [
			'borc_id'          => $borc_id,
			'donem_id'         => $donem_id,
			'bakiye_tipi'      => $bakiye_tipi,
			'tutar'            => $veri['tutar'] ?? null,
			'banka_bildirimi'  => $veri['banka_bildirimi'] ?? null,
			'asgari_odeme'     => $veri['asgari_odeme'] ?? null,
			'odeme'            => $veri['odeme'] ?? null,
			'faiz'             => $veri['faiz'] ?? null,
			'son_odeme_tarihi' => $veri['son_odeme_tarihi'] ?? null,
			'kontrol_tarihi'   => current_time( 'mysql' ),
			'not_metni'        => $veri['not'] ?? null,
		];
		$bicim = [ '%d', '%d', '%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s' ];

		if ( $var ) {
			return false !== $wpdb->update( $f, $satir, [ 'id' => (int) $var ], $bicim, [ '%d' ] );
		}
		return false !== $wpdb->insert( $f, $satir, $bicim );
	}

	/**
	 * Borcun o yıldaki durumu: defterden yürütülen seyir (yalnız kart) ve
	 * bildirilen rakamlar bir arada.
	 *
	 * @return array{seyir:array,fotolar:array,acilis:float,hesaplanabilir:bool}
	 */
	public static function durum( object $borc, int $yil ): array {
		$fotolar = self::fotolar( (int) $borc->id, $yil );
		$acilis  = 0.0;
		foreach ( $fotolar as $ay => $tipler ) {
			if ( isset( $tipler['acilis'] ) ) {
				$acilis = (float) $tipler['acilis']->tutar;
				break;
			}
		}

		$hesaplanabilir = 'kredi_karti' === (string) $borc->tip && $borc->hesap_id;
		$seyir          = [];
		if ( $hesaplanabilir ) {
			$ozet  = DHF_Kart::ozet( (int) $borc->hesap_id, $yil );
			$seyir = self::yuru( $ozet['aylik'], $acilis );
		}

		return [
			'seyir'          => $seyir,
			'fotolar'        => $fotolar,
			'acilis'         => $acilis,
			'hesaplanabilir' => (bool) $hesaplanabilir,
		];
	}

	/**
	 * Faiz dökümü: alt kategori bazında, kart / KMH / avans ayrımıyla.
	 *
	 * @return array{satirlar:array<int,array<string,mixed>>,gruplar:array<string,float>,toplam:float,gelir:float,oran:?float,aylik:array<int,float>}
	 */
	public static function faiz_dokumu( int $yil ): array {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		$k = DHF_Schema::table( 'kalem' );
		$e = DHF_Schema::table( 'islem_etki' );
		$d = DHF_Schema::table( 'donem' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT kl.id, kl.ad, ROUND(SUM(et.tutar),2) AS toplam, COUNT(*) AS satir
			 FROM {$i} s
			 JOIN {$e} et ON et.islem_id = s.id AND et.etki_tipi = 'faiz_masraf'
			 JOIN {$d} dn ON dn.id = s.donem_id
			 LEFT JOIN {$k} kl ON kl.id = s.kalem_id
			 WHERE dn.yil = %d AND s.durum = 'gerceklesti'
			 GROUP BY kl.id ORDER BY toplam DESC",
			$yil
		) );

		$satirlar = [];
		$gruplar  = [ 'kart' => 0.0, 'kmh' => 0.0, 'avans' => 0.0, 'diger' => 0.0 ];
		$toplam   = 0.0;
		foreach ( $rows as $r ) {
			$tur        = self::faiz_turu( (string) $r->ad );
			$satirlar[] = [ 'ad' => (string) ( $r->ad ?: '—' ), 'toplam' => (float) $r->toplam, 'satir' => (int) $r->satir, 'tur' => $tur ];
			$gruplar[ $tur ] += (float) $r->toplam;
			$toplam          += (float) $r->toplam;
		}
		$gruplar = array_map( static fn( float $v ): float => round( $v, 2 ), $gruplar );

		$aylik_ham = $wpdb->get_results( $wpdb->prepare(
			"SELECT dn.ay, ROUND(SUM(et.tutar),2) AS toplam
			 FROM {$i} s
			 JOIN {$e} et ON et.islem_id = s.id AND et.etki_tipi = 'faiz_masraf'
			 JOIN {$d} dn ON dn.id = s.donem_id
			 WHERE dn.yil = %d AND s.durum = 'gerceklesti' GROUP BY dn.ay ORDER BY dn.ay",
			$yil
		) );
		$aylik = [];
		foreach ( $aylik_ham as $r ) {
			$aylik[ (int) $r->ay ] = (float) $r->toplam;
		}

		$gelir = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT ROUND(SUM(et.tutar),2) FROM {$i} s
			 JOIN {$e} et ON et.islem_id = s.id AND et.etki_tipi = 'gelir'
			 JOIN {$d} dn ON dn.id = s.donem_id
			 WHERE dn.yil = %d AND s.durum = 'gerceklesti'",
			$yil
		) );

		return [
			'satirlar' => $satirlar,
			'gruplar'  => $gruplar,
			'toplam'   => round( $toplam, 2 ),
			'gelir'    => $gelir,
			'oran'     => $gelir > 0 ? round( 100 * $toplam / $gelir, 1 ) : null,
			'aylik'    => $aylik,
		];
	}

	/**
	 * Üst şerit: borç başına o ayın bakiyesi, kaynağı ve güncelliği.
	 *
	 * @return array{satirlar:array<int,array<string,mixed>>,toplam:float,bildirilen:float,hesaplanan:float,eksik:int,guncellenmemis:array<int,string>,acilis_eksik:array<int,string>}
	 */
	public static function ozet( int $yil, int $ay ): array {
		$fotolar  = self::fotolar_hepsi( $yil );
		$satirlar = [];
		$toplam   = 0.0;
		$bildirilen = 0.0;
		$hesaplanan = 0.0;
		$eksik    = 0;
		$guncel_degil = [];
		$acilis_eksik = [];
		$bugun    = current_time( 'Y-m-d' );

		foreach ( self::borclar() as $b ) {
			$tip   = (string) $b->tip;
			$fb    = $fotolar[ (int) $b->id ] ?? [];
			$bakiye = null;
			$kaynak = '—';
			$asgari = null;
			$odeme  = null;
			$faiz   = null;
			$son    = null;
			$onceki = null;

			// 1) Bu ayın bildirilen rakamı
			$sira = self::TIP_BAKIYE[ $tip ] ?? [ 'guncel_borc' ];
			foreach ( array_reverse( $sira ) as $t ) {
				if ( 'acilis' !== $t && isset( $fb[ $ay ][ $t ] ) && null !== $fb[ $ay ][ $t ]->tutar ) {
					$bakiye = (float) $fb[ $ay ][ $t ]->tutar;
					$kaynak = 'bildirilen';
					$asgari = null !== $fb[ $ay ][ $t ]->asgari_odeme ? (float) $fb[ $ay ][ $t ]->asgari_odeme : null;
					$odeme  = null !== $fb[ $ay ][ $t ]->odeme ? (float) $fb[ $ay ][ $t ]->odeme : null;
					$faiz   = null !== $fb[ $ay ][ $t ]->faiz ? (float) $fb[ $ay ][ $t ]->faiz : null;
					$son    = $fb[ $ay ][ $t ]->son_odeme_tarihi;
					break;
				}
			}

			// 2) Yoksa defterden hesaplanan (yalnız kart)
			$bildirilen_tutar = $bakiye;
			$hesaplanan_tutar = null;
			$durum = self::durum( $b, $yil );
			if ( $durum['hesaplanabilir'] && isset( $durum['seyir'][ $ay ] ) ) {
				$hesaplanan_tutar = $durum['seyir'][ $ay ]['kapanis'];
			}
			if ( $durum['hesaplanabilir'] && isset( $durum['seyir'][ $ay ] ) ) {
				$onceki = $durum['seyir'][ $ay ]['acilis'];
				if ( null === $faiz ) {
					$faiz = $durum['seyir'][ $ay ]['faiz'];
				}
				if ( null === $odeme ) {
					$odeme = $durum['seyir'][ $ay ]['odeme'];
				}
			}

			// 3) Kredilerde kalan taksitten tahmin
			$kalan = self::kalan_taksit(
				null !== $b->taksit_no ? (int) $b->taksit_no : null,
				null !== $b->taksit_toplam ? (int) $b->taksit_toplam : null,
				$b->bitis_tarihi ? (string) $b->bitis_tarihi : null,
				$bugun
			);
			$tahmin_tutar = null;
			if ( 'kredi' === $tip && $kalan && $b->aylik_taksit ) {
				$kalan_o_ay   = self::kalan_taksit_ayinda(
					$kalan,
					null !== $b->taksit_toplam ? (int) $b->taksit_toplam : null,
					$yil, $ay,
					(int) current_time( 'Y' ), (int) current_time( 'n' )
				);
				$tahmin_tutar = $kalan_o_ay ? round( $kalan_o_ay * (float) $b->aylik_taksit, 2 ) : null;
				$kalan        = $kalan_o_ay;
			}

			[ $bakiye, $kaynak ] = self::bakiye_sec( $bildirilen_tutar, $hesaplanan_tutar, $tahmin_tutar );

			if ( null === $bakiye ) {
				++$eksik;
				if ( $b->aktif ) {
					$ad_tam = trim( (string) ( $b->kurum ?: '' ) . ' ' . $b->ad );
					if ( 'acilis_eksik' === $kaynak ) {
						$acilis_eksik[] = $ad_tam;
					} else {
						$guncel_degil[] = $ad_tam;
					}
				}
			} else {
				$toplam += abs( $bakiye );
				if ( 'bildirilen' === $kaynak ) {
					$bildirilen += abs( $bakiye );
				} else {
					$hesaplanan += abs( $bakiye );
				}
			}

			$satirlar[] = [
				'borc'    => $b,
				'bakiye'  => $bakiye,
				'kaynak'  => $kaynak,
				'onceki'  => $onceki,
				'asgari'  => $asgari,
				'odeme'   => $odeme,
				'faiz'    => $faiz,
				'son'     => $son,
				'kalan'   => $kalan,
				'kullanim' => self::kullanim( $bakiye, null !== $b->limit_tutar ? (float) $b->limit_tutar : null ),
				'guncel'  => isset( $fb[ $ay ] ) && array_filter( array_keys( $fb[ $ay ] ), static fn( string $t ): bool => 'acilis' !== $t ),
			];
		}

		return [
			'satirlar'       => $satirlar,
			'toplam'         => round( $toplam, 2 ),
			'bildirilen'     => round( $bildirilen, 2 ),
			'hesaplanan'     => round( $hesaplanan, 2 ),
			'eksik'          => $eksik,
			'guncellenmemis' => $guncel_degil,
			'acilis_eksik'   => $acilis_eksik,
		];
	}

	/** Kredilerin takvimi için veri. */
	public static function krediler( string $bugun ): array {
		$out = [];
		foreach ( self::borclar( true ) as $b ) {
			if ( 'kredi' !== (string) $b->tip || ! $b->aylik_taksit ) {
				continue;
			}
			$kalan = self::kalan_taksit(
				null !== $b->taksit_no ? (int) $b->taksit_no : null,
				null !== $b->taksit_toplam ? (int) $b->taksit_toplam : null,
				$b->bitis_tarihi ? (string) $b->bitis_tarihi : null,
				$bugun
			);
			$out[] = [
				'ad'           => trim( (string) ( $b->kurum ?: '' ) . ' ' . $b->ad ),
				'aylik_taksit' => (float) $b->aylik_taksit,
				'kalan'        => (int) ( $kalan ?? 0 ),
				'bitis'        => $b->bitis_tarihi,
			];
		}
		return $out;
	}
}
