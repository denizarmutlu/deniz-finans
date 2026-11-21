<?php
/**
 * Kartlar ekranı (0.6.3) — kart başına ay ay harcama, faiz ve ödeme.
 *
 * Salt okunur. Ekstre borcu ve limit kullanımı 0.7'de (Borçlar) gelir.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Screen_Kartlar {

	private static function tl( float $v ): string {
		return number_format( $v, 2, ',', '.' );
	}

	public static function render(): void {
		DHF_Caps::guard();
		$g      = wp_unslash( $_GET );
		$kartlar = DHF_Kart::kartlar();
		$yil    = absint( $g['yil'] ?? current_time( 'Y' ) ) ?: (int) current_time( 'Y' );
		$yil    = max( 2020, min( 2100, $yil ) );
		$ay     = absint( $g['ay'] ?? 0 );
		$ay     = $ay >= 1 && $ay <= 12 ? $ay : 0;
		$kart_id = absint( $g['kart'] ?? 0 );
		$ids    = array_map( static fn( object $k ): int => (int) $k->id, $kartlar );
		if ( ! in_array( $kart_id, $ids, true ) ) {
			$kart_id = $ids[0] ?? 0;
		}
		$url = static fn( array $ek ): string => esc_url( add_query_arg( array_merge(
			[ 'page' => 'dhf-kartlar', 'kart' => $kart_id, 'yil' => $yil ], $ek ), admin_url( 'admin.php' ) ) );

		echo '<div class="wrap dhf-kartlar">';
		echo '<h1>' . esc_html__( 'Kartlar', 'deniz-finans' ) . ' <span class="dhf-rozet">' . esc_html( (string) $yil ) . '</span></h1>';

		if ( ! $kartlar ) {
			echo '<p>' . esc_html__( 'Tanımlı kredi kartı hesabı yok. Ayarlar → Hesaplar\'dan "kredi kartı" tipinde hesap ekleyin.', 'deniz-finans' ) . '</p></div>';
			return;
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $kartlar as $k ) {
			$ad = trim( (string) ( $k->kurum ?: '' ) . ' · ' . $k->ad, ' ·' );
			printf( '<a class="nav-tab %s" href="%s">%s</a>', (int) $k->id === $kart_id ? 'nav-tab-active' : '',
				esc_url( add_query_arg( [ 'page' => 'dhf-kartlar', 'kart' => (int) $k->id, 'yil' => $yil ], admin_url( 'admin.php' ) ) ),
				esc_html( $ad . ( $k->aktif ? '' : ' (pasif)' ) ) );
		}
		echo '</nav>';

		echo '<form method="get" class="dhf-secici"><input type="hidden" name="page" value="dhf-kartlar"><input type="hidden" name="kart" value="' . esc_attr( (string) $kart_id ) . '">';
		echo '<label>' . esc_html__( 'Yıl', 'deniz-finans' ) . ' <select name="yil" onchange="this.form.submit()">';
		foreach ( array_unique( array_merge( DHF_Rapor::yillar(), [ $yil, (int) current_time( 'Y' ) ] ) ) as $y ) {
			printf( '<option value="%1$d" %2$s>%1$d</option>', $y, selected( $y, $yil, false ) );
		}
		echo '</select></label></form>';

		$o = DHF_Kart::ozet( $kart_id, $yil );

		echo '<p class="description">' . esc_html__( 'Harcama: bu karta yazılan satırlar (tüketim, yatırım ve aile adına ödemeler). Faiz: kart faizi, KKDF, BSMV ve kart ücretleri. Ödeme: karta yapılan ödemeler, hangi hesaptan yapıldığına bakılmaksızın. Fark = harcama + faiz − ödeme: artıysa borç o ay büyümüş, eksiyse küçülmüştür. Ekstrede yazan borç ve limit kullanımı Borçlar modülünde (0.7) gelecek.', 'deniz-finans' ) . '</p>';

		echo '<table class="widefat striped dhf-tablo" style="max-width:820px"><thead><tr><th>' . esc_html__( 'Ay', 'deniz-finans' ) . '</th><th class="dhf-sag">Harcama</th><th class="dhf-sag">Faiz</th><th class="dhf-sag">Ödeme</th><th class="dhf-sag">Fark</th></tr></thead><tbody>';
		foreach ( $o['aylik'] as $a => $r ) {
			if ( ! $r['harcama'] && ! $r['faiz'] && ! $r['odeme'] ) {
				continue;
			}
			printf( '<tr%s><td><a href="%s">%s</a></td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag%s"><strong>%s</strong></td></tr>',
				$a === $ay ? ' style="background:#fff8e5"' : '', $url( [ 'ay' => $a ] ), esc_html( DHF_Takvim::ay_adi( $yil, $a ) ),
				esc_html( self::tl( $r['harcama'] ) ), esc_html( self::tl( $r['faiz'] ) ), esc_html( self::tl( $r['odeme'] ) ),
				$r['fark'] < 0 ? ' dhf-eksi' : '', esc_html( ( $r['fark'] > 0 ? '+' : '' ) . self::tl( $r['fark'] ) ) );
		}
		$t = $o['toplam'];
		printf( '<tr class="dhf-toplam"><th>%s</th><th class="dhf-sag">%s</th><th class="dhf-sag">%s</th><th class="dhf-sag">%s</th><th class="dhf-sag">%s</th></tr></tbody></table>',
			esc_html__( 'Yıl toplamı', 'deniz-finans' ), esc_html( self::tl( $t['harcama'] ) ), esc_html( self::tl( $t['faiz'] ) ), esc_html( self::tl( $t['odeme'] ) ),
			esc_html( ( $t['fark'] > 0 ? '+' : '' ) . self::tl( $t['fark'] ) ) );

		if ( $o['dagilim'] ) {
			echo '<h2>' . esc_html__( 'Harcamanın dağılımı', 'deniz-finans' ) . '</h2><table class="widefat striped" style="max-width:520px"><tbody>';
			foreach ( $o['dagilim'] as $ad => $v ) {
				printf( '<tr><td>%s</td><td class="dhf-sag">%s</td><td class="dhf-sag dhf-soluk">%%%s</td></tr>', esc_html( (string) $ad ), esc_html( self::tl( $v ) ),
					esc_html( $t['harcama'] ? number_format( 100 * $v / $t['harcama'], 1, ',', '.' ) : '0' ) );
			}
			echo '</tbody></table>';
		}

		$gel = DHF_Kart::gelecek( $kart_id, 18 );
		if ( $gel['planlar'] ) {
			echo '<h2>' . esc_html__( 'Taksitli alışverişler ve gelecek yük', 'deniz-finans' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Defterde yalnızca ekstresi gelmiş taksitler var. Kalan taksitler buradaki tahmindir: taksit tutarının sabit kaldığı ve her ay aynı günde çekileceği varsayılır. Ekstresi geldikçe gerçek satır deftere girer ve bu tablo kendiliğinden küçülür.', 'deniz-finans' ) . '</p>';

			echo '<table class="widefat striped dhf-tablo" style="max-width:900px"><thead><tr><th>Alışveriş</th><th class="dhf-sag">Toplam</th><th class="dhf-sag">Taksit</th><th class="dhf-sag">Ödenen</th><th class="dhf-sag">Kalan</th><th class="dhf-sag">Aylık</th><th class="dhf-sag">Kalan tutar</th></tr></thead><tbody>';
			foreach ( $gel['planlar'] as $p ) {
				printf( '<tr%s><td>%s</td><td class="dhf-sag dhf-soluk">%s</td><td class="dhf-sag">%d</td><td class="dhf-sag">%d</td><td class="dhf-sag"><strong>%d</strong></td><td class="dhf-sag">%s</td><td class="dhf-sag"><strong>%s</strong></td></tr>',
					$p['kalan'] ? '' : ' class="dhf-soluk"',
					esc_html( (string) $p['ad'] ),
					esc_html( null === $p['toplam'] ? '—' : self::tl( (float) $p['toplam'] ) ),
					(int) $p['adet'], (int) $p['odenen'], (int) $p['kalan'],
					esc_html( self::tl( (float) $p['aylik'] ) ),
					esc_html( $p['kalan'] ? self::tl( (float) $p['kalan_toplam'] ) : 'bitti' ) );
			}
			printf( '<tr class="dhf-toplam"><th colspan="6">%s</th><th class="dhf-sag">%s</th></tr></tbody></table>',
				esc_html__( 'Kalan taksit borcu', 'deniz-finans' ), esc_html( self::tl( (float) $gel['kalan_toplam'] ) ) );

			if ( $gel['aylar'] ) {
				echo '<h3>' . esc_html__( 'Aylara düşen taksit', 'deniz-finans' ) . '</h3>';
				echo '<table class="widefat striped dhf-tablo" style="max-width:520px"><thead><tr><th>Ay</th><th class="dhf-sag">Taksit yükü</th><th class="dhf-sag">Kümülatif</th></tr></thead><tbody>';
				$kum = 0.0;
				foreach ( $gel['aylar'] as $anahtar => $v ) {
					$kum += $v;
					[ $y, $a ] = array_map( 'intval', explode( '-', (string) $anahtar ) );
					printf( '<tr><td>%s</td><td class="dhf-sag"><strong>%s</strong></td><td class="dhf-sag dhf-soluk">%s</td></tr>',
						esc_html( DHF_Takvim::ay_adi( $y, $a ) ), esc_html( self::tl( (float) $v ) ), esc_html( self::tl( $kum ) ) );
				}
				echo '</tbody></table>';
			}
		}

		if ( $ay ) {
			echo '<h2>' . esc_html( DHF_Takvim::ay_adi( $yil, $ay ) ) . ' — ' . esc_html__( 'satırlar', 'deniz-finans' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th>Tarih</th><th>Alt kategori</th><th>Açıklama</th><th>Not</th><th class="dhf-sag">Tutar</th></tr></thead><tbody>';
			$sat = array_merge(
				array_map( static fn( object $s ): array => [ $s, 'kart_faiz' === $s->etki_profili ? 'faiz' : '' ], DHF_Kart::satirlar( $kart_id, $yil ) ),
				array_map( static fn( object $s ): array => [ $s, 'odeme' ], DHF_Kart::odemeler( $kart_id, $yil ) )
			);
			usort( $sat, static fn( array $a, array $b ): int => strcmp( (string) $a[0]->tarih, (string) $b[0]->tarih ) );
			foreach ( $sat as [ $s, $tur ] ) {
				if ( (int) substr( (string) $s->tarih, 5, 2 ) !== $ay ) {
					continue;
				}
				printf( '<tr%s><td>%s</td><td>%s</td><td>%s</td><td class="dhf-soluk">%s</td><td class="dhf-sag">%s</td></tr>',
					'odeme' === $tur ? ' style="background:#eef7ee"' : ( 'faiz' === $tur ? ' style="background:#fdeeee"' : '' ),
					esc_html( mysql2date( 'd.m.Y', (string) $s->tarih ) ), esc_html( (string) $s->kalem_ad ), esc_html( (string) $s->aciklama ),
					esc_html( (string) $s->not_metni ), esc_html( ( 'odeme' === $tur ? '− ' : '' ) . self::tl( (float) $s->tutar ) ) );
			}
			echo '</tbody></table><p class="description">' . esc_html__( 'Yeşil: karta ödeme. Kırmızı: faiz ve ücret.', 'deniz-finans' ) . '</p>';
		}
		echo '</div>';
	}
}
