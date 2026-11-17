<?php
/**
 * Takvim yardımcıları — WordPress'e bağımlı değildir, testte doğrudan çalışır.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Takvim {

	public const AYLAR = [ 1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
		'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık' ];

	public static function ay_sonu( int $yil, int $ay ): int {
		return (int) gmdate( 't', gmmktime( 0, 0, 0, $ay, 1, $yil ) );
	}

	/**
	 * Kalemin ödeme günü o ayda hangi güne düşer (§2.1).
	 * Gün korunur; hedef ayda o gün yoksa ayın son günü. Orijinal gün ayrıca saklandığı
	 * için kayma birikmez: 31 Ocak → 28 Şubat → 31 Mart.
	 */
	public static function gun( int $yil, int $ay, int $orijinal ): int {
		$orijinal = max( 1, min( 31, $orijinal ) );
		return min( $orijinal, self::ay_sonu( $yil, $ay ) );
	}

	public static function tarih( int $yil, int $ay, int $orijinal ): string {
		return sprintf( '%04d-%02d-%02d', $yil, $ay, self::gun( $yil, $ay, $orijinal ) );
	}

	/** @return array{0:int,1:int} */
	public static function onceki( int $yil, int $ay ): array {
		return 1 === $ay ? [ $yil - 1, 12 ] : [ $yil, $ay - 1 ];
	}

	/** @return array{0:int,1:int} */
	public static function sonraki( int $yil, int $ay ): array {
		return 12 === $ay ? [ $yil + 1, 1 ] : [ $yil, $ay + 1 ];
	}

	public static function ay_adi( int $yil, int $ay ): string {
		return ( self::AYLAR[ $ay ] ?? '?' ) . ' ' . $yil;
	}
}
