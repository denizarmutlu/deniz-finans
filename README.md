# Deniz Finans

Kişisel gelir-gider, borç, yatırım ve bilanço takibi için WordPress eklentisi. Yalnızca yönetici panelinde çalışır; sitenin ön yüzüne hiçbir çıktı vermez.

Google Sheets'te tutulan aylık nakit akışı tablosunun yerini almak için yazıldı. Temel fikir: **"gider" tek bir rakam değildir.** Kredi kartı ödemesi, kredi taksiti, faiz ve gerçek harcama ayrı sayaçlarda tutulur; böylece aynı para iki kez gider sayılmaz.

## Durum — 0.4.0

| Bileşen | Sürüm | Durum |
|---|---|---|
| Eklenti iskeleti, şema yönetimi, 22 tablo | 0.1 | Çalışıyor |
| `dhf_goruntule` yetkisi + yetki yönetimi | 0.1 | Çalışıyor |
| Ayarlar ekranı: şema tanısı, yetki | 0.1 | Çalışıyor |
| Kalem sözlüğü + Kalemler ekranı (liste, filtre, satır içi düzenleme) | 0.2 | Çalışıyor |
| Aylık Hareketler ekranı (ay seç, taslak üret, düzenle, kaydet) | 0.2 | Çalışıyor |
| Tiplenmiş etkiler motoru | 0.2 | Çalışıyor + testli |
| Aktarım: CSV önizleme, kalem eşleştirme, inceleme kuyruğu, onaylı yazma | 0.3 | Çalışıyor + testli |
| Hesap tanımları (Ayarlar) ve kaleme hesap bağlama (Kalemler) | 0.3 | Çalışıyor |
| Dönem durumları (açık → kontrol → kesinleşti → yeniden açıldı), değişiklik kaydı | 0.4 | Çalışıyor + testli |
| "Geçen ay vardı, bu ay yok" uyarısı, elle satır ekleme, mükerrer sorusu | 0.4 | Çalışıyor |
| Raporlar, WordPress Pano widget'ı | 0.5 | Planlandı |
| Borçlar, yatırımlar, bilanço | 0.7–0.9 | Yer tutucu |

## Tiplenmiş etkiler — projenin çekirdeği

Bir işlemin tutarı, kalemin etki profiline göre birden fazla sayaca dağılır:

| Profil | Etkiler | Önemi |
|---|---|---|
| `tuketim` | tüketim gideri + nakit çıkışı | Gerçek harcama |
| `kredi_taksit` | nakit çıkışı | **Tüketim yaratmaz** |
| `kart_odeme` | nakit çıkışı + borç azaltımı | **Tüketim yaratmaz** — harcama zaten kendi kategorisinde girildi |
| `kart_faiz` | nakit çıkışı + faiz masrafı | Karşılıksız çıkan tek kalem |
| `yatirim` | yatırım alımı + nakit çıkışı | Gider değil |
| `gelir` | gelir + nakit girişi | |
| `borc_artisi` | tüketim gideri + borç artışı | Taksitli alışveriş; nakit çıkışı yaratmaz |
| `transfer` | transfer | Hesaplar arası |

Profil kalemde bir kez tanımlanır; işlem her kaydedildiğinde etkiler yeniden üretilir, elle girilmez.

Bunun sonucu olarak raporlar üç ayrı soruyu cevaplar:

| Görünüm | Neyi toplar | Soru |
|---|---|---|
| Tüketim | Tüketim gideri | "Gerçekten ne harcadım?" |
| Nakit | Bütün nakit çıkışları | "Hesabımdan ne çıktı?" |
| Maliyet | Tüketim + faiz | "Bu ay bana neye mal oldu?" |

## Aktarım

Google Sheets'teki sekme CSV olarak indirilir ve **Deniz Finans → Aktarım**'a yüklenir. Akış: önizleme → doğrulama → onay → yazma. Önizleme işlem defterine hiçbir şey yazmaz.

- Sütunlar konuma göre değil başlık adına göre bulunur.
- Türk tarih ve tutar biçimi okunur (`15.03.2026`, `30/05/2026`, `1.785,0`). Okunamayan tarih **düzeltilmez**, satır inceleme kuyruğuna gider.
- Dosyada birebir aynı satır ikinci kez geçiyorsa kuyruğa girer; varsayılan karar "atla"dır. Atlanan satır silinmez, gerekçesiyle kaydı kalır.
- Defterdeki serbest metin ("İgdaş doğalgaz hb") kalem sözlüğüne bir kez eşleştirilir ve hatırlanır. Eşleşmeyen satıra benzerliğe dayalı bir öneri gelir, onaylanmadan yazılmaz.
- Aynı dosya tekrar yüklenirse daha önce yazılan ya da atlanan satırlar "daha önce aktarıldı" olarak gelir.
- Yazma tek veritabanı işlemidir: bir satır yazılamazsa hiçbiri yazılmaz.

## Kabul testleri

```
php tests/etki-testi.php
php tests/aktarim-testi.php
php tests/donem-testi.php
```

WordPress gerektirmez, örnek bir ay üzerinde çalışır. Kart ödemesinin ve kredi taksitinin tüketime yazılmadığını, mükerrer satırın yakalandığını ve aynı ay içindeki iki meşru taksitin (farklı tarihli) yanlışlıkla mükerrer sayılmadığını doğrular.

## Kurulum

1. Depoyu indir ve klasörü `deniz-finans` adıyla zip'le.
2. WordPress → Eklentiler → Yeni Ekle → Eklenti Yükle.
3. Etkinleştir. Tablolar kurulur, yetki etkinleştiren yöneticiye verilir.
4. Sol menüde **Deniz Finans** görünür. Önce **Deniz Finans → Ayarlar**'da tabloların kurulduğunu doğrula.

### Kendi verinle kurmak

Depoda kişisel veri yoktur. İlk kurulumda kendi tanımlarının yüklenmesini istersen, zip'lemeden önce:

| Şablon | Kopyala | İçerik |
|---|---|---|
| `includes/data/kisisel.ornek.php` | `includes/data/kisisel.php` | Kurumlar, borçlar, BES sözleşmeleri, fiziksel varlıklar |
| `includes/data/kalemler.ornek.php` | `includes/data/kalemler.php` | Tekrarlayan kalem sözlüğü |

Bu iki dosya `.gitignore`'dadır, depoya gönderilmez. Yoksa eklenti genel kategoriler ve örnek kalemlerle kurulur.

## Güvenlik

- Tek yetki: `dhf_goruntule`. Role değil **kullanıcıya** verilir — sonradan eklenen bir editör finans ekranlarını görmez.
- Her ekranın ve her POST işleyicisinin ilk satırı `DHF_Caps::guard()`. Hiçbir yerde `manage_options`'a düşülmez, `__return_true` kullanılmaz.
- Ön yüzde hiçbir çıktı yok: shortcode, widget, public query var veya REST ucu kaydedilmez.
- Formlarda nonce (`wp_nonce_field` / `check_admin_referer`).
- Tüm sorgular `$wpdb->prepare`; tablo adları beyaz listeden gelir.

## Veri güvenliği

**Devre dışı bırakmak veri silmez.** `uninstall.php` bilinçli olarak yok — eklenti silinse bile tablolar yerinde kalır. Kurulumdan önce yedeğinin `*_dhf_*` tablolarını kapsadığını doğrula.

## Dosya yapısı

```
deniz-finans.php                     bootstrap, sabitler, aktivasyon
includes/class-dhf-schema.php        22 tablo, dbDelta, tanı
includes/class-dhf-caps.php          yetki: grant / revoke / guard
includes/class-dhf-seed.php          ilk kurulum verisi (genel kategoriler)
includes/class-dhf-admin.php         menü, yönlendirme, Ayarlar ekranı
includes/class-dhf-etki.php          tiplenmiş etkiler motoru
includes/class-dhf-kalem.php         kalem sözlüğü + dönem için beklenen kalemler
includes/class-dhf-islem.php         işlem defteri, taslak üretimi, toplamlar
includes/data/*.ornek.php            kişisel veri şablonları
includes/screens/                    Hareketler, Kalemler, Aktarım ekranları; Hesaplar bölümü
includes/class-dhf-ayristirici.php   CSV, tarih, tutar, eşleştirme anahtarı — WordPress'siz
includes/class-dhf-donem.php         dönem durumları ve değişiklik kaydı
includes/class-dhf-takvim.php        ödeme günü kuralı, ay gezinme — WordPress'siz
includes/class-dhf-aktarim.php       önizleme, kalem eşleştirme, inceleme kuyruğu, yazma
tests/etki-testi.php                 kabul testi, WordPress gerektirmez
tests/aktarim-testi.php              ayrıştırıcı kabul testi, WordPress gerektirmez
tests/donem-testi.php                takvim (31 Ocak → 28 Şubat → 31 Mart) ve dönem geçişleri
```

## Gereksinimler

WordPress 6.0+, PHP 8.0+.

## Lisans

GPL-2.0-or-later — bkz. [LICENSE](LICENSE).
