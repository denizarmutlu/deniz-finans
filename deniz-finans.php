<?php
/**
 * Plugin Name:       Deniz Finans
 * Plugin URI:        https://github.com/denizarmutlu/deniz-finans
 * Description:       Kişisel gelir-gider, borç, yatırım ve bilanço takibi. Yalnızca yönetici erişimi; ön yüzde hiçbir çıktı üretmez.
 * Version:           0.5.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Deniz Armutlu
 * License:           GPL-2.0-or-later
 * Text Domain:       deniz-finans
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DHF_VERSION', '0.5.1' );
define( 'DHF_DB_VERSION', 5 );
define( 'DHF_FILE', __FILE__ );
define( 'DHF_DIR', plugin_dir_path( __FILE__ ) );
define( 'DHF_URL', plugin_dir_url( __FILE__ ) );

/** Tüm ekranları koruyan tek yetki. Hiçbir yerde manage_options'a düşmez. */
define( 'DHF_CAP', 'dhf_goruntule' );

require_once DHF_DIR . 'includes/class-dhf-schema.php';
require_once DHF_DIR . 'includes/class-dhf-caps.php';
require_once DHF_DIR . 'includes/class-dhf-etki.php';
require_once DHF_DIR . 'includes/class-dhf-kalem.php';
require_once DHF_DIR . 'includes/class-dhf-takvim.php';
require_once DHF_DIR . 'includes/class-dhf-donem.php';
require_once DHF_DIR . 'includes/class-dhf-islem.php';
require_once DHF_DIR . 'includes/class-dhf-ayristirici.php';
require_once DHF_DIR . 'includes/class-dhf-aktarim.php';
require_once DHF_DIR . 'includes/class-dhf-deniz-hesap.php';
require_once DHF_DIR . 'includes/class-dhf-seed.php';
require_once DHF_DIR . 'includes/class-dhf-rapor.php';
require_once DHF_DIR . 'includes/class-dhf-pano.php';
require_once DHF_DIR . 'includes/screens/class-dhf-screen-hareketler.php';
require_once DHF_DIR . 'includes/screens/class-dhf-screen-kalemler.php';
require_once DHF_DIR . 'includes/screens/class-dhf-screen-aktarim.php';
require_once DHF_DIR . 'includes/screens/class-dhf-screen-hesaplar.php';
require_once DHF_DIR . 'includes/screens/class-dhf-screen-raporlar.php';
require_once DHF_DIR . 'includes/class-dhf-admin.php';

register_activation_hook( __FILE__, static function (): void {
	DHF_Schema::install();
	DHF_Caps::grant_to_current_admin();
	DHF_Seed::run_once();
	DHF_Seed::kalemler_once();
	DHF_Aktarim::eslesme_seed();
	update_option( 'dhf_eslesme_norm', 2, false );
} );

register_deactivation_hook( __FILE__, static function (): void {
	// Tablolar ve yetki bilinçli olarak korunur. Veri silme yalnızca uninstall.php ile,
	// o da açıkça onay isteyen bir ayar arkasında olacak (1.0).
} );

add_action( 'plugins_loaded', static function (): void {
	// Şema sürümü atlandıysa (dosya elle güncellendiyse) sessizce tamamla.
	if ( (int) get_option( 'dhf_db_version', 0 ) < DHF_DB_VERSION ) {
		DHF_Schema::install();
		// 0.1'den yükseltenlerde tanımlar var ama kalem sözlüğü yok.
		DHF_Seed::kalemler_once();
		// 0.3: kalem sözlüğündeki varyantlar eşleştirme tablosuna taşınır.
		DHF_Aktarim::eslesme_seed();
		DHF_Aktarim::eslesme_normalize_et();
	}
	new DHF_Admin();
} );
