<?php
/**
 * İşlem defteri ve dönem toplamları.
 *
 * Toplamlar asla saklanmaz (§2.8); hepsi islem_etki üzerinden hesaplanır.
 * Tek bir "gider" toplamı YOKTUR — tüketim, faiz, borç azaltımı ve yatırım ayrıdır.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Islem {

	public const DURUMLAR = [
		'taslak'         => 'Taslak',
		'gerceklesti'    => 'Gerçekleşti',
		'gerceklesmedi'  => 'Bu ay gerçekleşmedi',
		'planlanan'      => 'Planlanan',
	];

	/* ---------------------------------------------------------------- */
	/* Dönem                                                             */
	/* ---------------------------------------------------------------- */

	public static function donem( int $yil, int $ay, bool $olustur = true ): ?object {
		global $wpdb;
		$t = DHF_Schema::table( 'donem' );

		$d = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE yil = %d AND ay = %d", $yil, $ay ) );
		if ( $d || ! $olustur ) {
			return $d ?: null;
		}
		$wpdb->insert( $t, [ 'yil' => $yil, 'ay' => $ay, 'durum' => 'acik' ], [ '%d', '%d', '%s' ] );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE yil = %d AND ay = %d", $yil, $ay ) );
	}

	/**
	 * Kalem sözlüğünden ayın taslağını üretir (§2.1).
	 * Zaten satırı olan kalem atlanır — aynı ay iki kez oluşturulsa bile mükerrer olmaz.
	 * Var olan bir ayda tekrar çağrılınca yalnızca eksik kalemleri ekler.
	 *
	 * @return array{eklendi:int,atlandi:int}
	 */
	public static function taslak_uret( int $yil, int $ay ): array {
		$donem = self::donem( $yil, $ay );
		if ( ! $donem || ! DHF_Donem::duzenlenebilir( $donem ) ) {
			return [ 'eklendi' => 0, 'atlandi' => 0 ];
		}

		$mevcut  = self::kalemleri( (int) $donem->id );
		$eklendi = $atlandi = 0;
		foreach ( DHF_Kalem::donem_icin( $yil, $ay ) as $k ) {
			if ( isset( $mevcut[ (int) $k->id ] ) ) {
				++$atlandi;
				continue;
			}
			$eklendi += self::taslak_satir( $donem, $k ) ? 1 : 0;
		}
		return [ 'eklendi' => $eklendi, 'atlandi' => $atlandi ];
	}

	/** Dönemde satırı olan kalem id'leri. @return array<int,true> */
	private static function kalemleri( int $donem_id ): array {
		global $wpdb;
		$i   = DHF_Schema::table( 'islem' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT kalem_id FROM {$i} WHERE donem_id = %d AND kalem_id IS NOT NULL", $donem_id
		) );
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	/**
	 * Tek kalem için taslak satır. Sabit kalemde tutar dolu, değişkende boş gelir —
	 * "tutar bekliyor" (§2.2). Gün kuralı DHF_Takvim::gun().
	 */
	private static function taslak_satir( object $donem, object $k ): int {
		global $wpdb;
		$orijinal = (int) ( $k->varsayilan_gun ?: 1 );
		$tutar    = ( 'sabit' === $k->tekrar_tipi ) ? (float) $k->varsayilan_tutar : 0.0;

		$wpdb->insert( DHF_Schema::table( 'islem' ), [
			'donem_id'     => $donem->id,
			'tarih'        => DHF_Takvim::tarih( (int) $donem->yil, (int) $donem->ay, $orijinal ),
			'orijinal_gun' => $orijinal,
			'kalem_id'     => $k->id,
			'tur'          => $k->tur,
			'kategori_id'  => $k->kategori_id,
			'hesap_id'     => $k->hesap_id,
			'aciklama'     => $k->ad,
			'tutar'        => $tutar,
			'durum'        => 'taslak',
			'kaynak'       => 'kopya',
		] );

		$id = (int) $wpdb->insert_id;
		if ( $id && $tutar > 0 ) {
			DHF_Etki::yaz( $id, (string) $k->etki_profili, $tutar );
		}
		return $id;
	}

	/**
	 * "Geçen ay vardı, bu ay yok" (§5.1, §3.8): geçen ay gerçekleşmiş, bu ay hiç satırı
	 * olmayan tekrarlayan kalemler. Otomatik ödemelerin deftere girilmeden kalmasını yakalar.
	 *
	 * @return object[] kalem + gecen_tutar + gecen_tarih
	 */
	public static function eksikler( int $yil, int $ay ): array {
		global $wpdb;
		[ $oy, $oa ] = DHF_Takvim::onceki( $yil, $ay );
		$onceki = self::donem( $oy, $oa, false );
		if ( ! $onceki ) {
			return [];
		}
		$bu      = self::donem( $yil, $ay, false );
		$mevcut  = $bu ? self::kalemleri( (int) $bu->id ) : [];
		$i       = DHF_Schema::table( 'islem' );
		$k       = DHF_Schema::table( 'kalem' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT kl.*, SUM(s.tutar) AS gecen_tutar, MAX(s.tarih) AS gecen_tarih
			 FROM {$i} s INNER JOIN {$k} kl ON kl.id = s.kalem_id
			 WHERE s.donem_id = %d AND s.durum = 'gerceklesti' AND s.tutar > 0
			   AND kl.aktif = 1 AND kl.tekrar_tipi <> 'tek_seferlik'
			 GROUP BY kl.id ORDER BY kl.ad",
			$onceki->id
		) );
		return array_values( array_filter( $rows, static fn( $r ): bool => ! isset( $mevcut[ (int) $r->id ] ) ) );
	}

	/** Seçilen kalemleri bu aya taslak olarak ekler. */
	public static function eksik_ekle( int $yil, int $ay, array $kalem_idler ): int {
		$donem = self::donem( $yil, $ay );
		if ( ! $donem || ! DHF_Donem::duzenlenebilir( $donem ) ) {
			return 0;
		}
		$mevcut = self::kalemleri( (int) $donem->id );
		$n      = 0;
		foreach ( array_unique( array_map( 'intval', $kalem_idler ) ) as $id ) {
			$k = DHF_Kalem::bul( $id );
			if ( $k && ! isset( $mevcut[ $id ] ) ) {
				$n += self::taslak_satir( $donem, $k ) ? 1 : 0;
			}
		}
		return $n;
	}

	/**
	 * Elle satır ekler — deftere girilmemiş ödeme ya da tek seferlik harcama.
	 *
	 * Aynı ayda aynı kalem + aynı tutar varsa ENGELLEMEZ, sorar (§2.7): $onay false ise
	 * 'mukerrer' hatası döner ve mevcut satırın tarihini/tutarını söyler.
	 *
	 * @param array{tarih:string,kalem_id:int,tutar:float,not?:string} $v
	 */
	public static function satir_ekle( array $v, bool $onay = false ): int|WP_Error {
		global $wpdb;
		$tarih = (string) $v['tarih'];
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $tarih, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return new WP_Error( 'tarih', 'Tarih geçersiz.' );
		}
		$k = DHF_Kalem::bul( (int) $v['kalem_id'] );
		if ( ! $k ) {
			return new WP_Error( 'kalem', 'Alt kategori seçilmedi.' );
		}
		$tutar = round( (float) $v['tutar'], 2 );
		if ( $tutar <= 0 ) {
			return new WP_Error( 'tutar', 'Tutar sıfırdan büyük olmalı.' );
		}

		$donem = self::donem( (int) $m[1], (int) $m[2] );
		if ( ! DHF_Donem::duzenlenebilir( $donem ) ) {
			return new WP_Error( 'kesin', 'Bu ay kesinleşmiş. Satır eklemek için önce ayı gerekçeyle yeniden aç.' );
		}

		$i = DHF_Schema::table( 'islem' );
		if ( ! $onay ) {
			$var = $wpdb->get_row( $wpdb->prepare(
				"SELECT tarih, tutar FROM {$i} WHERE donem_id = %d AND kalem_id = %d AND tutar = %f LIMIT 1",
				$donem->id, $k->id, $tutar
			) );
			if ( $var ) {
				return new WP_Error( 'mukerrer', sprintf(
					'Bu kalem bu ay zaten var (%s, %s). Yine de eklemek istiyorsan kutuyu işaretleyip tekrar gönder.',
					mysql2date( 'd.m', $var->tarih ), number_format_i18n( (float) $var->tutar, 2 )
				) );
			}
		}

		$kategori = $k->kategori_id ? (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT ad FROM ' . DHF_Schema::table( 'kategori' ) . ' WHERE id = %d', $k->kategori_id
		) ) : '';

		$ok = $wpdb->insert( $i, [
			'donem_id'      => $donem->id,
			'tarih'         => $tarih,
			'orijinal_gun'  => (int) $m[3],
			'kalem_id'      => $k->id,
			'tur'           => $k->tur,
			'kategori_id'   => $k->kategori_id,
			'hesap_id'      => $k->hesap_id,
			'aciklama'      => $k->ad,
			'not_metni'     => '' === trim( (string) ( $v['not'] ?? '' ) ) ? null : trim( (string) $v['not'] ),
			'tutar'         => $tutar,
			'durum'         => $tarih > current_time( 'Y-m-d' ) ? 'planlanan' : 'gerceklesti',
			'kaynak'        => 'manuel',
			'tekil_anahtar' => DHF_Ayristirici::tekil_anahtar( $tarih, (string) $k->tur, $kategori, (string) $k->ad, $tutar ),
		] );
		$id = (int) $wpdb->insert_id;
		if ( ! $ok || ! $id ) {
			return new WP_Error( 'db', 'Satır yazılamadı.' );
		}
		DHF_Etki::yaz( $id, (string) $k->etki_profili, $tutar );

		if ( 'yeniden_acildi' === $donem->durum ) {
			DHF_Donem::log( (int) $donem->id, $id, 'satir_eklendi', null,
				$k->ad . ' · ' . number_format( $tutar, 2, ',', '.' ), DHF_Donem::acilma_gerekcesi( (int) $donem->id ) );
		}
		return $id;
	}

	/* ---------------------------------------------------------------- */
	/* Satırlar                                                          */
	/* ---------------------------------------------------------------- */

	/** @return object[] */
	public static function satirlar( int $donem_id ): array {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		$k = DHF_Schema::table( 'kalem' );
		$c = DHF_Schema::table( 'kategori' );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT s.*, kl.ad AS kalem_ad, kl.tekrar_tipi, kl.etki_profili, kl.odeme_sekli,
					kl.varsayilan_tutar, kt.ad AS kategori_ad, hs.ad AS hesap_ad, hs.tip AS hesap_tip
			 FROM {$i} s
			 LEFT JOIN {$k} kl ON kl.id = s.kalem_id
			 LEFT JOIN {$c} kt ON kt.id = s.kategori_id
			 LEFT JOIN " . DHF_Schema::table( 'hesap' ) . " hs ON hs.id = s.hesap_id
			 WHERE s.donem_id = %d
			 ORDER BY (s.tutar = 0 AND kl.tekrar_tipi = 'degisken') DESC, s.tur, kt.sira, s.tarih",
			$donem_id
		) );
	}

	/** Geçen ayın aynı kalemdeki tutarı — karşılaştırma kolonu için. */
	public static function gecen_ay( int $yil, int $ay ): array {
		global $wpdb;
		$onceki = DHF_Takvim::onceki( $yil, $ay );
		$d      = self::donem( $onceki[0], $onceki[1], false );
		if ( ! $d ) {
			return [];
		}
		$i    = DHF_Schema::table( 'islem' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT kalem_id, SUM(tutar) AS tutar FROM {$i}
			 WHERE donem_id = %d AND kalem_id IS NOT NULL GROUP BY kalem_id",
			$d->id
		) );
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->kalem_id ] = (float) $r->tutar;
		}
		return $out;
	}

	/**
	 * Satır tutarını/durumunu günceller ve etkileri yeniden üretir.
	 * Kesinleşmiş dönemde hiçbir şey yapmaz. Yeniden açılmış dönemde her değişen alan,
	 * açılma gerekçesiyle birlikte log'a yazılır (§2.4). Değişmeyen satıra dokunulmaz.
	 */
	public static function satir_guncelle( int $id, float $tutar, string $durum, ?string $not ): bool {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );

		$satir = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$i} WHERE id = %d", $id ) );
		if ( ! $satir ) {
			return false;
		}
		if ( ! isset( self::DURUMLAR[ $durum ] ) ) {
			$durum = 'taslak';
		}
		$donem = DHF_Donem::bul( (int) $satir->donem_id );
		if ( ! DHF_Donem::duzenlenebilir( $donem ) || DHF_Deniz_Hesap::KAYNAK === $satir->kaynak ) {
			return false; // Deniz Hesap satırı orada düzenlenir
		}
		$not   = ( null === $not || '' === $not ) ? null : $not;
		$tutar = round( $tutar, 2 );

		$degisen = [];
		if ( abs( (float) $satir->tutar - $tutar ) > 0.004 ) {
			$degisen['tutar'] = [ (string) $satir->tutar, number_format( $tutar, 2, '.', '' ) ];
		}
		if ( $satir->durum !== $durum ) {
			$degisen['durum'] = [ (string) $satir->durum, $durum ];
		}
		if ( (string) $satir->not_metni !== (string) $not ) {
			$degisen['not'] = [ (string) $satir->not_metni, (string) $not ];
		}
		if ( ! $degisen ) {
			return false;
		}
		if ( $donem && 'yeniden_acildi' === $donem->durum ) {
			$gerekce = DHF_Donem::acilma_gerekcesi( (int) $donem->id );
			foreach ( $degisen as $alan => [ $eski, $yeni ] ) {
				DHF_Donem::log( (int) $donem->id, $id, $alan, $eski, $yeni, $gerekce );
			}
		}

		$wpdb->update( $i,
			[ 'tutar' => $tutar, 'durum' => $durum, 'not_metni' => $not ],
			[ 'id' => $id ], [ '%f', '%s', '%s' ], [ '%d' ]
		);

		// "Gerçekleşmedi" satırı sıfır etkiyle durur; silinmez (§5.1).
		$profil = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT etki_profili FROM ' . DHF_Schema::table( 'kalem' ) . ' WHERE id = %d',
			(int) $satir->kalem_id
		) );
		DHF_Etki::yaz( $id, $profil ?: 'tuketim', 'gerceklesmedi' === $durum ? 0.0 : $tutar );

		return true;
	}

	/**
	 * Satırı siler (mükerrer ya da yanlış girilmiş satır için). Kesinleşmiş ayda ve
	 * Deniz Hesap aynasında çalışmaz. Silme her zaman log'a yazılır; aktarımdan gelen
	 * satırın aktarım kaydı "atlandı" olur, aynı CSV tekrar yüklenince geri gelmez.
	 */
	public static function satir_sil( int $id ): true|WP_Error {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		$s = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.*, k.ad AS kalem_ad FROM {$i} s LEFT JOIN " . DHF_Schema::table( 'kalem' ) . ' k ON k.id = s.kalem_id WHERE s.id = %d', $id
		) );
		if ( ! $s ) {
			return new WP_Error( 'yok', __( 'Satır bulunamadı.', 'deniz-finans' ) );
		}
		$donem = DHF_Donem::bul( (int) $s->donem_id );
		if ( ! DHF_Donem::duzenlenebilir( $donem ) ) {
			return new WP_Error( 'kilitli', __( 'Bu ay kesinleşti; satır silmek için ayı gerekçeyle yeniden aç.', 'deniz-finans' ) );
		}
		if ( DHF_Deniz_Hesap::KAYNAK === $s->kaynak ) {
			return new WP_Error( 'ayna', __( 'Bu satır Deniz Hesap\'tan geliyor; orada düzelt.', 'deniz-finans' ) );
		}

		$ozet = implode( ' · ', array_filter( [
			(string) ( $s->kalem_ad ?: $s->aciklama ),
			mysql2date( 'd.m', (string) $s->tarih ),
			number_format( (float) $s->tutar, 2, '.', '' ),
			( $s->aciklama && $s->aciklama !== $s->kalem_ad ) ? (string) $s->aciklama : '',
		] ) );
		$gerekce = 'yeniden_acildi' === $donem->durum
			? DHF_Donem::acilma_gerekcesi( (int) $donem->id )
			: __( 'Hareketler ekranından silindi', 'deniz-finans' );

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( DHF_Schema::table( 'islem_etki' ), [ 'islem_id' => $id ], [ '%d' ] );
		$wpdb->update( DHF_Schema::table( 'aktarim_satir' ),
			[ 'onay_durumu' => 'atlandi', 'karar' => 'atla', 'gerekce' => 'satir_silindi', 'eslesen_islem_id' => null ],
			[ 'eslesen_islem_id' => $id ] );
		$silindi = $wpdb->delete( $i, [ 'id' => $id ], [ '%d' ] );
		if ( ! $silindi ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db', __( 'Satır silinemedi.', 'deniz-finans' ) );
		}
		DHF_Donem::log( (int) $donem->id, $id, 'satir_silindi', $ozet, null, $gerekce );
		$wpdb->query( 'COMMIT' );
		return true;
	}

	/**
	 * Bir kalemin açık aylardaki satırlarının etkilerini kalemin güncel profiliyle yeniden üretir.
	 * @return int yeniden hesaplanan satır sayısı
	 */
	public static function etkileri_yenile( int $kalem_id ): int {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		$d = DHF_Schema::table( 'donem' );
		$k = DHF_Kalem::bul( $kalem_id );
		if ( ! $k ) {
			return 0;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.tutar, s.durum FROM {$i} s INNER JOIN {$d} dn ON dn.id = s.donem_id
			 WHERE s.kalem_id = %d AND dn.durum <> 'kesinlesti'", $kalem_id
		) );
		foreach ( $rows as $r ) {
			DHF_Etki::yaz( (int) $r->id, (string) $k->etki_profili, 'gerceklesmedi' === $r->durum ? 0.0 : (float) $r->tutar );
		}
		return count( $rows );
	}

	/* ---------------------------------------------------------------- */
	/* Toplamlar — hepsi hesaplanır, hiçbiri saklanmaz                   */
	/* ---------------------------------------------------------------- */

	/** @return array<string,float> */
	public static function toplamlar( int $donem_id ): array {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		$e = DHF_Schema::table( 'islem_etki' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.etki_tipi, SUM(e.tutar) AS toplam
			 FROM {$e} e INNER JOIN {$i} s ON s.id = e.islem_id
			 WHERE s.donem_id = %d AND s.durum <> 'planlanan'
			 GROUP BY e.etki_tipi",
			$donem_id
		) );

		$t = array_fill_keys( DHF_Etki::TIPLER, 0.0 );
		foreach ( $rows as $r ) {
			$t[ $r->etki_tipi ] = (float) $r->toplam;
		}

		$t['nakit_ihtiyaci'] = $t['nakit_girisi'] - $t['nakit_cikisi'];
		return $t;
	}

	/** Mükerrer şüphesi: aynı dönemde aynı kalem + aynı tutar (§2.7). */
	public static function mukerrer_sayisi( int $donem_id ): int {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT kalem_id, tutar FROM {$i}
				WHERE donem_id = %d AND kalem_id IS NOT NULL AND tutar > 0 AND kaynak <> 'deniz_hesap'
				GROUP BY kalem_id, tutar HAVING COUNT(*) > 1
			) x",
			$donem_id
		) );
	}

	/** Tutarı bekleyen değişken kalem sayısı. */
	public static function bekleyen_sayisi( int $donem_id ): int {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		$k = DHF_Schema::table( 'kalem' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$i} s INNER JOIN {$k} kl ON kl.id = s.kalem_id
			 WHERE s.donem_id = %d AND kl.tekrar_tipi = 'degisken'
			   AND s.tutar = 0 AND s.durum = 'taslak'",
			$donem_id
		) );
	}
}
