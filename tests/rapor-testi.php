<?php
/**
 * Rapor yardımcıları kabul testi — WordPress'e ihtiyaç duymaz.
 *
 * Çalıştırma: php tests/rapor-testi.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require_once __DIR__ . '/../includes/class-dhf-takvim.php';
require_once __DIR__ . '/../includes/class-dhf-rapor.php';

$gecti = $kaldi = 0;
function kontrol( string $ad, bool $sonuc ): void {
	global $gecti, $kaldi;
	$sonuc ? ++$gecti : ++$kaldi;
	printf( "%s %s\n", $sonuc ? '✓' : '✗', $ad );
}

/* Örnek ay — uydurma rakamlar, etki tipleri gerçek */
$t = [
	'gelir' => 100000.0, 'tuketim_gideri' => 60000.0, 'nakit_girisi' => 100000.0,
	'nakit_cikisi' => 150000.0, 'borc_artisi' => 1000.0, 'borc_azaltimi' => 50000.0,
	'faiz_masraf' => 12000.0, 'yatirim_alimi' => 8000.0, 'devlet_katkisi' => 0.0, 'transfer' => 0.0,
];

/* §5.4 — üç görünüm üç ayrı soru */
kontrol( 'Tüketim görünümü = tüketim gideri', 60000.0 === DHF_Rapor::gorunum_tutar( $t, 'tuketim' ) );
kontrol( 'Maliyet görünümü = tüketim + faiz', 72000.0 === DHF_Rapor::gorunum_tutar( $t, 'maliyet' ) );
kontrol( 'Nakit görünümü = bütün nakit çıkışı', 150000.0 === DHF_Rapor::gorunum_tutar( $t, 'nakit' ) );
$t2 = array_merge( $t, [ 'borc_azaltimi' => 999999.0 ] );
kontrol( 'Kart borcu azaltımı tüketime ve maliyete girmiyor',
	DHF_Rapor::gorunum_tutar( $t, 'tuketim' ) === DHF_Rapor::gorunum_tutar( $t2, 'tuketim' )
	&& DHF_Rapor::gorunum_tutar( $t, 'maliyet' ) === DHF_Rapor::gorunum_tutar( $t2, 'maliyet' ) );
kontrol( 'Bilinmeyen görünüm tüketime düşer', 'tuketim' === DHF_Rapor::gorunum( 'gider' ) );

/* Mart 2026 kontrol rakamları (canlı defter, 18.11.2026) */
$mart = array_merge( $t, [ 'tuketim_gideri' => 185805.80, 'faiz_masraf' => 32057.0, 'nakit_cikisi' => 523632.80 ] );
kontrol( 'Mart maliyet = 185.805,80 + 32.057 = 217.862,80', 217862.80 === DHF_Rapor::gorunum_tutar( $mart, 'maliyet' ) );

/* §8 — CSV formül enjeksiyonu */
kontrol( '=HYPERLINK temizlenir', "'=HYPERLINK(\"x\")" === DHF_Rapor::csv_guvenli( '=HYPERLINK("x")' ) );
kontrol( '+, -, @ ile başlayan metin temizlenir',
	"'+1" === DHF_Rapor::csv_guvenli( '+1' ) && "'-cmd" === DHF_Rapor::csv_guvenli( '-cmd' ) && "'@SUM" === DHF_Rapor::csv_guvenli( '@SUM' ) );
kontrol( 'Normal metin dokunulmaz', 'Çekmeköy ev' === DHF_Rapor::csv_guvenli( 'Çekmeköy ev' ) );
kontrol( 'Boş metin dokunulmaz', '' === DHF_Rapor::csv_guvenli( '' ) );
kontrol( 'Tutar CSV\'de 1234,56 biçiminde (binlik ayırıcısız)', '1234,56' === DHF_Rapor::csv_tutar( 1234.56 ) );
kontrol( 'Eksi tutar sayı olarak kalır (metin değil)', '-387771,80' === DHF_Rapor::csv_tutar( -387771.8 ) );
kontrol( 'Veri yok → boş hücre', '' === DHF_Rapor::csv_tutar( null ) );

/* §5.3 — elle ödenecekler: vade penceresi */
$v = DHF_Rapor::vadeler( [ 1 => 23, 2 => 30, 3 => 5, 4 => 18 ], '2026-11-18', 10 );
kontrol( '23\'ü ve 18\'i pencerede, 30\'u (12 gün) dışarıda', [ 4 => '2026-11-18', 1 => '2026-11-23' ] === $v );
$v = DHF_Rapor::vadeler( [ 1 => 3, 2 => 31 ], '2026-11-25', 10 );
kontrol( 'Ay dönümü: 3\'ü Aralık\'a, 31\'i Kasım 30\'a düşer', [ 2 => '2026-11-30', 1 => '2026-12-03' ] === $v );
$v = DHF_Rapor::vadeler( [ 1 => 31 ], '2027-02-20', 10 );
kontrol( '31 → 28 Şubat (ay sonu kuralı)', [ 1 => '2027-02-28' ] === $v );
$v = DHF_Rapor::vadeler( [ 1 => 10 ], '2026-12-28', 15 );
kontrol( 'Yıl dönümü: Aralık 28 + 15 gün → 10 Ocak 2027', [ 1 => '2027-01-10' ] === $v );
kontrol( 'Geçmiş gün listeye girmez', [] === DHF_Rapor::vadeler( [ 1 => 5 ], '2026-11-18', 10 ) );

printf( "\n%d/%d geçti\n", $gecti, $gecti + $kaldi );
exit( $kaldi ? 1 : 0 );
