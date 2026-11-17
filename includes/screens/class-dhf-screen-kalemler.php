<?php
/**
 * Kalemler ekranı.
 *
 * Tekrarlayan kalem sözlüğü. Aylık taslak buradan üretilir; bir kalem doğru
 * tanımlandığında o kalem bir daha yanlış kaydedilemez.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Screen_Kalemler {

	public static function render(): void {
		DHF_Caps::guard();

		$filtre  = isset( $_GET['filtre'] ) ? sanitize_key( wp_unslash( $_GET['filtre'] ) ) : 'aktif';
		$kalemler = DHF_Kalem::hepsi();

		$kalemler = array_values( array_filter( $kalemler, static function ( object $k ) use ( $filtre ): bool {
			return match ( $filtre ) {
				'pasif'    => ! (int) $k->aktif,
				'degisken' => (int) $k->aktif && 'degisken' === $k->tekrar_tipi,
				'tumu'     => true,
				default    => (bool) (int) $k->aktif,
			};
		} ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Kalemler', 'deniz-finans' ) . '</h1>';
		echo '<p class="description">' . esc_html__(
			'Aylık taslak bu sözlükten üretilir. Sabit kalemlerde tutar dolu gelir; değişken kalemlerde boş gelir ve "tutar bekliyor" olarak işaretlenir.',
			'deniz-finans'
		) . '</p>';

		if ( isset( $_GET['dhf_mesaj'] ) && 'kaydedildi' === sanitize_key( wp_unslash( $_GET['dhf_mesaj'] ) ) ) {
			$n = isset( $_GET['n'] ) ? absint( wp_unslash( $_GET['n'] ) ) : 0;
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( /* translators: %d: kalem sayısı */
					__( '%d kalem kaydedildi.', 'deniz-finans' ), $n ) ) );
		}

		self::filtreler( $filtre, $kalemler );
		self::tablo( $kalemler, $filtre );
		echo '</div>';
	}

	private static function filtreler( string $aktif, array $gosterilen ): void {
		$secenekler = [
			'aktif'    => __( 'Aktif', 'deniz-finans' ),
			'degisken' => __( 'Tutarı değişken', 'deniz-finans' ),
			'pasif'    => __( 'Pasif', 'deniz-finans' ),
			'tumu'     => __( 'Tümü', 'deniz-finans' ),
		];
		echo '<ul class="subsubsub">';
		$son = array_key_last( $secenekler );
		foreach ( $secenekler as $k => $etiket ) {
			printf( '<li><a href="%s"%s>%s</a>%s</li>',
				esc_url( add_query_arg( [ 'page' => 'dhf-kalemler', 'filtre' => $k ], admin_url( 'admin.php' ) ) ),
				$k === $aktif ? ' class="current"' : '',
				esc_html( $etiket ),
				$k === $son ? '' : ' | '
			);
		}
		echo '</ul>';
		printf( '<p class="description" style="clear:both">%s</p>',
			esc_html( sprintf( /* translators: %d: kalem sayısı */
				__( '%d kalem gösteriliyor.', 'deniz-finans' ), count( $gosterilen ) ) ) );
	}

	private static function tablo( array $kalemler, string $filtre ): void {
		if ( ! $kalemler ) {
			echo '<p>' . esc_html__( 'Bu filtrede kalem yok.', 'deniz-finans' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dhf_kalemler' );
		echo '<input type="hidden" name="action" value="dhf_kalemler">';
		echo '<input type="hidden" name="filtre" value="' . esc_attr( $filtre ) . '">';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ 'Kalem', 'Tür / Kategori', 'Tekrar', 'Gün', 'Varsayılan tutar', 'Ödeme şekli', 'Etki', 'Aktif' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$son_kategori = null;
		foreach ( $kalemler as $k ) {
			$grup = $k->tur . ' · ' . ( $k->kategori_ad ?: '—' );
			if ( $grup !== $son_kategori ) {
				printf( '<tr><th colspan="8" style="background:#f6f7f7">%s</th></tr>', esc_html( $grup ) );
				$son_kategori = $grup;
			}

			$id = (int) $k->id;
			echo '<tr>';

			printf( '<td><strong>%s</strong>%s</td>',
				esc_html( $k->ad ),
				$k->not_metni ? '<br><span class="description">' . esc_html( $k->not_metni ) . '</span>' : ''
			);
			printf( '<td>%s</td>', esc_html( $k->kategori_ad ?: '—' ) );

			printf( '<td><select name="k[%d][tekrar_tipi]">', $id );
			foreach ( DHF_Kalem::TEKRAR as $deger => $etiket ) {
				printf( '<option value="%s"%s>%s</option>',
					esc_attr( $deger ), selected( $k->tekrar_tipi, $deger, false ), esc_html( $etiket ) );
			}
			echo '</select></td>';

			printf( '<td><input type="number" min="1" max="31" name="k[%d][varsayilan_gun]" value="%s" style="width:60px"></td>',
				$id, esc_attr( (string) $k->varsayilan_gun ) );

			printf( '<td><input type="number" step="0.01" name="k[%d][varsayilan_tutar]" value="%s" style="width:110px;text-align:right"></td>',
				$id, esc_attr( null === $k->varsayilan_tutar ? '' : (string) $k->varsayilan_tutar ) );

			printf( '<td><select name="k[%d][odeme_sekli]">', $id );
			foreach ( DHF_Kalem::ODEME as $deger => $etiket ) {
				printf( '<option value="%s"%s>%s</option>',
					esc_attr( $deger ), selected( $k->odeme_sekli, $deger, false ), esc_html( $etiket ) );
			}
			echo '</select></td>';

			printf( '<td><select name="k[%d][etki_profili]" title="%s">', $id,
				esc_attr( DHF_Etki::aciklama( (string) $k->etki_profili ) ) );
			foreach ( DHF_Etki::profiller() as $deger => $p ) {
				printf( '<option value="%s"%s>%s</option>',
					esc_attr( $deger ), selected( $k->etki_profili, $deger, false ), esc_html( $p['etiket'] ) );
			}
			echo '</select></td>';

			printf( '<td><input type="checkbox" name="k[%d][aktif]" value="1"%s></td>',
				$id, checked( (int) $k->aktif, 1, false ) );

			echo '</tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Kalemleri kaydet', 'deniz-finans' ), 'primary' );
		echo '</form>';
	}

	public static function handle(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_kalemler' );

		$filtre = isset( $_POST['filtre'] ) ? sanitize_key( wp_unslash( $_POST['filtre'] ) ) : 'aktif';
		$ham    = isset( $_POST['k'] ) && is_array( $_POST['k'] ) ? wp_unslash( $_POST['k'] ) : [];

		$sayac = 0;
		foreach ( $ham as $id => $alanlar ) {
			$id = absint( $id );
			if ( ! $id || ! is_array( $alanlar ) ) {
				continue;
			}

			$profil = sanitize_key( (string) ( $alanlar['etki_profili'] ?? '' ) );
			$tekrar = sanitize_key( (string) ( $alanlar['tekrar_tipi'] ?? '' ) );
			$odeme  = sanitize_key( (string) ( $alanlar['odeme_sekli'] ?? '' ) );
			$tutar  = trim( (string) ( $alanlar['varsayilan_tutar'] ?? '' ) );
			$gun    = absint( $alanlar['varsayilan_gun'] ?? 0 );

			$veri = [ 'aktif' => isset( $alanlar['aktif'] ) ? 1 : 0 ];
			if ( isset( DHF_Kalem::TEKRAR[ $tekrar ] ) ) {
				$veri['tekrar_tipi'] = $tekrar;
			}
			if ( isset( DHF_Kalem::ODEME[ $odeme ] ) ) {
				$veri['odeme_sekli'] = $odeme;
			}
			if ( DHF_Etki::var_mi( $profil ) ) {
				$veri['etki_profili'] = $profil;
			}
			if ( $gun >= 1 && $gun <= 31 ) {
				$veri['varsayilan_gun'] = $gun;
			}
			$veri['varsayilan_tutar'] = ( '' === $tutar ) ? '' : (float) str_replace( ',', '.', $tutar );

			$sayac += DHF_Kalem::guncelle( $id, $veri ) ? 1 : 0;
		}

		wp_safe_redirect( add_query_arg( [
			'page' => 'dhf-kalemler', 'filtre' => $filtre, 'dhf_mesaj' => 'kaydedildi', 'n' => $sayac,
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}
