<?php
/**
 * Aktarım ayrıştırıcısı kabul testi — WordPress'e ihtiyaç duymaz.
 * Veriler uydurmadır; kaynak tablodaki gerçek hata türlerini taklit eder.
 *
 * Çalıştırma: php tests/aktarim-testi.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require_once __DIR__ . '/../includes/class-dhf-ayristirici.php';

$gecti = $kaldi = 0;
function kontrol( string $ad, bool $sonuc ): void {
	global $gecti, $kaldi;
	$sonuc ? ++$gecti : ++$kaldi;
	printf( "%s %s\n", $sonuc ? '✓' : '✗', $ad );
}

/* Tarih — otomatik düzeltme YOK */
kontrol( 'GG.AA.YYYY okunur', '2026-03-15' === DHF_Ayristirici::tarih( '15.03.2026' ) );
kontrol( 'GG/AA/YYYY okunur', '2026-05-30' === DHF_Ayristirici::tarih( '30/05/2026' ) );
kontrol( '01.06.0926 reddedilir, düzeltilmez', null === DHF_Ayristirici::tarih( '01.06.0926' ) );
kontrol( '18.05.1927 reddedilir', null === DHF_Ayristirici::tarih( '18.05.1927' ) );
kontrol( '31.02.2026 reddedilir', null === DHF_Ayristirici::tarih( '31.02.2026' ) );

/* Tutar — Türk biçimi */
kontrol( '"1.785,0" = 1785', 1785.0 === DHF_Ayristirici::tutar( '1.785,0' ) );
kontrol( '"86.566,9" = 86566.9', 86566.9 === DHF_Ayristirici::tutar( '86.566,9' ) );
kontrol( '"600,8" = 600.8', 600.8 === DHF_Ayristirici::tutar( '600,8' ) );
kontrol( '"15.000" = 15000', 15000.0 === DHF_Ayristirici::tutar( '15.000' ) );
kontrol( '"abc" reddedilir', null === DHF_Ayristirici::tutar( 'abc' ) );

/* Normalize — Türkçe büyük/küçük harf ve boşluk */
kontrol( 'İ/I Türkçe küçülür', DHF_Ayristirici::normalize( 'İGDAŞ  Doğalgaz HB ' ) === DHF_Ayristirici::normalize( 'igdaş doğalgaz hb' ) );
kontrol( 'Sondaki boşluk eşleşmeyi bozmaz', DHF_Ayristirici::normalize( 'İhtiyaç Kredisi ' ) === DHF_Ayristirici::normalize( 'ihtiyaç kredisi' ) );
kontrol( 'Karışık dil: "Internet" = "internet"', DHF_Ayristirici::normalize( 'Internet' ) === DHF_Ayristirici::normalize( 'internet' ) );
kontrol( 'Karışık dil: "HDI FIBA" = "hdi fiba"', DHF_Ayristirici::normalize( 'HDI FIBA' ) === DHF_Ayristirici::normalize( 'hdi fiba' ) );

/* Öneri benzerliği — banka adı ortak kelimelerden ağır basar */
kontrol( 'Halkbank kart ödemesi, Akbank\'tan önce gelir',
	DHF_Ayristirici::benzerlik( 'Halkbank Kredi Kartı Ödeme', 'halkbank kart ödemesi' )
	> DHF_Ayristirici::benzerlik( 'Halkbank Kredi Kartı Ödeme', 'akbank kredi kartı ödeme' ) );
kontrol( 'İki harfli kurum adı ("İş") sayılır',
	DHF_Ayristirici::benzerlik( 'İş Bankası Kredi Kartı ödeme', 'iş bankası kart ödemesi' )
	> DHF_Ayristirici::benzerlik( 'İş Bankası Kredi Kartı ödeme', 'ziraat bankası kredi kartı ödeme' ) );
kontrol( 'Kelime sırası farklı olsa da kişi adı eşleşir',
	DHF_Ayristirici::benzerlik( 'Ahmet Usta a Motor Bakım için', 'motor bakımı (ahmet usta)' ) >= 60 );

/* CSV — Sheets dışa aktarımı: virgül ayraç, virgüllü tutar tırnaklı */
$csv = "\xEF\xBB\xBFTarih,Tür,Kategori,Açıklama,Tutar\n"
	. "01.03.0926,Yatırım,BES,Örnek BES,\"1.000,0\"\n"
	. "05.03.2026,Gider,Ev,Elektrik,\"450,0\"\n"
	. "10.03.2026,Gider,Kredi Kartı,A Bankası kart ödemesi,\"20.000,0\"\n"
	. "10.03.2026,Gider,Kredi Kartı,A Bankası kart ödemesi,\"20.000,0\"\n"
	. "15.03.2026,Gider,Kredi,İhtiyaç kredisi,\"4.000,0\"\n"
	. "23.03.2026,Gider,Kredi,İhtiyaç kredisi,\"4.000,0\"\n"
	. ",,,,\n"
	. "30/03/2026,Gelir,Kira,Kira,\"10.000,0\"\n";

$v = DHF_Ayristirici::csv( $csv );
kontrol( 'BOM temizlenir, başlıklar okunur', 'Tarih' === $v['basliklar'][0] );
kontrol( 'Boş satır atlanır (7 veri satırı)', 7 === count( $v['satirlar'] ) );
kontrol( 'Kaynak satır numarası korunur (son satır = 9)', 9 === array_key_last( $v['satirlar'] ) );

$h = DHF_Ayristirici::sutun_haritasi( '2026', $v['basliklar'] );
kontrol( 'Sütunlar başlık adından bulunur', is_array( $h ) && 4 === $h['tutar'] );
kontrol( 'Eksik sütun adıyla bildirilir', 'tutar' === DHF_Ayristirici::sutun_haritasi( '2026', [ 'Tarih', 'Tür', 'Kategori', 'Açıklama' ] ) );

$satirlar = [];
$gorulen  = [];
foreach ( $v['satirlar'] as $no => $hucre ) {
	$s = DHF_Ayristirici::satir( $hucre, $h );
	$k = DHF_Ayristirici::icerik_anahtari( $s['ham'] );
	$gorulen[ $k ] = ( $gorulen[ $k ] ?? 0 ) + 1;
	$s['tekrar']   = $gorulen[ $k ];
	$s['anahtar']  = DHF_Ayristirici::satir_anahtari( '2026', $s['ham'], $s['tekrar'] );
	$satirlar[ $no ] = $s;
}

kontrol( 'Hatalı tarihli satır kuyruğa (hatali_tarih)', in_array( 'hatali_tarih', $satirlar[2]['hatalar'], true ) );
kontrol( 'Hatalı tarihli satırın tutarı yine okunur', 1000.0 === $satirlar[2]['tutar'] );
kontrol( 'Tür "Yatırım" -> yatirim', 'yatirim' === $satirlar[2]['tur'] );
kontrol( 'Birebir aynı satırın 2. görülüşü işaretlenir', 1 === $satirlar[4]['tekrar'] && 2 === $satirlar[5]['tekrar'] );
kontrol( 'Mükerrer kopyanın satır anahtarı farklı (iki kez aktarılmasın diye)', $satirlar[4]['anahtar'] !== $satirlar[5]['anahtar'] );
kontrol( 'Aynı ay iki meşru taksit mükerrer sayılmaz (tarih farklı)', 1 === $satirlar[6]['tekrar'] && 1 === $satirlar[7]['tekrar'] );
kontrol( 'Eğik çizgili tarih okunur', '2026-03-30' === $satirlar[9]['tarih'] );

/* Aynı dosyanın ikinci yüklemesi aynı anahtarları üretir -> "daha önce aktarıldı" */
$v2 = DHF_Ayristirici::csv( $csv );
$s2 = DHF_Ayristirici::satir( $v2['satirlar'][3], $h );
kontrol( 'Tekrar yüklemede satır anahtarı aynı', DHF_Ayristirici::satir_anahtari( '2026', $s2['ham'], 1 ) === $satirlar[3]['anahtar'] );

/* Tekil anahtar: açıklamadaki boşluk/harf farkı aynı kaydı verir, tarih farkı vermez */
kontrol( 'Tekil anahtar normalize edilir',
	DHF_Ayristirici::tekil_anahtar( '2026-03-15', 'gider', 'Kredi', 'İhtiyaç kredisi ', 4000 )
	=== DHF_Ayristirici::tekil_anahtar( '2026-03-15', 'gider', 'kredi', 'ihtiyaç  kredisi', 4000.0 ) );
kontrol( 'Tekil anahtar tarihi içerir',
	DHF_Ayristirici::tekil_anahtar( '2026-03-15', 'gider', 'Kredi', 'İhtiyaç kredisi', 4000 )
	!== DHF_Ayristirici::tekil_anahtar( '2026-03-23', 'gider', 'Kredi', 'İhtiyaç kredisi', 4000 ) );

// 0.6.1 — kart ekstresi profili
$kh = DHF_Ayristirici::sutun_haritasi( 'kart', [ 'Tarih', 'Tür', 'Alt kategori', 'Açıklama', 'Tutar', 'Hesap', 'Not' ] );
kontrol( 'Kart profili sütunları bulunur', is_array( $kh ) && 2 === $kh['alt_kategori'] && 5 === $kh['hesap'] && 6 === $kh['not'] );
kontrol( 'Kart profilinde Hesap sütunu zorunlu', 'hesap' === DHF_Ayristirici::sutun_haritasi( 'kart', [ 'Tarih', 'Tür', 'Alt kategori', 'Açıklama', 'Tutar', 'Not' ] ) );
$ks = DHF_Ayristirici::satir( [ '12.05.2026', 'Gider', 'Market', 'MIGROS ISTANBUL', '1.234,56', 'YKB kredi kartı', 'YKB ekstre 5' ], $kh );
kontrol( 'Kart satırı okunur', '2026-05-12' === $ks['tarih'] && 'gider' === $ks['tur'] && 1234.56 === $ks['tutar'] && ! $ks['hatalar'] );
kontrol( '2026 profili değişmedi', is_array( DHF_Ayristirici::sutun_haritasi( '2026', [ 'Tarih', 'Tür', 'Kategori', 'Açıklama', 'Tutar' ] ) ) );

printf( "\n%d / %d test geçti\n", $gecti, $gecti + $kaldi );
exit( $kaldi ? 1 : 0 );
