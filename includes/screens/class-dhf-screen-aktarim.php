<?php
/**
 * Aktarım ekranı (0.3).
 *
 * Önizleme → doğrulama → onay → yazma. Önizlemede işlem defterine hiçbir şey yazılmaz.
 * Tek yazma düğmesi "Kaydet ve deftere yaz"; o da eşleşmeyen satır kaldıkça çalışmaz.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Screen_Aktarim {

	private const AYLAR = [ 1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
		'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık' ];

	private const DURUM_ETIKET = [
		'hazir'     => [ 'Hazır', '#1a7f37' ],
		'eslesmedi' => [ 'Kalem seç', '#b26200' ],
		'kuyruk'    => [ 'İnceleme', '#b32d2e' ],
		'zaten_var' => [ 'Daha önce aktarıldı', '#646970' ],
		'yazildi'   => [ 'Yazıldı', '#1a7f37' ],
		'atlandi'   => [ 'Atlandı', '#646970' ],
	];

	public static function render(): void {
		DHF_Caps::guard();

		echo '<div class="wrap dhf-aktarim">';
		echo '<h1>' . esc_html__( 'Aktarım', 'deniz-finans' ) . '</h1>';
		self::bildirim();

		$id  = isset( $_GET['aktarim'] ) ? absint( wp_unslash( $_GET['aktarim'] ) ) : 0;
		$akt = $id ? DHF_Aktarim::bul( $id ) : null;

		if ( $akt ) {
			self::detay( $akt );
		} else {
			self::yukleme_formu();
			self::gecmis();
		}
		echo '</div>';
		self::stil();
	}

	/* ------------------------------------------------------------------ */

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'dhf-aktarim' ], $args ), admin_url( 'admin.php' ) );
	}

	private static function bildirim(): void {
		if ( ! isset( $_GET['dhf_mesaj'] ) ) {
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['dhf_mesaj'] ) );
		$n   = isset( $_GET['n'] ) ? absint( wp_unslash( $_GET['n'] ) ) : 0;
		$m   = isset( $_GET['m'] ) ? absint( wp_unslash( $_GET['m'] ) ) : 0;
		$hata = get_transient( 'dhf_aktarim_hata_' . get_current_user_id() );

		$mesajlar = [
			'onizleme' => [ 'success', __( 'Önizleme hazır. Defterine henüz hiçbir şey yazılmadı.', 'deniz-finans' ) ],
			'kaydedildi' => [ 'success', sprintf( __( '%d satır güncellendi.', 'deniz-finans' ), $n ) ],
			'yazildi'  => [ 'success', sprintf( __( 'Deftere yazıldı: %1$d satır. Gerekçesiyle atlanan: %2$d satır.', 'deniz-finans' ), $n, $m ) ],
			'silindi'  => [ 'success', __( 'Önizleme silindi.', 'deniz-finans' ) ],
			'hata'     => [ 'error', $hata ? (string) $hata : __( 'İşlem yapılamadı.', 'deniz-finans' ) ],
		];
		if ( isset( $mesajlar[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $mesajlar[ $key ][0] ), esc_html( $mesajlar[ $key ][1] ) );
		}
		if ( 'hata' === $key ) {
			delete_transient( 'dhf_aktarim_hata_' . get_current_user_id() );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Yükleme                                                             */
	/* ------------------------------------------------------------------ */

	private static function yukleme_formu(): void {
		echo '<div class="dhf-kutu">';
		echo '<h2>' . esc_html__( 'Yeni aktarım', 'deniz-finans' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Google Sheets\'te sekmeyi aç → Dosya → İndir → Virgülle ayrılmış değerler (.csv). İndirilen dosyayı buraya yükle. Kaynak dosya değişmez; toplam satırları okunmaz.',
			'deniz-finans'
		) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dhf_aktarim_yukle' );
		echo '<input type="hidden" name="action" value="dhf_aktarim_yukle">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th><label for="dhf-dosya">' . esc_html__( 'CSV dosyası', 'deniz-finans' ) . '</label></th>';
		echo '<td><input type="file" id="dhf-dosya" name="dosya" accept=".csv,text/csv" required></td></tr>';

		echo '<tr><th>' . esc_html__( 'Eşleştirme profili', 'deniz-finans' ) . '</th><td><select name="profil">';
		foreach ( DHF_Ayristirici::PROFILLER as $k => $p ) {
			printf( '<option value="%s">%s</option>', esc_attr( $k ), esc_html( $p['etiket'] ) );
		}
		echo '</select><p class="description">' . esc_html__( '2025 profili 0.6\'da eklenecek.', 'deniz-finans' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Yalnızca bu ay', 'deniz-finans' ) . '</th><td>';
		echo '<select name="yil"><option value="">' . esc_html__( 'Tüm dosya', 'deniz-finans' ) . '</option>';
		for ( $y = 2025; $y <= 2027; $y++ ) {
			printf( '<option value="%1$d"%2$s>%1$d</option>', $y, selected( $y, 2026, false ) );
		}
		echo '</select> <select name="ay"><option value="">—</option>';
		foreach ( self::AYLAR as $n => $ad ) {
			printf( '<option value="%d"%s>%s</option>', $n, selected( $n, 3, false ), esc_html( $ad ) );
		}
		echo '</select><p class="description">' . esc_html__(
			'Tarihi okunamayan satırlar hangi aya ait olduğu bilinemediği için her durumda inceleme kuyruğuna girer.',
			'deniz-finans'
		) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Önizle', 'deniz-finans' ) );
		echo '</form></div>';
	}

	private static function gecmis(): void {
		$liste = DHF_Aktarim::liste();
		echo '<h2>' . esc_html__( 'Geçmiş aktarımlar', 'deniz-finans' ) . '</h2>';
		if ( ! $liste ) {
			echo '<p>' . esc_html__( 'Henüz aktarım yok.', 'deniz-finans' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:960px"><thead><tr>';
		foreach ( [ '#', 'Tarih', 'Dosya', 'Kapsam', 'Satır', 'Kuyruk', 'Yazılan', 'Durum' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $liste as $a ) {
			$kapsam = $a->filtre_ay ? self::AYLAR[ (int) $a->filtre_ay ] . ' ' . $a->filtre_yil : __( 'Tüm dosya', 'deniz-finans' );
			printf(
				'<tr><td><a href="%s">%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
				esc_url( self::url( [ 'aktarim' => (int) $a->id ] ) ), (int) $a->id,
				esc_html( mysql2date( 'd.m.Y H:i', $a->tarih ) ),
				esc_html( (string) $a->dosya_adi ), esc_html( $kapsam ),
				(int) $a->satir_sayisi, (int) $a->kuyruk_sayisi, (int) $a->yazilan,
				'onizleme' === $a->durum ? '<strong>' . esc_html__( 'Onay bekliyor', 'deniz-finans' ) . '</strong>' : esc_html__( 'Yazıldı', 'deniz-finans' )
			);
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------------ */
	/* Önizleme / sonuç                                                    */
	/* ------------------------------------------------------------------ */

	private static function detay( object $akt ): void {
		$aktarim_id = (int) $akt->id;
		$satirlar   = DHF_Aktarim::satirlar( $aktarim_id );
		$ozet       = DHF_Aktarim::ozet( $aktarim_id );
		$acik       = 'onizleme' === $akt->durum;
		$kapsam     = $akt->filtre_ay ? self::AYLAR[ (int) $akt->filtre_ay ] . ' ' . $akt->filtre_yil : __( 'tüm dosya', 'deniz-finans' );

		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( self::url() ), esc_html__( 'Aktarımlar', 'deniz-finans' ) );
		printf( '<h2>#%d · %s · %s</h2>', $aktarim_id, esc_html( (string) $akt->dosya_adi ), esc_html( $kapsam ) );

		if ( ! $acik && $akt->filtre_ay ) {
			printf( '<p><a class="button" href="%s">%s</a></p>',
				esc_url( add_query_arg( [ 'page' => 'dhf-hareketler', 'yil' => (int) $akt->filtre_yil, 'ay' => (int) $akt->filtre_ay ], admin_url( 'admin.php' ) ) ),
				esc_html__( 'Ayın hareketlerine git', 'deniz-finans' ) );
		}

		self::ozet_kutusu( $ozet, $acik );

		$kalemler = [];
		foreach ( DHF_Kalem::hepsi() as $k ) {
			$kalemler[ $k->tur ][] = $k;
		}

		if ( $acik ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'dhf_aktarim_karar_' . $aktarim_id );
			echo '<input type="hidden" name="action" value="dhf_aktarim_karar">';
			echo '<input type="hidden" name="aktarim_id" value="' . esc_attr( (string) $aktarim_id ) . '">';
		}

		echo '<table class="widefat dhf-aktarim-tablo"><thead><tr>';
		foreach ( [ 'Satır', 'Durum', 'Tarih', 'Kategori', 'Açıklama (kaynakta)', 'Tutar', 'Kalem', 'Etki', 'Karar / not' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $satirlar as $r ) {
			self::satir( $r, $acik, $kalemler[ (string) $r->tur ] ?? [] );
		}
		echo '</tbody></table>';

		if ( $acik ) {
			echo '<p class="submit">';
			submit_button( __( 'Kararları kaydet', 'deniz-finans' ), 'secondary', 'kaydet', false );
			echo ' ';
			submit_button(
				__( 'Kaydet ve deftere yaz', 'deniz-finans' ), 'primary', 'yaz', false,
				$ozet['eksik_kalem'] ? [ 'title' => __( 'Önce kalemi seçilmeyen satırları tamamla', 'deniz-finans' ) ] : []
			);
			echo '</p></form>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
			wp_nonce_field( 'dhf_aktarim_sil_' . $aktarim_id );
			echo '<input type="hidden" name="action" value="dhf_aktarim_sil">';
			echo '<input type="hidden" name="aktarim_id" value="' . esc_attr( (string) $aktarim_id ) . '">';
			submit_button( __( 'Önizlemeyi sil', 'deniz-finans' ), 'link-delete small', 'sil', false );
			echo '</form>';
		}
	}

	private static function ozet_kutusu( array $o, bool $acik ): void {
		$fmt = static fn( float $v ): string => number_format( $v, 2, ',', '.' );
		$d   = $o['durum'];

		echo '<div class="dhf-serit">';
		foreach ( self::DURUM_ETIKET as $k => [ $etiket, $renk ] ) {
			if ( ! empty( $d[ $k ] ) ) {
				printf( '<span style="color:%s"><strong>%d</strong> %s</span>', esc_attr( $renk ), (int) $d[ $k ], esc_html( $etiket ) );
			}
		}
		echo '</div>';

		echo '<div class="dhf-ozet">';
		echo '<div><h3>' . esc_html__( 'Sheets\'te yazan (düz toplam)', 'deniz-finans' ) . '</h3><table>';
		foreach ( [ 'gelir' => 'Gelir', 'gider' => 'Gider', 'yatirim' => 'Yatırım' ] as $k => $e ) {
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $e ), esc_html( $fmt( $o['duz'][ $k ] ) ) );
		}
		printf( '<tr class="dhf-soluk"><td>%s</td><td>%s</td></tr>', esc_html__( 'Atlanan / yazılmayacak', 'deniz-finans' ), esc_html( $fmt( $o['atlanan'] ) ) );
		echo '</table></div>';

		echo '<div><h3>' . esc_html( $acik ? __( 'Yazılınca defterde görünecek', 'deniz-finans' ) : __( 'Deftere yazılan', 'deniz-finans' ) ) . '</h3><table>';
		$e = $o['etki'];
		foreach ( [
			[ 'Tüketim', $e['tuketim_gideri'], true ],
			[ 'Faiz', $e['faiz_masraf'], true ],
			[ 'Kart borcu azaltımı', $e['borc_azaltimi'], false ],
			[ 'Yatırım', $e['yatirim_alimi'], false ],
			[ 'Gelir', $e['gelir'], false ],
			[ 'Nakit çıkışı (hepsi)', $e['nakit_cikisi'], false ],
			[ 'Nakit ihtiyacı', $e['nakit_ihtiyaci'], true ],
		] as [ $etiket, $deger, $kalin ] ) {
			printf( '<tr%s><td>%s</td><td>%s</td></tr>', $kalin ? ' class="dhf-kalin"' : '', esc_html( $etiket ), esc_html( $fmt( (float) $deger ) ) );
		}
		echo '</table><p class="description">' . esc_html__(
			'Tek bir "gider" toplamı yok: kart ödemesi ve kredi taksiti tüketime yazılmaz.', 'deniz-finans'
		) . '</p></div>';
		echo '</div>';
	}

	private static function satir( object $r, bool $acik, array $kalemler ): void {
		$ham     = json_decode( (string) $r->ham_veri, true ) ?: [];
		[ $etiket, $renk ] = self::DURUM_ETIKET[ $r->onay_durumu ] ?? [ $r->onay_durumu, '#000' ];
		$duzenle = $acik && in_array( $r->onay_durumu, [ 'hazir', 'eslesmedi', 'kuyruk' ], true );
		$id      = (int) $r->id;

		printf( '<tr class="dhf-d-%s">', esc_attr( $r->onay_durumu ) );
		printf( '<td>%d</td>', (int) $r->kaynak_satir_no );
		printf( '<td><span style="color:%s;font-weight:600">%s</span></td>', esc_attr( $renk ), esc_html( $etiket ) );

		// Tarih: okunamadıysa ham hali kırmızı gösterilir; kuyrukta düzeltme alanı açılır.
		if ( $r->tarih ) {
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $r->tarih ) ) );
		} else {
			echo '<td><code style="color:#b32d2e">' . esc_html( (string) ( $ham['tarih'] ?? '' ) ) . '</code>';
			if ( $duzenle ) {
				printf( '<br><input type="text" name="r[%d][tarih]" placeholder="GG.AA.YYYY" size="10">', $id );
			}
			echo '</td>';
		}

		printf( '<td>%s</td>', esc_html( (string) ( $r->kategori_ad ?: ( $ham['kategori'] ?? '' ) ) ) );
		printf( '<td>%s</td>', esc_html( (string) ( $ham['aciklama'] ?? '' ) ) );
		printf( '<td style="text-align:right;white-space:nowrap">%s</td>',
			null === $r->tutar ? '<code>' . esc_html( (string) ( $ham['tutar'] ?? '' ) ) . '</code>' : esc_html( number_format( (float) $r->tutar, 2, ',', '.' ) ) );

		// Kalem
		echo '<td>';
		if ( $duzenle && $kalemler ) {
			$oneri = 'oneri' === $r->karar;
			printf( '<select name="r[%d][kalem_id]"%s>', $id, $oneri ? ' class="dhf-oneri"' : '' );
			echo '<option value="">' . esc_html__( '— kalem seç —', 'deniz-finans' ) . '</option>';
			foreach ( $kalemler as $k ) {
				printf( '<option value="%d"%s>%s%s</option>', (int) $k->id, selected( (int) $r->kalem_id, (int) $k->id, false ),
					esc_html( $k->ad ), (int) $k->aktif ? '' : ' (pasif)' );
			}
			echo '</select>';
			if ( 'hazir' !== $r->onay_durumu ) {
				printf( '<br><label><input type="checkbox" name="r[%d][hatirla]" value="1" checked> %s</label>', $id, esc_html__( 'bu açıklamayı hatırla', 'deniz-finans' ) );
			}
			if ( $oneri ) {
				echo '<br><span class="description">' . esc_html__( 'öneri — kontrol et', 'deniz-finans' ) . '</span>';
			}
		} else {
			echo esc_html( (string) ( $r->kalem_ad ?: '—' ) );
		}
		echo '</td>';

		printf( '<td><small>%s</small></td>', esc_html( $r->etki_profili ? DHF_Etki::ozet( (string) $r->etki_profili ) : '—' ) );

		// Karar
		echo '<td>';
		if ( $r->hata ) {
			echo '<span style="color:#b32d2e">' . esc_html( DHF_Aktarim::hata_metni( (string) $r->hata ) ) . '</span><br>';
		}
		if ( $r->gerekce ) {
			echo '<span class="description">' . esc_html( (string) $r->gerekce ) . '</span><br>';
		}
		if ( $duzenle && 'kuyruk' === $r->onay_durumu ) {
			foreach ( [ 'atla' => __( 'Atla', 'deniz-finans' ), 'ekle' => __( 'Yine de ekle', 'deniz-finans' ) ] as $k => $e ) {
				printf( '<label style="margin-right:8px"><input type="radio" name="r[%d][karar]" value="%s"%s> %s</label>',
					$id, esc_attr( $k ), checked( $r->karar ?: 'atla', $k, false ), esc_html( $e ) );
			}
		}
		if ( $r->eslesen_islem_id ) {
			printf( '<small>%s #%d</small>', esc_html__( 'işlem', 'deniz-finans' ), (int) $r->eslesen_islem_id );
		}
		echo '</td></tr>';
	}

	/* ------------------------------------------------------------------ */
	/* İşleyiciler                                                         */
	/* ------------------------------------------------------------------ */

	public static function handle_yukle(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_aktarim_yukle' );

		$dosya = $_FILES['dosya'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $dosya || UPLOAD_ERR_OK !== (int) $dosya['error'] || ! is_uploaded_file( (string) $dosya['tmp_name'] ) ) {
			self::hata( __( 'Dosya yüklenemedi.', 'deniz-finans' ) );
		}
		$ad = sanitize_file_name( wp_unslash( (string) $dosya['name'] ) );
		if ( ! preg_match( '/\.(csv|txt)$/i', $ad ) || (int) $dosya['size'] > 2 * MB_IN_BYTES ) {
			self::hata( __( 'Yalnızca 2 MB\'tan küçük .csv dosyası kabul edilir.', 'deniz-finans' ) );
		}

		// Geçici dosya okunur ve hemen silinir; uploads klasörüne hiçbir şey yazılmaz (§8).
		$metin = (string) file_get_contents( (string) $dosya['tmp_name'] );
		wp_delete_file( (string) $dosya['tmp_name'] );
		if ( ! mb_check_encoding( $metin, 'UTF-8' ) ) {
			$metin = mb_convert_encoding( $metin, 'UTF-8', 'Windows-1254' );
		}

		$profil = sanitize_key( wp_unslash( (string) ( $_POST['profil'] ?? '2026' ) ) );
		$yil    = absint( $_POST['yil'] ?? 0 ) ?: null;
		$ay     = absint( $_POST['ay'] ?? 0 ) ?: null;
		if ( ! $yil || ! $ay || $ay > 12 ) {
			$yil = $ay = null;
		}

		$sonuc = DHF_Aktarim::onizleme( $metin, $ad, $profil, $yil, $ay );
		if ( is_wp_error( $sonuc ) ) {
			self::hata( $sonuc->get_error_message() );
		}
		wp_safe_redirect( self::url( [ 'aktarim' => $sonuc, 'dhf_mesaj' => 'onizleme' ] ) );
		exit;
	}

	public static function handle_karar(): void {
		DHF_Caps::guard();
		$aktarim_id = absint( $_POST['aktarim_id'] ?? 0 );
		check_admin_referer( 'dhf_aktarim_karar_' . $aktarim_id );

		$ham      = isset( $_POST['r'] ) && is_array( $_POST['r'] ) ? wp_unslash( $_POST['r'] ) : [];
		$kararlar = [];
		foreach ( $ham as $sid => $k ) {
			if ( ! is_array( $k ) ) {
				continue;
			}
			$kararlar[ absint( $sid ) ] = [
				'kalem_id' => absint( $k['kalem_id'] ?? 0 ),
				'hatirla'  => ! empty( $k['hatirla'] ),
				'karar'    => sanitize_key( (string) ( $k['karar'] ?? '' ) ),
				'tarih'    => sanitize_text_field( (string) ( $k['tarih'] ?? '' ) ),
			];
		}
		$n = DHF_Aktarim::karar_kaydet( $aktarim_id, $kararlar );

		if ( isset( $_POST['yaz'] ) ) {
			$sonuc = DHF_Aktarim::yaz( $aktarim_id );
			if ( is_wp_error( $sonuc ) ) {
				self::hata( $sonuc->get_error_message(), $aktarim_id );
			}
			wp_safe_redirect( self::url( [ 'aktarim' => $aktarim_id, 'dhf_mesaj' => 'yazildi', 'n' => $sonuc['yazildi'], 'm' => $sonuc['atlandi'] ] ) );
			exit;
		}

		wp_safe_redirect( self::url( [ 'aktarim' => $aktarim_id, 'dhf_mesaj' => 'kaydedildi', 'n' => $n ] ) );
		exit;
	}

	public static function handle_sil(): void {
		DHF_Caps::guard();
		$aktarim_id = absint( $_POST['aktarim_id'] ?? 0 );
		check_admin_referer( 'dhf_aktarim_sil_' . $aktarim_id );
		DHF_Aktarim::iptal( $aktarim_id );
		wp_safe_redirect( self::url( [ 'dhf_mesaj' => 'silindi' ] ) );
		exit;
	}

	private static function hata( string $mesaj, int $aktarim_id = 0 ): void {
		set_transient( 'dhf_aktarim_hata_' . get_current_user_id(), $mesaj, 120 );
		wp_safe_redirect( self::url( array_filter( [ 'aktarim' => $aktarim_id, 'dhf_mesaj' => 'hata' ] ) ) );
		exit;
	}

	private static function stil(): void {
		?>
		<style>
			.dhf-kutu{background:#fff;border:1px solid #dcdcde;padding:4px 16px 8px;max-width:960px}
			.dhf-serit{display:flex;gap:20px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;padding:10px 14px;margin:10px 0}
			.dhf-ozet{display:flex;gap:24px;flex-wrap:wrap;margin:0 0 16px}
			.dhf-ozet>div{background:#fff;border:1px solid #dcdcde;padding:4px 14px 10px;min-width:280px}
			.dhf-ozet h3{margin:.6em 0}
			.dhf-ozet td{padding:2px 12px 2px 0}
			.dhf-ozet td:last-child{text-align:right;font-variant-numeric:tabular-nums}
			.dhf-ozet .dhf-kalin td{font-weight:700}
			.dhf-ozet .dhf-soluk td{color:#646970}
			.dhf-aktarim-tablo td{vertical-align:top}
			.dhf-aktarim-tablo tr.dhf-d-eslesmedi{background:#fff8e5}
			.dhf-aktarim-tablo tr.dhf-d-kuyruk{background:#fcf0f1}
			.dhf-aktarim-tablo tr.dhf-d-atlandi,.dhf-aktarim-tablo tr.dhf-d-zaten_var{opacity:.6}
			.dhf-aktarim-tablo select{max-width:240px}
			.dhf-aktarim-tablo select.dhf-oneri{border-color:#b26200}
			@media (max-width:782px){.dhf-aktarim-tablo th:nth-child(8),.dhf-aktarim-tablo td:nth-child(8){display:none}}
		</style>
		<?php
	}
}
