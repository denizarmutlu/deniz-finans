<?php
/**
 * Tiplenmiş etkiler.
 *
 * Projenin çekirdek kuralı burada: bir işlemin tutarı, etki tipine göre
 * birden fazla sayaca dağılır. Kart ödemesi nakit çıkışı ve borç azaltımı
 * yaratır ama TÜKETİM GİDERİ YARATMAZ — çift sayım böyle engellenir.
 *
 * Profil kalemde bir kez tanımlanır; işlem her kaydedildiğinde etkiler
 * buradan yeniden üretilir, elle girilmez.
 *
 * 0.6.1 — Karttan ödenen satır: satırın hesabı kredi kartıysa para o ay
 * hesaptan çıkmaz, kart borcu artar. Profilin "nakit çıkışı" etkisi
 * "borç artışı" olur; tüketim, yatırım ve faiz etkileri aynen kalır.
 * Para, kart ödemesi (kart_odeme) yapıldığında çıkar — böylece aynı
 * harcamanın nakdi iki kez sayılmaz.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Etki {

	/** Tüm etki tipleri (plan §2.3). */
	public const TIPLER = [
		'gelir', 'tuketim_gideri', 'nakit_girisi', 'nakit_cikisi',
		'borc_artisi', 'borc_azaltimi', 'faiz_masraf', 'yatirim_alimi',
		'devlet_katkisi', 'transfer',
	];

	/**
	 * Profil -> etki tipleri. Oran, işlem tutarının çarpanıdır.
	 * Anapara/faiz dağılımı bilinmediği için kredi taksiti tek etki üretir (§2.3).
	 */
	private const PROFILLER = [
		'gelir' => [
			'etiket'  => 'Gelir',
			'etkiler' => [ [ 'gelir', 1.0 ], [ 'nakit_girisi', 1.0 ] ],
			'aciklama' => 'Gelire ve nakit girişine yazılır.',
		],
		'tuketim' => [
			'etiket'  => 'Tüketim gideri',
			'etkiler' => [ [ 'tuketim_gideri', 1.0 ], [ 'nakit_cikisi', 1.0 ] ],
			'aciklama' => 'Gerçek harcama. Hem tüketime hem nakit çıkışına yazılır.',
		],
		'kredi_taksit' => [
			'etiket'  => 'Kredi taksiti',
			'etkiler' => [ [ 'nakit_cikisi', 1.0 ] ],
			'aciklama' => 'Tuketim gideri YARATMAZ. Odeme plani girilmisse faiz kismi ayrica faiz_masraf olarak yazilir (G12).',
		],
		'kart_odeme' => [
			'etiket'  => 'Kart / borç ödemesi',
			'etkiler' => [ [ 'nakit_cikisi', 1.0 ], [ 'borc_azaltimi', 1.0 ] ],
			'aciklama' => 'Tüketim gideri YARATMAZ. Harcama zaten kendi kategorisinde giderleşti.',
		],
		'kart_faiz' => [
			'etiket'  => 'Kart / KMH faizi',
			'etkiler' => [ [ 'nakit_cikisi', 1.0 ], [ 'faiz_masraf', 1.0 ] ],
			'aciklama' => 'Karşılığında hiçbir şey alınmayan tek kalem. Ayrı izlenir.',
		],
		'yatirim' => [
			'etiket'  => 'Yatırım alımı',
			'etkiler' => [ [ 'yatirim_alimi', 1.0 ], [ 'nakit_cikisi', 1.0 ] ],
			'aciklama' => 'Gider değil; nakit çıkışı ve varlık artışı.',
		],
		'borc_artisi' => [
			'etiket'  => 'Kartla alışveriş (taksitli)',
			'etkiler' => [ [ 'tuketim_gideri', 1.0 ], [ 'borc_artisi', 1.0 ] ],
			'aciklama' => 'Nakit çıkışı YARATMAZ; para henüz çıkmadı, borç arttı.',
		],
		'borc_kullanimi' => [
			'etiket'  => 'Borç kullanımı (ek kaynak)',
			'etkiler' => [ [ 'nakit_girisi', 1.0 ], [ 'borc_artisi', 1.0 ] ],
			'aciklama' => 'Nakit avans ya da kredi kullanımı: para hesaba girer, borç artar. GELİR YARATMAZ.',
		],
		'transfer' => [
			'etiket'  => 'Hesaplar arası transfer',
			'etkiler' => [ [ 'transfer', 1.0 ] ],
			'aciklama' => 'Hiçbir gelir/gider sayacına girmez.',
		],
	];

	public static function profiller(): array {
		return self::PROFILLER;
	}

	public static function var_mi( string $profil ): bool {
		return isset( self::PROFILLER[ $profil ] );
	}

	public static function etiket( string $profil ): string {
		return self::PROFILLER[ $profil ]['etiket'] ?? $profil;
	}

	public static function aciklama( string $profil ): string {
		return self::PROFILLER[ $profil ]['aciklama'] ?? '';
	}

	/**
	 * Bir işlemin etki satırlarını üretir.
	 *
	 * @return array<int,array{etki_tipi:string,tutar:float}>
	 */
	public static function uret( string $profil, float $tutar, bool $karta = false, float $faiz = 0.0 ): array {
		if ( ! isset( self::PROFILLER[ $profil ] ) ) {
			return [];
		}
		$out = [];
		foreach ( self::PROFILLER[ $profil ]['etkiler'] as [ $tip, $oran ] ) {
			if ( $karta && 'nakit_cikisi' === $tip ) {
				$tip = 'borc_artisi';
			}
			$out[] = [ 'etki_tipi' => $tip, 'tutar' => round( $tutar * $oran, 2 ) ];
		}
		if ( 'kredi_taksit' === $profil && $faiz > 0.0 ) {
			$out[] = [ 'etki_tipi' => 'faiz_masraf', 'tutar' => round( $faiz, 2 ) ];
		}
		return $out;
	}

	/** Satırın hesabı kredi kartı mı? (0.6.1) */
	public static function karta_mi( int $islem_id ): bool {
		global $wpdb;
		return 'kredi_karti' === (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT h.tip FROM ' . DHF_Schema::table( 'islem' ) . ' i JOIN ' . DHF_Schema::table( 'hesap' ) . ' h ON h.id = i.hesap_id WHERE i.id = %d',
			$islem_id
		) );
	}

	/**
	 * Bir işlemin etkilerini siler ve profilden yeniden yazar.
	 * $karta verilmezse satırın hesabından okunur.
	 */
	public static function yaz( int $islem_id, string $profil, float $tutar, ?bool $karta = null ): void {
		global $wpdb;
		$tablo = DHF_Schema::table( 'islem_etki' );
		$karta = $karta ?? self::karta_mi( $islem_id );
		$faiz  = ( 'kredi_taksit' === $profil && $tutar > 0.0 ) ? self::kredi_faizi( $islem_id ) : 0.0;

		$wpdb->delete( $tablo, [ 'islem_id' => $islem_id ], [ '%d' ] );

		foreach ( self::uret( $profil, $tutar, $karta, $faiz ) as $e ) {
			$wpdb->insert( $tablo, [
				'islem_id'  => $islem_id,
				'etki_tipi' => $e['etki_tipi'],
				'tutar'     => $e['tutar'],
			], [ '%d', '%s', '%f' ] );
		}
	}

	/**
	 * Kredi taksidinin faiz kismi (G12).
	 *
	 * Odeme plani borc_foto.faiz kolonunda duruyor. Satirin kalemi bir borca
	 * bagliysa ve o donemin fotografinda faiz varsa, kredi taksiti tek etki
	 * yerine nakit_cikisi + faiz_masraf uretir. Anapara kismi
	 * nakit_cikisi - faiz_masraf olarak turetilir; borc_azaltimi sayacina
	 * YAZILMAZ, cunku o sayac kart odemelerini sayiyor ve ikisi karismamali.
	 */
	public static function kredi_faizi( int $islem_id ): float {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT f.faiz FROM ' . DHF_Schema::table( 'borc_foto' ) . ' f
			 INNER JOIN ' . DHF_Schema::table( 'islem' ) . ' i ON i.donem_id = f.donem_id
			 INNER JOIN ' . DHF_Schema::table( 'kalem' ) . ' k ON k.id = i.kalem_id AND k.borc_id = f.borc_id
			 WHERE i.id = %d AND f.faiz IS NOT NULL AND f.faiz > 0
			 ORDER BY f.id ASC LIMIT 1',
			$islem_id
		) );
	}

	/** Kısa gösterim: "nakit çıkışı + borç azaltımı" */
	public static function ozet( string $profil, bool $karta = false ): string {
		if ( ! isset( self::PROFILLER[ $profil ] ) ) {
			return '—';
		}
		$parcalar = array_map(
			static fn( array $e ): string => str_replace( '_', ' ', $e['etki_tipi'] ),
			self::uret( $profil, 1.0, $karta )
		);
		return implode( ' + ', $parcalar );
	}
}
