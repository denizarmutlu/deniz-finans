<?php
/**
 * Kişisel kurulum verisi şablonu — gerçek veri değildir.
 *
 * Kendi tanımlarını ilk kurulumda yüklemek için bu dosyayı kisisel.php adıyla
 * kopyala ve düzenle. kisisel.php .gitignore'dadır; depoya girmez.
 * Dosya yoksa eklenti yalnızca genel kategorilerle kurulur; tanımlar sonra
 * Finans → Ayarlar'dan girilir.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Seed_Kisisel {

	public static function run(): void {
		$kurum = [];
		foreach ( [ 'Örnek Banka' => 'banka', 'Örnek Emeklilik' => 'emeklilik' ] as $ad => $tip ) {
			$kurum[ $ad ] = DHF_Seed::ins( 'kurum', [ 'ad' => $ad, 'tip' => $tip, 'aktif' => 1 ] );
		}

		DHF_Seed::ins( 'borc', [
			'kurum_id'      => $kurum['Örnek Banka'],
			'tip'           => 'kredi',
			'ad'            => 'Örnek Konut Kredisi',
			'para_birimi'   => 'TRY',
			'aylik_taksit'  => 10000.00,
			'taksit_no'     => 1,
			'taksit_toplam' => 120,
			'aktif'         => 1,
		] );

		DHF_Seed::ins( 'borc', [
			'kurum_id'    => $kurum['Örnek Banka'],
			'tip'         => 'kredi_karti',
			'ad'          => 'Örnek Kredi Kartı',
			'para_birimi' => 'TRY',
			'limit_tutar' => 50000.00,
			'aktif'       => 1,
		] );

		$sozlesme = DHF_Seed::ins( 'bes_sozlesme', [
			'kurum_id'         => $kurum['Örnek Emeklilik'],
			'sozlesme_no'      => '0000000',
			'baslangic_tarihi' => '2024-01-01',
			'aktif'            => 1,
		] );
		DHF_Seed::ins( 'bes_katki', [
			'sozlesme_id'       => $sozlesme,
			'gecerlilik_tarihi' => '2024-01-01',
			'aylik_katki'       => 2000.00,
		] );
	}
}
