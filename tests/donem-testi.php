<?php
/**
 * Takvim ve dönem durumu kabul testi — WordPress'e ihtiyaç duymaz.
 *
 * Çalıştırma: php tests/donem-testi.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require_once __DIR__ . '/../includes/class-dhf-takvim.php';
require_once __DIR__ . '/../includes/class-dhf-donem.php';

$gecti = $kaldi = 0;
function kontrol( string $ad, bool $sonuc ): void {
	global $gecti, $kaldi;
	$sonuc ? ++$gecti : ++$kaldi;
	printf( "%s %s\n", $sonuc ? '✓' : '✗', $ad );
}

/* §10 senaryo 3: 31 Ocak → 28 Şubat → 31 Mart (kayma birikmez) */
kontrol( '31 Ocak', '2026-01-31' === DHF_Takvim::tarih( 2026, 1, 31 ) );
kontrol( '31 → 28 Şubat', '2026-02-28' === DHF_Takvim::tarih( 2026, 2, 31 ) );
kontrol( '31 → 31 Mart (28\'e takılmaz)', '2026-03-31' === DHF_Takvim::tarih( 2026, 3, 31 ) );
kontrol( '31 → 30 Nisan', '2026-04-30' === DHF_Takvim::tarih( 2026, 4, 31 ) );
kontrol( 'Artık yıl: 31 → 29 Şubat 2028', '2028-02-29' === DHF_Takvim::tarih( 2028, 2, 31 ) );
kontrol( '30 → 28 Şubat, 30 Mart', '2026-02-28' === DHF_Takvim::tarih( 2026, 2, 30 ) && '2026-03-30' === DHF_Takvim::tarih( 2026, 3, 30 ) );
kontrol( 'Gün 15 her ay 15', '2026-02-15' === DHF_Takvim::tarih( 2026, 2, 15 ) );
kontrol( 'Geçersiz gün 0 → 1', '2026-02-01' === DHF_Takvim::tarih( 2026, 2, 0 ) );

/* Ay gezinme */
kontrol( 'Ocak\'tan önce Aralık (önceki yıl)', [ 2025, 12 ] === DHF_Takvim::onceki( 2026, 1 ) );
kontrol( 'Aralık\'tan sonra Ocak (sonraki yıl)', [ 2027, 1 ] === DHF_Takvim::sonraki( 2026, 12 ) );
kontrol( 'Ay adı', 'Mart 2026' === DHF_Takvim::ay_adi( 2026, 3 ) );

/* §2.4 dönem durumları — izin verilen geçişler */
$g = DHF_Donem::GECISLER;
kontrol( 'Açık → kontrol', isset( $g['acik']['kontrol'] ) );
kontrol( 'Açıktan doğrudan kesinleşmez (önce kontrol)', ! isset( $g['acik']['kesinlesti'] ) );
kontrol( 'Kontrol → kesinleşti ve geri açık', isset( $g['kontrol']['kesinlesti'], $g['kontrol']['acik'] ) );
kontrol( 'Kesinleşmiş ay sessizce açılamaz (yalnızca yeniden_acildi)', [ 'yeniden_acildi' ] === array_keys( $g['kesinlesti'] ) );
kontrol( 'Yeniden açılan ay yeniden kesinleşir', isset( $g['yeniden_acildi']['kesinlesti'] ) );
kontrol( 'Her durumun etiketi var', [] === array_diff( array_keys( $g ), array_keys( DHF_Donem::DURUMLAR ) ) );

printf( "\n%d / %d test geçti\n", $gecti, $gecti + $kaldi );
exit( $kaldi ? 1 : 0 );
