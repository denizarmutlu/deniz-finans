<?php
/**
 * Yönetim menüsü ve ekran iskeletleri.
 *
 * 0.1'de Ayarlar ekranı çalışır durumda (şema tanısı + yetki yönetimi).
 * Diğer ekranlar yer tutucu; her biri hangi sürümde geleceğini yazar.
 *
 * Ön yüzde hiçbir çıktı yok: shortcode, widget, public query var veya
 * REST ucu kaydedilmiyor (plan §8).
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Admin {

	private const SLUG = 'dhf';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_dhf_yetki', [ $this, 'handle_yetki' ] );
		add_action( 'admin_post_dhf_taslak', [ DHF_Screen_Hareketler::class, 'handle_taslak' ] );
		add_action( 'admin_post_dhf_satirlar', [ DHF_Screen_Hareketler::class, 'handle_satirlar' ] );
		add_action( 'admin_post_dhf_kalemler', [ DHF_Screen_Kalemler::class, 'handle' ] );
	}

	public function menu(): void {
		add_menu_page(
			__( 'Finans', 'deniz-finans' ),
			__( 'Finans', 'deniz-finans' ),
			DHF_CAP,
			self::SLUG,
			[ $this, 'page_panel' ],
			'dashicons-chart-area',
			3
		);

		$pages = [
			'dhf'            => [ __( 'Gösterge Paneli', 'deniz-finans' ), 'page_panel' ],
			'dhf-hareketler' => [ __( 'Aylık Hareketler', 'deniz-finans' ), 'page_hareketler' ],
			'dhf-kalemler'   => [ __( 'Kalemler', 'deniz-finans' ), 'page_kalemler' ],
			'dhf-raporlar'   => [ __( 'Raporlar', 'deniz-finans' ), 'page_raporlar' ],
			'dhf-borclar'    => [ __( 'Borçlar', 'deniz-finans' ), 'page_borclar' ],
			'dhf-yatirimlar' => [ __( 'Yatırımlar', 'deniz-finans' ), 'page_yatirimlar' ],
			'dhf-bilanco'    => [ __( 'Varlıklar ve Bilanço', 'deniz-finans' ), 'page_bilanco' ],
			'dhf-aktarim'    => [ __( 'Aktarım', 'deniz-finans' ), 'page_aktarim' ],
			'dhf-ayarlar'    => [ __( 'Ayarlar', 'deniz-finans' ), 'page_ayarlar' ],
		];

		foreach ( $pages as $slug => [ $title, $cb ] ) {
			add_submenu_page( self::SLUG, $title, $title, DHF_CAP, $slug, [ $this, $cb ] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Yer tutucu ekranlar                                                 */
	/* ------------------------------------------------------------------ */

	private function stub( string $baslik, string $surum, array $maddeler ): void {
		DHF_Caps::guard();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $baslik ) . '</h1>';
		echo '<p><strong>' . esc_html( sprintf( /* translators: %s: sürüm */ __( '%s sürümünde gelecek.', 'deniz-finans' ), $surum ) ) . '</strong></p>';
		echo '<ul class="ul-disc">';
		foreach ( $maddeler as $m ) {
			echo '<li>' . esc_html( $m ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	public function page_panel(): void {
		$this->stub( __( 'Gösterge Paneli', 'deniz-finans' ), '0.5–0.9', [
			'Bölge 1 — Bu ay ne yapmam lazım: ay kapanış durumu, elle ödenecekler listesi',
			'Bölge 2 — Nakit ihtiyacı; tüketim / faiz / borç azaltımı / yatırım ayrı kutularda',
			'Bölge 3 — Toplam borç, aylık değişim, yıl başından beri faiz, sabit yük çizgisi',
			'Bölge 4 — Portföy, reel getiri, net finansal durum ve net servet (nominal + reel)',
		] );
	}

	public function page_hareketler(): void {
		DHF_Screen_Hareketler::render();
	}

	public function page_kalemler(): void {
		DHF_Screen_Kalemler::render();
	}

	public function page_raporlar(): void {
		$this->stub( __( 'Raporlar', 'deniz-finans' ), '0.5', [
			'Üç görünüm seçici: Tüketim / Nakit / Maliyet — başlıkta hangisi olduğu yazılı',
			'Ay × Kategori pivotu, nakit ihtiyacı, yıl karşılaştırma',
			'Sabit yük takvimi, planlanan/gerçekleşen, mutabakat raporu',
		] );
	}

	public function page_borclar(): void {
		$this->stub( __( 'Borçlar', 'deniz-finans' ), '0.7', [
			'Tek tabloda kartlar, KMH\'ler ve krediler; beş bakiye tipi ayrı kolonlarda',
			'Güncellik kolonu: "bu ay girildi" / "geçen aydan taşındı"',
			'Faiz paneli: aylık ve yıllık faiz, kurum kırılımı, efektif oran',
			'Para birimi: TL / USD / EUR / altın gram — TL karşılığı her ay hesaplanır',
		] );
	}

	public function page_yatirimlar(): void {
		$this->stub( __( 'Yatırımlar', 'deniz-finans' ), '0.8', [
			'Varlık ağacı: Kurum → Tür → Enstrüman',
			'Doğru getiri hesabı: katkılar ve devlet katkısı düşülür',
			'BES: sözleşme → fon dağılımı, 12 fon değişim hakkı sayacı',
			'Açılış envanteri 31.12.2026',
		] );
	}

	public function page_bilanco(): void {
		$this->stub( __( 'Varlıklar ve Bilanço', 'deniz-finans' ), '0.9', [
			'Üç katman: likit / yarı likit / kullanım varlığı — ayrı toplanır',
			'Net finansal durum ve net servet, ikisi de nominal ve reel',
			'Varlık başına gelir tablosu (kira, vergi, sigorta, tamirat, kredi faizi)',
			'Likidite paneli: nakde dönme süresi ve bedeli',
			'Alternatif senaryo: aynı para mevduatta / altında / BIST\'te / dolarda ne olurdu',
		] );
	}

	public function page_aktarim(): void {
		$this->stub( __( 'Aktarım', 'deniz-finans' ), '0.3 / 0.6', [
			'CSV yükleme, 2025 ve 2026 için ayrı eşleştirme profilleri',
			'Önizleme → doğrulama → onay → yazma',
			'İnceleme kuyruğu: hatalı tarihler, mükerrer satırlar, çözülemeyen kurumlar',
			'Sheets\'teki toplam satırları okunmaz — toplamlar yeniden hesaplanır',
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Ayarlar — 0.1'de çalışıyor                                          */
	/* ------------------------------------------------------------------ */

	public function page_ayarlar(): void {
		DHF_Caps::guard();

		$durum   = DHF_Schema::status();
		$holders = DHF_Caps::holders();
		$eksik   = array_filter( $durum, static fn( array $t ): bool => ! $t['var'] );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Finans — Ayarlar', 'deniz-finans' ) . '</h1>';

		if ( isset( $_GET['dhf_mesaj'] ) ) {
			$mesajlar = [
				'yetki_verildi'  => __( 'Yetki verildi.', 'deniz-finans' ),
				'yetki_alindi'   => __( 'Yetki geri alındı.', 'deniz-finans' ),
				'kullanici_yok'  => __( 'Kullanıcı bulunamadı.', 'deniz-finans' ),
			];
			$key = sanitize_key( wp_unslash( $_GET['dhf_mesaj'] ) );
			if ( isset( $mesajlar[ $key ] ) ) {
				printf(
					'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
					'kullanici_yok' === $key ? 'error' : 'success',
					esc_html( $mesajlar[ $key ] )
				);
			}
		}

		/* Sürüm ve şema tanısı */
		echo '<h2>' . esc_html__( 'Kurulum durumu', 'deniz-finans' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Eklenti sürümü', 'deniz-finans' ),
			esc_html( DHF_VERSION )
		);
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Şema sürümü', 'deniz-finans' ),
			esc_html( (string) get_option( 'dhf_db_version', 0 ) )
		);
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Tablolar', 'deniz-finans' ),
			$eksik
				? esc_html( sprintf(
					/* translators: 1: eksik sayısı 2: toplam */
					__( '%1$d / %2$d eksik', 'deniz-finans' ),
					count( $eksik ),
					count( $durum )
				) )
				: esc_html( sprintf( __( '%d tablo kurulu', 'deniz-finans' ), count( $durum ) ) )
		);
		echo '</tbody></table>';

		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Kalem sözlüğü', 'deniz-finans' ),
			esc_html( sprintf(
				/* translators: %d: kalem sayısı */
				__( '%d kalem yüklendi', 'deniz-finans' ),
				(int) get_option( 'dhf_kalem_seed_done', 0 )
			) )
		);

		echo '<h3>' . esc_html__( 'Tablolar', 'deniz-finans' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		echo '<th>' . esc_html__( 'Tablo', 'deniz-finans' ) . '</th>';
		echo '<th>' . esc_html__( 'Durum', 'deniz-finans' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'Satır', 'deniz-finans' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $durum as $ad => $t ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td style="text-align:right">%s</td></tr>',
				esc_html( $t['tablo'] ),
				$t['var']
					? '<span style="color:#1a7f37">' . esc_html__( 'kurulu', 'deniz-finans' ) . '</span>'
					: '<span style="color:#b32d2e">' . esc_html__( 'EKSİK', 'deniz-finans' ) . '</span>',
				esc_html( null === $t['satir'] ? '—' : number_format_i18n( $t['satir'] ) )
			);
		}
		echo '</tbody></table>';

		/* Yetki */
		echo '<h2>' . esc_html__( 'Erişim yetkisi', 'deniz-finans' ) . '</h2>';
		echo '<p>' . esc_html( sprintf(
			/* translators: %s: yetki adı */
			__( 'Finans ekranları yalnızca %s yetkisi olan kullanıcılara görünür. Yetki role değil kullanıcıya verilir; editör veya yazar rolleri hiçbir ekranı görmez.', 'deniz-finans' ),
			DHF_CAP
		) ) . '</p>';

		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		echo '<th>' . esc_html__( 'Kullanıcı', 'deniz-finans' ) . '</th>';
		echo '<th>' . esc_html__( 'İşlem', 'deniz-finans' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( ! $holders ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Yetkili kullanıcı yok.', 'deniz-finans' ) . '</td></tr>';
		}
		foreach ( $holders as $u ) {
			echo '<tr><td>' . esc_html( $u->display_name . ' (' . $u->user_login . ')' ) . '</td><td>';
			$this->yetki_form( (int) $u->ID, 'revoke', __( 'Yetkiyi geri al', 'deniz-finans' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Yetki ver', 'deniz-finans' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dhf_yetki' );
		echo '<input type="hidden" name="action" value="dhf_yetki">';
		echo '<input type="hidden" name="islem" value="grant">';
		echo '<select name="user_id">';
		foreach ( get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ] ] ) as $u ) {
			printf(
				'<option value="%d">%s</option>',
				(int) $u->ID,
				esc_html( $u->display_name . ' (' . $u->user_login . ')' )
			);
		}
		echo '</select> ';
		submit_button( __( 'Yetki ver', 'deniz-finans' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Güvenlik kontrol listesi', 'deniz-finans' ) . '</h2>';
		echo '<p>' . esc_html__( 'Bunlar eklentiden bağımsız, sitede yapılması gereken işler (plan §8):', 'deniz-finans' ) . '</p>';
		echo '<ul class="ul-disc">';
		foreach ( [
			__( 'Hangi CleanTalk eklentisi kurulu — anti-spam mı, güvenlik mi? İkisi birlikte çalıştırılmamalı.', 'deniz-finans' ),
			__( 'Admin girişinde iki faktörlü doğrulama (TOTP, SMS değil) — yedek kodlar kaydedilmeli.', 'deniz-finans' ),
			__( 'UpdraftPlus yedeği bu özel tabloları kapsıyor mu? Geri yükleme bir kez test edilmeli.', 'deniz-finans' ),
		] as $madde ) {
			echo '<li>' . esc_html( $madde ) . '</li>';
		}
		echo '</ul>';

		echo '</div>';
	}

	private function yetki_form( int $user_id, string $islem, string $etiket ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'dhf_yetki' );
		echo '<input type="hidden" name="action" value="dhf_yetki">';
		echo '<input type="hidden" name="islem" value="' . esc_attr( $islem ) . '">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
		submit_button( $etiket, 'small', 'submit', false );
		echo '</form>';
	}

	public function handle_yetki(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_yetki' );

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$islem   = isset( $_POST['islem'] ) ? sanitize_key( wp_unslash( $_POST['islem'] ) ) : '';

		$mesaj = 'kullanici_yok';
		if ( $user_id && 'grant' === $islem && DHF_Caps::grant( $user_id ) ) {
			$mesaj = 'yetki_verildi';
		} elseif ( $user_id && 'revoke' === $islem && DHF_Caps::revoke( $user_id ) ) {
			$mesaj = 'yetki_alindi';
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'dhf-ayarlar', 'dhf_mesaj' => $mesaj ],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
