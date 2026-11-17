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
	 *
	 * @return array{eklendi:int,atlandi:int}
	 */
	public static function taslak_uret( int $yil, int $ay ): array {
		global $wpdb;

		$donem = self::donem( $yil, $ay );
		if ( ! $donem ) {
			return [ 'eklendi' => 0, 'atlandi' => 0 ];
		}

		$islem_t = DHF_Schema::table( 'islem' );
		$mevcut  = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT kalem_id FROM {$islem_t} WHERE donem_id = %d AND kalem_id IS NOT NULL",
			$donem->id
		) );
		$mevcut = array_map( 'intval', $mevcut );

		$eklendi = $atlandi = 0;
		$son_gun = (int) gmdate( 't', (int) mktime( 0, 0, 0, $ay, 1, $yil ) );

		foreach ( DHF_Kalem::donem_icin( $yil, $ay ) as $k ) {
			if ( in_array( (int) $k->id, $mevcut, true ) ) {
				++$atlandi;
				continue;
			}

			// Gün korunur; hedef ayda o gün yoksa ayın son gününe çekilir,
			// ama orijinal gün saklanır (§2.1): 31 Ocak -> 28 Şubat -> 31 Mart.
			$orijinal = (int) ( $k->varsayilan_gun ?: 1 );
			$gun      = min( $orijinal, $son_gun );

			// Sabit kalemde tutar dolu, değişkende boş gelir — "tutar bekliyor" (§2.2).
			$tutar = ( 'sabit' === $k->tekrar_tipi ) ? (float) $k->varsayilan_tutar : 0.0;

			$wpdb->insert( $islem_t, [
				'donem_id'     => $donem->id,
				'tarih'        => sprintf( '%04d-%02d-%02d', $yil, $ay, $gun ),
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
			++$eklendi;
		}

		return [ 'eklendi' => $eklendi, 'atlandi' => $atlandi ];
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
					kl.varsayilan_tutar, kt.ad AS kategori_ad
			 FROM {$i} s
			 LEFT JOIN {$k} kl ON kl.id = s.kalem_id
			 LEFT JOIN {$c} kt ON kt.id = s.kategori_id
			 WHERE s.donem_id = %d
			 ORDER BY (s.tutar = 0 AND kl.tekrar_tipi = 'degisken') DESC, s.tur, kt.sira, s.tarih",
			$donem_id
		) );
	}

	/** Geçen ayın aynı kalemdeki tutarı — karşılaştırma kolonu için. */
	public static function gecen_ay( int $yil, int $ay ): array {
		global $wpdb;
		$onceki = ( 1 === $ay ) ? [ $yil - 1, 12 ] : [ $yil, $ay - 1 ];
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

	/** Satır tutarını/durumunu günceller ve etkileri yeniden üretir. */
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

		$wpdb->update( $i,
			[ 'tutar' => $tutar, 'durum' => $durum, 'not_metni' => ( '' === $not ? null : $not ) ],
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
				WHERE donem_id = %d AND kalem_id IS NOT NULL AND tutar > 0
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
