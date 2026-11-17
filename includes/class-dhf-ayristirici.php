<?php
/**
 * Aktarım ayrıştırıcısı — WordPress'e bağımlı değildir, testte doğrudan çalışır.
 *
 * Kurallar (plan §2.9, §3.6):
 *  - Hatalı tarih/sayı OTOMATİK DÜZELTİLMEZ; null döner, satır inceleme kuyruğuna gider.
 *  - Tarih: GG.AA.YYYY ve GG/AA/YYYY birlikte kabul edilir.
 *  - Tutar: Türk biçimi ("1.785,0", "86.566,9"), metin olarak gelir.
 *  - Sütunlar konuma göre değil başlık adına göre bulunur.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Ayristirici {

	/** Makul tarih aralığı. 0926 ve 1927 gibi yazım hataları bunun dışında kalır. */
	public const YIL_MIN = 2000;
	public const YIL_MAX = 2100;

	/** Eşleştirme profilleri. 2025 profili 0.6'da eklenecek. */
	public const PROFILLER = [
		'2026' => [
			'etiket'  => '2026 sekmesi (Tarih · Tür · Kategori · Açıklama · Tutar)',
			'sutunlar' => [
				'tarih'    => [ 'tarih' ],
				'tur'      => [ 'tür', 'tur' ],
				'kategori' => [ 'kategori' ],
				'aciklama' => [ 'açıklama', 'aciklama' ],
				'tutar'    => [ 'tutar' ],
			],
		],
	];

	public const TURLER = [
		'gelir'   => 'gelir',
		'gider'   => 'gider',
		'yatırım' => 'yatirim',
		'yatirim' => 'yatirim',
	];

	/**
	 * Türkçe duyarlı küçük harf + boşluk sadeleştirme. Eşleştirme anahtarı budur.
	 * "İgdaş doğalgaz  HB " ile "igdaş doğalgaz hb" aynı anahtarı verir.
	 */
	public static function normalize( string $s ): string {
		$s = str_replace( [ 'İ', 'I' ], [ 'i', 'ı' ], $s );
		$s = mb_strtolower( $s, 'UTF-8' );
		$s = preg_replace( '/\s+/u', ' ', $s ) ?? $s;
		return trim( $s );
	}

	/** "15.03.2026" | "30/05/2026" -> "2026-03-15". Geçersizse null — düzeltme yapılmaz. */
	public static function tarih( string $ham ): ?string {
		$ham = trim( $ham );
		if ( ! preg_match( '#^(\d{1,2})[./](\d{1,2})[./](\d{4})$#', $ham, $m ) ) {
			return null;
		}
		[ , $g, $a, $y ] = array_map( 'intval', $m );
		if ( $y < self::YIL_MIN || $y > self::YIL_MAX || ! checkdate( $a, $g, $y ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', $y, $a, $g );
	}

	/** "1.785,0" -> 1785.0 · "86.566,9" -> 86566.9 · "-500" -> -500.0. Geçersizse null. */
	public static function tutar( string $ham ): ?float {
		$s = trim( str_replace( [ '₺', 'TL', "\u{00A0}", ' ' ], '', $ham ) );
		if ( '' === $s ) {
			return null;
		}
		if ( str_contains( $s, ',' ) ) {
			// Türk biçimi: nokta binlik, virgül ondalık.
			$s = str_replace( '.', '', $s );
			$s = str_replace( ',', '.', $s );
		} elseif ( preg_match( '/^-?\d{1,3}(\.\d{3})+$/', $s ) ) {
			// "15.000" — virgülsüz binlik ayraçlı tam sayı.
			$s = str_replace( '.', '', $s );
		}
		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $s ) ) {
			return null;
		}
		return round( (float) $s, 2 );
	}

	/**
	 * CSV metnini satırlara ayırır. Ayraç (virgül / noktalı virgül / sekme) kendiliğinden bulunur.
	 *
	 * @return array{basliklar:string[],satirlar:array<int,array<int,string>>}
	 *         satirlar: kaynak satır no (1 = başlık) => hücreler
	 */
	public static function csv( string $metin ): array {
		$metin = preg_replace( '/^\xEF\xBB\xBF/', '', $metin ) ?? $metin;
		$ilk   = strtok( $metin, "\n" ) ?: '';
		$ayrac = ',';
		$say   = [ ',' => substr_count( $ilk, ',' ), ';' => substr_count( $ilk, ';' ), "\t" => substr_count( $ilk, "\t" ) ];
		arsort( $say );
		if ( reset( $say ) > 0 ) {
			$ayrac = (string) key( $say );
		}

		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $metin );
		rewind( $fh );

		$basliklar = [];
		$satirlar  = [];
		$no        = 0;
		while ( false !== ( $hucreler = fgetcsv( $fh, 0, $ayrac, '"', '' ) ) ) {
			++$no;
			if ( 1 === $no ) {
				$basliklar = array_map( static fn( $h ): string => trim( (string) $h ), $hucreler );
				continue;
			}
			$hucreler = array_map( static fn( $h ): string => (string) $h, $hucreler );
			if ( '' === trim( implode( '', $hucreler ) ) ) {
				continue; // tamamen boş satır
			}
			$satirlar[ $no ] = $hucreler;
		}
		fclose( $fh );

		return [ 'basliklar' => $basliklar, 'satirlar' => $satirlar ];
	}

	/**
	 * Profil sütunlarını başlık adına göre bulur.
	 *
	 * @param string[] $basliklar
	 * @return array<string,int>|string  alan => indeks, ya da eksik sütunun adı
	 */
	public static function sutun_haritasi( string $profil, array $basliklar ): array|string {
		$p = self::PROFILLER[ $profil ] ?? null;
		if ( ! $p ) {
			return 'profil';
		}
		$norm = array_map( [ self::class, 'normalize' ], $basliklar );
		$out  = [];
		foreach ( $p['sutunlar'] as $alan => $adlar ) {
			$idx = false;
			foreach ( $adlar as $ad ) {
				$idx = array_search( self::normalize( $ad ), $norm, true );
				if ( false !== $idx ) {
					break;
				}
			}
			if ( false === $idx ) {
				return $alan;
			}
			$out[ $alan ] = (int) $idx;
		}
		return $out;
	}

	/**
	 * Tek satırı çözümler. Hiçbir alanı tahmin etmez; çözemediğini hata listesine yazar.
	 *
	 * @param array<int,string> $hucreler
	 * @param array<string,int> $harita
	 * @return array{ham:array<string,string>,tarih:?string,tur:?string,tutar:?float,hatalar:string[]}
	 */
	public static function satir( array $hucreler, array $harita ): array {
		$ham = [];
		foreach ( $harita as $alan => $idx ) {
			$ham[ $alan ] = (string) ( $hucreler[ $idx ] ?? '' );
		}

		$hatalar = [];
		$tarih   = self::tarih( $ham['tarih'] );
		if ( null === $tarih ) {
			$hatalar[] = 'hatali_tarih';
		}
		$tur = self::TURLER[ self::normalize( $ham['tur'] ) ] ?? null;
		if ( null === $tur ) {
			$hatalar[] = 'hatali_tur';
		}
		$tutar = self::tutar( $ham['tutar'] );
		if ( null === $tutar ) {
			$hatalar[] = 'hatali_tutar';
		} elseif ( 0.0 === $tutar ) {
			$hatalar[] = 'sifir_tutar';
		} elseif ( $tutar < 0 ) {
			$hatalar[] = 'negatif_tutar';
		}

		return [ 'ham' => $ham, 'tarih' => $tarih, 'tur' => $tur, 'tutar' => $tutar, 'hatalar' => $hatalar ];
	}

	/**
	 * Kaynak satırın kimliği. Aynı dosya iki kez yüklenince bu anahtar tekrar aktarımı engeller.
	 * $tekrar: dosya içinde birebir aynı satırın kaçıncı görülüşü (1, 2, ...). Mükerrer satırın
	 * ikinci kopyası farklı anahtar alır; mükerrer olduğu ayrıca işaretlenir.
	 *
	 * @param array<string,string> $ham
	 */
	public static function satir_anahtari( string $profil, array $ham, int $tekrar ): string {
		ksort( $ham );
		$parca = array_map( static fn( $v ): string => self::normalize( (string) $v ), $ham );
		return md5( $profil . '|' . implode( '|', $parca ) . '|' . $tekrar );
	}

	/** Birebir aynılık anahtarı — mükerrer tespiti için (tekrar sayısı içermez). */
	public static function icerik_anahtari( array $ham ): string {
		ksort( $ham );
		return md5( implode( '|', array_map( static fn( $v ): string => self::normalize( (string) $v ), $ham ) ) );
	}

	/** islem.tekil_anahtar ile aynı formül (§2.7): tarih|tür|kategori|açıklama|tutar. */
	public static function tekil_anahtar( string $tarih, string $tur, string $kategori, string $aciklama, float $tutar ): string {
		return md5( implode( '|', [
			$tarih, $tur, self::normalize( $kategori ), self::normalize( $aciklama ), number_format( $tutar, 2, '.', '' ),
		] ) );
	}
}
