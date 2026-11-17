<?php
/**
 * Dönem durumları ve değişiklik kaydı (plan §2.4).
 *
 *   acik ⇄ kontrol → kesinlesti → (gerekçeyle) yeniden_acildi → kesinlesti
 *
 * Kesinleşmiş dönemde satırlar düzenlenemez. Düzeltmek için dönem gerekçe yazılarak
 * yeniden açılır; yeniden açılmış dönemdeki her değişiklik eski/yeni değer ve o gerekçeyle
 * log tablosuna yazılır.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Donem {

	public const DURUMLAR = [
		'acik'           => 'Açık',
		'kontrol'        => 'Kontrolde',
		'kesinlesti'     => 'Kesinleşti',
		'yeniden_acildi' => 'Yeniden açıldı',
	];

	/** İzin verilen geçişler ve düğme etiketleri. */
	public const GECISLER = [
		'acik'           => [ 'kontrol' => 'Kontrole al' ],
		'kontrol'        => [ 'kesinlesti' => 'Ayı kesinleştir', 'acik' => 'Açığa döndür' ],
		'kesinlesti'     => [ 'yeniden_acildi' => 'Yeniden aç' ],
		'yeniden_acildi' => [ 'kesinlesti' => 'Tekrar kesinleştir' ],
	];

	public static function bul( int $id ): ?object {
		global $wpdb;
		$t = DHF_Schema::table( 'donem' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ) ?: null;
	}

	public static function duzenlenebilir( ?object $donem ): bool {
		return ! $donem || 'kesinlesti' !== $donem->durum;
	}

	/** Kesinleştirmeyi engelleyen satırlar: tutarı girilmemiş taslaklar. */
	public static function engeller( int $donem_id ): int {
		global $wpdb;
		$i = DHF_Schema::table( 'islem' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$i} WHERE donem_id = %d AND durum = 'taslak' AND tutar = 0", $donem_id
		) );
	}

	/**
	 * Dönem durumunu değiştirir.
	 *
	 * Kesinleştirirken: tutarı girilmiş taslak satırlar "gerçekleşti" olur (ayı kapatmak
	 * onları onaylamaktır). Tutarı boş taslak kaldıysa kesinleşmez — o satır ya doldurulur
	 * ya "bu ay gerçekleşmedi" işaretlenir.
	 */
	public static function gecis( int $donem_id, string $yeni, string $gerekce = '' ): true|WP_Error {
		global $wpdb;
		$d = self::bul( $donem_id );
		if ( ! $d ) {
			return new WP_Error( 'yok', 'Dönem bulunamadı.' );
		}
		if ( ! isset( self::GECISLER[ $d->durum ][ $yeni ] ) ) {
			return new WP_Error( 'gecis', sprintf( '"%s" durumundan "%s" durumuna geçilemez.',
				self::DURUMLAR[ $d->durum ] ?? $d->durum, self::DURUMLAR[ $yeni ] ?? $yeni ) );
		}
		$gerekce = trim( $gerekce );
		if ( 'yeniden_acildi' === $yeni && '' === $gerekce ) {
			return new WP_Error( 'gerekce', 'Kesinleşmiş ayı yeniden açmak için gerekçe yazılmalı.' );
		}

		$t = DHF_Schema::table( 'donem' );
		$i = DHF_Schema::table( 'islem' );

		if ( 'kesinlesti' === $yeni ) {
			$engel = self::engeller( $donem_id );
			if ( $engel ) {
				return new WP_Error( 'engel', sprintf(
					'%d satırın tutarı boş. Tutarını gir ya da durumunu "Bu ay gerçekleşmedi" yap; sonra kesinleştir.', $engel
				) );
			}
			$onaylanan = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$i} SET durum = 'gerceklesti' WHERE donem_id = %d AND durum = 'taslak'", $donem_id
			) );
			if ( $onaylanan ) {
				self::log( $donem_id, null, 'taslak_onay', '', (string) $onaylanan, 'Kesinleştirmede taslak satırlar gerçekleşti sayıldı' );
			}
			$wpdb->update( $t, [
				'durum'             => 'kesinlesti',
				'kesinlesme_tarihi' => current_time( 'mysql' ),
				'kesinlestiren'     => get_current_user_id() ?: null,
			], [ 'id' => $donem_id ] );
		} else {
			$wpdb->update( $t, [ 'durum' => $yeni ], [ 'id' => $donem_id ] );
		}

		self::log( $donem_id, null, 'durum', (string) $d->durum, $yeni, '' === $gerekce ? null : $gerekce );
		return true;
	}

	public static function log( ?int $donem_id, ?int $islem_id, string $alan, ?string $eski, ?string $yeni, ?string $gerekce ): void {
		global $wpdb;
		$wpdb->insert( DHF_Schema::table( 'log' ), [
			'donem_id'     => $donem_id,
			'islem_id'     => $islem_id,
			'kullanici_id' => get_current_user_id() ?: null,
			'tarih'        => current_time( 'mysql' ),
			'alan'         => mb_substr( $alan, 0, 60 ),
			'eski_deger'   => $eski,
			'yeni_deger'   => $yeni,
			'gerekce'      => $gerekce,
		] );
	}

	/** Dönemin son yeniden açılma gerekçesi — yeniden açık dönemdeki her değişikliğe eklenir. */
	public static function acilma_gerekcesi( int $donem_id ): ?string {
		global $wpdb;
		$t = DHF_Schema::table( 'log' );
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT gerekce FROM {$t} WHERE donem_id = %d AND alan = 'durum' AND yeni_deger = 'yeniden_acildi'
			 ORDER BY id DESC LIMIT 1", $donem_id
		) ) ?: null;
	}

	/** @return object[] */
	public static function kayitlar( int $donem_id, int $limit = 50 ): array {
		global $wpdb;
		$l = DHF_Schema::table( 'log' );
		$i = DHF_Schema::table( 'islem' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT l.*, s.aciklama FROM {$l} l LEFT JOIN {$i} s ON s.id = l.islem_id
			 WHERE l.donem_id = %d ORDER BY l.id DESC LIMIT %d", $donem_id, $limit
		) );
	}
}
