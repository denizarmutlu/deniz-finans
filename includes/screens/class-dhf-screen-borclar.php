<?php
/**
 * Borçlar ekranı (0.7) — plan §5.5.
 *
 * Tek tabloda kartlar, KMH'ler ve krediler. İki rakam yan yana durur:
 * defterden hesaplanan bakiye ve bankanın bildirdiği rakam. Fark gizlenmez.
 *
 * "Kalan Borç" diye bir kolon yoktur (plan §5.5): mevcut Sheets tablosunda o
 * kolon *toplam borç − ödeme* olarak hesaplanıyordu ve asgari ödeme yapıldığında
 * faizle büyüyen bakiyeyi modellemediği için yanlış çıkıyordu.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Screen_Borclar {

	private static function tl( ?float $v, string $bos = '—' ): string {
		return null === $v ? $bos : number_format( $v, 2, ',', '.' );
	}

	private static function sayi( string $ham ): ?float {
		$ham = trim( $ham );
		if ( '' === $ham ) {
			return null;
		}
		$v = DHF_Ayristirici::tutar( $ham );
		return null === $v ? null : round( (float) $v, 2 );
	}

	public static function render(): void {
		DHF_Caps::guard();
		$g   = wp_unslash( $_GET );
		$yil = absint( $g['yil'] ?? current_time( 'Y' ) ) ?: (int) current_time( 'Y' );
		$yil = max( 2020, min( 2100, $yil ) );
		$ay  = absint( $g['ay'] ?? current_time( 'n' ) );
		$ay  = $ay >= 1 && $ay <= 12 ? $ay : (int) current_time( 'n' );

		$borclar = DHF_Borc::borclar();
		$secili  = absint( $g['borc'] ?? 0 );
		$ids     = array_map( static fn( object $b ): int => (int) $b->id, $borclar );
		if ( $secili && ! in_array( $secili, $ids, true ) ) {
			$secili = 0;
		}

		$ozet = DHF_Borc::ozet( $yil, $ay );
		$faiz = DHF_Borc::faiz_dokumu( $yil );

		echo '<div class="wrap dhf-borclar">';
		printf( '<h1>%s <span class="dhf-rozet">%s %d</span></h1>',
			esc_html__( 'Borçlar', 'deniz-finans' ), esc_html( DHF_Takvim::AYLAR[ $ay ] ), $yil );

		if ( ! $borclar ) {
			echo '<p>' . esc_html__( 'Tanımlı borç yok.', 'deniz-finans' ) . '</p></div>';
			return;
		}

		// Ay / yıl seçici
		echo '<form method="get" class="dhf-secici"><input type="hidden" name="page" value="dhf-borclar">';
		if ( $secili ) {
			echo '<input type="hidden" name="borc" value="' . esc_attr( (string) $secili ) . '">';
		}
		echo '<label>' . esc_html__( 'Yıl', 'deniz-finans' ) . ' <select name="yil" onchange="this.form.submit()">';
		foreach ( array_unique( array_merge( DHF_Rapor::yillar(), [ $yil, (int) current_time( 'Y' ) ] ) ) as $y ) {
			printf( '<option value="%1$d" %2$s>%1$d</option>', $y, selected( $y, $yil, false ) );
		}
		echo '</select></label> <label>' . esc_html__( 'Ay', 'deniz-finans' ) . ' <select name="ay" onchange="this.form.submit()">';
		foreach ( DHF_Takvim::AYLAR as $n => $ad ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', $n, selected( $n, $ay, false ), esc_html( $ad ) );
		}
		echo '</select></label></form>';

		/* ----- Üst şerit ----- */
		$bu_ay_faiz = $faiz['aylik'][ $ay ] ?? 0.0;
		echo '<div class="dhf-toplam">';
		printf( '<div class="dhf-kutu dhf-vurgu"><span class="dhf-kutu-etiket">%s</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html__( 'Toplam borç', 'deniz-finans' ), esc_html( self::tl( $ozet['toplam'] ) ),
			esc_html( sprintf( 'bildirilen %s · hesaplanan %s', self::tl( $ozet['bildirilen'] ), self::tl( $ozet['hesaplanan'] ) ) ) );
		printf( '<div class="dhf-kutu dhf-faiz"><span class="dhf-kutu-etiket">%s</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html__( 'Bu ay faiz', 'deniz-finans' ), esc_html( self::tl( (float) $bu_ay_faiz ) ), esc_html( DHF_Takvim::AYLAR[ $ay ] ) );
		printf( '<div class="dhf-kutu dhf-faiz"><span class="dhf-kutu-etiket">%s</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html__( 'Yıl başından beri faiz', 'deniz-finans' ), esc_html( self::tl( $faiz['toplam'] ) ),
			esc_html( $faiz['aylik'] ? sprintf( 'ayda ortalama %s', self::tl( round( $faiz['toplam'] / count( $faiz['aylik'] ), 2 ) ) ) : '' ) );
		printf( '<div class="dhf-kutu"><span class="dhf-kutu-etiket">%s</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html__( 'Faizin gelire oranı', 'deniz-finans' ),
			esc_html( null === $faiz['oran'] ? '—' : '%' . number_format( $faiz['oran'], 1, ',', '.' ) ),
			esc_html( sprintf( 'gelir %s', self::tl( $faiz['gelir'] ) ) ) );
		echo '</div>';

		echo '<p class="description">' . esc_html__( 'Bakiye iki kaynaktan gelebilir. "Hesaplanan": defterdeki satırlardan yürütülür (açılış + harcama + faiz − ödeme) ve yalnızca kredi kartlarında anlamlıdır. "Bildirilen": bankanın söylediği rakam, aşağıdaki formla girilir. Kesim günleri ayın 7, 13, 14, 15 ve 19\'u olduğu için hesaplanan ile ekstre borcu birebir tutmaz; fark bilgidir, hata değil. KMH\'de bakiye defterden yürütülemez — hesaba giren para defterde yok — bu yüzden yalnız bildirilen rakamla izlenir.', 'deniz-finans' ) . '</p>';

		if ( $ozet['acilis_eksik'] ) {
			printf( '<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Açılış (devir) bakiyesi eksik:', 'deniz-finans' ),
				esc_html( implode( ' · ', $ozet['acilis_eksik'] ) . ' — bu kart(lar)ın yıl içindeki ödemesi harcamasından fazla, yani yıla devreden bir borçla başlamış. Devir girilmeden bakiye hesaplanamaz; aşağıdan "Açılış (devir)" olarak Ocak ayına girin.' ) );
		}

		if ( $ozet['guncellenmemis'] ) {
			printf( '<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
				esc_html( sprintf( '%d borcun bu ay rakamı yok:', count( $ozet['guncellenmemis'] ) ) ),
				esc_html( implode( ' · ', $ozet['guncellenmemis'] ) . ' — toplam gerçek borcu yansıtmıyor.' ) );
		}

		/* ----- Ana tablo ----- */
		$url = static fn( array $ek ): string => esc_url( add_query_arg( array_merge(
			[ 'page' => 'dhf-borclar', 'yil' => $yil, 'ay' => $ay ], $ek ), admin_url( 'admin.php' ) ) );

		echo '<table class="widefat striped dhf-tablo"><thead><tr>';
		foreach ( [ 'Kurum / borç', 'Tip', 'PB', 'Limit', 'Bakiye', 'Kaynak', 'Kullanım', 'Asgari', 'Ödeme', 'Faiz', 'Değişim', 'Son ödeme' ] as $b ) {
			printf( '<th%s>%s</th>', in_array( $b, [ 'Kurum / borç', 'Tip', 'PB', 'Kaynak' ], true ) ? '' : ' class="dhf-sag"', esc_html( $b ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( $ozet['satirlar'] as $s ) {
			$b      = $s['borc'];
			$ad     = trim( (string) ( $b->kurum ?: '' ) . ' · ' . $b->ad, ' ·' );
			$degisim = null !== $s['bakiye'] && null !== $s['onceki'] ? round( $s['bakiye'] - $s['onceki'], 2 ) : null;
			$sinif  = $b->aktif ? '' : ' class="dhf-soluk"';
			$kaynak_etiket = [
				'bildirilen'   => 'banka',
				'hesaplanan'   => 'defterden',
				'tahmin'       => 'tahmin',
				'acilis_eksik' => 'açılış eksik',
				'yok'          => 'veri yok',
			][ $s['kaynak'] ] ?? $s['kaynak'];

			printf( '<tr%s><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s</td><td>%s</td><td class="dhf-sag">%s</td><td class="dhf-sag"><strong>%s</strong></td><td>%s</td><td class="dhf-sag%s">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag%s">%s</td><td class="dhf-sag">%s</td></tr>',
				$sinif,
				$url( [ 'borc' => (int) $b->id ] ),
				esc_html( $ad ),
				$b->aktif ? '' : ' <span class="dhf-soluk">(pasif)</span>',
				esc_html( DHF_Borc::TIPLER[ (string) $b->tip ] ?? (string) $b->tip ),
				esc_html( (string) $b->para_birimi ),
				esc_html( self::tl( null !== $b->limit_tutar ? (float) $b->limit_tutar : null ) ),
				esc_html( self::tl( $s['bakiye'] ) ),
				'<span class="dhf-soluk">' . esc_html( $kaynak_etiket ) . '</span>',
				null !== $s['kullanim'] && $s['kullanim'] >= 90 ? ' dhf-eksi' : '',
				esc_html( null === $s['kullanim'] ? '—' : '%' . number_format( $s['kullanim'], 1, ',', '.' ) ),
				esc_html( self::tl( $s['asgari'] ) ),
				esc_html( self::tl( $s['odeme'] ) ),
				esc_html( self::tl( $s['faiz'] ) ),
				null !== $degisim && $degisim > 0 ? ' dhf-eksi' : '',
				esc_html( null === $degisim ? '—' : ( $degisim > 0 ? '+' : '' ) . self::tl( $degisim ) ),
				esc_html( $s['son'] ? mysql2date( 'd.m.Y', (string) $s['son'] ) : ( $s['kalan'] ? sprintf( '%d taksit', $s['kalan'] ) : '—' ) )
			);
		}
		printf( '<tr class="dhf-toplam-satir"><th colspan="4">%s</th><th class="dhf-sag">%s</th><th colspan="7"></th></tr></tbody></table>',
			esc_html__( 'Toplam', 'deniz-finans' ), esc_html( self::tl( $ozet['toplam'] ) ) );
		echo '<p class="description">' . esc_html__( 'Değişim: bakiyenin o ay içindeki hareketi (artıysa borç büyümüş). Kullanım %90\'ı geçince kırmızı. Kredilerde "Son ödeme" yerine kalan taksit sayısı yazar.', 'deniz-finans' ) . '</p>';

		/* ----- Seçili borcun seyri ve fotoğraf girişi ----- */
		if ( $secili ) {
			$b = DHF_Borc::bul( $secili );
			if ( $b ) {
				self::detay( $b, $yil, $ay );
			}
		} else {
			echo '<p><em>' . esc_html__( 'Bir borcun adına tıklayınca aylık seyri ve banka rakamı girişi açılır.', 'deniz-finans' ) . '</em></p>';
		}

		/* ----- Faiz paneli ----- */
		echo '<h2>' . esc_html__( 'Faiz paneli', 'deniz-finans' ) . '</h2>';
		$gruplar = $faiz['gruplar'];
		$kmh_top = round( $gruplar['kmh'] + $gruplar['avans'], 2 );
		echo '<div class="dhf-toplam">';
		printf( '<div class="dhf-kutu"><span class="dhf-kutu-etiket">%s</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html__( 'Kredili mevduat / ek hesap faizi', 'deniz-finans' ), esc_html( self::tl( $kmh_top ) ),
			esc_html( $faiz['toplam'] > 0 ? sprintf( 'faizin %%%s\'i', number_format( 100 * $kmh_top / $faiz['toplam'], 1, ',', '.' ) ) : '' ) );
		printf( '<div class="dhf-kutu"><span class="dhf-kutu-etiket">%s</span><span class="dhf-kutu-deger">%s</span><span class="dhf-kutu-fark">%s</span></div>',
			esc_html__( 'Kart faizi', 'deniz-finans' ), esc_html( self::tl( $gruplar['kart'] ) ),
			esc_html( $faiz['toplam'] > 0 ? sprintf( 'faizin %%%s\'i', number_format( 100 * $gruplar['kart'] / $faiz['toplam'], 1, ',', '.' ) ) : '' ) );
		echo '</div>';

		if ( $faiz['satirlar'] ) {
			echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Alt kategori</th><th>Tür</th><th class="dhf-sag">Satır</th><th class="dhf-sag">Yıl toplamı</th><th class="dhf-sag">Pay</th></tr></thead><tbody>';
			foreach ( $faiz['satirlar'] as $r ) {
				printf( '<tr><td>%s</td><td>%s</td><td class="dhf-sag">%d</td><td class="dhf-sag">%s</td><td class="dhf-sag dhf-soluk">%%%s</td></tr>',
					esc_html( $r['ad'] ),
					esc_html( [ 'kart' => 'kart', 'kmh' => 'KMH', 'avans' => 'avans', 'diger' => '—' ][ $r['tur'] ] ?? '' ),
					$r['satir'], esc_html( self::tl( $r['toplam'] ) ),
					esc_html( $faiz['toplam'] > 0 ? number_format( 100 * $r['toplam'] / $faiz['toplam'], 1, ',', '.' ) : '0' ) );
			}
			printf( '<tr class="dhf-toplam-satir"><th colspan="3">%s</th><th class="dhf-sag">%s</th><th></th></tr></tbody></table>',
				esc_html__( 'Toplam', 'deniz-finans' ), esc_html( self::tl( $faiz['toplam'] ) ) );
		}

		/* ----- Kredi bitiş takvimi ----- */
		$krediler = DHF_Borc::krediler( current_time( 'Y-m-d' ) );
		if ( $krediler ) {
			$tak = DHF_Borc::kredi_takvimi( $krediler, (int) current_time( 'Y' ), (int) current_time( 'n' ), 18 );
			echo '<h2>' . esc_html__( 'Kredi bitiş takvimi', 'deniz-finans' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:620px"><thead><tr><th>Kredi</th><th class="dhf-sag">Aylık taksit</th><th class="dhf-sag">Kalan taksit</th><th class="dhf-sag">Kalan toplam</th><th>Bitiş</th></tr></thead><tbody>';
			foreach ( $krediler as $k ) {
				printf( '<tr><td>%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td>%s</td></tr>',
					esc_html( $k['ad'] ), esc_html( self::tl( $k['aylik_taksit'] ) ),
					esc_html( $k['kalan'] ? (string) $k['kalan'] : '—' ),
					esc_html( self::tl( $k['kalan'] ? round( $k['kalan'] * $k['aylik_taksit'], 2 ) : null ) ),
					esc_html( $k['bitis'] ? mysql2date( 'd.m.Y', (string) $k['bitis'] ) : '—' ) );
			}
			echo '</tbody></table>';
			if ( $tak['aylar'] ) {
				echo '<table class="widefat striped" style="max-width:420px;margin-top:10px"><thead><tr><th>Ay</th><th class="dhf-sag">Sabit kredi yükü</th></tr></thead><tbody>';
				foreach ( $tak['aylar'] as $anahtar => $v ) {
					[ $y, $a ] = array_map( 'intval', explode( '-', $anahtar ) );
					printf( '<tr><td>%s</td><td class="dhf-sag">%s</td></tr>', esc_html( DHF_Takvim::ay_adi( $y, $a ) ), esc_html( self::tl( $v ) ) );
				}
				echo '</tbody></table>';
			}
			echo '<p class="description">' . esc_html__( 'Kalan taksit sayısı, girilmiş taksit numarasından ya da bitiş tarihinden hesaplanır; ikisi de yoksa boş kalır (tahmin edilmez). Anapara/faiz ayrımı kredinin ödeme planı girilmeden hesaplanmaz.', 'deniz-finans' ) . '</p>';
		}

		echo '<style>
		.dhf-toplam{display:flex;flex-wrap:wrap;gap:10px;margin:12px 0;max-width:1100px}
		.dhf-kutu{flex:1;min-width:170px;background:#fff;border:1px solid #c3c4c7;padding:10px 14px}
		.dhf-kutu-etiket,.dhf-kutu-fark{display:block;font-size:12px;color:#646970}
		.dhf-kutu-deger{display:block;font-size:18px;font-weight:600;margin:2px 0}
		.dhf-kutu.dhf-faiz .dhf-kutu-deger{color:#b32d2e}.dhf-kutu.dhf-vurgu{border-color:#2271b1;border-width:2px}
		.dhf-sag{text-align:right}.dhf-soluk{color:#646970}.dhf-eksi{color:#b32d2e}
		.dhf-rozet{font-size:13px;background:#f0f0f1;border-radius:3px;padding:2px 8px;vertical-align:middle}
		.dhf-tablo td,.dhf-tablo th{padding:6px 8px}
		.dhf-toplam-satir th{background:#f6f7f7}
		.dhf-foto-form input[type=text]{width:120px}
		</style></div>';
	}

	/** Seçili borcun aylık seyri + banka rakamı girişi. */
	private static function detay( object $b, int $yil, int $ay ): void {
		$durum = DHF_Borc::durum( $b, $yil );
		$ad    = trim( (string) ( $b->kurum ?? '' ) . ' · ' . $b->ad, ' ·' );
		$tip   = (string) $b->tip;

		printf( '<h2>%s <span class="dhf-rozet">%s</span></h2>', esc_html( $ad ), esc_html( DHF_Borc::TIPLER[ $tip ] ?? $tip ) );

		if ( $durum['hesaplanabilir'] ) {
			printf( '<p class="description">%s</p>', esc_html( sprintf(
				'Açılış (devir) %s TL olarak alındı. Bakiye her ay şöyle yürür: açılış + harcama + faiz − ödeme.',
				self::tl( $durum['acilis'] )
			) ) );
			echo '<table class="widefat striped dhf-tablo" style="max-width:900px"><thead><tr><th>Ay</th><th class="dhf-sag">Açılış</th><th class="dhf-sag">Harcama</th><th class="dhf-sag">Faiz</th><th class="dhf-sag">Ödeme</th><th class="dhf-sag">Kapanış (hesaplanan)</th><th class="dhf-sag">Banka (ekstre)</th><th class="dhf-sag">Fark</th></tr></thead><tbody>';
			foreach ( $durum['seyir'] as $a => $r ) {
				$bildirilen = null;
				foreach ( [ 'ekstre_borcu', 'guncel_borc' ] as $t ) {
					if ( isset( $durum['fotolar'][ $a ][ $t ] ) && null !== $durum['fotolar'][ $a ][ $t ]->tutar ) {
						$bildirilen = (float) $durum['fotolar'][ $a ][ $t ]->tutar;
						break;
					}
				}
				if ( ! $r['hareket'] && null === $bildirilen ) {
					continue;
				}
				$fark = null !== $bildirilen ? round( $bildirilen - $r['kapanis'], 2 ) : null;
				printf( '<tr%s><td>%s</td><td class="dhf-sag dhf-soluk">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag"><strong>%s</strong></td><td class="dhf-sag">%s</td><td class="dhf-sag dhf-soluk">%s</td></tr>',
					$a === $ay ? ' style="background:#fff8e5"' : '',
					esc_html( DHF_Takvim::ay_adi( $yil, $a ) ),
					esc_html( self::tl( $r['acilis'] ) ), esc_html( self::tl( $r['harcama'] ) ),
					esc_html( self::tl( $r['faiz'] ) ), esc_html( self::tl( $r['odeme'] ) ),
					esc_html( self::tl( $r['kapanis'] ) ), esc_html( self::tl( $bildirilen ) ),
					esc_html( null === $fark ? '—' : ( $fark > 0 ? '+' : '' ) . self::tl( $fark ) ) );
			}
			echo '</tbody></table>';
			$eksi = array_filter( $durum['seyir'], static fn( array $r ): bool => $r['hareket'] && $r['kapanis'] < 0 );
			if ( $eksi ) {
				printf( '<p class="dhf-uyari-not">%s</p>', esc_html__( 'Kapanış eksiye düşüyor: açılış (devir) bakiyesi girilmemiş ya da yıl başındaki ekstreler defterde yok. Rakamlar hareketin yönünü gösterir, bakiyeyi göstermez.', 'deniz-finans' ) );
			}
		} else {
			printf( '<p class="description">%s</p>', esc_html(
				'kmh' === $tip
					? 'KMH bakiyesi defterden yürütülemez: hesaba giren para (maaş, kira, kendi hesapları arası aktarım) defterde yok. Bankanın bildirdiği rakamı aşağıdan girin.'
					: 'Bu borç türünde bakiye defterden hesaplanmaz; bankanın bildirdiği rakamı aşağıdan girin.'
			) );
		}

		// Mevcut fotoğraflar
		if ( $durum['fotolar'] ) {
			echo '<h3>' . esc_html__( 'Girilmiş banka rakamları', 'deniz-finans' ) . '</h3>';
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Ay</th><th>Bakiye tipi</th><th class="dhf-sag">Tutar</th><th class="dhf-sag">Asgari</th><th class="dhf-sag">Ödeme</th><th class="dhf-sag">Faiz</th><th>Son ödeme</th><th>Not</th></tr></thead><tbody>';
			foreach ( $durum['fotolar'] as $a => $tipler ) {
				foreach ( $tipler as $t => $r ) {
					printf( '<tr><td>%s</td><td>%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td class="dhf-sag">%s</td><td>%s</td><td class="dhf-soluk">%s</td></tr>',
						esc_html( DHF_Takvim::ay_adi( $yil, (int) $a ) ),
						esc_html( DHF_Borc::BAKIYE_TIPLERI[ $t ] ?? $t ),
						esc_html( self::tl( null !== $r->tutar ? (float) $r->tutar : null ) ),
						esc_html( self::tl( null !== $r->asgari_odeme ? (float) $r->asgari_odeme : null ) ),
						esc_html( self::tl( null !== $r->odeme ? (float) $r->odeme : null ) ),
						esc_html( self::tl( null !== $r->faiz ? (float) $r->faiz : null ) ),
						esc_html( $r->son_odeme_tarihi ? mysql2date( 'd.m.Y', (string) $r->son_odeme_tarihi ) : '—' ),
						esc_html( (string) ( $r->not_metni ?? '' ) ) );
				}
			}
			echo '</tbody></table>';
		}

		// Giriş formu
		$tipler = DHF_Borc::TIP_BAKIYE[ $tip ] ?? array_keys( DHF_Borc::BAKIYE_TIPLERI );
		echo '<h3>' . esc_html__( 'Banka rakamı gir / güncelle', 'deniz-finans' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dhf-foto-form">';
		wp_nonce_field( 'dhf_borc_foto' );
		echo '<input type="hidden" name="action" value="dhf_borc_foto">';
		printf( '<input type="hidden" name="borc" value="%d"><input type="hidden" name="yil" value="%d">', (int) $b->id, $yil );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Ay', 'deniz-finans' ) . '</th><td><select name="ay">';
		foreach ( DHF_Takvim::AYLAR as $n => $adi ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', $n, selected( $n, $ay, false ), esc_html( $adi ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Bakiye tipi', 'deniz-finans' ) . '</th><td><select name="tip">';
		foreach ( $tipler as $t ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $t ), esc_html( DHF_Borc::BAKIYE_TIPLERI[ $t ] ?? $t ) );
		}
		echo '</select> <span class="description">' . esc_html__( '"Açılış (devir)" yılın ilk ayına girilir: 1 Ocak itibarıyla devreden borç.', 'deniz-finans' ) . '</span></td></tr>';
		foreach ( [ 'tutar' => 'Tutar', 'asgari' => 'Asgari ödeme', 'odeme' => 'Yapılan ödeme', 'faiz' => 'Faiz' ] as $alan => $etiket ) {
			printf( '<tr><th>%s</th><td><input type="text" name="%s" placeholder="0,00"></td></tr>', esc_html( $etiket ), esc_attr( $alan ) );
		}
		echo '<tr><th>' . esc_html__( 'Son ödeme tarihi', 'deniz-finans' ) . '</th><td><input type="date" name="son_odeme"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Not', 'deniz-finans' ) . '</th><td><input type="text" name="not" class="regular-text"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Kaydet', 'deniz-finans' ) );
		echo '<p class="description">' . esc_html__( 'Bütün tutar alanları boş bırakılırsa o ayın/tipin kaydı silinir. Boş hücre "veri yok" demektir, sıfır demek değildir.', 'deniz-finans' ) . '</p>';
		echo '</form>';
	}

	/** admin_post_dhf_borc_foto */
	public static function handle(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_borc_foto' );
		$p    = wp_unslash( $_POST );
		$borc = absint( $p['borc'] ?? 0 );
		$yil  = absint( $p['yil'] ?? 0 );
		$ay   = absint( $p['ay'] ?? 0 );
		$tip  = sanitize_key( (string) ( $p['tip'] ?? '' ) );

		$mesaj = 'borc_hata';
		if ( $borc && $yil && $ay >= 1 && $ay <= 12 && isset( DHF_Borc::BAKIYE_TIPLERI[ $tip ] ) ) {
			$donem = DHF_Islem::donem( $yil, $ay );
			if ( $donem ) {
				$ok = DHF_Borc::foto_yaz( $borc, (int) $donem->id, $tip, [
					'tutar'            => self::sayi( (string) ( $p['tutar'] ?? '' ) ),
					'banka_bildirimi'  => self::sayi( (string) ( $p['tutar'] ?? '' ) ),
					'asgari_odeme'     => self::sayi( (string) ( $p['asgari'] ?? '' ) ),
					'odeme'            => self::sayi( (string) ( $p['odeme'] ?? '' ) ),
					'faiz'             => self::sayi( (string) ( $p['faiz'] ?? '' ) ),
					'son_odeme_tarihi' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['son_odeme'] ?? '' ) ) ? (string) $p['son_odeme'] : null,
					'not'              => sanitize_text_field( (string) ( $p['not'] ?? '' ) ) ?: null,
				] );
				$mesaj = $ok ? 'borc_kaydedildi' : 'borc_hata';
			}
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'dhf-borclar', 'yil' => $yil ?: (int) current_time( 'Y' ), 'ay' => $ay ?: (int) current_time( 'n' ), 'borc' => $borc, 'dhf_mesaj' => $mesaj ],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
