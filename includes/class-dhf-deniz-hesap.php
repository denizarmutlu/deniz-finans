<?php
/**
 * Deniz Hesap (Domain & Hosting Takip) bağlantısı — plan §5.12.
 *
 * Web sitesi işinin tek doğru kaynağı Deniz Hesap eklentisidir. Deniz Finans onun
 * `hk_hareketler` tablosunu SALT OKUNUR okur ve her ödenmiş hareketi deftere bir
 * ayna satırı olarak yansıtır (kaynak = 'deniz_hesap', dis_ref = 'hk:<id>').
 * Ayna satırları Finans'ta düzenlenmez; değişiklik Deniz Hesap'ta yapılır ve ay
 * açıldığında / "Deniz Hesap'tan güncelle" ile yeniden yansıtılır.
 *
 * Etkiler:
 *   gelir → gelir + nakit girişi
 *   gider → tüketim + kart borcu artışı (kartla ödeniyor; nakit çıkışı kart ödemesinde zaten var)
 *
 * Kesinleşmiş ay yansıtılmaz; Deniz Hesap'ta sonradan değişen tutar ekranda uyarı olarak görünür.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Deniz_Hesap {

	public const KAYNAK = 'deniz_hesap';

	private static function t( string $ad ): string {
		global $wpdb;
		return $wpdb->prefix . 'hk_' . $ad;
	}

	public static function var_mi(): bool {
		static $var = null;
		global $wpdb;
		if ( null === $var ) {
			$var = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t( 'hareketler' ) ) );
		}
		return $var;
	}

	/**
	 * Ayna satırlarının bağlanacağı iki alt kategori. Yoksa bir kez oluşturulur.
	 *
	 * @return array{gelir:int,gider:int}
	 */
	public static function kalemler(): array {
		global $wpdb;
		$kayit = get_option( 'dhf_hk_kalemler' );
		if ( is_array( $kayit ) && DHF_Kalem::bul( (int) ( $kayit['gelir'] ?? 0 ) ) && DHF_Kalem::bul( (int) ( $kayit['gider'] ?? 0 ) ) ) {
			return [ 'gelir' => (int) $kayit['gelir'], 'gider' => (int) $kayit['gider'] ];
		}

		$kt    = DHF_Schema::table( 'kategori' );
		$gelir = (int) $wpdb->get_var( "SELECT k.id FROM " . DHF_Schema::table( 'kalem' ) . " k WHERE k.tur = 'gelir' AND k.ad = 'Web sitesi'" );
		if ( ! $gelir ) {
			$ek    = (int) $wpdb->get_var( "SELECT id FROM {$kt} WHERE tur = 'gelir' AND ad = 'Ek İşler'" );
			$gelir = (int) DHF_Kalem::ekle( 'Web sitesi', $ek, 'degisken', 'gelir' );
		}

		$web = (int) $wpdb->get_var( "SELECT id FROM {$kt} WHERE tur = 'gider' AND ad = 'Web işi'" );
		if ( ! $web ) {
			$wpdb->insert( $kt, [ 'ad' => 'Web işi', 'tur' => 'gider', 'rapor_rolu' => 'normal', 'sira' => 80, 'aktif' => 1 ] );
			$web = (int) $wpdb->insert_id;
		}
		$gider = DHF_Kalem::ekle( 'Domain ve hosting', $web, 'degisken', 'borc_artisi' );
		$gider = is_wp_error( $gider ) ? 0 : (int) $gider;
		if ( $gider ) {
			DHF_Kalem::guncelle( $gider, [
				'odeme_sekli' => 'karta_otomatik',
				'tekrar_tipi' => 'tek_seferlik', // aylık taslağa girmez; satırları Deniz Hesap'tan gelir
				'not_metni'   => 'Deniz Hesap eklentisinden okunur (domain, hosting, SSL, yeni site giderleri). Kartla ödenir: tüketim + kart borcu artışı; nakit çıkışı kart ödemesinde. Burada düzenlenmez.',
			] );
		}
		if ( $gelir ) {
			DHF_Kalem::guncelle( $gelir, [ 'tekrar_tipi' => 'tek_seferlik' ] );
		}
		$out = [ 'gelir' => $gelir, 'gider' => $gider ];
		update_option( 'dhf_hk_kalemler', $out, false );
		return $out;
	}

	/**
	 * Bir ayın Deniz Hesap'taki ödenmiş hareketleri. Tarih: ödeme tarihi, yoksa hareket tarihi.
	 *
	 * @return object[]
	 */
	public static function hareketler( int $yil, int $ay ): array {
		global $wpdb;
		if ( ! self::var_mi() ) {
			return [];
		}
		$bas = sprintf( '%04d-%02d-01', $yil, $ay );
		$son = sprintf( '%04d-%02d-%02d', $yil, $ay, DHF_Takvim::ay_sonu( $yil, $ay ) );
		$h   = self::t( 'hareketler' );
		$m   = self::t( 'musteriler' );
		$z   = self::t( 'hizmetler' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT h.id, h.tur, h.tutar, h.kategori, h.aciklama,
			        COALESCE(h.odeme_tarihi, h.tarih) AS gun,
			        m.firma, m.ad AS kisi, m.kendi, z.ad AS hizmet, z.tur AS hizmet_tur
			 FROM {$h} h
			 LEFT JOIN {$m} m ON m.id = h.musteri_id
			 LEFT JOIN {$z} z ON z.id = h.hizmet_id
			 WHERE h.durum = 'odendi' AND h.tutar > 0
			   AND COALESCE(h.odeme_tarihi, h.tarih) BETWEEN %s AND %s
			 ORDER BY gun, h.id",
			$bas, $son
		) );
	}

	/** Tarihi olmayan ödenmiş hareket sayısı — hiçbir aya düşemez, ekranda uyarılır. */
	public static function tarihsiz(): int {
		global $wpdb;
		if ( ! self::var_mi() ) {
			return 0;
		}
		$h = self::t( 'hareketler' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$h} WHERE durum = 'odendi' AND odeme_tarihi IS NULL AND tarih IS NULL" );
	}

	/** Bekleyen alacak toplamı (Pano için, 0.5). */
	public static function bekleyen_alacak(): float {
		global $wpdb;
		if ( ! self::var_mi() ) {
			return 0.0;
		}
		$h = self::t( 'hareketler' );
		return (float) $wpdb->get_var( "SELECT COALESCE(SUM(tutar),0) FROM {$h} WHERE tur = 'gelir' AND durum = 'bekliyor'" );
	}

	private static function aciklama( object $r ): string {
		$kim = trim( (string) ( $r->firma ?: $r->kisi ) );
		$ne  = trim( (string) ( $r->hizmet ?: '' ) );
		$ek  = trim( wp_strip_all_tags( (string) $r->aciklama ) );
		$parca = array_filter( [ $kim, $ne, $ek ] );
		return mb_substr( implode( ' · ', $parca ) ?: 'Deniz Hesap #' . $r->id, 0, 190 );
	}

	/**
	 * Ayı Deniz Hesap'la eşitler. Dönem yoksa ve Deniz Hesap'ta o ay hareket yoksa hiçbir şey yapmaz.
	 *
	 * @return array{eklendi:int,guncellendi:int,silindi:int,kilitli:bool,fark:int}
	 */
	public static function esitle( int $yil, int $ay ): array {
		global $wpdb;
		$sonuc = [ 'eklendi' => 0, 'guncellendi' => 0, 'silindi' => 0, 'kilitli' => false, 'fark' => 0 ];
		if ( ! self::var_mi() ) {
			return $sonuc;
		}

		$kaynak = self::hareketler( $yil, $ay );
		$donem  = DHF_Islem::donem( $yil, $ay, false );
		if ( ! $donem && ! $kaynak ) {
			return $sonuc;
		}
		$i = DHF_Schema::table( 'islem' );

		$mevcut = [];
		if ( $donem ) {
			foreach ( $wpdb->get_results( $wpdb->prepare(
				"SELECT id, dis_ref, tutar, tarih, aciklama FROM {$i} WHERE donem_id = %d AND kaynak = %s", $donem->id, self::KAYNAK
			) ) as $r ) {
				$mevcut[ (string) $r->dis_ref ] = $r;
			}
		}

		// Kesinleşmiş ay: yazma yok, yalnızca fark sayılır.
		if ( $donem && ! DHF_Donem::duzenlenebilir( $donem ) ) {
			$sonuc['kilitli'] = true;
			$gorulen = [];
			foreach ( $kaynak as $r ) {
				$ref = 'hk:' . $r->id;
				$gorulen[ $ref ] = true;
				$m = $mevcut[ $ref ] ?? null;
				if ( ! $m || abs( (float) $m->tutar - (float) $r->tutar ) > 0.004 || $m->tarih !== $r->gun ) {
					++$sonuc['fark'];
				}
			}
			$sonuc['fark'] += count( array_diff_key( $mevcut, $gorulen ) );
			return $sonuc;
		}

		$donem   = $donem ?: DHF_Islem::donem( $yil, $ay );
		$kalem   = self::kalemler();
		$gorulen = [];

		foreach ( $kaynak as $r ) {
			$ref = 'hk:' . $r->id;
			$gorulen[ $ref ] = true;
			$gelir    = 'gelir' === $r->tur;
			$kalem_id = $gelir ? $kalem['gelir'] : $kalem['gider'];
			$k        = DHF_Kalem::bul( $kalem_id );
			if ( ! $k ) {
				continue;
			}
			$veri = [
				'tarih'        => $r->gun,
				'orijinal_gun' => (int) substr( (string) $r->gun, 8, 2 ),
				'kalem_id'     => $kalem_id,
				'tur'          => $gelir ? 'gelir' : 'gider',
				'kategori_id'  => $k->kategori_id,
				'aciklama'     => self::aciklama( $r ),
				'tutar'        => round( (float) $r->tutar, 2 ),
				'durum'        => 'gerceklesti',
			];

			$m = $mevcut[ $ref ] ?? null;
			if ( $m ) {
				if ( abs( (float) $m->tutar - $veri['tutar'] ) < 0.005 && $m->tarih === $veri['tarih'] && $m->aciklama === $veri['aciklama'] ) {
					continue;
				}
				$wpdb->update( $i, $veri, [ 'id' => (int) $m->id ] );
				DHF_Etki::yaz( (int) $m->id, (string) $k->etki_profili, $veri['tutar'] );
				++$sonuc['guncellendi'];
			} else {
				$wpdb->insert( $i, $veri + [
					'donem_id' => (int) $donem->id,
					'kaynak'   => self::KAYNAK,
					'dis_ref'  => $ref,
				] );
				$id = (int) $wpdb->insert_id;
				if ( $id ) {
					DHF_Etki::yaz( $id, (string) $k->etki_profili, $veri['tutar'] );
					++$sonuc['eklendi'];
				}
			}
		}

		// Deniz Hesap'ta silinen, ödenmedi'ye dönen ya da başka aya taşınan hareketler.
		foreach ( array_diff_key( $mevcut, $gorulen ) as $m ) {
			$wpdb->delete( DHF_Schema::table( 'islem_etki' ), [ 'islem_id' => (int) $m->id ], [ '%d' ] );
			$wpdb->delete( $i, [ 'id' => (int) $m->id ], [ '%d' ] );
			++$sonuc['silindi'];
		}

		if ( 'yeniden_acildi' === $donem->durum && ( $sonuc['eklendi'] || $sonuc['guncellendi'] || $sonuc['silindi'] ) ) {
			DHF_Donem::log( (int) $donem->id, null, 'deniz_hesap',
				null, sprintf( '+%d ~%d -%d', $sonuc['eklendi'], $sonuc['guncellendi'], $sonuc['silindi'] ),
				DHF_Donem::acilma_gerekcesi( (int) $donem->id ) );
		}
		return $sonuc;
	}

	/** Aktarımda: bu kalem Deniz Hesap'tan okunuyor mu (Sheets satırı atlanmalı mı)? */
	public static function aynalanan_kalem( ?int $kalem_id ): bool {
		if ( ! $kalem_id || ! self::var_mi() ) {
			return false;
		}
		$k = self::kalemler();
		return in_array( $kalem_id, [ $k['gelir'], $k['gider'] ], true );
	}
}
