<?php
/**
 * Kartlar görünümü kabul testi (0.6.3) — WordPress'e ihtiyaç duymaz.
 *
 * Çalıştırma: php tests/kart-testi.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require_once __DIR__ . '/../includes/class-dhf-ayristirici.php';
require_once __DIR__ . '/../includes/class-dhf-kart.php';

$gecti = $kaldi = 0;
function kontrol( string $ad, bool $sonuc ): void {
	global $gecti, $kaldi;
	$sonuc ? ++$gecti : ++$kaldi;
	printf( "%s %s\n", $sonuc ? '✓' : '✗', $ad );
}

/* Ödeme alt kategorisi → kartın kurumu */
kontrol( '"YKB kart asgari ödemesi" → YapıKredi', 'yapikredi' === DHF_Kart::kurum_anahtari( 'YKB kart asgari ödemesi' ) );
kontrol( 'Kurum "YapıKredi" → yapikredi', 'yapikredi' === DHF_Kart::kurum_anahtari( 'YapıKredi' ) );
kontrol( '"Akbank kart ödemesi" → akbank', 'akbank' === DHF_Kart::kurum_anahtari( 'Akbank kart ödemesi' ) );
kontrol( '"Halkbank kart ödemesi" → halkbank', 'halkbank' === DHF_Kart::kurum_anahtari( 'Halkbank kart ödemesi' ) );
kontrol( '"İş Bankası kart ödemesi" → isbankasi', 'isbankasi' === DHF_Kart::kurum_anahtari( 'İş Bankası kart ödemesi' ) );
kontrol( '"QNB kart ödemesi" → qnb', 'qnb' === DHF_Kart::kurum_anahtari( 'QNB kart ödemesi' ) );

/* Ay ay toplama ve fark */
$a = DHF_Kart::aylik( [
	[ 'ay' => 3, 'tutar' => 1000.0, 'tur' => 'harcama' ],
	[ 'ay' => 3, 'tutar' => 500.5,  'tur' => 'harcama' ],
	[ 'ay' => 3, 'tutar' => 120.25, 'tur' => 'faiz' ],
	[ 'ay' => 3, 'tutar' => 2000.0, 'tur' => 'odeme' ],
	[ 'ay' => 4, 'tutar' => 300.0,  'tur' => 'harcama' ],
] );
kontrol( 'Mart harcama 1.500,50', 1500.5 === $a[3]['harcama'] );
kontrol( 'Mart faiz 120,25', 120.25 === $a[3]['faiz'] );
kontrol( 'Mart fark = harcama + faiz − ödeme (−379,25)', -379.25 === $a[3]['fark'] );
kontrol( 'Nisan fark +300 (borç büyüdü)', 300.0 === $a[4]['fark'] );
kontrol( '12 ay döner, boş ay sıfır', 12 === count( $a ) && 0.0 === $a[1]['fark'] );
kontrol( 'Bilinmeyen tür sayılmaz', 0.0 === DHF_Kart::aylik( [ [ 'ay' => 5, 'tutar' => 9.0, 'tur' => 'baska' ] ] )[5]['harcama'] );

printf( "\n%d / %d test geçti\n", $gecti, $gecti + $kaldi );
exit( $kaldi ? 1 : 0 );
