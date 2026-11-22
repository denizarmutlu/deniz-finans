<?php
/**
 * XLSX dışa aktarım (plan §5.4, §5.9 — 0.6'nın kalan işi).
 *
 * Kütüphane yok: SpreadsheetML'in en küçük geçerli hali ZipArchive ile yazılır
 * (sunucuda ZipArchive var, §12). Dosya kalıcı olarak diske yazılmaz; geçici
 * dosya gönderildikten sonra silinir.
 *
 * Tutarlar metin değil SAYI olarak yazılır — Excel'de doğrudan toplanabilirler.
 * CSV'deki formül enjeksiyonu temizliği burada gerekmez: hücre tipi inlineStr
 * olduğu için eşittir ile başlayan metin formül sayılmaz.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DHF_Xlsx {

	/** Hücre biçimleri — styles.xml'deki cellXfs sırası. */
	private const S_METIN   = 0;
	private const S_KALIN   = 1;
	private const S_TUTAR   = 2;
	private const S_TUTAR_K = 3;
	private const S_UST     = 4;
	private const S_NOT     = 5;

	/** Başlık satırı — dondurma bundan sonra başlar. */
	private const BASLIK_SATIRI = 3;

	private const XML_BAS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
	private const NS      = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const NS_R    = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	public static function destekli(): bool {
		return class_exists( 'ZipArchive' );
	}

	/** Sayfaları tek çalışma kitabına yazar ve indirtir. Bu çağrıdan sonra dönülmez. */
	public static function gonder( string $dosya, array $sayfalar ): void {
		$yol = self::olustur( $sayfalar );
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $dosya . '"' );
		header( 'Content-Length: ' . (string) filesize( $yol ) );
		readfile( $yol );
		unlink( $yol );
		exit;
	}

	/** @return string Geçici dosyanın yolu — çağıran silmekle yükümlü. */
	public static function olustur( array $sayfalar ): string {
		$sayfalar = array_values( $sayfalar );
		if ( ! $sayfalar ) {
			$sayfalar = [ [ 'ad' => 'Bos', 'baslik' => '', 'kolonlar' => [], 'satirlar' => [], 'alt' => null ] ];
		}
		$yol = (string) tempnam( get_temp_dir(), 'dhf-xlsx-' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $yol, ZipArchive::OVERWRITE | ZipArchive::CREATE ) ) {
			unlink( $yol );
			wp_die( esc_html__( 'XLSX dosyası oluşturulamadı.', 'deniz-finans' ) );
		}
		$adlar = self::adlar( $sayfalar );
		$zip->addFromString( '[Content_Types].xml', self::content_types( count( $sayfalar ) ) );
		$zip->addFromString( '_rels/.rels', self::rels_kok() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook( $adlar ) );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::rels_workbook( count( $sayfalar ) ) );
		$zip->addFromString( 'xl/styles.xml', self::styles() );
		foreach ( $sayfalar as $i => $s ) {
			$zip->addFromString( sprintf( 'xl/worksheets/sheet%d.xml', $i + 1 ), self::sheet( $s ) );
		}
		$zip->close();
		return $yol;
	}

	/** Sayfa adları: Excel'in yasakladığı karakterler çıkar, 31 karaktere iner, tekilleşir. */
	private static function adlar( array $sayfalar ): array {
		$out = [];
		foreach ( $sayfalar as $i => $s ) {
			$ad = trim( str_replace( [ '[', ']', ':', '*', '?', '/', '\\' ], ' ', (string) ( $s['ad'] ?? '' ) ) );
			$ad = '' === $ad ? 'Sayfa ' . ( $i + 1 ) : mb_substr( $ad, 0, 31 );
			while ( in_array( $ad, $out, true ) ) {
				$ad = mb_substr( $ad, 0, 28 ) . '-' . ( $i + 1 );
			}
			$out[] = $ad;
		}
		return $out;
	}

	/**
	 * Bir sayfanın XML'i. Yerleşim: 1. satır başlık, 2. boş, 3. kolon adları,
	 * sonra satırlar, varsa toplam satırı ve en altta rapor notu.
	 */
	private static function sheet( array $s ): string {
		$kolonlar = array_values( (array) ( $s['kolonlar'] ?? [] ) );

		$satirlar   = [];
		$satirlar[] = [ [ (string) ( $s['baslik'] ?? '' ), self::S_UST ] ];
		$satirlar[] = [];
		$satirlar[] = array_map( static fn( $k ): array => [ (string) $k, self::S_KALIN ], $kolonlar );
		foreach ( (array) ( $s['satirlar'] ?? [] ) as $r ) {
			$satirlar[] = array_map( static fn( $v ): array => self::hucre( $v ), array_values( (array) $r ) );
		}
		if ( ! empty( $s['alt'] ) ) {
			$satirlar[] = array_map( static fn( $v ): array => self::hucre( $v, true ), array_values( (array) $s['alt'] ) );
		}
		if ( ! empty( $s['not'] ) ) {
			$satirlar[] = [];
			$satirlar[] = [ [ (string) $s['not'], self::S_NOT ] ];
		}

		$en  = 1;
		$xml = '';
		foreach ( $satirlar as $r => $hucreler ) {
			$no    = $r + 1;
			$en    = max( $en, count( $hucreler ) );
			$cells = '';
			foreach ( $hucreler as $c => [ $v, $st ] ) {
				if ( null === $v || '' === $v ) {
					continue;
				}
				$ref    = self::harf( $c ) . $no;
				$cells .= is_float( $v )
					? sprintf( '<c r="%s" s="%d"><v>%s</v></c>', $ref, $st, self::sayi( $v ) )
					: sprintf( '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $ref, $st, self::kacis( (string) $v ) );
			}
			$xml .= sprintf( '<row r="%d">%s</row>', $no, $cells );
		}

		return self::XML_BAS . '<worksheet xmlns="' . self::NS . '">'
			. sprintf( '<dimension ref="A1:%s%d"/>', self::harf( $en - 1 ), count( $satirlar ) )
			. sprintf( '<sheetViews><sheetView workbookViewId="0"><pane ySplit="%1$d" topLeftCell="A%2$d" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>', self::BASLIK_SATIRI, self::BASLIK_SATIRI + 1 )
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. sprintf( '<cols><col min="1" max="1" width="30" customWidth="1"/><col min="2" max="%d" width="15" customWidth="1"/></cols>', max( 2, $en ) )
			. '<sheetData>' . $xml . '</sheetData></worksheet>';
	}

	/**
	 * Hücre değerini tipine göre biçimiyle eşler — DHF_Rapor tablolarıyla aynı kural:
	 * float/int tutar, null "veri yok", string metin.
	 *
	 * @param mixed $v
	 * @return array{0:float|string|null,1:int}
	 */
	private static function hucre( $v, bool $kalin = false ): array {
		if ( is_float( $v ) || is_int( $v ) ) {
			return [ (float) $v, $kalin ? self::S_TUTAR_K : self::S_TUTAR ];
		}
		if ( null === $v ) {
			return [ null, self::S_METIN ];
		}
		return [ (string) $v, $kalin ? self::S_KALIN : self::S_METIN ];
	}

	/** 0 tabanlı kolon sırası → A, B, ... AA. */
	private static function harf( int $i ): string {
		$s = '';
		for ( $n = $i + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$s = chr( 65 + ( $n - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	/** Ondalık ayrıcı her zaman nokta — XML yerel biçim tanımaz. */
	private static function sayi( float $v ): string {
		$s = sprintf( '%.4F', $v );
		return str_contains( $s, '.' ) ? rtrim( rtrim( $s, '0' ), '.' ) : $s;
	}

	private static function kacis( string $s ): string {
		$s = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s );
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private static function content_types( int $n ): string {
		$x = self::XML_BAS . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		for ( $i = 1; $i <= $n; $i++ ) {
			$x .= sprintf( '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', $i );
		}
		return $x . '</Types>';
	}

	private static function rels_kok(): string {
		return self::XML_BAS . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="' . self::NS_R . '/officeDocument" Target="xl/workbook.xml"/></Relationships>';
	}

	private static function rels_workbook( int $n ): string {
		$x = self::XML_BAS . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		for ( $i = 1; $i <= $n; $i++ ) {
			$x .= sprintf( '<Relationship Id="rId%1$d" Type="%2$s/worksheet" Target="worksheets/sheet%1$d.xml"/>', $i, self::NS_R );
		}
		return $x . sprintf( '<Relationship Id="rId%d" Type="%s/styles" Target="styles.xml"/>', $n + 1, self::NS_R ) . '</Relationships>';
	}

	private static function workbook( array $adlar ): string {
		$x = self::XML_BAS . '<workbook xmlns="' . self::NS . '" xmlns:r="' . self::NS_R . '"><sheets>';
		foreach ( $adlar as $i => $ad ) {
			$x .= sprintf( '<sheet name="%s" sheetId="%d" r:id="rId%d"/>', self::kacis( (string) $ad ), $i + 1, $i + 1 );
		}
		return $x . '</sheets></workbook>';
	}

	/** Altı biçim: metin, kalın, tutar, kalın tutar, üst başlık, not. Eksi tutar kırmızı. */
	private static function styles(): string {
		return self::XML_BAS . '<styleSheet xmlns="' . self::NS . '">'
			. '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]\-#,##0.00"/></numFmts>'
			. '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="14"/><name val="Calibri"/></font>'
			. '<font><sz val="9"/><color rgb="FF646970"/><name val="Calibri"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="6">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			. '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
			. '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
	}
}
