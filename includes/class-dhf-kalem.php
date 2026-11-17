<?php
/**
 * Kalem sözlüğü.
 *
 * Aylık taslak satır kopyalayarak değil, bu sözlükten üretilir: geçen ay
 * yanlışlıkla girilmiş bir satır yeni aya taşınmaz.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Kalem {

	public const TEKRAR = [
		'sabit'        => 'Sabit tutarlı',
		'degisken'     => 'Tutarı değişken',
		'tek_seferlik' => 'Tek seferlik',
	];

	public const ODEME = [
		'otomatik_talimat' => 'Otomatik talimat',
		'karta_otomatik'   => 'Karta otomatik',
		'elle'             => 'Elle ödeme',
	];

	/** @return object[] */
	public static function hepsi( bool $sadece_aktif = false ): array {
		global $wpdb;
		$t   = DHF_Schema::table( 'kalem' );
		$k   = DHF_Schema::table( 'kategori' );
		$sql = "SELECT kl.*, kt.ad AS kategori_ad, kt.tur AS kategori_tur
				FROM {$t} kl LEFT JOIN {$k} kt ON kt.id = kl.kategori_id";
		if ( $sadece_aktif ) {
			$sql .= ' WHERE kl.aktif = 1';
		}
		$sql .= ' ORDER BY kl.tur, kt.sira, kl.ad';
		return $wpdb->get_results( $sql );
	}

	public static function bul( int $id ): ?object {
		global $wpdb;
		$t = DHF_Schema::table( 'kalem' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ) ?: null;
	}

	/** @param array<string,mixed> $veri */
	public static function guncelle( int $id, array $veri ): bool {
		global $wpdb;

		$izinli = [
			'ad' => '%s', 'tekrar_tipi' => '%s', 'varsayilan_gun' => '%d',
			'varsayilan_tutar' => '%f', 'odeme_sekli' => '%s', 'etki_profili' => '%s',
			'kalan_taksit' => '%d', 'hesap_id' => '%d', 'bitis_tarihi' => '%s', 'not_metni' => '%s', 'aktif' => '%d',
		];

		$set = $fmt = [];
		foreach ( $veri as $alan => $deger ) {
			if ( ! isset( $izinli[ $alan ] ) ) {
				continue;
			}
			$set[ $alan ] = ( '' === $deger ) ? null : $deger;
			$fmt[]        = $izinli[ $alan ];
		}
		if ( ! $set ) {
			return false;
		}
		return false !== $wpdb->update( DHF_Schema::table( 'kalem' ), $set, [ 'id' => $id ], $fmt, [ '%d' ] );
	}

	/**
	 * Hareketler ekranından yeni kalem (§5.1 "+ Yeni kalem"). Etki profili verilmezse türden çıkar:
	 * gelir → gelir, yatırım → yatirim, gider → tuketim. Kart ödemesi ya da kredi taksiti gibi
	 * tüketim olmayan bir kalemse profili açıkça seçilmeli — yoksa tüketime yazılır.
	 */
	public static function ekle( string $ad, int $kategori_id, string $tekrar, string $profil = '' ): int|WP_Error {
		global $wpdb;
		$ad = trim( $ad );
		if ( '' === $ad ) {
			return new WP_Error( 'ad', 'Yeni alt kategorinin adı boş.' );
		}
		$kat = $wpdb->get_row( $wpdb->prepare( 'SELECT id, tur FROM ' . DHF_Schema::table( 'kategori' ) . ' WHERE id = %d', $kategori_id ) );
		if ( ! $kat ) {
			return new WP_Error( 'kategori', 'Yeni alt kategori için kategori seçilmeli.' );
		}
		if ( ! isset( self::TEKRAR[ $tekrar ] ) ) {
			$tekrar = 'tek_seferlik';
		}
		if ( ! DHF_Etki::var_mi( $profil ) ) {
			$profil = [ 'gelir' => 'gelir', 'yatirim' => 'yatirim' ][ $kat->tur ] ?? 'tuketim';
		}

		$t   = DHF_Schema::table( 'kalem' );
		$var = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE ad = %s AND tur = %s", $ad, $kat->tur ) );
		if ( $var ) {
			return (int) $var;
		}
		$wpdb->insert( $t, [
			'ad'           => mb_substr( $ad, 0, 190 ),
			'tur'          => $kat->tur,
			'kategori_id'  => (int) $kat->id,
			'tekrar_tipi'  => $tekrar,
			'odeme_sekli'  => 'elle',
			'etki_profili' => $profil,
			'aktif'        => 1,
		] );
		$id = (int) $wpdb->insert_id;
		if ( $id && class_exists( 'DHF_Aktarim' ) ) {
			DHF_Aktarim::eslesme_ekle( $ad, (string) $kat->tur, $id );
		}
		return $id ?: new WP_Error( 'db', 'Alt kategori eklenemedi.' );
	}

	/**
	 * Bir dönem için beklenen kalemleri döndürür.
	 * Pasif kalemler, bitiş tarihi geçmiş kalemler ve tek seferlikler dışarıda kalır.
	 *
	 * @return object[]
	 */
	public static function donem_icin( int $yil, int $ay ): array {
		$ayin_ilki = sprintf( '%04d-%02d-01', $yil, $ay );
		$out       = [];
		foreach ( self::hepsi( true ) as $k ) {
			if ( 'tek_seferlik' === $k->tekrar_tipi ) {
				continue;
			}
			if ( $k->bitis_tarihi && $k->bitis_tarihi < $ayin_ilki ) {
				continue;
			}
			if ( null !== $k->kalan_taksit && (int) $k->kalan_taksit <= 0 ) {
				continue;
			}
			$out[] = $k;
		}
		return $out;
	}

	/** İlk kurulumda kalem sözlüğünü yükler. Kategori ve kurum adlarını id'ye çevirir. */
	public static function seed(): int {
		global $wpdb;

		// Kişisel sözlük (kalemler.php, depoda yok) varsa o, yoksa örnek sözlük.
		$dosya = DHF_DIR . 'includes/data/kalemler.php';
		if ( ! is_readable( $dosya ) ) {
			$dosya = DHF_DIR . 'includes/data/kalemler.ornek.php';
		}
		if ( ! is_readable( $dosya ) ) {
			return 0;
		}
		$kalemler = require $dosya;
		if ( ! is_array( $kalemler ) ) {
			return 0;
		}

		$kategori_t = DHF_Schema::table( 'kategori' );
		$kurum_t    = DHF_Schema::table( 'kurum' );
		$hesap_t    = DHF_Schema::table( 'hesap' );
		$kalem_t    = DHF_Schema::table( 'kalem' );

		$sayac = 0;
		foreach ( $kalemler as $k ) {
			$kategori_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$kategori_t} WHERE ad = %s AND tur = %s",
				$k['kategori'], $k['tur']
			) );

			$hesap_id = null;
			if ( ! empty( $k['kurum'] ) ) {
				$hesap_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT h.id FROM {$hesap_t} h INNER JOIN {$kurum_t} ku ON ku.id = h.kurum_id
					 WHERE ku.ad = %s LIMIT 1",
					$k['kurum']
				) );
			}

			$ok = $wpdb->insert( $kalem_t, [
				'ad'               => $k['ad'],
				'tur'              => $k['tur'],
				'kategori_id'      => $kategori_id ? (int) $kategori_id : null,
				'hesap_id'         => $hesap_id ? (int) $hesap_id : null,
				'tekrar_tipi'      => $k['tekrar_tipi'],
				'varsayilan_gun'   => $k['varsayilan_gun'],
				'varsayilan_tutar' => $k['varsayilan_tutar'],
				'odeme_sekli'      => $k['odeme_sekli'],
				'etki_profili'     => $k['etki_profili'],
				'bitis_tarihi'     => $k['bitis_tarihi'],
				'not_metni'        => $k['not_metni'],
				'aktif'            => $k['aktif'],
			] );
			$sayac += $ok ? 1 : 0;
		}
		return $sayac;
	}
}
