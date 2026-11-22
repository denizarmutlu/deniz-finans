<?php
/**
 * Raporlar ekranı (plan §5.4, 0.5).
 *
 * Dört sekme: Özet (üç görünüm yan yana) · Kategori pivotu · Nakit ihtiyacı · Yıl karşılaştırma.
 * Görünüm seçici başlıkta her zaman yazılı (kabul senaryosu 19). Her tablo CSV olarak
 * indirilebilir; indirme admin-post.php üzerinden nonce'lu, dosya diske yazılmaz.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Screen_Raporlar {

	private const SEKMELER = [
		'ozet'  => 'Özet',
		'pivot' => 'Kategori pivotu',
		'nakit' => 'Nakit ihtiyacı',
		'yil'   => 'Yıl karşılaştırma',
	];

	/** @return array{sekme:string,yil:int,gorunum:string,seviye:string,onceki:int} */
	private static function parametreler( array $src ): array {
		$sekme = sanitize_key( (string) ( $src['sekme'] ?? 'ozet' ) );
		$yil   = absint( $src['yil'] ?? current_time( 'Y' ) );
		$yil   = max( 2020, min( 2100, $yil ?: (int) current_time( 'Y' ) ) );
		$onc   = absint( $src['onceki'] ?? ( $yil - 1 ) );
		return [
			'sekme'   => isset( self::SEKMELER[ $sekme ] ) ? $sekme : 'ozet',
			'yil'     => $yil,
			'gorunum' => DHF_Rapor::gorunum( sanitize_key( (string) ( $src['gorunum'] ?? 'tuketim' ) ) ),
			'seviye'  => 'alt' === ( $src['seviye'] ?? '' ) ? 'alt' : 'kategori',
			'onceki'  => max( 2020, min( 2100, $onc ?: $yil - 1 ) ),
		];
	}

	private static function tablo( array $p ): array {
		return match ( $p['sekme'] ) {
			'pivot' => DHF_Rapor::tablo_pivot( $p['yil'], $p['gorunum'], $p['seviye'] ),
			'nakit' => DHF_Rapor::tablo_nakit( $p['yil'] ),
			'yil'   => DHF_Rapor::tablo_yil( $p['yil'], $p['onceki'], $p['gorunum'] ),
			default => DHF_Rapor::tablo_ozet( $p['yil'] ),
		};
	}

	public static function render(): void {
		DHF_Caps::guard();
		$p = self::parametreler( wp_unslash( $_GET ) );
		$t = self::tablo( $p );

		$url = static fn( array $ek ): string => esc_url( add_query_arg( array_merge(
			[ 'page' => 'dhf-raporlar' ], array_diff_key( $p, [ 'onceki' => 1 ] ), $ek ), admin_url( 'admin.php' ) ) );

		$gorunumlu = in_array( $p['sekme'], [ 'pivot', 'yil' ], true );
		[ $g_etiket ] = DHF_Rapor::GORUNUMLER[ $p['gorunum'] ];

		echo '<div class="wrap dhf-raporlar">';
		echo '<h1>' . esc_html__( 'Raporlar', 'deniz-finans' );
		if ( $gorunumlu ) {
			echo ' <span class="dhf-rozet">' . esc_html( $g_etiket . ' görünümü' ) . '</span>';
		}
		echo '</h1>';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::SEKMELER as $s => $ad ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $s === $p['sekme'] ? 'nav-tab-active' : '', $url( [ 'sekme' => $s ] ), esc_html( $ad ) );
		}
		echo '</nav>';

		/* Seçiciler */
		echo '<form method="get" class="dhf-secici">';
		echo '<input type="hidden" name="page" value="dhf-raporlar">';
		echo '<input type="hidden" name="sekme" value="' . esc_attr( $p['sekme'] ) . '">';
		echo '<label>' . esc_html__( 'Yıl', 'deniz-finans' ) . ' <select name="yil" onchange="this.form.submit()">';
		$yillar = DHF_Rapor::yillar();
		foreach ( array_unique( array_merge( $yillar, [ $p['yil'], (int) current_time( 'Y' ) - 1 ] ) ) as $y ) {
			printf( '<option value="%1$d" %2$s>%1$d</option>', $y, selected( $y, $p['yil'], false ) );
		}
		echo '</select></label>';
		if ( 'yil' === $p['sekme'] ) {
			echo '<label>' . esc_html__( 'Karşılaştırılan', 'deniz-finans' ) . ' <select name="onceki" onchange="this.form.submit()">';
			foreach ( range( $p['yil'] - 1, max( 2020, $p['yil'] - 5 ) ) as $y ) {
				printf( '<option value="%1$d" %2$s>%1$d</option>', $y, selected( $y, $p['onceki'], false ) );
			}
			echo '</select></label>';
		}
		if ( $gorunumlu ) {
			echo '<span class="dhf-gorunum">';
			foreach ( DHF_Rapor::GORUNUMLER as $g => [ $etiket, , $soru ] ) {
				printf( '<label title="%s"><input type="radio" name="gorunum" value="%s" %s onchange="this.form.submit()"> %s</label>',
					esc_attr( $soru ), esc_attr( $g ), checked( $g, $p['gorunum'], false ), esc_html( $etiket ) );
			}
			echo '</span>';
		}
		if ( 'pivot' === $p['sekme'] ) {
			echo '<label>' . esc_html__( 'Kırılım', 'deniz-finans' ) . ' <select name="seviye" onchange="this.form.submit()">';
			printf( '<option value="kategori" %s>Kategori</option>', selected( 'kategori', $p['seviye'], false ) );
			printf( '<option value="alt" %s>Alt kategori</option>', selected( 'alt', $p['seviye'], false ) );
			echo '</select></label>';
		}
		echo '<noscript><button class="button">' . esc_html__( 'Göster', 'deniz-finans' ) . '</button></noscript>';
		echo '</form>';

		echo '<h2>' . esc_html( $t['baslik'] ) . '</h2>';
		if ( ! empty( $t['hata'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $t['hata'] ) . '</p></div>';
		}
		if ( ! $t['satirlar'] || null === $t['alt'] && 'yil' !== $p['sekme'] ) {
			echo '<p>' . esc_html( sprintf( __( '%d için defterde veri yok.', 'deniz-finans' ), $p['yil'] ) ) . '</p>';
		} else {
			self::tablo_html( $t );
		}
		if ( ! empty( $t['not'] ) ) {
			echo '<p class="dhf-soluk" style="max-width:900px">' . esc_html( $t['not'] ) . '</p>';
		}

		self::kesinlik_notu( $p['yil'] );

		/* İndirme: CSV bu tabloyu, XLSX dört sekmeyi birden verir. */
		echo '<div class="dhf-indir">';
		$indirmeler = [ 'csv' => __( 'CSV indir (bu tablo)', 'deniz-finans' ) ];
		if ( DHF_Xlsx::destekli() ) {
			$indirmeler['xlsx'] = __( 'XLSX indir (dört sekme)', 'deniz-finans' );
		}
		foreach ( $indirmeler as $tip => $etiket ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'dhf_rapor_' . $tip );
			printf( '<input type="hidden" name="action" value="dhf_rapor_%s">', esc_attr( $tip ) );
			foreach ( $p as $k => $v ) {
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( (string) $v ) );
			}
			submit_button( $etiket, 'secondary', 'submit_' . $tip, false );
			echo '</form>';
		}
		echo '</div>';

		echo '</div>';
		self::stil();
	}

	/** Kesinleşmemiş ayı olan yılda raporun kesin olmadığını söyler. */
	private static function kesinlik_notu( int $yil ): void {
		$acik = [];
		foreach ( DHF_Rapor::aylik( $yil ) as $ay => $a ) {
			if ( 'kesinlesti' !== $a['donem']->durum ) {
				$acik[] = DHF_Takvim::AYLAR[ $ay ];
			}
		}
		if ( $acik ) {
			echo '<p class="dhf-uyari-not">' . esc_html( sprintf(
				__( 'Kesinleşmemiş ay: %s — bu ayların rakamları değişebilir.', 'deniz-finans' ), implode( ', ', $acik ) ) ) . '</p>';
		}
	}

	/** Genel tablo basıcı — Gösterge Paneli de kullanır. */
	public static function tablo_html( array $t ): void {
		echo '<div class="dhf-kaydir"><table class="widefat striped dhf-rapor"><thead><tr>';
		foreach ( $t['kolonlar'] as $k ) {
			echo '<th>' . esc_html( $k ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $t['satirlar'] as $s ) {
			self::satir_html( $s, 'td' );
		}
		echo '</tbody>';
		if ( $t['alt'] ) {
			echo '<tfoot>';
			self::satir_html( $t['alt'], 'th' );
			echo '</tfoot>';
		}
		echo '</table></div>';
	}

	private static function satir_html( array $s, string $etiket ): void {
		$bos = count( array_filter( $s, static fn( $v ) => is_float( $v ) || is_int( $v ) ) ) === 0;
		echo '<tr' . ( $bos ? ' class="dhf-bos-satir"' : '' ) . '>';
		foreach ( $s as $v ) {
			if ( is_float( $v ) || is_int( $v ) ) {
				printf( '<%1$s class="dhf-sag%3$s">%2$s</%1$s>', $etiket, esc_html( number_format_i18n( (float) $v, 2 ) ), $v < 0 ? ' dhf-eksi' : '' );
			} elseif ( null === $v ) {
				printf( '<%1$s class="dhf-sag dhf-soluk">—</%1$s>', $etiket );
			} else {
				printf( '<%1$s>%2$s</%1$s>', $etiket, esc_html( (string) $v ) );
			}
		}
		echo '</tr>';
	}

	public static function handle_csv(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_rapor_csv' );
		$p = self::parametreler( wp_unslash( $_POST ) );
		$t = self::tablo( $p );

		$dosya = sanitize_file_name( 'deniz-finans-' . $p['sekme'] . '-' . $p['yil'] . ( in_array( $p['sekme'], [ 'pivot', 'yil' ], true ) ? '-' . $p['gorunum'] : '' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $dosya . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel'in UTF-8'i tanıması için BOM.
		$yaz = static function ( array $s ) use ( $out ): void {
			fputcsv( $out, array_map( static fn( $v ) => ( is_float( $v ) || is_int( $v ) || null === $v )
				? DHF_Rapor::csv_tutar( null === $v ? null : (float) $v )
				: DHF_Rapor::csv_guvenli( (string) $v ), $s ), ';', '"', '' );
		};
		$yaz( [ $t['baslik'] ] );
		$yaz( $t['kolonlar'] );
		foreach ( $t['satirlar'] as $s ) {
			$yaz( $s );
		}
		if ( $t['alt'] ) {
			$yaz( $t['alt'] );
		}
		fclose( $out );
		exit;
	}

	/**
	 * XLSX — dört sekme tek çalışma kitabında.
	 *
	 * CSV tek tablo verir; XLSX'in tek kazancı çok sayfa olduğu için burada seçili
	 * sekme değil hepsi yazılır. Yıl, görünüm, kırılım ve karşılaştırılan yıl
	 * ekrandaki seçimden gelir — sekme adına görünüm de yazılır (kabul senaryosu 19).
	 */
	public static function handle_xlsx(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_rapor_xlsx' );
		if ( ! DHF_Xlsx::destekli() ) {
			wp_die( esc_html__( 'Sunucuda ZipArchive yok; XLSX üretilemiyor. CSV indirmeyi kullanın.', 'deniz-finans' ) );
		}
		$p = self::parametreler( wp_unslash( $_POST ) );

		$sayfalar = [];
		foreach ( self::SEKMELER as $sekme => $ad ) {
			$t = self::tablo( [ 'sekme' => $sekme ] + $p );
			if ( ! $t['satirlar'] ) {
				continue;
			}
			if ( in_array( $sekme, [ 'pivot', 'yil' ], true ) ) {
				[ $g_etiket ] = DHF_Rapor::GORUNUMLER[ $p['gorunum'] ];
				$ad          .= ' (' . $g_etiket . ')';
			}
			$sayfalar[] = [ 'ad' => $ad ] + $t;
		}
		if ( ! $sayfalar ) {
			wp_die( esc_html( sprintf( __( '%d için defterde veri yok.', 'deniz-finans' ), $p['yil'] ) ) );
		}

		DHF_Xlsx::gonder( sanitize_file_name( sprintf( 'deniz-finans-%d-%s.xlsx', $p['yil'], $p['gorunum'] ) ), $sayfalar );
	}

	public static function stil(): void {
		echo '<style>
		.dhf-secici{display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin:14px 0}
		.dhf-gorunum{display:inline-flex;gap:12px;background:#fff;border:1px solid #c3c4c7;padding:4px 10px}
		.dhf-rozet{font-size:13px;font-weight:400;background:#dbeafe;color:#1e40af;border-radius:3px;padding:2px 8px;vertical-align:middle}
		.dhf-kaydir{overflow-x:auto;max-width:100%}
		.dhf-rapor{width:auto;min-width:640px}
		.dhf-rapor th,.dhf-rapor td{white-space:nowrap;padding:6px 10px}
		.dhf-rapor tfoot th{font-weight:600;border-top:2px solid #c3c4c7}
		.dhf-rapor .dhf-bos-satir td{color:#a7aaad}
		.dhf-sag{text-align:right}.dhf-eksi{color:#b32d2e}.dhf-soluk{color:#646970;font-size:12px}
		.dhf-uyari-not{color:#8a6100}
		.dhf-indir{display:flex;gap:10px;align-items:flex-start;margin-top:12px}
		</style>';
	}
}
