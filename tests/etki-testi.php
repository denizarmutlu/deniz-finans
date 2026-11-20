<?php
/**
 * Tiplenmiş etkiler kabul testi — WordPress'e ihtiyaç duymaz.
 *
 * Örnek bir ayın defter satırlarıyla çalışır (veriler uydurmadır).
 * Kabul ölçütü: kart ödemesi ve kredi taksiti tüketim toplamını ARTIRMAZ.
 *
 * Çalıştırma: php tests/etki-testi.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require_once __DIR__ . '/../includes/class-dhf-etki.php';

/** Örnek ay — bilerek bir mükerrer satır içerir. */
$ay = [
	// [ tarih, kategori, aciklama, tutar ]
	[ '2026-03-03', 'Ev', 'Elektrik', 800.0 ],
	[ '2026-03-05', 'Ev', 'Market', 12000.0 ],
	[ '2026-03-10', 'Çocuk', 'Okul taksiti', 5000.0 ],
	[ '2026-03-20', 'Araç', 'Yakıt', 3200.0 ],
	[ '2026-03-16', 'Kredi', 'Konut kredisi', 10000.0 ],
	// Aynı kredinin aynı ay içindeki iki meşru taksiti — tarihleri farklı.
	[ '2026-03-15', 'Kredi', 'İhtiyaç kredisi', 4000.0 ],
	[ '2026-03-23', 'Kredi', 'İhtiyaç kredisi', 4000.0 ],
	[ '2026-03-25', 'Kredi Kartı', 'A Bankası kart ödemesi', 20000.0 ],
	[ '2026-03-25', 'Kredi Kartı', 'A Bankası kart ödemesi', 20000.0 ], // mükerrer
	[ '2026-03-25', 'Kredi Kartı', 'A Bankası kart faizi', 1500.0 ],
	[ '2026-03-27', 'Kredi Kartı', 'B Bankası kart ödemesi', 6000.0 ],
	[ '2026-03-27', 'Kredi Kartı', 'B Bankası dönem faizi', 700.0 ],
];

/** Kategori + açıklamadan etki profili — kalem sözlüğündeki eşlemenin aynısı. */
function profil( string $kategori, string $aciklama ): string {
	return match ( $kategori ) {
		'Kredi'       => 'kredi_taksit',
		'Kredi Kartı' => str_contains( mb_strtolower( $aciklama ), 'faiz' ) ? 'kart_faiz' : 'kart_odeme',
		default       => 'tuketim',
	};
}

function topla( array $satirlar ): array {
	$t = array_fill_keys( DHF_Etki::TIPLER, 0.0 );
	foreach ( $satirlar as [ , $kat, $acik, $tutar ] ) {
		foreach ( DHF_Etki::uret( profil( $kat, $acik ), $tutar ) as $e ) {
			$t[ $e['etki_tipi'] ] += $e['tutar'];
		}
	}
	return $t;
}

function tl( float $v ): string {
	return number_format( $v, 2, ',', '.' );
}

$gecen = 0;
$kalan = 0;
function kontrol( string $ad, float $bulunan, float $beklenen, float $tolerans = 0.01 ): void {
	global $gecen, $kalan;
	$ok = abs( $bulunan - $beklenen ) <= $tolerans;
	$ok ? $gecen++ : $kalan++;
	printf( "  %s  %-46s %14s  (beklenen %s)\n",
		$ok ? 'GECTI' : 'KALDI', $ad, tl( $bulunan ), tl( $beklenen ) );
}

$cizgi = str_repeat( '=', 86 ) . "\n";

echo $cizgi . "ORNEK AY — ham satirlar (mukerrer dahil)\n" . $cizgi;

$t          = topla( $ay );
$duz_toplam = array_sum( array_map( static fn( array $r ): float => $r[3], $ay ) );

kontrol( 'Duz toplam (eski yontemin "gider"i)', $duz_toplam, 87200.0 );
kontrol( 'TUKETIM GIDERI (gercek harcama)', $t['tuketim_gideri'], 21000.0 );
kontrol( 'Faiz masrafi', $t['faiz_masraf'], 2200.0 );
kontrol( 'Borc azaltimi (kart odemeleri)', $t['borc_azaltimi'], 46000.0 );
kontrol( 'Nakit cikisi (hepsi)', $t['nakit_cikisi'], 87200.0 );

printf( "\n  Eski yontemin sisirdigi tutar: %s TL\n", tl( $duz_toplam - $t['tuketim_gideri'] ) );

echo "\n" . $cizgi . "AYNI AY — mukerrer satir cikarilmis hali\n" . $cizgi;

$temiz   = [];
$gorulen = [];
foreach ( $ay as $r ) {
	// Mukerrer anahtari TARIHI icerir. Icermezse ayni ay icindeki mesru
	// tekrarlar (15.03 ve 23.03'teki iki kredi taksiti) yanlislikla silinir.
	$anahtar = implode( '|', $r );
	if ( isset( $gorulen[ $anahtar ] ) ) {
		continue;
	}
	$gorulen[ $anahtar ] = true;
	$temiz[]             = $r;
}
$t2 = topla( $temiz );
kontrol( 'Cikarilan satir sayisi', (float) ( count( $ay ) - count( $temiz ) ), 1.0 );
kontrol( 'Mesru iki kredi taksiti korundu', $t2['nakit_cikisi'] - $t['nakit_cikisi'] + 20000.0, 0.0 );
kontrol( 'Tuketim (mukerrer tuketimde degildi)', $t2['tuketim_gideri'], 21000.0 );
kontrol( 'Nakit cikisi (20.000 azaldi)', $t2['nakit_cikisi'], 67200.0 );

echo "\n" . $cizgi . "UC RAPOR GORUNUMU\n" . $cizgi;
printf( "  Tuketim gorunumu : %14s   \"Gercekten ne harcadim?\"\n", tl( $t2['tuketim_gideri'] ) );
printf( "  Nakit gorunumu   : %14s   \"Hesabimdan ne cikti?\"\n", tl( $t2['nakit_cikisi'] ) );
printf( "  Maliyet gorunumu : %14s   \"Bu ay bana neye mal oldu?\"\n",
	tl( $t2['tuketim_gideri'] + $t2['faiz_masraf'] ) );

echo "\n" . $cizgi . "PROFIL DAVRANISLARI\n" . $cizgi;
foreach ( DHF_Etki::profiller() as $ad => $p ) {
	printf( "  %-14s %-34s %s\n", $ad, DHF_Etki::ozet( $ad ), $p['etiket'] );
}

echo "\n";
$kart = topla( [ [ '2026-03-30', 'Kredi Kartı', 'Kart ödemesi', 1000.0 ] ] );
kontrol( '1.000 TL kart odemesi -> tuketim', $kart['tuketim_gideri'], 0.0 );
kontrol( '1.000 TL kart odemesi -> borc azaltimi', $kart['borc_azaltimi'], 1000.0 );
$kredi = topla( [ [ '2026-03-06', 'Kredi', 'Konut kredisi', 10000.0 ] ] );
kontrol( '10.000 TL kredi taksiti -> tuketim', $kredi['tuketim_gideri'], 0.0 );


// 0.6.1 — karttan ödenen satır: nakit çıkışı yerine borç artışı.
echo "\n" . $cizgi . "KARTTAN ODENEN SATIR (0.6.1)\n" . $cizgi;
$topla_k = static function ( string $profil, float $tutar, bool $karta ): array {
	$t = array_fill_keys( DHF_Etki::TIPLER, 0.0 );
	foreach ( DHF_Etki::uret( $profil, $tutar, $karta ) as $e ) {
		$t[ $e['etki_tipi'] ] += $e['tutar'];
	}
	return $t;
};
$m = $topla_k( 'tuketim', 500.0, true );
kontrol( 'Kartla market -> tuketim', $m['tuketim_gideri'], 500.0 );
kontrol( 'Kartla market -> nakit cikisi YOK', $m['nakit_cikisi'], 0.0 );
kontrol( 'Kartla market -> kart borcu artisi', $m['borc_artisi'], 500.0 );
$b = $topla_k( 'yatirim', 2710.0, true );
kontrol( 'Kartla BES -> yatirim', $b['yatirim_alimi'], 2710.0 );
kontrol( 'Kartla BES -> nakit cikisi YOK', $b['nakit_cikisi'], 0.0 );
kontrol( 'Kartla BES -> kart borcu artisi', $b['borc_artisi'], 2710.0 );
$f = $topla_k( 'kart_faiz', 4386.0, true );
kontrol( 'Kart faizi karta -> faiz', $f['faiz_masraf'], 4386.0 );
kontrol( 'Kart faizi karta -> nakit cikisi YOK', $f['nakit_cikisi'], 0.0 );
$n = $topla_k( 'tuketim', 500.0, false );
kontrol( 'Nakit market -> nakit cikisi (degismedi)', $n['nakit_cikisi'], 500.0 );
// Uçtan uca: ay içinde kartla 3.000 harcama + kart ödemesi 3.000 -> nakit tek kez çıkar.
$ay_k = $topla_k( 'tuketim', 3000.0, true );
$od   = $topla_k( 'kart_odeme', 3000.0, false );
kontrol( 'Harcama + odeme -> nakit tek kez', $ay_k['nakit_cikisi'] + $od['nakit_cikisi'], 3000.0 );
kontrol( 'Harcama + odeme -> net kart borcu', $ay_k['borc_artisi'] - $od['borc_azaltimi'], 0.0 );
kontrol( 'Ozet metni karta', (float) ( 'tuketim gideri + borc artisi' === DHF_Etki::ozet( 'tuketim', true ) ), 1.0 );

echo "\n" . $cizgi;
printf( "SONUC: %d gecti, %d kaldi\n", $gecen, $kalan );
echo $cizgi;
exit( $kalan > 0 ? 1 : 0 );
