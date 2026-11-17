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
			'aciklama' => 'Tüketim gideri YARATMAZ. Anapara/faiz dağılımı bilinmediği için tahmin edilmez.',
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
	public static function uret( string $profil, float $tutar ): array {
		if ( ! isset( self::PROFILLER[ $profil ] ) ) {
			return [];
		}
		$out = [];
		foreach ( self::PROFILLER[ $profil ]['etkiler'] as [ $tip, $oran ] ) {
			$out[] = [ 'etki_tipi' => $tip, 'tutar' => round( $tutar * $oran, 2 ) ];
		}
		return $out;
	}

	/** Bir işlemin etkilerini siler ve profilden yeniden yazar. */
	public static function yaz( int $islem_id, string $profil, float $tutar ): void {
		global $wpdb;
		$tablo = DHF_Schema::table( 'islem_etki' );

		$wpdb->delete( $tablo, [ 'islem_id' => $islem_id ], [ '%d' ] );

		foreach ( self::uret( $profil, $tutar ) as $e ) {
			$wpdb->insert( $tablo, [
				'islem_id'  => $islem_id,
				'etki_tipi' => $e['etki_tipi'],
				'tutar'     => $e['tutar'],
			], [ '%d', '%s', '%f' ] );
		}
	}

	/** Kısa gösterim: "nakit çıkışı + borç azaltımı" */
	public static function ozet( string $profil ): string {
		if ( ! isset( self::PROFILLER[ $profil ] ) ) {
			return '—';
		}
		$parcalar = array_map(
			static fn( array $e ): string => str_replace( '_', ' ', $e[0] ),
			self::PROFILLER[ $profil ]['etkiler']
		);
		return implode( ' + ', $parcalar );
	}
}
