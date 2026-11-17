<?php
/**
 * Aylık Hareketler ekranı.
 *
 * Ay sonu tek oturumda doldurulur, bu yüzden pasif tablo değil tamamlama akışı:
 * tutarı bekleyen satırlar üstte, Tab ile yukarıdan aşağı tek geçiş.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Screen_Hareketler {

	public static function render(): void {
		DHF_Caps::guard();

		$yil = isset( $_GET['yil'] ) ? absint( wp_unslash( $_GET['yil'] ) ) : (int) current_time( 'Y' );
		$ay  = isset( $_GET['ay'] ) ? absint( wp_unslash( $_GET['ay'] ) ) : (int) current_time( 'n' );
		$yil = max( 2020, min( 2100, $yil ) );
		$ay  = max( 1, min( 12, $ay ) );

		$donem    = DHF_Islem::donem( $yil, $ay );
		$satirlar = $donem ? DHF_Islem::satirlar( (int) $donem->id ) : [];
		$gecen    = DHF_Islem::gecen_ay( $yil, $ay );

		echo '<div class="wrap dhf-hareketler">';
		self::baslik( $yil, $ay, $donem );
		self::bildirim();

		if ( ! $satirlar ) {
			self::bos_ay( $yil, $ay );
			echo '</div>';
			return;
		}

		self::durum_seridi( (int) $donem->id, count( $satirlar ) );
		self::tablo( $yil, $ay, $satirlar, $gecen );
		self::toplam_seridi( (int) $donem->id );

		echo '</div>';
		self::stil();
	}

	/* ------------------------------------------------------------------ */

	private static function baslik( int $yil, int $ay, ?object $donem ): void {
		$aylar = [ 1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
			'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık' ];

		$onceki = ( 1 === $ay ) ? [ $yil - 1, 12 ] : [ $yil, $ay - 1 ];
		$sonraki = ( 12 === $ay ) ? [ $yil + 1, 1 ] : [ $yil, $ay + 1 ];
		$url = static fn( array $d ): string => esc_url( add_query_arg(
			[ 'page' => 'dhf-hareketler', 'yil' => $d[0], 'ay' => $d[1] ], admin_url( 'admin.php' ) ) );

		echo '<h1 style="display:flex;align-items:center;gap:12px">';
		printf( '<a class="button" href="%s">&larr;</a>', $url( $onceki ) );
		printf( '<span>%s %d</span>', esc_html( $aylar[ $ay ] ), $yil );
		printf( '<a class="button" href="%s">&rarr;</a>', $url( $sonraki ) );
		if ( $donem ) {
			printf( '<span class="dhf-rozet dhf-rozet-%s">%s</span>',
				esc_attr( $donem->durum ), esc_html( $donem->durum ) );
		}
		echo '</h1>';
	}

	private static function bildirim(): void {
		if ( ! isset( $_GET['dhf_mesaj'] ) ) {
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['dhf_mesaj'] ) );
		$n   = isset( $_GET['n'] ) ? absint( wp_unslash( $_GET['n'] ) ) : 0;
		$m   = isset( $_GET['m'] ) ? absint( wp_unslash( $_GET['m'] ) ) : 0;

		$mesajlar = [
			'taslak_uretildi' => sprintf(
				/* translators: 1: eklenen 2: atlanan */
				__( 'Taslak üretildi: %1$d satır eklendi, %2$d kalem zaten vardı.', 'deniz-finans' ),
				$n, $m
			),
			'kaydedildi' => sprintf(
				/* translators: %d: satır sayısı */
				__( '%d satır kaydedildi.', 'deniz-finans' ),
				$n
			),
		];
		if ( isset( $mesajlar[ $key ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $mesajlar[ $key ] ) );
		}
	}

	private static function bos_ay( int $yil, int $ay ): void {
		$beklenen = count( DHF_Kalem::donem_icin( $yil, $ay ) );
		echo '<div class="notice notice-info"><p>';
		printf(
			esc_html__( 'Bu ay henüz oluşturulmadı. Kalem sözlüğünde bu ay için beklenen %d kalem var.', 'deniz-finans' ),
			(int) $beklenen
		);
		echo '</p></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dhf_taslak' );
		echo '<input type="hidden" name="action" value="dhf_taslak">';
		printf( '<input type="hidden" name="yil" value="%d"><input type="hidden" name="ay" value="%d">', $yil, $ay );
		submit_button( __( 'Ayı oluştur', 'deniz-finans' ), 'primary' );
		echo '</form>';
	}

	private static function durum_seridi( int $donem_id, int $toplam ): void {
		$bekleyen = DHF_Islem::bekleyen_sayisi( $donem_id );
		$mukerrer = DHF_Islem::mukerrer_sayisi( $donem_id );

		echo '<div class="dhf-serit">';
		printf( '<span><strong>%d</strong> satır</span>', $toplam );
		if ( $bekleyen ) {
			printf( '<span class="dhf-uyari"><strong>%d</strong> kalem tutar bekliyor</span>', $bekleyen );
		} else {
			echo '<span class="dhf-tamam">tüm tutarlar girildi</span>';
		}
		if ( $mukerrer ) {
			printf( '<span class="dhf-hata"><strong>%d</strong> mükerrer şüphesi</span>', $mukerrer );
		}
		echo '</div>';
	}

	private static function tablo( int $yil, int $ay, array $satirlar, array $gecen ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dhf_satirlar' );
		echo '<input type="hidden" name="action" value="dhf_satirlar">';
		printf( '<input type="hidden" name="yil" value="%d"><input type="hidden" name="ay" value="%d">', $yil, $ay );

		echo '<table class="widefat striped dhf-tablo"><thead><tr>';
		foreach ( [ 'Tarih', 'Kalem', 'Kategori', 'Geçen ay', 'Tutar', 'Durum', 'Etki', 'Not' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$gorulen = [];
		foreach ( $satirlar as $s ) {
			$bekliyor = ( 'degisken' === $s->tekrar_tipi && 0.0 === (float) $s->tutar && 'taslak' === $s->durum );
			$anahtar  = $s->kalem_id . '|' . (float) $s->tutar;
			$mukerrer = (float) $s->tutar > 0 && isset( $gorulen[ $anahtar ] );
			$gorulen[ $anahtar ] = true;

			$sinif = $bekliyor ? 'dhf-bekliyor' : '';
			$sinif .= $mukerrer ? ' dhf-mukerrer' : '';

			printf( '<tr class="%s">', esc_attr( trim( $sinif ) ) );
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m', $s->tarih ) ) );
			printf( '<td><strong>%s</strong>%s</td>',
				esc_html( $s->kalem_ad ?: $s->aciklama ),
				'otomatik_talimat' === $s->odeme_sekli
					? ' <span class="dhf-etiket">otomatik</span>' : ''
			);
			printf( '<td>%s</td>', esc_html( (string) $s->kategori_ad ) );

			$gecen_tutar = $gecen[ (int) $s->kalem_id ] ?? null;
			printf( '<td class="dhf-sag dhf-soluk">%s</td>',
				null === $gecen_tutar ? '—' : esc_html( number_format_i18n( $gecen_tutar, 2 ) ) );

			printf(
				'<td><input type="number" step="0.01" name="satir[%d][tutar]" value="%s" class="dhf-tutar%s"></td>',
				(int) $s->id,
				esc_attr( 0.0 === (float) $s->tutar ? '' : (string) $s->tutar ),
				$bekliyor ? ' dhf-bos' : ''
			);

			printf( '<td><select name="satir[%d][durum]">', (int) $s->id );
			foreach ( DHF_Islem::DURUMLAR as $k => $v ) {
				printf( '<option value="%s"%s>%s</option>',
					esc_attr( $k ), selected( $s->durum, $k, false ), esc_html( $v ) );
			}
			echo '</select></td>';

			printf( '<td class="dhf-soluk" title="%s">%s</td>',
				esc_attr( DHF_Etki::aciklama( (string) $s->etki_profili ) ),
				esc_html( DHF_Etki::ozet( (string) $s->etki_profili ) ) );

			printf( '<td><input type="text" name="satir[%d][not]" value="%s" class="dhf-not"></td>',
				(int) $s->id, esc_attr( (string) $s->not_metni ) );
			echo '</tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Tümünü kaydet', 'deniz-finans' ), 'primary' );
		echo '</form>';
	}

	private static function toplam_seridi( int $donem_id ): void {
		$t = DHF_Islem::toplamlar( $donem_id );

		// Tek bir "gider" toplamı bilinçli olarak gösterilmiyor (plan §5.1).
		$kutular = [
			[ 'Gelir', $t['gelir'], '' ],
			[ 'Tüketim', $t['tuketim_gideri'], '' ],
			[ 'Faiz', $t['faiz_masraf'], 'dhf-faiz' ],
			[ 'Borç azaltımı', $t['borc_azaltimi'], '' ],
			[ 'Yatırım', $t['yatirim_alimi'], '' ],
			[ 'Nakit ihtiyacı', $t['nakit_ihtiyaci'], 'dhf-vurgu' ],
		];

		echo '<div class="dhf-toplam">';
		foreach ( $kutular as [ $etiket, $deger, $sinif ] ) {
			printf(
				'<div class="dhf-kutu %s"><span class="dhf-kutu-etiket">%s</span><span class="dhf-kutu-deger">%s</span></div>',
				esc_attr( $sinif ), esc_html( $etiket ), esc_html( number_format_i18n( $deger, 2 ) )
			);
		}
		echo '</div>';
		echo '<p class="dhf-soluk">' . esc_html__(
			'Faiz ayrı duruyor: diğerlerinden farklı olarak karşılığında hiçbir şey alınmayan tek kalem. Kart ödemesi tüketime yazılmaz.',
			'deniz-finans'
		) . '</p>';
	}

	private static function stil(): void {
		echo '<style>
		.dhf-serit{display:flex;gap:20px;margin:12px 0;padding:10px 14px;background:#fff;border:1px solid #c3c4c7;border-left-width:4px;border-left-color:#2271b1}
		.dhf-serit .dhf-uyari{color:#8a6100}.dhf-serit .dhf-hata{color:#b32d2e}.dhf-serit .dhf-tamam{color:#1a7f37}
		.dhf-tablo td{vertical-align:middle}
		.dhf-tablo tr.dhf-bekliyor{background:#fcf3d7}
		.dhf-tablo tr.dhf-mukerrer{box-shadow:inset 3px 0 0 #b32d2e}
		.dhf-tutar{width:120px;text-align:right}
		.dhf-tutar.dhf-bos{background:#fff8e0;border-color:#dba617}
		.dhf-not{width:100%;max-width:260px}
		.dhf-sag{text-align:right}.dhf-soluk{color:#646970;font-size:12px}
		.dhf-etiket{background:#f0f0f1;border-radius:3px;padding:1px 6px;font-size:11px;color:#646970}
		.dhf-rozet{font-size:12px;font-weight:400;background:#f0f0f1;border-radius:3px;padding:2px 8px}
		.dhf-toplam{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0}
		.dhf-kutu{flex:1;min-width:130px;background:#fff;border:1px solid #c3c4c7;padding:10px 14px}
		.dhf-kutu-etiket{display:block;font-size:12px;color:#646970}
		.dhf-kutu-deger{display:block;font-size:18px;font-weight:600;margin-top:2px}
		.dhf-kutu.dhf-faiz .dhf-kutu-deger{color:#b32d2e}
		.dhf-kutu.dhf-vurgu{border-color:#2271b1;border-width:2px}
		</style>';
	}

	/* ------------------------------------------------------------------ */
	/* POST işleyicileri                                                   */
	/* ------------------------------------------------------------------ */

	public static function handle_taslak(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_taslak' );

		$yil = isset( $_POST['yil'] ) ? absint( wp_unslash( $_POST['yil'] ) ) : 0;
		$ay  = isset( $_POST['ay'] ) ? absint( wp_unslash( $_POST['ay'] ) ) : 0;

		$sonuc = DHF_Islem::taslak_uret( $yil, $ay );

		self::geri( $yil, $ay, 'taslak_uretildi', $sonuc['eklendi'], $sonuc['atlandi'] );
	}

	public static function handle_satirlar(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_satirlar' );

		$yil = isset( $_POST['yil'] ) ? absint( wp_unslash( $_POST['yil'] ) ) : 0;
		$ay  = isset( $_POST['ay'] ) ? absint( wp_unslash( $_POST['ay'] ) ) : 0;

		$sayac = 0;
		$ham   = isset( $_POST['satir'] ) && is_array( $_POST['satir'] ) ? wp_unslash( $_POST['satir'] ) : [];

		foreach ( $ham as $id => $alanlar ) {
			$id = absint( $id );
			if ( ! $id || ! is_array( $alanlar ) ) {
				continue;
			}
			$tutar = (float) str_replace( ',', '.', (string) ( $alanlar['tutar'] ?? '0' ) );
			$durum = sanitize_key( (string) ( $alanlar['durum'] ?? 'taslak' ) );
			$not   = sanitize_text_field( (string) ( $alanlar['not'] ?? '' ) );

			$sayac += DHF_Islem::satir_guncelle( $id, $tutar, $durum, $not ) ? 1 : 0;
		}

		self::geri( $yil, $ay, 'kaydedildi', $sayac, 0 );
	}

	private static function geri( int $yil, int $ay, string $mesaj, int $n, int $m ): void {
		wp_safe_redirect( add_query_arg( [
			'page' => 'dhf-hareketler', 'yil' => $yil, 'ay' => $ay,
			'dhf_mesaj' => $mesaj, 'n' => $n, 'm' => $m,
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}
