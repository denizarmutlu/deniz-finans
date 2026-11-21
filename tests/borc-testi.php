<?php
/**
 * Borç modülü kabul testi (0.7) — WordPress'e ihtiyaç duymaz.
 *
 * Çalıştırma: php tests/borc-testi.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require_once __DIR__ . '/../includes/class-dhf-takvim.php';
require_once __DIR__ . '/../includes/class-dhf-borc.php';

$gecti = $kaldi = 0;
function kontrol( string $ad, bool $sonuc ): void {
	global $gecti, $kaldi;
	$sonuc ? ++$gecti : ++$kaldi;
	printf( "%s %s\n", $sonuc ? '✓' : '✗', $ad );
}

/* ---- Bakiye yürütme: açılış + harcama + faiz − ödeme ---- */
$aylik = [
	1 => [ 'harcama' => 500.0, 'faiz' => 100.0, 'odeme' => 200.0 ],
	2 => [ 'harcama' => 0.0, 'faiz' => 0.0, 'odeme' => 0.0 ],
	3 => [ 'harcama' => 1000.5, 'faiz' => 0.0, 'odeme' => 1500.0 ],
];
$s = DHF_Borc::yuru( $aylik, 1000.0 );
kontrol( 'Ocak kapanışı 1.400 (1000+500+100−200)', 1400.0 === $s[1]['kapanis'] );
kontrol( 'Şubat açılışı Ocak kapanışıdır', 1400.0 === $s[2]['acilis'] );
kontrol( 'Hareketsiz ay bakiyeyi taşır', 1400.0 === $s[2]['kapanis'] );
kontrol( 'Hareketsiz ay "hareket" bayrağı kapalı', false === $s[2]['hareket'] );
kontrol( 'Mart kapanışı 900,50 (1400+1000,50−1500)', 900.5 === $s[3]['kapanis'] );
kontrol( 'Ödeme borcu azaltır (Mart < Şubat)', $s[3]['kapanis'] < $s[2]['kapanis'] );
kontrol( '12 ay döner', 12 === count( $s ) );
kontrol( 'Veri olmayan ay sıfır hareketle taşır', 900.5 === $s[12]['kapanis'] );
kontrol( 'Açılış sıfırken ilk ay kapanışı = hareket', 600.0 === DHF_Borc::yuru( [ 1 => [ 'harcama' => 600.0, 'faiz' => 0.0, 'odeme' => 0.0 ] ], 0.0 )[1]['kapanis'] );

/* ---- Faiz türü: kart faizi ile kredili mevduat faizi ayrı sayılmalı ---- */
kontrol( '"YKB KMH faizi" → kmh', 'kmh' === DHF_Borc::faiz_turu( 'YKB KMH faizi' ) );
kontrol( '"Akbank KMH faizi" → kmh', 'kmh' === DHF_Borc::faiz_turu( 'Akbank KMH faizi' ) );
kontrol( '"QNB ek hesap faizi" → kmh', 'kmh' === DHF_Borc::faiz_turu( 'QNB ek hesap faizi' ) );
kontrol( '"QNB avans faizi" → avans', 'avans' === DHF_Borc::faiz_turu( 'QNB avans faizi' ) );
kontrol( '"Ziraat kart faizi" → kart', 'kart' === DHF_Borc::faiz_turu( 'Ziraat kart faizi' ) );
kontrol( '"Halkbank kart faizi" → kart', 'kart' === DHF_Borc::faiz_turu( 'Halkbank kart faizi' ) );
kontrol( 'Tanınmayan ad → diger', 'diger' === DHF_Borc::faiz_turu( 'Gecikme bedeli' ) );

/* ---- Kalan taksit: tahmin edilmez, hesaplanır ---- */
kontrol( 'Taksit 43/120 → 77 kalan', 77 === DHF_Borc::kalan_taksit( 43, 120, null, '2026-11-21' ) );
kontrol( 'Bitiş tarihinden (23.03.2027) → 5 taksit', 5 === DHF_Borc::kalan_taksit( null, null, '2027-03-23', '2026-11-21' ) );
kontrol( 'Bitiş tarihinden (30.01.2027) → 3 taksit', 3 === DHF_Borc::kalan_taksit( null, null, '2027-01-30', '2026-11-21' ) );
kontrol( 'Veri yoksa null (tahmin edilmez)', null === DHF_Borc::kalan_taksit( null, null, null, '2026-11-21' ) );
kontrol( 'Bitişi geçmiş kredi null', null === DHF_Borc::kalan_taksit( null, null, '2026-01-01', '2026-11-21' ) );
kontrol( 'Taksit numarası bitişten önce gelir', 77 === DHF_Borc::kalan_taksit( 43, 120, '2033-04-15', '2026-11-21' ) );

/* ---- Kalan taksit geçmiş aylarda ---- */
kontrol( 'Kasım\'da 77 kalan → Ocak\'ta 87', 87 === DHF_Borc::kalan_taksit_ayinda( 77, 120, 2026, 1, 2026, 11 ) );
kontrol( 'Şubat\'ta 86 (Sheets notu 34/120 ile tutuyor)', 86 === DHF_Borc::kalan_taksit_ayinda( 77, 120, 2026, 2, 2026, 11 ) );
kontrol( 'Bu ay değişmez', 77 === DHF_Borc::kalan_taksit_ayinda( 77, 120, 2026, 11, 2026, 11 ) );
kontrol( 'Gelecek ay bir azalır', 76 === DHF_Borc::kalan_taksit_ayinda( 77, 120, 2026, 12, 2026, 11 ) );
kontrol( 'Toplam taksidi aşmaz', 120 === DHF_Borc::kalan_taksit_ayinda( 77, 120, 2020, 1, 2026, 11 ) );
kontrol( 'Sıfırın altına düşmez', 0 === DHF_Borc::kalan_taksit_ayinda( 3, 22, 2027, 6, 2026, 11 ) );
kontrol( 'Toplam bilinmiyorsa sınır yok (5 + 89 ay)', 94 === DHF_Borc::kalan_taksit_ayinda( 5, null, 2019, 6, 2026, 11 ) );

/* ---- Limit kullanımı ---- */
kontrol( 'Kullanım %90,0', 90.0 === DHF_Borc::kullanim( 18000.0, 20000.0 ) );
kontrol( 'Limit yoksa null', null === DHF_Borc::kullanim( 18000.0, null ) );
kontrol( 'Bakiye yoksa null', null === DHF_Borc::kullanim( null, 20000.0 ) );
kontrol( 'Eksi bakiye mutlak değerle (KMH)', 40.6 === DHF_Borc::kullanim( -6090.0, 15000.0 ) );

/* ---- Kredi takvimi ---- */
$tak = DHF_Borc::kredi_takvimi( [
	[ 'ad' => 'Konut', 'aylik_taksit' => 14117.90, 'kalan' => 3 ],
	[ 'ad' => 'Tüketici', 'aylik_taksit' => 9855.0, 'kalan' => 1 ],
	[ 'ad' => 'Bitmiş', 'aylik_taksit' => 5000.0, 'kalan' => 0 ],
], 2026, 11, 12 );
kontrol( 'Kasım yükü 23.972,90 (iki kredi)', 23972.9 === $tak['aylar']['2026-11'] );
kontrol( 'Aralık yükü yalnız konut', 14117.9 === $tak['aylar']['2026-12'] );
kontrol( 'Ocak 2027 de konut', 14117.9 === $tak['aylar']['2027-01'] );
kontrol( 'Şubat 2027 yok (taksit bitti)', ! isset( $tak['aylar']['2027-02'] ) );
kontrol( 'Kalan toplam 52.208,70', 52208.7 === $tak['toplam'] );
kontrol( 'Bitmiş kredi takvime girmez', 3 === count( $tak['aylar'] ) );
$tak2 = DHF_Borc::kredi_takvimi( [ [ 'ad' => 'Uzun', 'aylik_taksit' => 1000.0, 'kalan' => 78 ] ], 2026, 12, 6 );
kontrol( 'Pencere 6 ayı aşmaz', 6 === count( $tak2['aylar'] ) );
kontrol( 'Pencere kalan toplamı kısmaz', 78000.0 === $tak2['toplam'] );
kontrol( 'Yıl sonu Aralık→Ocak döner', isset( $tak2['aylar']['2027-01'] ) );

/* ---- Bakiye kaynağı seçimi: eksi hesaplanan bakiye "veri yok"tur ---- */
kontrol( 'Banka rakamı varsa o kazanır', [ 18760.0, 'bildirilen' ] === DHF_Borc::bakiye_sec( 18760.0, 5000.0, 9999.0 ) );
kontrol( 'Banka yoksa defterden hesaplanan', [ 44314.99, 'hesaplanan' ] === DHF_Borc::bakiye_sec( null, 44314.99, null ) );
kontrol( 'Eksi hesaplanan bakiye → açılış eksik (toplama girmez)', [ null, 'acilis_eksik' ] === DHF_Borc::bakiye_sec( null, -719567.78, null ) );
kontrol( 'Sıfır hesaplanan bakiye geçerlidir', [ 0.0, 'hesaplanan' ] === DHF_Borc::bakiye_sec( null, 0.0, null ) );
kontrol( 'Eksi bildirilen rakam olduğu gibi kalır', [ -500.0, 'bildirilen' ] === DHF_Borc::bakiye_sec( -500.0, null, null ) );
kontrol( 'Hesaplanamayanda kalan taksit tahmini', [ 49275.0, 'tahmin' ] === DHF_Borc::bakiye_sec( null, null, 49275.0 ) );
kontrol( 'Hiçbiri yoksa veri yok', [ null, 'yok' ] === DHF_Borc::bakiye_sec( null, null, null ) );
kontrol( 'Eksi hesaplanan varken tahmine düşmez', 'acilis_eksik' === DHF_Borc::bakiye_sec( null, -100.0, 5000.0 )[1] );

/* ---- Bakiye tipi tanımları ---- */
kontrol( 'Altı bakiye tipi var (acilis dahil)', 6 === count( DHF_Borc::BAKIYE_TIPLERI ) );
kontrol( 'Kredide kapama bakiyesi var, ekstre borcu yok',
	in_array( 'kapama_bakiyesi', DHF_Borc::TIP_BAKIYE['kredi'], true ) && ! in_array( 'ekstre_borcu', DHF_Borc::TIP_BAKIYE['kredi'], true ) );
kontrol( 'Kredide kalan anapara en çok tercih edilen (0.7.4)',
	'kalan_anapara' === DHF_Borc::TIP_BAKIYE['kredi'][ count( DHF_Borc::TIP_BAKIYE['kredi'] ) - 1 ] );
kontrol( 'Kartta ekstre borcu en çok tercih edilen (0.7.3)',
	'ekstre_borcu' === DHF_Borc::TIP_BAKIYE['kredi_karti'][ count( DHF_Borc::TIP_BAKIYE['kredi_karti'] ) - 1 ] );
kontrol( 'Kartta ekstre borcu var, kalan anapara yok',
	in_array( 'ekstre_borcu', DHF_Borc::TIP_BAKIYE['kredi_karti'], true ) && ! in_array( 'kalan_anapara', DHF_Borc::TIP_BAKIYE['kredi_karti'], true ) );

printf( "\n%d / %d test geçti\n", $gecti, $gecti + $kaldi );
exit( $kaldi ? 1 : 0 );
