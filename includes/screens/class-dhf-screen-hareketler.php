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

		// Ayı görüntülemek dönemi oluşturmaz; dönem "Ayı oluştur" ya da ilk satırla doğar.
		$donem    = DHF_Islem::donem( $yil, $ay, false );
		$satirlar = $donem ? DHF_Islem::satirlar( (int) $donem->id ) : [];
		$gecen    = DHF_Islem::gecen_ay( $yil, $ay );
		$acik     = DHF_Donem::duzenlenebilir( $donem );

		echo '<div class="wrap dhf-hareketler">';
		self::baslik( $yil, $ay, $donem );
		self::bildirim();

		if ( ! $satirlar ) {
			self::bos_ay( $yil, $ay );
			self::satir_ekle_formu( $yil, $ay );
			echo '</div>';
			self::stil();
			return;
		}

		$eksikler = $acik ? DHF_Islem::eksikler( $yil, $ay ) : [];
		self::durum_seridi( $yil, $ay, $donem, count( $satirlar ), count( $eksikler ) );
		self::donem_dugmeleri( $yil, $ay, $donem );
		if ( $eksikler ) {
			self::eksikler( $yil, $ay, $eksikler );
		}
		self::tablo( $yil, $ay, $satirlar, $gecen, $acik );
		self::toplam_seridi( (int) $donem->id );
		if ( $acik ) {
			self::satir_ekle_formu( $yil, $ay );
		}
		self::kayitlar( (int) $donem->id );

		echo '</div>';
		self::stil();
	}

	/* ------------------------------------------------------------------ */

	private static function baslik( int $yil, int $ay, ?object $donem ): void {
		$aylar   = DHF_Takvim::AYLAR;
		$onceki  = DHF_Takvim::onceki( $yil, $ay );
		$sonraki = DHF_Takvim::sonraki( $yil, $ay );
		$url = static fn( array $d ): string => esc_url( add_query_arg(
			[ 'page' => 'dhf-hareketler', 'yil' => $d[0], 'ay' => $d[1] ], admin_url( 'admin.php' ) ) );

		echo '<h1 style="display:flex;align-items:center;gap:12px">';
		printf( '<a class="button" href="%s">&larr;</a>', $url( $onceki ) );
		printf( '<span>%s %d</span>', esc_html( $aylar[ $ay ] ), $yil );
		printf( '<a class="button" href="%s">&rarr;</a>', $url( $sonraki ) );
		if ( $donem ) {
			printf( '<span class="dhf-rozet dhf-rozet-%s">%s</span>',
				esc_attr( $donem->durum ), esc_html( DHF_Donem::DURUMLAR[ $donem->durum ] ?? $donem->durum ) );
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
				__( '%d satır güncellendi.', 'deniz-finans' ),
				$n
			),
			'eklendi'    => __( 'Satır eklendi.', 'deniz-finans' ),
			'eksik_eklendi' => sprintf( __( '%d kalem bu aya eklendi; tutarlarını gir.', 'deniz-finans' ), $n ),
			'durum'      => __( 'Ayın durumu değişti.', 'deniz-finans' ),
		];
		if ( 'hata' === $key ) {
			$hata = get_transient( 'dhf_hareket_hata_' . get_current_user_id() );
			delete_transient( 'dhf_hareket_hata_' . get_current_user_id() );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $hata ?: __( 'İşlem yapılamadı.', 'deniz-finans' ) ) );
			return;
		}
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

	private static function durum_seridi( int $yil, int $ay, object $donem, int $toplam, int $eksik ): void {
		$donem_id = (int) $donem->id;
		$bekleyen = DHF_Islem::bekleyen_sayisi( $donem_id );
		$mukerrer = DHF_Islem::mukerrer_sayisi( $donem_id );
		echo '<div class="dhf-serit">';
		printf( '<span><strong>%d</strong> satır</span>', $toplam );
		if ( $bekleyen ) {
			printf( '<span class="dhf-uyari"><strong>%d</strong> kalem tutar bekliyor</span>', $bekleyen );
		} else {
			echo '<span class="dhf-tamam">tüm tutarlar girildi</span>';
		}
		if ( $eksik ) {
			printf( '<span class="dhf-uyari"><a href="#dhf-eksikler"><strong>%d</strong> kalem geçen ay vardı, bu ay yok</a></span>', $eksik );
		}
		if ( $mukerrer ) {
			printf( '<span class="dhf-hata"><strong>%d</strong> mükerrer şüphesi</span>', $mukerrer );
		}
		echo '</div>';

	}

	private static function donem_dugmeleri( int $yil, int $ay, object $donem ): void {
		$gecisler = DHF_Donem::GECISLER[ $donem->durum ] ?? [];
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dhf-donem-form">';
		wp_nonce_field( 'dhf_donem_durum' );
		echo '<input type="hidden" name="action" value="dhf_donem_durum">';
		printf( '<input type="hidden" name="yil" value="%d"><input type="hidden" name="ay" value="%d">', $yil, $ay );

		if ( 'kesinlesti' === $donem->durum ) {
			printf( '<span class="dhf-soluk">%s</span> ', esc_html( sprintf(
				__( 'Bu ay %s tarihinde kesinleşti; satırlar kilitli.', 'deniz-finans' ),
				mysql2date( 'd.m.Y H:i', (string) $donem->kesinlesme_tarihi )
			) ) );
			echo '<input type="text" name="gerekce" required placeholder="' . esc_attr__( 'Yeniden açma gerekçesi', 'deniz-finans' ) . '" style="min-width:260px"> ';
		} elseif ( 'yeniden_acildi' === $donem->durum ) {
			printf( '<span class="dhf-uyari">%s</span> ', esc_html( sprintf(
				__( 'Yeniden açıldı: "%s". Her değişiklik kayda geçiyor.', 'deniz-finans' ),
				(string) DHF_Donem::acilma_gerekcesi( (int) $donem->id )
			) ) );
		} else {
			echo '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'dhf_taslak_eksik', 'yil' => $yil, 'ay' => $ay ], admin_url( 'admin-post.php' ) ), 'dhf_taslak_eksik' ) ) . '">'
				. esc_html__( 'Sözlükten eksik kalemleri ekle', 'deniz-finans' ) . '</a> ';
		}
		foreach ( $gecisler as $hedef => $etiket ) {
			printf( '<button type="submit" name="hedef" value="%s" class="button%s">%s</button> ',
				esc_attr( $hedef ), 'kesinlesti' === $hedef ? ' button-primary' : '', esc_html( $etiket ) );
		}
		echo '</form>';
	}

	private static function eksikler( int $yil, int $ay, array $eksikler ): void {
		echo '<div class="dhf-eksik" id="dhf-eksikler">';
		echo '<h3>' . esc_html__( 'Geçen ay vardı, bu ay yok', 'deniz-finans' ) . '</h3>';
		echo '<p class="dhf-soluk">' . esc_html__(
			'Deftere girilmeden kalan ödemeler çoğunlukla otomatik talimatlar. Ödendiyse işaretle ve ekle; tutarı tabloda girersin. Ödenmediyse boş bırak.',
			'deniz-finans'
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dhf_eksik_ekle' );
		echo '<input type="hidden" name="action" value="dhf_eksik_ekle">';
		printf( '<input type="hidden" name="yil" value="%d"><input type="hidden" name="ay" value="%d">', $yil, $ay );
		echo '<ul>';
		foreach ( $eksikler as $k ) {
			printf( '<li><label><input type="checkbox" name="kalem[]" value="%d" checked> <strong>%s</strong> <span class="dhf-soluk">— geçen ay %s, %s%s</span></label></li>',
				(int) $k->id, esc_html( $k->ad ),
				esc_html( mysql2date( 'd.m', (string) $k->gecen_tarih ) ),
				esc_html( number_format_i18n( (float) $k->gecen_tutar, 2 ) ),
				'elle' !== $k->odeme_sekli ? ' · ' . esc_html( DHF_Kalem::ODEME[ $k->odeme_sekli ] ?? $k->odeme_sekli ) : ''
			);
		}
		echo '</ul>';
		submit_button( __( 'Seçilenleri bu aya ekle', 'deniz-finans' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	private static function tablo( int $yil, int $ay, array $satirlar, array $gecen, bool $acik = true ): void {
		$kilit = $acik ? '' : ' disabled';
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
				'<td><input type="number" step="0.01" min="0" name="satir[%d][tutar]" value="%s" class="dhf-tutar%s"%s></td>',
				(int) $s->id,
				esc_attr( 0.0 === (float) $s->tutar ? '' : (string) $s->tutar ),
				$bekliyor ? ' dhf-bos' : '',
				$kilit
			);

			printf( '<td><select name="satir[%d][durum]"%s>', (int) $s->id, $kilit );
			foreach ( DHF_Islem::DURUMLAR as $k => $v ) {
				printf( '<option value="%s"%s>%s</option>',
					esc_attr( $k ), selected( $s->durum, $k, false ), esc_html( $v ) );
			}
			echo '</select></td>';

			printf( '<td class="dhf-soluk" title="%s">%s</td>',
				esc_attr( DHF_Etki::aciklama( (string) $s->etki_profili ) ),
				esc_html( DHF_Etki::ozet( (string) $s->etki_profili ) ) );

			printf( '<td><input type="text" name="satir[%d][not]" value="%s" class="dhf-not"%s></td>',
				(int) $s->id, esc_attr( (string) $s->not_metni ), $kilit );
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $acik ) {
			submit_button( __( 'Tümünü kaydet', 'deniz-finans' ), 'primary' );
		}
		echo '</form>';
	}

	/**
	 * Elle satır: deftere girilmemiş ödeme ya da tek seferlik harcama. Sözlükte olmayan
	 * bir şeyse "+ Yeni kalem" ile aynı formda oluşturulur. Mükerrer şüphesinde form
	 * değerleriyle geri gelir ve "yine de ekle" sorar (§2.7).
	 */
	private static function satir_ekle_formu( int $yil, int $ay ): void {
		$kayit = get_transient( 'dhf_satir_form_' . get_current_user_id() );
		delete_transient( 'dhf_satir_form_' . get_current_user_id() );
		$v = is_array( $kayit ) ? $kayit : [];

		global $wpdb;
		$kategoriler = $wpdb->get_results( 'SELECT id, ad, tur FROM ' . DHF_Schema::table( 'kategori' ) . " WHERE aktif = 1 ORDER BY FIELD(tur, 'gider', 'gelir', 'yatirim'), sira, ad" );
		$kalemler    = DHF_Kalem::hepsi();
		$turler      = [ 'gider' => 'Gider', 'gelir' => 'Gelir', 'yatirim' => 'Yatırım' ];
		$varsayilan  = $v['tarih'] ?? sprintf( '%04d-%02d-%02d', $yil, $ay, min( (int) current_time( 'j' ), DHF_Takvim::ay_sonu( $yil, $ay ) ) );

		echo '<div class="dhf-ekle" id="dhf-ekle">';
		echo '<h3>' . esc_html__( 'Satır ekle', 'deniz-finans' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dhf_satir_ekle' );
		echo '<input type="hidden" name="action" value="dhf_satir_ekle">';
		printf( '<input type="hidden" name="yil" value="%d"><input type="hidden" name="ay" value="%d">', $yil, $ay );

		echo '<div class="dhf-ekle-satir">';
		printf( '<label>%s<br><input type="date" name="tarih" value="%s" required></label>', esc_html__( 'Tarih', 'deniz-finans' ), esc_attr( $varsayilan ) );

		echo '<label>' . esc_html__( 'Kalem', 'deniz-finans' ) . '<br><select name="kalem_id" class="dhf-kalem-sec">';
		echo '<option value="">— ' . esc_html__( 'seç', 'deniz-finans' ) . ' —</option>';
		foreach ( $turler as $tur => $etiket ) {
			printf( '<optgroup label="%s">', esc_attr( $etiket ) );
			foreach ( $kalemler as $k ) {
				if ( $k->tur === $tur ) {
					printf( '<option value="%d"%s>%s%s</option>', (int) $k->id, selected( (int) ( $v['kalem_id'] ?? 0 ), (int) $k->id, false ),
						esc_html( $k->ad ), (int) $k->aktif ? '' : ' (pasif)' );
				}
			}
			echo '</optgroup>';
		}
		printf( '<option value="yeni"%s>+ %s</option>', selected( (string) ( $v['kalem_id'] ?? '' ), 'yeni', false ), esc_html__( 'Yeni kalem…', 'deniz-finans' ) );
		echo '</select></label>';

		printf( '<label>%s<br><input type="number" step="0.01" min="0.01" name="tutar" value="%s" class="dhf-tutar" required></label>',
			esc_html__( 'Tutar', 'deniz-finans' ), esc_attr( (string) ( $v['tutar'] ?? '' ) ) );
		printf( '<label>%s<br><input type="text" name="not" value="%s" class="dhf-not"></label>',
			esc_html__( 'Not', 'deniz-finans' ), esc_attr( (string) ( $v['not'] ?? '' ) ) );
		echo '</div>';

		// Yeni kalem paneli — yalnızca "Yeni kalem…" seçilince görünür.
		echo '<div class="dhf-yeni-kalem"' . ( 'yeni' === ( $v['kalem_id'] ?? '' ) ? '' : ' hidden' ) . '>';
		printf( '<label>%s<br><input type="text" name="yeni[ad]" value="%s"></label>', esc_html__( 'Kalem adı', 'deniz-finans' ), esc_attr( (string) ( $v['yeni']['ad'] ?? '' ) ) );
		echo '<label>' . esc_html__( 'Kategori', 'deniz-finans' ) . '<br><select name="yeni[kategori_id]">';
		foreach ( $kategoriler as $c ) {
			printf( '<option value="%d"%s>%s · %s</option>', (int) $c->id, selected( (int) ( $v['yeni']['kategori_id'] ?? 0 ), (int) $c->id, false ),
				esc_html( $turler[ $c->tur ] ?? $c->tur ), esc_html( $c->ad ) );
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Tekrar', 'deniz-finans' ) . '<br><select name="yeni[tekrar]">';
		foreach ( DHF_Kalem::TEKRAR as $k => $e ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) ( $v['yeni']['tekrar'] ?? 'tek_seferlik' ), $k, false ), esc_html( $e ) );
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Etki', 'deniz-finans' ) . '<br><select name="yeni[profil]"><option value="">' . esc_html__( 'Türden (gider = tüketim)', 'deniz-finans' ) . '</option>';
		foreach ( array_keys( DHF_Etki::profiller() ) as $p ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $p ), selected( (string) ( $v['yeni']['profil'] ?? '' ), $p, false ), esc_html( DHF_Etki::etiket( $p ) ) );
		}
		echo '</select></label></div>';

		if ( ! empty( $v['mukerrer'] ) ) {
			echo '<p class="dhf-mukerrer-uyari"><label><input type="checkbox" name="onay" value="1"> '
				. esc_html__( 'Biliyorum, bu ikinci ayrı bir ödeme — yine de ekle', 'deniz-finans' ) . '</label></p>';
		}
		submit_button( __( 'Ekle', 'deniz-finans' ), 'secondary', 'submit', false );
		echo '</form></div>';
		?>
		<script>
		document.querySelectorAll('.dhf-kalem-sec').forEach(function (s) {
			s.addEventListener('change', function () {
				var p = s.closest('form').querySelector('.dhf-yeni-kalem');
				if (p) { p.hidden = s.value !== 'yeni'; }
			});
		});
		</script>
		<?php
	}

	private static function kayitlar( int $donem_id ): void {
		$kayitlar = DHF_Donem::kayitlar( $donem_id );
		if ( ! $kayitlar ) {
			return;
		}
		$alanlar = [ 'durum' => 'Ay durumu', 'tutar' => 'Tutar', 'not' => 'Not', 'satir_eklendi' => 'Satır eklendi', 'taslak_onay' => 'Taslaklar onaylandı' ];
		echo '<details class="dhf-kayit"><summary>' . esc_html( sprintf( __( 'Değişiklik kaydı (%d)', 'deniz-finans' ), count( $kayitlar ) ) ) . '</summary>';
		echo '<table class="widefat striped"><thead><tr><th>Zaman</th><th>Satır</th><th>Alan</th><th>Eski</th><th>Yeni</th><th>Gerekçe</th></tr></thead><tbody>';
		foreach ( $kayitlar as $l ) {
			$deger = static fn( ?string $d ): string => 'durum' === $l->alan ? ( DHF_Donem::DURUMLAR[ (string) $d ] ?? ( DHF_Islem::DURUMLAR[ (string) $d ] ?? (string) $d ) ) : (string) $d;
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( mysql2date( 'd.m.Y H:i', $l->tarih ) ), esc_html( (string) ( $l->aciklama ?: '—' ) ),
				esc_html( $alanlar[ $l->alan ] ?? $l->alan ), esc_html( $deger( $l->eski_deger ) ),
				esc_html( $deger( $l->yeni_deger ) ), esc_html( (string) $l->gerekce ) );
		}
		echo '</tbody></table></details>';
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
		.dhf-rozet-kesinlesti{background:#d1e7dd;color:#0f5132}.dhf-rozet-kontrol{background:#fff3cd}.dhf-rozet-yeniden_acildi{background:#f8d7da}
		.dhf-donem-form{margin:8px 0 12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
		.dhf-eksik,.dhf-ekle{background:#fff;border:1px solid #c3c4c7;padding:4px 14px 12px;margin:14px 0;max-width:960px}
		.dhf-eksik{border-left:4px solid #dba617}
		.dhf-eksik ul{margin:6px 0 10px}
		.dhf-ekle-satir,.dhf-yeni-kalem{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:10px}
		.dhf-yeni-kalem{background:#f6f7f7;padding:8px 10px}
		.dhf-mukerrer-uyari{color:#b32d2e;font-weight:600}
		.dhf-kayit{margin:16px 0;max-width:960px}.dhf-kayit summary{cursor:pointer;color:#2271b1}
		@media (max-width:782px){.dhf-tablo th:nth-child(3),.dhf-tablo td:nth-child(3),.dhf-tablo th:nth-child(7),.dhf-tablo td:nth-child(7){display:none}.dhf-tutar{width:96px}}
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

	public static function handle_taslak_eksik(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_taslak_eksik' );
		$yil   = absint( $_GET['yil'] ?? 0 );
		$ay    = absint( $_GET['ay'] ?? 0 );
		$sonuc = DHF_Islem::taslak_uret( $yil, $ay );
		self::geri( $yil, $ay, 'taslak_uretildi', $sonuc['eklendi'], $sonuc['atlandi'] );
	}

	public static function handle_eksik_ekle(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_eksik_ekle' );
		$yil = absint( $_POST['yil'] ?? 0 );
		$ay  = absint( $_POST['ay'] ?? 0 );
		$ids = isset( $_POST['kalem'] ) && is_array( $_POST['kalem'] ) ? array_map( 'absint', wp_unslash( $_POST['kalem'] ) ) : [];
		self::geri( $yil, $ay, 'eksik_eklendi', DHF_Islem::eksik_ekle( $yil, $ay, $ids ), 0 );
	}

	public static function handle_donem_durum(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_donem_durum' );
		$yil   = absint( $_POST['yil'] ?? 0 );
		$ay    = absint( $_POST['ay'] ?? 0 );
		$donem = DHF_Islem::donem( $yil, $ay, false );
		if ( ! $donem ) {
			self::hata( $yil, $ay, __( 'Bu ay henüz oluşturulmadı.', 'deniz-finans' ) );
		}
		$sonuc = DHF_Donem::gecis(
			(int) $donem->id,
			sanitize_key( wp_unslash( (string) ( $_POST['hedef'] ?? '' ) ) ),
			sanitize_text_field( wp_unslash( (string) ( $_POST['gerekce'] ?? '' ) ) )
		);
		if ( is_wp_error( $sonuc ) ) {
			self::hata( $yil, $ay, $sonuc->get_error_message() );
		}
		self::geri( $yil, $ay, 'durum', 0, 0 );
	}

	public static function handle_satir_ekle(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_satir_ekle' );
		$yil = absint( $_POST['yil'] ?? 0 );
		$ay  = absint( $_POST['ay'] ?? 0 );

		$yeni_ham = isset( $_POST['yeni'] ) && is_array( $_POST['yeni'] ) ? wp_unslash( $_POST['yeni'] ) : [];
		$v = [
			'tarih'    => sanitize_text_field( wp_unslash( (string) ( $_POST['tarih'] ?? '' ) ) ),
			'kalem_id' => sanitize_key( wp_unslash( (string) ( $_POST['kalem_id'] ?? '' ) ) ),
			'tutar'    => (float) str_replace( ',', '.', (string) wp_unslash( $_POST['tutar'] ?? '0' ) ),
			'not'      => sanitize_text_field( wp_unslash( (string) ( $_POST['not'] ?? '' ) ) ),
			'yeni'     => [
				'ad'          => sanitize_text_field( (string) ( $yeni_ham['ad'] ?? '' ) ),
				'kategori_id' => absint( $yeni_ham['kategori_id'] ?? 0 ),
				'tekrar'      => sanitize_key( (string) ( $yeni_ham['tekrar'] ?? '' ) ),
				'profil'      => sanitize_key( (string) ( $yeni_ham['profil'] ?? '' ) ),
			],
		];

		// Satır başka bir aya düşüyorsa kullanıcıyı o aya götür.
		if ( preg_match( '/^(\d{4})-(\d{2})-\d{2}$/', $v['tarih'], $m ) ) {
			$yil = (int) $m[1];
			$ay  = (int) $m[2];
		}

		$kalem_id = 'yeni' === $v['kalem_id']
			? DHF_Kalem::ekle( $v['yeni']['ad'], $v['yeni']['kategori_id'], $v['yeni']['tekrar'], $v['yeni']['profil'] )
			: absint( $v['kalem_id'] );
		if ( is_wp_error( $kalem_id ) ) {
			self::form_geri( $yil, $ay, $v, $kalem_id->get_error_message() );
		}
		if ( 'yeni' === $v['kalem_id'] ) {
			$v['kalem_id'] = $kalem_id; // tekrar denenirse ikinci kez kalem oluşturmasın
		}

		$sonuc = DHF_Islem::satir_ekle( [ 'tarih' => $v['tarih'], 'kalem_id' => $kalem_id, 'tutar' => $v['tutar'], 'not' => $v['not'] ], ! empty( $_POST['onay'] ) );
		if ( is_wp_error( $sonuc ) ) {
			$v['mukerrer'] = 'mukerrer' === $sonuc->get_error_code();
			self::form_geri( $yil, $ay, $v, $sonuc->get_error_message() );
		}
		self::geri( $yil, $ay, 'eklendi', 1, 0 );
	}

	/** @param array<string,mixed> $v */
	private static function form_geri( int $yil, int $ay, array $v, string $mesaj ): void {
		set_transient( 'dhf_satir_form_' . get_current_user_id(), $v, 300 );
		set_transient( 'dhf_hareket_hata_' . get_current_user_id(), $mesaj, 300 );
		wp_safe_redirect( add_query_arg( [ 'page' => 'dhf-hareketler', 'yil' => $yil, 'ay' => $ay, 'dhf_mesaj' => 'hata' ], admin_url( 'admin.php' ) ) . '#dhf-ekle' );
		exit;
	}

	private static function hata( int $yil, int $ay, string $mesaj ): void {
		set_transient( 'dhf_hareket_hata_' . get_current_user_id(), $mesaj, 300 );
		wp_safe_redirect( add_query_arg( [ 'page' => 'dhf-hareketler', 'yil' => $yil, 'ay' => $ay, 'dhf_mesaj' => 'hata' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function geri( int $yil, int $ay, string $mesaj, int $n, int $m ): void {
		wp_safe_redirect( add_query_arg( [
			'page' => 'dhf-hareketler', 'yil' => $yil, 'ay' => $ay,
			'dhf_mesaj' => $mesaj, 'n' => $n, 'm' => $m,
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}
