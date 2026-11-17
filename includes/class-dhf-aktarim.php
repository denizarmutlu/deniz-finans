<?php
/**
 * Veri aktarımı (plan §2.9, §5.9).
 *
 * Akış: önizleme → doğrulama → onay → yazma. Önizleme hiçbir işlem satırı yazmaz;
 * yalnızca aktarim ve aktarim_satir tablolarına kaynak satırı olduğu gibi kaydeder.
 *
 * Satır durumları (aktarim_satir.onay_durumu):
 *   hazir      — kalemi bulundu, sorun yok
 *   eslesmedi  — açıklama hiçbir kaleme bağlanamadı; ekranda seçilecek (öneri varsa kalem_id'de)
 *   kuyruk     — inceleme kuyruğu: hatalı tarih/tutar, mükerrer, kategori yok, defterde zaten var
 *   zaten_var  — bu kaynak satır daha önceki bir aktarımda yazıldı ya da gerekçesiyle atlandı;
 *                aynı dosya tekrar yüklenince ne yazılır ne yeniden sorulur
 *   yazildi    — işlem defterine yazıldı (eslesen_islem_id dolu)
 *   atlandi    — gerekçesiyle dışarıda bırakıldı; satır silinmez, kaydı kalır
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Aktarim {

	public const HATALAR = [
		'hatali_tarih'  => 'Tarih okunamadı veya geçersiz',
		'hatali_tur'    => 'Tür Gelir / Gider / Yatırım değil',
		'hatali_tutar'  => 'Tutar okunamadı',
		'sifir_tutar'   => 'Tutar sıfır',
		'negatif_tutar' => 'Tutar eksi',
		'mukerrer'      => 'Dosyada birebir aynı satır var',
		'kategori_yok'  => 'Kategori tanımlı değil',
		'defterde_var'  => 'Defterde aynı tarih, kalem ve tutarla bir kayıt zaten var',
	];

	/* ---------------------------------------------------------------- */
	/* Kalem eşleştirme                                                  */
	/* ---------------------------------------------------------------- */

	/**
	 * Eşleştirme tablosunu kalem sözlüğünden doldurur: her kalemin adı ve
	 * not alanındaki "Defterdeki varyantlar: a | b | c" listesi. Bir kez çalışır.
	 */
	public static function eslesme_seed(): int {
		global $wpdb;
		if ( get_option( 'dhf_eslesme_seed_done' ) ) {
			return 0;
		}
		$n = 0;
		foreach ( DHF_Kalem::hepsi() as $k ) {
			$metinler = [ (string) $k->ad ];
			if ( $k->not_metni && preg_match( '/Defterdeki varyantlar:\s*(.+)$/u', (string) $k->not_metni, $m ) ) {
				$metinler = array_merge( $metinler, explode( '|', $m[1] ) );
			}
			foreach ( $metinler as $metin ) {
				$n += self::eslesme_ekle( $metin, (string) $k->tur, (int) $k->id, 'sozluk' ) ? 1 : 0;
			}
		}
		update_option( 'dhf_eslesme_seed_done', $n, false );
		return $n;
	}

	public static function eslesme_ekle( string $metin, string $tur, int $kalem_id, string $kaynak = 'elle' ): bool {
		global $wpdb;
		$metin = DHF_Ayristirici::normalize( $metin );
		if ( '' === $metin || ! $kalem_id ) {
			return false;
		}
		$t = DHF_Schema::table( 'kalem_eslesme' );
		// Aynı metin başka kaleme bağlıysa elle seçilen kazanır.
		return false !== $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (metin, tur, kalem_id, kaynak) VALUES (%s, %s, %d, %s)
			 ON DUPLICATE KEY UPDATE kalem_id = VALUES(kalem_id), kaynak = VALUES(kaynak)",
			$metin, $tur, $kalem_id, $kaynak
		) );
	}

	public static function eslesme_bul( string $metin, string $tur ): ?int {
		global $wpdb;
		$t  = DHF_Schema::table( 'kalem_eslesme' );
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT kalem_id FROM {$t} WHERE metin = %s AND tur = %s",
			DHF_Ayristirici::normalize( $metin ), $tur
		) );
		return $id ? (int) $id : null;
	}

	/**
	 * Eşleşme yoksa öneri: aynı türdeki kalemlerin adı ve bilinen varyantlarıyla benzerlik
	 * (DHF_Ayristirici::benzerlik); aynı kategorideki aday +10. Öneri yalnızca ön seçimdir,
	 * ekranda "öneri — kontrol et" diye işaretlenir ve onaylanmadan yazılmaz.
	 */
	public static function oneri( string $metin, string $tur, ?int $kategori_id ): ?int {
		global $wpdb;
		$e = DHF_Schema::table( 'kalem_eslesme' );
		$k = DHF_Schema::table( 'kalem' );

		$adaylar = $wpdb->get_results( $wpdb->prepare(
			"SELECT es.metin, es.kalem_id, kl.kategori_id FROM {$e} es
			 INNER JOIN {$k} kl ON kl.id = es.kalem_id WHERE es.tur = %s",
			$tur
		) );

		$en_iyi = null;
		$skor   = 0.0;
		foreach ( $adaylar as $a ) {
			$puan = DHF_Ayristirici::benzerlik( $metin, (string) $a->metin );
			if ( $kategori_id && (int) $a->kategori_id === $kategori_id ) {
				$puan += 10;
			}
			if ( $puan > $skor ) {
				$skor   = $puan;
				$en_iyi = (int) $a->kalem_id;
			}
		}
		return $skor >= 60 ? $en_iyi : null;
	}

	/**
	 * Eşleştirme anahtarlarını güncel normalize() kuralına taşır (0.3.1: Türkçe harfler katlandı).
	 * Yeni anahtarda çakışan satır silinir; elle girilmiş eşleşme sözlükten gelene tercih edilir.
	 */
	public static function eslesme_normalize_et(): int {
		global $wpdb;
		if ( (int) get_option( 'dhf_eslesme_norm', 1 ) >= 2 ) {
			return 0;
		}
		$t     = DHF_Schema::table( 'kalem_eslesme' );
		$satir = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY kaynak = 'elle' DESC, id" );
		$alinan = [];
		$n      = 0;
		foreach ( $satir as $r ) {
			$yeni = DHF_Ayristirici::normalize( (string) $r->metin );
			$key  = $r->tur . '|' . $yeni;
			if ( isset( $alinan[ $key ] ) ) {
				$wpdb->delete( $t, [ 'id' => (int) $r->id ], [ '%d' ] );
				++$n;
				continue;
			}
			$alinan[ $key ] = true;
			if ( $yeni !== $r->metin ) {
				// Geçici çakışmayı önlemek için önce benzersiz bir ara değer.
				$wpdb->update( $t, [ 'metin' => '~' . $r->id ], [ 'id' => (int) $r->id ] );
				$wpdb->update( $t, [ 'metin' => $yeni ], [ 'id' => (int) $r->id ] );
				++$n;
			}
		}
		update_option( 'dhf_eslesme_norm', 2, false );
		return $n;
	}

	/* ---------------------------------------------------------------- */
	/* Önizleme                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * CSV'yi okur, satırları aktarim_satir'a yazar. İşlem defterine hiçbir şey yazmaz.
	 * $yil/$ay verilirse yalnızca o ayın satırları alınır; tarihi okunamayan satırlar
	 * hangi aya ait olduğu bilinemeyeceği için her durumda kuyruğa girer.
	 *
	 * @return int|WP_Error aktarim_id
	 */
	public static function onizleme( string $csv, string $dosya_adi, string $profil, ?int $yil = null, ?int $ay = null ): int|WP_Error {
		global $wpdb;

		$veri   = DHF_Ayristirici::csv( $csv );
		$harita = DHF_Ayristirici::sutun_haritasi( $profil, $veri['basliklar'] );
		if ( is_string( $harita ) ) {
			return new WP_Error( 'sutun', sprintf( 'Dosyada "%s" sütunu bulunamadı. Başlık satırı: %s', $harita, implode( ' · ', $veri['basliklar'] ) ) );
		}
		if ( ! $veri['satirlar'] ) {
			return new WP_Error( 'bos', 'Dosyada veri satırı yok.' );
		}

		$akt_t   = DHF_Schema::table( 'aktarim' );
		$satir_t = DHF_Schema::table( 'aktarim_satir' );
		$hash    = hash( 'sha256', $profil . '|' . $yil . '|' . $ay . '|' . $csv );

		// Aynı dosya + aynı filtre için bekleyen önizleme varsa yenisini açma.
		$var = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$akt_t} WHERE ozet_hash = %s AND durum = 'onizleme'", $hash
		) );
		if ( $var ) {
			return (int) $var;
		}

		$wpdb->insert( $akt_t, [
			'kaynak'       => 'csv',
			'dosya_adi'    => mb_substr( $dosya_adi, 0, 255 ),
			'sekme'        => $profil,
			'profil'       => $profil,
			'filtre_yil'   => $yil,
			'filtre_ay'    => $ay,
			'kullanici_id' => get_current_user_id() ?: null,
			'ozet_hash'    => $hash,
			'durum'        => 'onizleme',
			'tarih'        => current_time( 'mysql' ),
		] );
		$aktarim_id = (int) $wpdb->insert_id;
		if ( ! $aktarim_id ) {
			return new WP_Error( 'db', 'Aktarım kaydı oluşturulamadı.' );
		}

		$gorulen = [];   // içerik anahtarı => [kaç kez, ilk satır no]
		foreach ( $veri['satirlar'] as $no => $hucreler ) {
			$s = DHF_Ayristirici::satir( $hucreler, $harita );

			$icerik = DHF_Ayristirici::icerik_anahtari( $s['ham'] );
			$gorulen[ $icerik ][0] = ( $gorulen[ $icerik ][0] ?? 0 ) + 1;
			$gorulen[ $icerik ][1] = $gorulen[ $icerik ][1] ?? $no;
			$tekrar = $gorulen[ $icerik ][0];

			// Ay filtresi: tarihi okunan ve başka aya düşen satır bu aktarıma girmez.
			if ( $s['tarih'] && $yil && $ay && substr( $s['tarih'], 0, 7 ) !== sprintf( '%04d-%02d', $yil, $ay ) ) {
				continue;
			}

			$kategori_id = $s['tur'] ? self::kategori_bul( $s['ham']['kategori'], $s['tur'] ) : null;
			$kalem_id    = $s['tur'] ? self::eslesme_bul( $s['ham']['aciklama'], $s['tur'] ) : null;
			$oneri       = ( ! $kalem_id && $s['tur'] ) ? self::oneri( $s['ham']['aciklama'], $s['tur'], $kategori_id ) : null;

			$hatalar = $s['hatalar'];
			$gerekce = null;
			if ( $tekrar > 1 ) {
				$hatalar[] = 'mukerrer';
				$gerekce   = sprintf( 'Satır %d ile birebir aynı', $gorulen[ $icerik ][1] );
			}
			if ( $s['tur'] && ! $kategori_id ) {
				$hatalar[] = 'kategori_yok';
			}
			if ( ! $hatalar && $s['tarih'] && null !== $s['tutar'] && self::defterde_var( $s['tarih'], $s['tur'], $s['ham']['kategori'], $s['ham']['aciklama'], $s['tutar'] ) ) {
				$hatalar[] = 'defterde_var';
			}

			$anahtar = DHF_Ayristirici::satir_anahtari( $profil, $s['ham'], $tekrar );

			if ( self::daha_once_yazildi( $anahtar ) ) {
				$durum = 'zaten_var';
			} elseif ( $hatalar ) {
				$durum = 'kuyruk';
			} elseif ( $kalem_id ) {
				$durum = 'hazir';
			} else {
				$durum = 'eslesmedi';
			}

			$wpdb->insert( $satir_t, [
				'aktarim_id'      => $aktarim_id,
				'kaynak_satir_no' => $no,
				'satir_anahtari'  => $anahtar,
				'ham_veri'        => wp_json_encode( $s['ham'], JSON_UNESCAPED_UNICODE ),
				'tarih'           => $s['tarih'],
				'tur'             => $s['tur'],
				'kategori_id'     => $kategori_id,
				'tutar'           => $s['tutar'],
				'kalem_id'        => $kalem_id ?: $oneri,
				'hata'            => $hatalar ? implode( ',', array_unique( $hatalar ) ) : null,
				'onay_durumu'     => $durum,
				// Kuyruktaki satırın varsayılanı: atla. Ancak bilinçli "ekle" ile yazılır.
				'karar'           => 'kuyruk' === $durum ? 'atla' : ( 'eslesmedi' === $durum && $oneri ? 'oneri' : null ),
				'gerekce'         => $gerekce,
			] );
		}

		return $aktarim_id;
	}

	private static function kategori_bul( string $ad, string $tur ): ?int {
		static $cache = null;
		global $wpdb;
		if ( null === $cache ) {
			$cache = [];
			foreach ( $wpdb->get_results( 'SELECT id, ad, tur FROM ' . DHF_Schema::table( 'kategori' ) ) as $k ) {
				$cache[ $k->tur . '|' . DHF_Ayristirici::normalize( (string) $k->ad ) ] = (int) $k->id;
			}
		}
		return $cache[ $tur . '|' . DHF_Ayristirici::normalize( $ad ) ] ?? null;
	}

	private static function daha_once_yazildi( string $anahtar ): bool {
		global $wpdb;
		$t = DHF_Schema::table( 'aktarim_satir' );
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$t} WHERE satir_anahtari = %s AND onay_durumu IN ('yazildi', 'atlandi') LIMIT 1", $anahtar
		) );
	}

	private static function defterde_var( string $tarih, string $tur, string $kategori, string $aciklama, float $tutar ): bool {
		global $wpdb;
		$t = DHF_Schema::table( 'islem' );
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$t} WHERE tekil_anahtar = %s LIMIT 1",
			DHF_Ayristirici::tekil_anahtar( $tarih, $tur, $kategori, $aciklama, $tutar )
		) );
	}

	/* ---------------------------------------------------------------- */
	/* Okuma                                                             */
	/* ---------------------------------------------------------------- */

	public static function bul( int $id ): ?object {
		global $wpdb;
		$t = DHF_Schema::table( 'aktarim' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ) ?: null;
	}

	/** @return object[] */
	public static function liste( int $limit = 20 ): array {
		global $wpdb;
		$a = DHF_Schema::table( 'aktarim' );
		$s = DHF_Schema::table( 'aktarim_satir' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, COUNT(s.id) AS satir_sayisi,
			        SUM(s.onay_durumu = 'kuyruk') AS kuyruk_sayisi
			 FROM {$a} a LEFT JOIN {$s} s ON s.aktarim_id = a.id
			 GROUP BY a.id ORDER BY a.id DESC LIMIT %d",
			$limit
		) );
	}

	/** @return object[] */
	public static function satirlar( int $aktarim_id ): array {
		global $wpdb;
		$s = DHF_Schema::table( 'aktarim_satir' );
		$k = DHF_Schema::table( 'kalem' );
		$c = DHF_Schema::table( 'kategori' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT s.*, kl.ad AS kalem_ad, kl.etki_profili, kl.aktif AS kalem_aktif, kt.ad AS kategori_ad
			 FROM {$s} s
			 LEFT JOIN {$k} kl ON kl.id = s.kalem_id
			 LEFT JOIN {$c} kt ON kt.id = s.kategori_id
			 WHERE s.aktarim_id = %d
			 ORDER BY FIELD(s.onay_durumu, 'eslesmedi', 'kuyruk', 'hazir', 'zaten_var', 'yazildi', 'atlandi'),
			          s.tarih, s.kaynak_satir_no",
			$aktarim_id
		) );
	}

	/** Satır yazılacak mı? Yazma ve özetin tek ortak kuralı. */
	public static function yazilacak_mi( object $r ): bool {
		if ( ! $r->kalem_id || ! $r->tarih || null === $r->tutar || (float) $r->tutar <= 0 ) {
			return false;
		}
		if ( 'hazir' === $r->onay_durumu ) {
			return true;
		}
		return 'kuyruk' === $r->onay_durumu && 'ekle' === $r->karar;
	}

	/**
	 * Önizleme özeti: durum sayıları, Sheets'teki düz toplamlar ve yazılacak satırların
	 * tiplenmiş etki toplamları. Mutabakatın ilk hali (§5.4).
	 *
	 * @return array{durum:array<string,int>,duz:array<string,float>,etki:array<string,float>,atlanan:float,eksik_kalem:int}
	 */
	public static function ozet( int $aktarim_id ): array {
		$durum = [];
		$duz   = [ 'gelir' => 0.0, 'gider' => 0.0, 'yatirim' => 0.0 ];
		$etki  = array_fill_keys( DHF_Etki::TIPLER, 0.0 );
		$atlanan = 0.0;
		$eksik   = 0;

		foreach ( self::satirlar( $aktarim_id ) as $r ) {
			$durum[ $r->onay_durumu ] = ( $durum[ $r->onay_durumu ] ?? 0 ) + 1;

			if ( $r->tur && null !== $r->tutar && isset( $duz[ $r->tur ] ) ) {
				$duz[ $r->tur ] += (float) $r->tutar;
			}
			if ( 'eslesmedi' === $r->onay_durumu ) {
				++$eksik;
			}

			$yaz = self::yazilacak_mi( $r ) || 'yazildi' === $r->onay_durumu;
			if ( $yaz ) {
				foreach ( DHF_Etki::uret( (string) $r->etki_profili, (float) $r->tutar ) as $e ) {
					$etki[ $e['etki_tipi'] ] += $e['tutar'];
				}
			} elseif ( null !== $r->tutar && 'zaten_var' !== $r->onay_durumu ) {
				$atlanan += (float) $r->tutar;
			}
		}

		$etki['nakit_ihtiyaci'] = $etki['nakit_girisi'] - $etki['nakit_cikisi'];

		return [ 'durum' => $durum, 'duz' => $duz, 'etki' => $etki, 'atlanan' => $atlanan, 'eksik_kalem' => $eksik ];
	}

	/* ---------------------------------------------------------------- */
	/* Kararlar ve yazma                                                 */
	/* ---------------------------------------------------------------- */

	/**
	 * Ekrandan gelen kararları uygular.
	 *
	 * @param array<int,array{kalem_id?:int,hatirla?:bool,karar?:string,tarih?:string}> $kararlar satir_id => karar
	 * @return int güncellenen satır sayısı
	 */
	public static function karar_kaydet( int $aktarim_id, array $kararlar ): int {
		global $wpdb;
		$akt = self::bul( $aktarim_id );
		if ( ! $akt || 'onizleme' !== $akt->durum ) {
			return 0;
		}
		$t = DHF_Schema::table( 'aktarim_satir' );

		$n = 0;
		foreach ( $kararlar as $satir_id => $k ) {
			$r = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE id = %d AND aktarim_id = %d", (int) $satir_id, $aktarim_id
			) );
			if ( ! $r || in_array( $r->onay_durumu, [ 'yazildi', 'zaten_var', 'atlandi' ], true ) ) {
				continue;
			}

			$set      = [];
			$kalem_id = (int) ( $k['kalem_id'] ?? 0 );
			if ( $kalem_id && DHF_Kalem::bul( $kalem_id ) ) {
				$set['kalem_id'] = $kalem_id;
				if ( 'eslesmedi' === $r->onay_durumu ) {
					$set['onay_durumu'] = 'hazir';
					$set['karar']       = null;
				}
				if ( ! empty( $k['hatirla'] ) && $r->tur ) {
					$ham = json_decode( (string) $r->ham_veri, true ) ?: [];
					self::eslesme_ekle( (string) ( $ham['aciklama'] ?? '' ), (string) $r->tur, $kalem_id );
				}
			}

			if ( 'kuyruk' === $r->onay_durumu ) {
				$karar = ( 'ekle' === ( $k['karar'] ?? '' ) ) ? 'ekle' : 'atla';
				$set['karar'] = $karar;

				// Hatalı tarih yalnızca elle düzeltilerek eklenebilir; düzeltme gerekçeye yazılır.
				$duz = isset( $k['tarih'] ) ? trim( (string) $k['tarih'] ) : '';
				if ( '' !== $duz ) {
					$yeni = DHF_Ayristirici::tarih( $duz ) ?? ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $duz ) ? $duz : null );
					if ( $yeni && ( ! $akt->filtre_yil || substr( $yeni, 0, 7 ) === sprintf( '%04d-%02d', $akt->filtre_yil, $akt->filtre_ay ) ) ) {
						$ham            = json_decode( (string) $r->ham_veri, true ) ?: [];
						$set['tarih']   = $yeni;
						$set['gerekce'] = sprintf( 'Tarih elle düzeltildi: %s → %s', $ham['tarih'] ?? '?', $yeni );
					}
				}
			}

			if ( $set ) {
				$n += (int) (bool) $wpdb->update( $t, $set, [ 'id' => (int) $r->id ] );
			}
		}

		// Yeni hatırlanan eşleşmeler aynı aktarımdaki diğer eşleşmeyen satırlara da uygulanır.
		foreach ( $wpdb->get_results( $wpdb->prepare(
			"SELECT id, tur, ham_veri FROM {$t} WHERE aktarim_id = %d AND onay_durumu = 'eslesmedi'", $aktarim_id
		) ) as $r ) {
			$ham = json_decode( (string) $r->ham_veri, true ) ?: [];
			$id  = self::eslesme_bul( (string) ( $ham['aciklama'] ?? '' ), (string) $r->tur );
			if ( $id ) {
				$wpdb->update( $t, [ 'kalem_id' => $id, 'onay_durumu' => 'hazir', 'karar' => null ], [ 'id' => (int) $r->id ] );
				++$n;
			}
		}

		return $n;
	}

	/**
	 * Onaylanan satırları işlem defterine yazar. Tek veritabanı işlemi: ya hepsi ya hiçbiri.
	 *
	 * @return array{yazildi:int,atlandi:int}|WP_Error
	 */
	public static function yaz( int $aktarim_id ): array|WP_Error {
		global $wpdb;
		$akt = self::bul( $aktarim_id );
		if ( ! $akt || 'onizleme' !== $akt->durum ) {
			return new WP_Error( 'durum', 'Bu aktarım zaten yazılmış veya iptal edilmiş.' );
		}

		$satirlar = self::satirlar( $aktarim_id );
		$eksik    = array_filter( $satirlar, static fn( $r ): bool => 'eslesmedi' === $r->onay_durumu );
		if ( $eksik ) {
			return new WP_Error( 'eksik', sprintf( '%d satırın alt kategorisi seçilmedi. Önce alt kategori seçin.', count( $eksik ) ) );
		}

		$islem_t = DHF_Schema::table( 'islem' );
		$satir_t = DHF_Schema::table( 'aktarim_satir' );
		$bugun   = current_time( 'Y-m-d' );

		$wpdb->query( 'START TRANSACTION' );
		$yazildi = $atlandi = 0;

		foreach ( $satirlar as $r ) {
			if ( self::yazilacak_mi( $r ) ) {
				$ham   = json_decode( (string) $r->ham_veri, true ) ?: [];
				$kalem = DHF_Kalem::bul( (int) $r->kalem_id );
				$yil   = (int) substr( (string) $r->tarih, 0, 4 );
				$ay    = (int) substr( (string) $r->tarih, 5, 2 );
				$donem = DHF_Islem::donem( $yil, $ay );
				$tutar = (float) $r->tutar;
				if ( ! DHF_Donem::duzenlenebilir( $donem ) ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'kesin', sprintf( '%s kesinleşmiş; aktarım için önce ayı gerekçeyle yeniden aç. Hiçbir satır yazılmadı.', DHF_Takvim::ay_adi( $yil, $ay ) ) );
				}

				$ok = $wpdb->insert( $islem_t, [
					'donem_id'      => (int) $donem->id,
					'tarih'         => $r->tarih,
					'orijinal_gun'  => (int) substr( (string) $r->tarih, 8, 2 ),
					'kalem_id'      => (int) $r->kalem_id,
					'tur'           => $r->tur,
					'kategori_id'   => $r->kategori_id ?: ( $kalem->kategori_id ?? null ),
					'hesap_id'      => $kalem->hesap_id ?? null,
					'aciklama'      => mb_substr( trim( (string) ( $ham['aciklama'] ?? '' ) ), 0, 190 ),
					'tutar'         => $tutar,
					'durum'         => $r->tarih > $bugun ? 'planlanan' : 'gerceklesti',
					'kaynak'        => 'import',
					'tekil_anahtar' => DHF_Ayristirici::tekil_anahtar( (string) $r->tarih, (string) $r->tur, (string) ( $ham['kategori'] ?? '' ), (string) ( $ham['aciklama'] ?? '' ), $tutar ),
				] );
				$islem_id = (int) $wpdb->insert_id;
				if ( ! $ok || ! $islem_id ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'db', sprintf( 'Kaynak satır %d yazılamadı; hiçbir satır yazılmadı.', (int) $r->kaynak_satir_no ) );
				}
				DHF_Etki::yaz( $islem_id, (string) $kalem->etki_profili, $tutar );
				$wpdb->update( $satir_t, [ 'onay_durumu' => 'yazildi', 'eslesen_islem_id' => $islem_id ], [ 'id' => (int) $r->id ] );
				++$yazildi;
			} elseif ( in_array( $r->onay_durumu, [ 'kuyruk', 'hazir' ], true ) ) {
				$gerekce = $r->gerekce ?: self::hata_metni( (string) $r->hata );
				if ( $r->hata && str_contains( (string) $r->hata, 'mukerrer' ) ) {
					$gerekce = 'Mükerrer, atlandı. ' . ( $r->gerekce ?: '' );
				}
				$wpdb->update( $satir_t, [ 'onay_durumu' => 'atlandi', 'gerekce' => mb_substr( trim( $gerekce ), 0, 255 ) ], [ 'id' => (int) $r->id ] );
				++$atlandi;
			}
		}

		$wpdb->update( DHF_Schema::table( 'aktarim' ), [
			'durum'       => 'yazildi',
			'yazilan'     => $yazildi,
			'onay_tarihi' => current_time( 'mysql' ),
		], [ 'id' => $aktarim_id ] );
		$wpdb->query( 'COMMIT' );

		return [ 'yazildi' => $yazildi, 'atlandi' => $atlandi ];
	}

	/** Yalnızca yazılmamış önizleme silinebilir. Yazılmış aktarımın kaydı kalıcıdır. */
	public static function iptal( int $aktarim_id ): bool {
		global $wpdb;
		$akt = self::bul( $aktarim_id );
		if ( ! $akt || 'onizleme' !== $akt->durum ) {
			return false;
		}
		$wpdb->delete( DHF_Schema::table( 'aktarim_satir' ), [ 'aktarim_id' => $aktarim_id ], [ '%d' ] );
		$wpdb->delete( DHF_Schema::table( 'aktarim' ), [ 'id' => $aktarim_id ], [ '%d' ] );
		return true;
	}

	public static function hata_metni( string $kodlar ): string {
		if ( '' === $kodlar ) {
			return '';
		}
		return implode( ' · ', array_map(
			static fn( string $k ): string => self::HATALAR[ $k ] ?? $k,
			explode( ',', $kodlar )
		) );
	}
}
