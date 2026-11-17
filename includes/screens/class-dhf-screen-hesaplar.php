<?php
/**
 * Hesap tanımları — Ayarlar ekranının bir bölümü.
 *
 * Bir hesap bir kuruma bağlıdır (vadesiz hesap, kredi kartı, KMH, nakit).
 * Kalemler bir hesaba bağlanınca aktarılan ve kopyalanan satırlar hesabı kendiliğinden taşır.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Screen_Hesaplar {

	public const TIPLER = [
		'banka'       => 'Vadesiz hesap',
		'kredi_karti' => 'Kredi kartı',
		'kmh'         => 'KMH',
		'nakit'       => 'Nakit',
	];

	/** @return object[] */
	public static function hepsi( bool $sadece_aktif = false ): array {
		global $wpdb;
		$h   = DHF_Schema::table( 'hesap' );
		$k   = DHF_Schema::table( 'kurum' );
		$sql = "SELECT h.*, k.ad AS kurum_ad FROM {$h} h LEFT JOIN {$k} k ON k.id = h.kurum_id";
		if ( $sadece_aktif ) {
			$sql .= ' WHERE h.aktif = 1';
		}
		return $wpdb->get_results( $sql . ' ORDER BY k.ad, h.tip, h.ad' );
	}

	public static function render_bolum(): void {
		global $wpdb;
		$hesaplar = self::hepsi();
		$kurumlar = $wpdb->get_results( 'SELECT id, ad FROM ' . DHF_Schema::table( 'kurum' ) . " WHERE tip = 'banka' AND aktif = 1 ORDER BY ad" );

		echo '<h2 id="hesaplar">' . esc_html__( 'Hesaplar', 'deniz-finans' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Alt kategoriler hesaba bağlanınca hesap bazlı raporlar 2026 geçmişi için de çalışır. Hesabı Alt kategoriler ekranında bağla.',
			'deniz-finans'
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dhf_hesap' );
		echo '<input type="hidden" name="action" value="dhf_hesap">';

		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		foreach ( [ 'Kurum', 'Hesap adı', 'Tip', 'Aktif' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $hesaplar ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Henüz hesap yok.', 'deniz-finans' ) . '</td></tr>';
		}
		foreach ( $hesaplar as $h ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td><input type="checkbox" name="aktif[%d]" value="1"%s><input type="hidden" name="var[%d]" value="1"></td></tr>',
				esc_html( (string) $h->kurum_ad ), esc_html( (string) $h->ad ),
				esc_html( self::TIPLER[ $h->tip ] ?? $h->tip ),
				(int) $h->id, checked( (int) $h->aktif, 1, false ), (int) $h->id );
		}

		// Yeni hesap satırı
		echo '<tr><td><select name="yeni[kurum_id]"><option value="">' . esc_html__( '+ yeni hesap', 'deniz-finans' ) . '</option>';
		foreach ( $kurumlar as $k ) {
			printf( '<option value="%d">%s</option>', (int) $k->id, esc_html( $k->ad ) );
		}
		echo '</select></td>';
		echo '<td><input type="text" name="yeni[ad]" placeholder="' . esc_attr__( 'ör. Maaş hesabı', 'deniz-finans' ) . '"></td>';
		echo '<td><select name="yeni[tip]">';
		foreach ( self::TIPLER as $k => $e ) {
			printf( '<option value="%s">%s</option>', esc_attr( $k ), esc_html( $e ) );
		}
		echo '</select></td><td></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Hesapları kaydet', 'deniz-finans' ), 'secondary' );
		echo '</form>';
	}

	public static function handle(): void {
		DHF_Caps::guard();
		check_admin_referer( 'dhf_hesap' );
		global $wpdb;
		$t = DHF_Schema::table( 'hesap' );

		$var   = isset( $_POST['var'] ) && is_array( $_POST['var'] ) ? array_map( 'absint', array_keys( wp_unslash( $_POST['var'] ) ) ) : [];
		$aktif = isset( $_POST['aktif'] ) && is_array( $_POST['aktif'] ) ? array_map( 'absint', array_keys( wp_unslash( $_POST['aktif'] ) ) ) : [];
		foreach ( $var as $id ) {
			$wpdb->update( $t, [ 'aktif' => in_array( $id, $aktif, true ) ? 1 : 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
		}

		$yeni = isset( $_POST['yeni'] ) && is_array( $_POST['yeni'] ) ? wp_unslash( $_POST['yeni'] ) : [];
		$kurum_id = absint( $yeni['kurum_id'] ?? 0 );
		$ad       = sanitize_text_field( (string) ( $yeni['ad'] ?? '' ) );
		$tip      = sanitize_key( (string) ( $yeni['tip'] ?? '' ) );
		if ( $kurum_id && '' !== $ad && isset( self::TIPLER[ $tip ] ) ) {
			$wpdb->insert( $t, [ 'kurum_id' => $kurum_id, 'ad' => mb_substr( $ad, 0, 120 ), 'tip' => $tip, 'aktif' => 1 ] );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'dhf-ayarlar' ], admin_url( 'admin.php' ) ) . '#hesaplar' );
		exit;
	}
}
