# wp-update-server

`update.yesilturkiyeist.org`. Private `yesilturkiyeist/wp-paketler` reposundaki release'leri
WordPress sitelerine "güncelleme var" olarak sunar. GitHub token yalnız bu sunucuda durur;
siteler kendi anahtarlarıyla bağlanır (YT Güncelleyici eklentisi).

```
wp-paketler (push → Actions → <slug>-v<sürüm> release + <slug>.zip)
        │ token (Contents: Read-only)
        ▼
wp-update-server   /admin (panel) · /v1/check · /v1/download/<slug>/<sürüm>.zip · /v1/webhook
        ▲ X-YT-Site-Key
WordPress siteleri (YT Güncelleyici)
```

Tüm ayarlar **/admin** panelinden girilir; `config.php` gerekmez.

## Kurulum (Hostinger, zip)

1. hPanel'de `update.yesilturkiyeist.org` subdomain'ini açın (normal PHP sitesi, PHP 7.4+), SSL'i açın.
2. [Releases](../../releases/latest) sayfasından `wp-update-server.zip`'i indirin, Dosya Yöneticisi'nde
   subdomain'in kök klasörüne yükleyip açın.
3. Hemen `https://update.yesilturkiyeist.org/admin` adresini açın › **İlk kurulum**: kullanıcı adı + parola.
   (İlk kurulumu yapan yönetici olur; zip'i açar açmaz yapın.)
4. **GitHub** kartı: token'ı yapıştırın › *Kaydet ve test et* › yeşil **Bağlı** görünmeli.
   Token: GitHub › Settings › Developer settings › Fine-grained tokens › owner `yesilturkiyeist`,
   yalnız `wp-paketler`, Repository permissions › **Contents: Read-only**.
5. **Site ekle**: ad, paketler (hepsi ya da seçili), kanal › *Anahtar üret*. Anahtar **bir kez** gösterilir;
   kopyalayın, *Bu anahtarı test et* ile deneyin.
6. Sitede: `yt-guncelleyici.zip`'i bir kez kurun › **Ayarlar › YT Güncelleyici** › anahtarı yapıştırın
   › *Kaydet ve kontrol et* › yeşil **Bağlı**. Sunucu adresi varsayılan olarak bu sunucudur.
7. (İsteğe bağlı) **Anında yayın** › *Secret üret* › wp-paketler › Settings › Secrets › Actions'a
   `UPDATE_WEBHOOK_SECRET` adıyla ekleyin. Yoksa yeni sürüm en geç 5 dakikada görünür.

Sonraki sürümlerde aynı zip'i üstüne açmanız yeterli: ayarlar `data/settings.json`'da, zip'te yok, korunur.

Panelde: GitHub durumu, siteler (bağlı mı, son görülme, kurulu sürümler), site düzenle/sil
(silinen sitenin anahtarı hemen çalışmaz), paket listesi, parola değiştirme.
Sunucuda anahtarın kendisi değil yalnız sha256'sı durur. Anahtarı kaybederseniz siteyi silip yenisini ekleyin.

`data/` yazılabilir olmalı.

## Kontrol

- `https://update.yesilturkiyeist.org/` → `{"ok":true,…,"configured":true}`
- `https://update.yesilturkiyeist.org/data/settings.json` → 403
- `https://update.yesilturkiyeist.org/admin` → giriş sayfası

## Docker / Coolify (isteğe bağlı)

Build Pack: **Dockerfile**, kalıcı depolama `/var/www/html/data`. Ayarlar yine /admin'den girilebilir;
isterseniz ortam değişkeni de olur (panel değerleri bunları ezer):

| Değişken | Açıklama |
|---|---|
| `YT_UPDATE_GITHUB_TOKEN` | GitHub token |
| `YT_UPDATE_SITES` | JSON: `{"<sha256>":{"name":"…","packages":"*","channel":"stable"}}` (panelde salt okunur) |
| `YT_UPDATE_WEBHOOK_SECRET` | webhook secret |
| `YT_UPDATE_ADMIN_USER` / `YT_UPDATE_ADMIN_PASSWORD` | panel girişi (ilk kurulum yerine) |
| `YT_UPDATE_BASE_URL` | boşsa istekten bulunur |
| `YT_UPDATE_REPO` | varsayılan `yesilturkiyeist/wp-paketler` |
| `YT_UPDATE_CACHE_TTL` | release önbelleği, sn (300) |
| `YT_UPDATE_DATA_DIR` | varsayılan `./data` |

Eski `config.php` varsa çalışmaya devam eder: oradaki parola ile giriş yapılır, oradaki siteler
panelde salt okunur görünür. Panelden girilen değerler `config.php`'yi ezer.

## Uç noktalar

| | |
|---|---|
| `GET /v1/health` | durum |
| `POST /v1/check` | site anahtarıyla; izinli paketlerin son sürümleri |
| `GET /v1/download/<slug>/<sürüm>.zip` | site anahtarıyla; zip'i GitHub'dan bir kez alıp `data/zips`'te saklar |
| `POST /v1/webhook` | `X-Hub-Signature-256` imzalı; release önbelleğini boşaltır |
| `GET /admin` | yönetim paneli (`/status` buraya yönlenir) |

## Nginx

```
location ~ ^/(config\.php|config\.sample\.php|lib/|data/|bin/|\.) { deny all; }
location / { try_files $uri /index.php$is_args$args; }
location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass php; }
```

## Sorun giderme

| Belirti | Bakılacak yer |
|---|---|
| Sitede "Could not resolve host" | Sunucu adresi yanlış ya da subdomain/DNS henüz yok. Adres: `https://update.yesilturkiyeist.org` |
| Sitede "Site anahtarı geçersiz" | /admin › *Var olan bir anahtarı test et*; site silindiyse yeniden ekleyin |
| Sitede "HTTP 502" | /admin › GitHub kartı kırmızı mı: token süresi dolmuş/yanlış |
| Yeni sürüm görünmüyor | wp-paketler Actions işi yeşil mi; /admin › *Paketleri yeniden oku* |
| Kayıtlar | `data/access.log`, PHP hata günlüğü |
