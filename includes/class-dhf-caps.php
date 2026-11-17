<?php
/**
 * Yetki.
 *
 * Tek yetki: dhf_goruntule. Yalnızca açıkça atanan kullanıcı(lar) görür.
 * Editör / yazar / abone rolleri hiçbir ekranı görmez ve rol bazlı atama YAPILMAZ —
 * yetki role değil kullanıcıya verilir, böylece ileride bir editör eklenince
 * finans ekranları kendiliğinden açılmaz.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Caps {

	/** Eklentiyi etkinleştiren yöneticiye yetkiyi verir. */
	public static function grant_to_current_admin(): void {
		$user = wp_get_current_user();
		if ( $user && $user->ID && user_can( $user, 'manage_options' ) ) {
			self::grant( (int) $user->ID );
		}
	}

	public static function grant( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		$user->add_cap( DHF_CAP );
		return true;
	}

	public static function revoke( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		$user->remove_cap( DHF_CAP );
		return true;
	}

	/** Yetkisi olan kullanıcılar — Ayarlar ekranındaki tanı kutusu için. */
	public static function holders(): array {
		$out = [];
		foreach ( get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ] ] ) as $u ) {
			if ( user_can( (int) $u->ID, DHF_CAP ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/**
	 * Her ekranın ve her AJAX/REST ucunun ilk satırı.
	 * manage_options'a asla düşmez; __return_true asla kullanılmaz.
	 */
	public static function guard(): void {
		if ( ! current_user_can( DHF_CAP ) ) {
			wp_die(
				esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'deniz-finans' ),
				esc_html__( 'Yetkisiz erişim', 'deniz-finans' ),
				[ 'response' => 403 ]
			);
		}
	}

	public static function can(): bool {
		return current_user_can( DHF_CAP );
	}
}
