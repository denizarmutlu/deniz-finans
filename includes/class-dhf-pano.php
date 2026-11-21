<?php
/**
 * WordPress Pano kutusu (plan §5.11, 0.5) ve eklentinin Gösterge Paneli (§5.3, Bölge 1-2).
 *
 * Pano kutusu "bu ay ne yapmam lazım" sorusunu cevaplar, bilançoyu değil.
 * Güvenlik: yetkisi olmayan kullanıcıya kutu hiç kaydedilmez. Veri sayfa oluşturulurken
 * basılır, AJAX/REST ucu yok. Tutarlar varsayılan olarak gizlidir — gizliyken HTML'e
 * hiç yazılmaz. Göster/gizle tercihi kullanıcı meta'sında, admin-post üzerinden nonce'lu.
 * Rakamlar DHF_Islem::toplamlar()'dan gelir: Hareketler'deki ile aynı fonksiyon.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Pano {

	private const META = 'dhf_pano_goster';

	public static function kaydet(): void {
		if ( ! DHF_Caps::can() ) {
			return; // Diğer yöneticiler kutuyu görmez, Ekran Seçenekleri'nde de çıkmaz.
		}
		wp_add_dashboard_widget(
			'dhf_pano',
			esc_html( 'Deniz Finans — ' . DHF_Takvim::ay_adi( (int) current_time( 'Y' ), (int) current_time( 'n' ) ) ),
			[ self::class, 'kutu' ]
		);
	}

	public static function gosteriliyor(): bool {
		return '1' === get_user_meta( get_current_user_id(), self::META, true );
	}

	public static function handle_goster(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_pano_goster' );
		$goster = isset( $_POST['goster'] ) && '1' === $_POST['goster'];
		update_user_meta( get_current_user_id(), self::META, $goster ? '1' : '0' );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'index.php' ) );
		exit;
	}

	private static function link( string $sayfa, array $ek = [] ): string {
		return esc_url( add_query_arg( array_merge( [ 'page' => $sayfa ], $ek ), admin_url( 'admin.php' ) ) );
	}

	/** Ay durumu satırı: "Kasım 2026 · açık · 6 alt kategori tutar bekliyor · 1 mükerrer şüphesi" */
	public static function durum_metni( array $v ): array {
		$parca = [ DHF_Takvim::ay_adi( $v['yil'], $v['ay'] ) ];
		$uyari = false;
		if ( ! $v['satir'] ) {
			$parca[] = 'henüz oluşturulmadı';
			$uyari   = true;
		} else {
			$parca[] = mb_strtolower( DHF_Donem::DURUMLAR[ $v['donem']->durum ] ?? $v['donem']->durum );
			if ( ! $v['defter'] ) {
				$parca[] = 'aylık taslak üretilmedi';
				$uyari   = true;
			}
			if ( $v['bekleyen'] ) {
				$parca[] = sprintf( '%d alt kategori tutar bekliyor', $v['bekleyen'] );
				$uyari   = true;
			}
			if ( $v['mukerrer'] ) {
				$parca[] = sprintf( '%d mükerrer şüphesi', $v['mukerrer'] );
				$uyari   = true;
			}
		}
		return [ implode( ' · ', $parca ), $uyari ];
	}

	public static function kutu(): void {
		if ( ! DHF_Caps::can() ) {
			return;
		}
		$v      = DHF_Rapor::bu_ay();
		$goster = self::gosteriliyor();
		$tl     = static fn( ?float $x ): string => null === $x ? '—' : ( $goster ? number_format_i18n( $x, 2 ) : '•••••' );

		echo '<div class="dhf-pano">';

		/* 1. Ay durumu */
		[ $metin, $uyari ] = self::durum_metni( $v );
		printf( '<p class="dhf-pano-durum%s"><a href="%s">%s</a></p>', $uyari ? ' dhf-pano-uyari' : '',
			self::link( 'dhf-hareketler', [ 'yil' => $v['yil'], 'ay' => $v['ay'] ] ), esc_html( $metin ) );

		if ( $v['acik_aylar'] ) {
			$adlar = array_map( static fn( $d ) => DHF_Takvim::ay_adi( (int) $d->yil, (int) $d->ay ), $v['acik_aylar'] );
			printf( '<p class="dhf-pano-uyari">%s</p>', esc_html( 'Kesinleşmeyi bekleyen: ' . implode( ', ', array_slice( $adlar, 0, 3 ) ) . ( count( $adlar ) > 3 ? ' …' : '' ) ) );
		}

		/* 2. Elle ödenecekler */
		if ( $v['elle'] ) {
			echo '<p class="dhf-pano-baslik">Önümüzdeki 10 günde elle ödenecekler</p><ul class="dhf-pano-liste">';
			foreach ( $v['elle'] as $o ) {
				printf( '<li><span>%s · %s</span><span>%s</span></li>', esc_html( mysql2date( 'd.m', $o['tarih'] ) ), esc_html( $o['ad'] ), esc_html( $tl( $o['tutar'] ) ) );
			}
			echo '</ul>';
		}

		/* 3. Rakamlar */
		if ( $v['t'] ) {
			$rd   = $v['rakam_donem'];
			$baska = (int) $rd->yil !== $v['yil'] || (int) $rd->ay !== $v['ay'];
			printf( '<p class="dhf-pano-baslik">%s</p>', esc_html( $baska ? 'Son verili ay: ' . DHF_Takvim::ay_adi( (int) $rd->yil, (int) $rd->ay ) : 'Bu ay' ) );
			echo '<div class="dhf-pano-rakam">';
			printf( '<div class="dhf-pano-buyuk"><span>Nakit ihtiyacı</span><strong class="%s">%s</strong></div>',
				$goster && $v['t']['nakit_ihtiyaci'] < 0 ? 'dhf-eksi' : '', esc_html( $tl( $v['t']['nakit_ihtiyaci'] ) ) );
			printf( '<div><span>Tüketim</span><strong>%s</strong></div>', esc_html( $tl( $v['t']['tuketim_gideri'] ) ) );
			printf( '<div><span>Faiz</span><strong class="dhf-eksi">%s</strong></div>', esc_html( $tl( $v['t']['faiz_masraf'] ) ) );
			echo '</div>';
		}

		/* 4. Bekleyenler */
		if ( $v['aktarim'] ) {
			printf( '<p><a href="%s">%s</a></p>', self::link( 'dhf-aktarim' ), esc_html( sprintf( '%d aktarım onay bekliyor →', $v['aktarim'] ) ) );
		}
		if ( $v['alacak'] > 0 ) {
			printf( '<p>Bekleyen web işi alacağı: <strong>%s</strong></p>', esc_html( $tl( $v['alacak'] ) ) );
		}
		if ( $v['tarihsiz'] ) {
			printf( '<p class="dhf-pano-uyari">Deniz Hesap\'ta tarihi boş %d ödenmiş hareket — hiçbir aya düşmüyor.</p>', (int) $v['tarihsiz'] );
		}

		/* 5. Kısayollar + gizlilik */
		echo '<p class="dhf-pano-alt">';
		printf( '<a class="button button-primary" href="%s">Ayı tamamla</a> ', self::link( 'dhf-hareketler', [ 'yil' => $v['yil'], 'ay' => $v['ay'] ] ) );
		printf( '<a class="button" href="%s">Gösterge Paneli</a> ', self::link( 'dhf' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;float:right">';
		wp_nonce_field( 'dhf_pano_goster' );
		echo '<input type="hidden" name="action" value="dhf_pano_goster"><input type="hidden" name="goster" value="' . ( $goster ? '0' : '1' ) . '">';
		printf( '<button type="submit" class="button-link">%s</button>', esc_html( $goster ? 'Tutarları gizle' : 'Tutarları göster' ) );
		echo '</form></p>';

		echo '</div>';
		echo '<style>
		.dhf-pano p{margin:6px 0}.dhf-pano-durum{font-weight:600}.dhf-pano-uyari,.dhf-pano-uyari a{color:#8a6100}
		.dhf-pano-baslik{font-size:11px;text-transform:uppercase;color:#646970;margin-top:12px!important}
		.dhf-pano-liste{margin:2px 0 8px}.dhf-pano-liste li{display:flex;justify-content:space-between;margin:2px 0}
		.dhf-pano-rakam{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end}
		.dhf-pano-rakam div{display:flex;flex-direction:column}.dhf-pano-rakam span{font-size:11px;color:#646970}
		.dhf-pano-rakam strong{font-size:14px;text-align:right}.dhf-pano-buyuk strong{font-size:20px}
		.dhf-eksi{color:#b32d2e}.dhf-pano-alt{margin-top:12px!important;border-top:1px solid #f0f0f1;padding-top:10px}
		@media (max-width:782px){.dhf-pano-rakam{flex-direction:column;align-items:stretch}.dhf-pano-rakam div{flex-direction:row;justify-content:space-between}}
		</style>';
	}

	/* ---------------------------------------------------------------- */
	/* Gösterge Paneli — eklentinin kendi ekranı, Bölge 1-2              */
	/* ---------------------------------------------------------------- */

	public static function panel(): void {
		DHF_Caps::guard();
		$v = DHF_Rapor::bu_ay();
		$tl = static fn( ?float $x ): string => null === $x ? '—' : number_format_i18n( $x, 2 );

		echo '<div class="wrap dhf-panel"><h1>' . esc_html__( 'Gösterge Paneli', 'deniz-finans' ) . '</h1>';

		/* Bölge 1 */
		echo '<div class="dhf-bolge"><h2>Bu ay ne yapmam lazım</h2>';
		[ $metin, $uyari ] = self::durum_metni( $v );
		printf( '<p class="%s"><strong><a href="%s">%s</a></strong></p>', $uyari ? 'dhf-uyari-not' : '',
			self::link( 'dhf-hareketler', [ 'yil' => $v['yil'], 'ay' => $v['ay'] ] ), esc_html( $metin ) );
		foreach ( $v['acik_aylar'] as $d ) {
			printf( '<p class="dhf-uyari-not"><a href="%s">%s</a> kesinleşmedi.</p>',
				self::link( 'dhf-hareketler', [ 'yil' => $d->yil, 'ay' => $d->ay ] ), esc_html( DHF_Takvim::ay_adi( (int) $d->yil, (int) $d->ay ) ) );
		}
		if ( $v['aktarim'] ) {
			printf( '<p><a href="%s">%s</a></p>', self::link( 'dhf-aktarim' ), esc_html( sprintf( '%d aktarım onay bekliyor', $v['aktarim'] ) ) );
		}
		if ( $v['tarihsiz'] ) {
			printf( '<p class="dhf-uyari-not">Deniz Hesap\'ta tarihi boş %d ödenmiş hareket var; hiçbir aya düşmüyor.</p>', (int) $v['tarihsiz'] );
		}
		echo '<h3>Önümüzdeki 10 günde elle ödenecekler</h3>';
		$elle = DHF_Rapor::elle_odenecekler( 10, 20 );
		if ( $elle ) {
			echo '<table class="widefat striped" style="max-width:560px"><tbody>';
			foreach ( $elle as $o ) {
				printf( '<tr><td>%s</td><td>%s</td><td class="dhf-sag">%s</td></tr>', esc_html( mysql2date( 'd.m.Y', $o['tarih'] ) ), esc_html( $o['ad'] ), esc_html( $tl( $o['tutar'] ) ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="dhf-soluk">Yok.</p>';
		}
		printf( '<p class="dhf-soluk">Liste, ödeme şekli "elle" olan gider alt kategorilerinden üretilir; otomatik talimatlılar girmez. Ödeme şeklini <a href="%s">Alt kategoriler</a> ekranında değiştirebilirsin.</p>', self::link( 'dhf-kalemler' ) );
		if ( $v['alacak'] > 0 ) {
			printf( '<p>Bekleyen web işi alacağı (Deniz Hesap): <strong>%s</strong></p>', esc_html( $tl( $v['alacak'] ) ) );
		}
		echo '</div>';

		/* Bölge 2 */
		echo '<div class="dhf-bolge">';
		if ( $v['t'] ) {
			$rd = $v['rakam_donem'];
			$baska = (int) $rd->yil !== $v['yil'] || (int) $rd->ay !== $v['ay'];
			printf( '<h2>%s%s</h2>', $baska ? 'Son verili ay: ' : 'Bu ay: ', esc_html( DHF_Takvim::ay_adi( (int) $rd->yil, (int) $rd->ay ) ) );
			$t = $v['t'];
			$g = $v['gecen'];
			$kutular = [
				[ 'Nakit ihtiyacı', 'nakit_ihtiyaci', 'dhf-vurgu' ],
				[ 'Tüketim', 'tuketim_gideri', '' ],
				[ 'Faiz', 'faiz_masraf', 'dhf-faiz' ],
				[ 'Kart borcu azaltımı', 'borc_azaltimi', '' ],
				[ 'Yatırım', 'yatirim_alimi', '' ],
				[ 'Gelir', 'gelir', '' ],
			];
			echo '<div class="dhf-toplam">';
			foreach ( $kutular as [ $etiket, $alan, $sinif ] ) {
				$fark = $g ? $t[ $alan ] - $g[ $alan ] : null;
				printf( '<div class="dhf-kutu %s"><span class="dhf-kutu-etiket">%s</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
					esc_attr( $sinif ), esc_html( $etiket ), esc_html( $tl( $t[ $alan ] ) ),
					esc_html( null === $fark ? 'geçen ay verisi yok' : sprintf( 'geçen aya göre %s%s', $fark > 0 ? '+' : '', number_format_i18n( $fark, 2 ) ) ) );
			}
			echo '</div>';
			printf( '<p><a href="%s">Yıllık özet ve kategori kırılımı → Raporlar</a></p>', self::link( 'dhf-raporlar' ) );
		} else {
			echo '<h2>Bu ay</h2><p>Defterde henüz veri yok.</p>';
		}
		echo '</div>';

		/* Bölge 3 — Borç (0.7) */
		$byil  = (int) current_time( 'Y' );
		$bay   = (int) current_time( 'n' );
		$bozet = DHF_Borc::ozet( $byil, $bay );
		$bfaiz = DHF_Borc::faiz_dokumu( $byil );
		$bkmh  = round( $bfaiz['gruplar']['kmh'] + $bfaiz['gruplar']['avans'], 2 );

		echo '<div class="dhf-bolge"><h2>Borç</h2><div class="dhf-toplam">';
		printf( '<div class="dhf-kutu dhf-vurgu"><span class="dhf-kutu-etiket">Toplam borç</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html( $tl( $bozet['toplam'] ) ),
			esc_html( sprintf( 'bildirilen %s · defterden %s', $tl( $bozet['bildirilen'] ), $tl( $bozet['hesaplanan'] ) ) ) );
		printf( '<div class="dhf-kutu dhf-faiz"><span class="dhf-kutu-etiket">Bu ay faiz</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html( $tl( (float) ( $bfaiz['aylik'][ $bay ] ?? 0.0 ) ) ), esc_html( DHF_Takvim::AYLAR[ $bay ] ) );
		printf( '<div class="dhf-kutu dhf-faiz"><span class="dhf-kutu-etiket">Yıl başından beri faiz</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html( $tl( $bfaiz['toplam'] ) ),
			esc_html( null === $bfaiz['oran'] ? '' : sprintf( 'gelirin %%%s\'i', number_format_i18n( $bfaiz['oran'], 1 ) ) ) );
		printf( '<div class="dhf-kutu"><span class="dhf-kutu-etiket">Kredili mevduat faizi</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html( $tl( $bkmh ) ), esc_html( sprintf( 'kart faizi %s', $tl( $bfaiz['gruplar']['kart'] ) ) ) );
		echo '</div>';

		$bdolu = [];
		foreach ( $bozet['satirlar'] as $bs ) {
			if ( null !== $bs['kullanim'] && $bs['kullanim'] >= 90 ) {
				$bdolu[] = trim( (string) ( $bs['borc']->kurum ?: '' ) . ' ' . $bs['borc']->ad ) . sprintf( ' (%%%s)', number_format_i18n( $bs['kullanim'], 1 ) );
			}
		}
		if ( $bdolu ) {
			printf( '<p class="dhf-uyari-not">Limiti dolmak üzere: %s</p>', esc_html( implode( ' · ', $bdolu ) ) );
		}
		if ( $bozet['guncellenmemis'] ) {
			printf( '<p class="dhf-soluk">%s</p>', esc_html( sprintf( '%d borcun bu ay rakamı yok: %s',
				count( $bozet['guncellenmemis'] ), implode( ' · ', $bozet['guncellenmemis'] ) ) ) );
		}
		printf( '<p><a href="%s">Bakiye gir, faiz panelini gör → Borçlar</a></p>', self::link( 'dhf-borclar' ) );
		echo '</div>';

		echo '<div class="dhf-bolge dhf-soluk"><h2>Varlık ve net durum</h2><p>Portföy, reel getiri ve net durum 0.8–0.9\'da gelecek.</p></div>';
		echo '</div>';
		echo '<style>
		.dhf-bolge{background:#fff;border:1px solid #c3c4c7;padding:4px 18px 14px;margin:14px 0;max-width:1100px}
		.dhf-toplam{display:flex;flex-wrap:wrap;gap:10px;margin:12px 0}
		.dhf-kutu{flex:1;min-width:150px;background:#fff;border:1px solid #c3c4c7;padding:10px 14px}
		.dhf-kutu-etiket,.dhf-kutu-fark{display:block;font-size:12px;color:#646970}
		.dhf-kutu-deger{display:block;font-size:18px;font-weight:600;margin:2px 0}
		.dhf-kutu.dhf-faiz .dhf-kutu-deger{color:#b32d2e}.dhf-kutu.dhf-vurgu{border-color:#2271b1;border-width:2px}
		.dhf-sag{text-align:right}.dhf-soluk{color:#646970}.dhf-uyari-not,.dhf-uyari-not a{color:#8a6100}
		</style>';
	}
}
