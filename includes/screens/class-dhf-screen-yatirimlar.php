<?php
/**
 * Yatırımlar ekranı (0.8) — salt okunur.
 *
 * Üç tablo: aylık özet · tür kırılımı · varlık ağacı (Kurum → Tür → Enstrüman).
 * Getiri DHF_Varlik'ten gelir; bu ekran hiçbir toplamı kendisi hesaplamaz (§2.8).
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Screen_Yatirimlar {

	private const AYLAR = [ 1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık' ];

	private static function tl( ?float $v, int $basamak = 0 ): string {
		if ( null === $v ) {
			return '—';
		}
		return number_format( $v, $basamak, ',', '.' );
	}

	private static function isaret( ?float $v ): string {
		if ( null === $v ) {
			return 'color:#646970';
		}
		return $v < 0 ? 'color:#b32d2e' : ( $v > 0 ? 'color:#007017' : 'color:#646970' );
	}

	public static function render(): void {
		DHF_Caps::guard();
		global $wpdb;
		$g   = wp_unslash( $_GET );
		$yil = absint( $g['yil'] ?? 0 );
		$d   = DHF_Schema::table( 'donem' );
		$yillar = $wpdb->get_col( "SELECT DISTINCT d.yil FROM {$d} d JOIN " . DHF_Schema::table( 'varlik_foto' ) . " f ON f.donem_id = d.id ORDER BY d.yil DESC" );
		$yillar = array_map( 'intval', $yillar );
		if ( ! in_array( $yil, $yillar, true ) ) {
			$yil = $yillar[0] ?? (int) current_time( 'Y' );
		}

		echo '<div class="wrap dhf-yatirimlar">';
		echo '<h1>' . esc_html__( 'Yatırımlar', 'deniz-finans' ) . ' <span class="dhf-rozet">' . esc_html( (string) $yil ) . '</span></h1>';

		if ( ! $yillar ) {
			echo '<p>' . esc_html__( 'Henüz varlık fotoğrafı yok.', 'deniz-finans' ) . '</p></div>';
			return;
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $yillar as $y ) {
			printf(
				'<a class="nav-tab %s" href="%s">%d</a>',
				$y === $yil ? 'nav-tab-active' : '',
				esc_url( add_query_arg( [ 'page' => 'dhf-yatirimlar', 'yil' => $y ], admin_url( 'admin.php' ) ) ),
				$y
			);
		}
		echo '</nav>';

		$ozet = DHF_Varlik::yillik_ozet( $yil );
		if ( ! $ozet ) {
			echo '<p>' . esc_html__( 'Bu yılda fotoğraf yok.', 'deniz-finans' ) . '</p></div>';
			return;
		}

		self::serit( $ozet );
		self::aylik_tablo( $ozet );
		self::tur_tablo( $ozet );
		self::agac( $yil, $ozet );
		self::bes_kartlari();

		echo '<p class="description" style="margin-top:18px">';
		echo esc_html__( 'Getiri bakiye farkı değildir: katkı, iç transfer ve devlet katkısı düşülür (§4.3). Yalnız iki dönemde de fotoğrafı olan varlıklar kıyaslanır; kapsama giren veya çıkan bakiye ayrı kolonda durur. BES satırlarında devlet katkısı kolonu henüz boş; o yüzden BES getirisi olduğundan yüksek görünüyor. Tutarlar yalnız kurumun fon hareketleri listesinden girilebilir, çünkü esas olan vade değil katkının fona girdiği tarih.', 'deniz-finans' );
		echo '</p>';
		echo '</div>';
	}

	private static function serit( array $ozet ): void {
		// Son dönemin kapsamı düşmüşse (çıkan kapsam var) o fotoğraf kısmidir:
		// başlıkta kapsamı tam olan en son dönem gösterilir, kısmi olan not olur.
		$son   = $ozet[ count( $ozet ) - 1 ];
		$kismi = null;
		for ( $i = count( $ozet ) - 1; $i >= 0; $i-- ) {
			if ( 0.0 === (float) $ozet[ $i ]['cikan_kapsam'] ) {
				$son = $ozet[ $i ];
				break;
			}
			$kismi = $ozet[ $i ];
		}
		$getiri = 0.0;
		$dis    = 0.0;
		$ic     = 0.0;
		foreach ( $ozet as $r ) {
			$getiri += (float) ( $r['getiri'] ?? 0 );
			$dis    += (float) $r['dis_giris'];
			$ic     += (float) $r['ic_transfer'];
		}
		$kutular = [
			[ __( 'Portföy', 'deniz-finans' ), self::tl( (float) $son['toplam'] ) . ' ₺', sprintf( '%s %d', self::AYLAR[ (int) $son['ay'] ], (int) $son['yil'] ), null ],
			[ __( 'Yıl getirisi', 'deniz-finans' ), self::tl( $getiri ) . ' ₺', __( 'aylık zincir', 'deniz-finans' ), $getiri ],
			[ __( 'Dıştan gelen para', 'deniz-finans' ), self::tl( $dis ) . ' ₺', __( 'yeni para', 'deniz-finans' ), null ],
			[ __( 'İç transfer', 'deniz-finans' ), self::tl( $ic ) . ' ₺', __( 'kurum arası, yeni para değil', 'deniz-finans' ), null ],
		];
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
		foreach ( $kutular as $k ) {
			echo '<div style="flex:1 1 180px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 14px">';
			echo '<div style="font-size:12px;color:#646970">' . esc_html( $k[0] ) . '</div>';
			echo '<div style="font-size:20px;font-weight:600;' . esc_attr( self::isaret( $k[3] ) ) . '">' . esc_html( $k[1] ) . '</div>';
			echo '<div style="font-size:11px;color:#646970">' . esc_html( $k[2] ) . '</div>';
			echo '</div>';
		}
		echo '</div>';
		if ( null !== $kismi ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: 1: ay adı, 2: kalem sayısı */
				esc_html__( '%1$s fotoğrafı kısmi: yalnız %2$d kalem girilmiş, o yüzden portföy kutusu daha eski bir aydan okunuyor. O ayın kalanı girilince kendiliğinden düzelir.', 'deniz-finans' ),
				esc_html( self::AYLAR[ (int) $kismi['ay'] ] ),
				(int) $kismi['kalem']
			);
			echo '</p></div>';
		}
	}
	private static function aylik_tablo( array $ozet ): void {
		echo '<h2>' . esc_html__( 'Ay ay', 'deniz-finans' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ 'Ay', 'Kalem', 'Kıyas', 'Portföy', 'Dış giriş', 'İç transfer', 'Devlet katkısı', 'Giren kapsam', 'Çıkan kapsam', 'Getiri', '%' ] as $i => $b ) {
			printf( '<th%s>%s</th>', $i >= 1 ? ' style="text-align:right"' : '', esc_html( $b ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $ozet as $r ) {
			$kapsam = ( (float) $r['giren_kapsam'] !== 0.0 || (float) $r['cikan_kapsam'] !== 0.0 );
			echo '<tr>';
			echo '<td><strong>' . esc_html( self::AYLAR[ (int) $r['ay'] ] ) . '</strong>';
			if ( $kapsam ) {
				echo ' <span title="' . esc_attr__( 'Kapsam değişti: kıyas bu ayda daha az kalem üzerinden yapıldı.', 'deniz-finans' ) . '" style="color:#996800">●</span>';
			}
			echo '</td>';
			printf( '<td style="text-align:right">%d</td>', (int) $r['kalem'] );
			printf( '<td style="text-align:right;color:#646970">%d</td>', (int) $r['kiyas_kalem'] );
			printf( '<td style="text-align:right"><strong>%s</strong></td>', esc_html( self::tl( (float) $r['toplam'] ) ) );
			printf( '<td style="text-align:right">%s</td>', esc_html( self::tl( (float) $r['dis_giris'] ) ) );
			printf( '<td style="text-align:right;color:#646970">%s</td>', esc_html( self::tl( (float) $r['ic_transfer'] ) ) );
			printf( '<td style="text-align:right;color:#646970">%s</td>', esc_html( self::tl( (float) $r['devlet_katkisi'] ) ) );
			printf( '<td style="text-align:right;color:#996800">%s</td>', esc_html( self::tl( (float) $r['giren_kapsam'] ) ) );
			printf( '<td style="text-align:right;color:#996800">%s</td>', esc_html( self::tl( (float) $r['cikan_kapsam'] ) ) );
			printf( '<td style="text-align:right;%s"><strong>%s</strong></td>', esc_attr( self::isaret( null === $r['getiri'] ? null : (float) $r['getiri'] ) ), esc_html( self::tl( null === $r['getiri'] ? null : (float) $r['getiri'] ) ) );
			printf( '<td style="text-align:right;%s">%s</td>', esc_attr( self::isaret( null === $r['yuzde'] ? null : (float) $r['yuzde'] ) ), null === $r['yuzde'] ? '—' : esc_html( '%' . self::tl( (float) $r['yuzde'], 1 ) ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function tur_tablo( array $ozet ): void {
		$bas = (int) $ozet[0]['donem_id'];
		$bit = (int) $ozet[ count( $ozet ) - 1 ]['donem_id'];
		$kir = DHF_Varlik::tur_kirilimi( $bas, $bit );
		if ( ! $kir ) {
			return;
		}
		uasort( $kir, static fn( array $a, array $b ): int => $b['bit'] <=> $a['bit'] );
		$etiket = [ 'HISSE' => 'Hisse', 'FON' => 'Fon', 'BES' => 'BES', 'EUROBOND' => 'Eurobond', 'YURTDISI' => 'Yurt dışı', 'COIN' => 'Coin', 'BES_FON' => 'BES fonu' ];
		printf( '<h2>%s</h2>', esc_html( sprintf( __( 'Tür bazında · %s → %s', 'deniz-finans' ), self::AYLAR[ (int) $ozet[0]['ay'] ], self::AYLAR[ (int) $ozet[ count( $ozet ) - 1 ]['ay'] ] ) ) );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ 'Tür', 'Başlangıç', 'Bitiş', 'Dış giriş', 'İç transfer', 'Devlet katkısı', 'Getiri', '%' ] as $i => $b ) {
			printf( '<th%s>%s</th>', $i >= 1 ? ' style="text-align:right"' : '', esc_html( $b ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $kir as $tur => $x ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $etiket[ $tur ] ?? $tur ) . '</strong></td>';
			foreach ( [ 'bas', 'bit', 'giris', 'ic', 'dk' ] as $alan ) {
				printf( '<td style="text-align:right">%s</td>', esc_html( self::tl( (float) ( $x[ $alan ] ?? 0 ) ) ) );
			}
			printf( '<td style="text-align:right;%s"><strong>%s</strong></td>', esc_attr( self::isaret( (float) $x['getiri'] ) ), esc_html( self::tl( (float) $x['getiri'] ) ) );
			printf( '<td style="text-align:right;%s">%s</td>', esc_attr( self::isaret( null === $x['yuzde'] ? null : (float) $x['yuzde'] ) ), null === $x['yuzde'] ? '—' : esc_html( '%' . self::tl( (float) $x['yuzde'], 1 ) ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function agac( int $yil, array $ozet ): void {
		$varliklar = DHF_Varlik::varliklar();
		$fotolar   = DHF_Varlik::fotolar( $yil );
		$donemler  = array_map( static fn( array $r ): int => (int) $r['donem_id'], $ozet );
		$etiket    = [ 'HISSE' => 'Hisse', 'FON' => 'Fon', 'BES' => 'BES', 'EUROBOND' => 'Eurobond', 'YURTDISI' => 'Yurt dışı', 'COIN' => 'Coin', 'BES_FON' => 'BES fonu' ];

		$kurumlar = [];
		foreach ( $varliklar as $id => $v ) {
			$varmi = false;
			foreach ( $donemler as $did ) {
				if ( isset( $fotolar[ $did ][ $id ] ) ) {
					$varmi = true;
					break;
				}
			}
			if ( $varmi ) {
				$kurumlar[ (string) $v['kurum_ad'] ][] = $id;
			}
		}
		ksort( $kurumlar );

		echo '<h2>' . esc_html__( 'Varlık ağacı', 'deniz-finans' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Kurum · Tür', 'deniz-finans' ) . '</th>';
		foreach ( $ozet as $r ) {
			echo '<th style="text-align:right">' . esc_html( mb_substr( self::AYLAR[ (int) $r['ay'] ], 0, 3 ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $kurumlar as $kurum => $idler ) {
			echo '<tr style="background:#f0f0f1"><td colspan="' . ( count( $donemler ) + 1 ) . '"><strong>' . esc_html( $kurum ) . '</strong></td></tr>';
			foreach ( $idler as $id ) {
				$v     = $varliklar[ $id ];
				$derin = empty( $v['ust_id'] ) ? 1 : 2;
				$ad    = 2 === $derin ? (string) $v['ad'] : ( $etiket[ (string) $v['tur'] ] ?? (string) $v['tur'] );
				if ( 'BES' === $v['tur'] && ! empty( $v['sozlesme_no'] ) ) {
					$ad = 'BES ' . $v['sozlesme_no'];
				}
				echo '<tr><td style="padding-left:' . ( 12 * $derin ) . 'px">' . esc_html( $ad );
				if ( ! empty( $v['kod'] ) ) {
					echo ' <code>' . esc_html( (string) $v['kod'] ) . '</code>';
				}
				if ( ! (int) $v['aktif'] ) {
					echo ' <span style="color:#646970">· ' . esc_html__( 'kapandı', 'deniz-finans' ) . '</span>';
				}
				echo '</td>';
				foreach ( $donemler as $did ) {
					$f = $fotolar[ $did ][ $id ] ?? null;
					echo '<td style="text-align:right' . ( null === $f ? ';color:#c3c4c7' : '' ) . '">';
					echo null === $f ? '·' : esc_html( self::tl( (float) $f['tutar'] ) );
					echo '</td>';
				}
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
	}

	private static function bes_kartlari(): void {
		global $wpdb;
		$s = DHF_Schema::table( 'bes_sozlesme' );
		$k = DHF_Schema::table( 'kurum' );
		$satirlar = $wpdb->get_results( "SELECT b.id, b.sozlesme_no, b.baslangic_tarihi, k.ad AS kurum FROM {$s} b LEFT JOIN {$k} k ON k.id = b.kurum_id WHERE b.aktif = 1 ORDER BY b.baslangic_tarihi", ARRAY_A );
		if ( ! $satirlar ) {
			return;
		}
		echo '<h2>' . esc_html__( 'BES hakkediş', 'deniz-finans' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ 'Sözleşme', 'Başlangıç', 'Sistemde', 'Hakkediş', 'Sonraki eşik' ] as $i => $b ) {
			printf( '<th%s>%s</th>', $i >= 2 ? ' style="text-align:right"' : '', esc_html( $b ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $satirlar as $r ) {
			$h = DHF_Varlik::hakkedis( (string) $r['baslangic_tarihi'] );
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $r['kurum'] ) . '</strong> <code>' . esc_html( (string) $r['sozlesme_no'] ) . '</code></td>';
			echo '<td>' . esc_html( mysql2date( 'd.m.Y', (string) $r['baslangic_tarihi'] ) ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( self::tl( (float) $h['yil'], 2 ) ) . ' ' . esc_html__( 'yıl', 'deniz-finans' ) . '</td>';
			echo '<td style="text-align:right;font-weight:600;' . ( 0 === $h['yuzde'] ? 'color:#b32d2e' : '' ) . '">%' . esc_html( (string) $h['yuzde'] ) . '</td>';
			echo '<td style="text-align:right">';
			echo null === $h['sonraki_tarih'] ? '—' : esc_html( mysql2date( 'd.m.Y', (string) $h['sonraki_tarih'] ) . ' → %' . $h['sonraki_yuzde'] );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Hakkediş yalnız devlet katkısına uygulanır ve çıkışta ele geçecek tutarı belirler. %100 için 56 yaş + 10 yıl gerekir; yaş bilgisi burada tutulmuyor.', 'deniz-finans' ) . '</p>';
	}
}