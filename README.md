# wp-update-server

`update.ustageliyor.org`. Private `yesilturkiyeist/wp-paketler` reposundaki release'leri
WordPress sitelerine "güncelleme var" olarak sunar. GitHub token yalnız bu sunucuda durur;
siteler kendi anahtarlarıyla bağlanır (YT Güncelleyici eklentisi).

```
wp-paketler (push → Actions → <slug>-v<sürüm> release + <slug>.zip)
        │ token (Contents: Read-only)
        ▼
wp-update-server   /v1/check · /v1/download/<slug>/<sürüm>.zip · /status · /v1/webhook
        ▲ X-YT-Site-Key
WordPress siteleri (YT Güncelleyici)
```

## Yayına alma

### A) Coolify / Docker (önerilen)

1. Coolify › New Resource › bu repo (GitHub App ile) › Build Pack: **Dockerfile**. Domain: `https://update.ustageliyor.org`.
2. Ortam değişkenlerini girin (aşağıdaki tablo). En az `YT_UPDATE_GITHUB_TOKEN`, `YT_UPDATE_SITES`, `YT_UPDATE_BASE_URL`.
3. Kalıcı depolama: `/var/www/html/data` (zip önbelleği, site durumu, log).
4. Deploy. Sonraki push'larda otomatik deploy açılabilir.

`docker-compose.yml` aynı ayarların compose karşılığıdır.

### B) Paylaşımlı PHP hosting (Hostinger): zip ile

1. hPanel'de `update.ustageliyor.org` subdomain'ini açın (normal PHP sitesi), SSL'i açın.
2. [Releases](../../releases/latest) sayfasından `wp-update-server.zip`'i indirin, Dosya Yöneticisi'nde
   subdomain'in kök klasörüne yükleyip açın.
3. `config.sample.php`'yi `config.php` olarak kopyalayın; `github_token` ve `admin_password`'ü doldurun.
4. `https://update.ustageliyor.org/status` › **Site anahtarı üret**: her site için çıkan satırı
   `config.php` › `sites` içine yapıştırın, anahtarı sitenin `wp-config.php`'sine yazın.
5. Sonraki sürümlerde aynı zip'i üstüne açmanız yeterli: zip'te `config.php` ve `data/` içeriği yok, korunur.

`data/` yazılabilir olmalı. Nginx için aşağıdaki örneğe bakın.

## Ortam değişkenleri

| Değişken | Açıklama |
|---|---|
| `YT_UPDATE_BASE_URL` | `https://update.ustageliyor.org` (indirme linkleri bununla üretilir) |
| `YT_UPDATE_GITHUB_TOKEN` | Fine-grained token: owner `yesilturkiyeist`, yalnız `wp-paketler`, **Contents: Read-only** |
| `YT_UPDATE_SITES` | Siteler, JSON: `{"<sha256>":{"name":"Yeşil Türkiye","packages":"*","channel":"stable"}}` |
| `YT_UPDATE_WEBHOOK_SECRET` | wp-paketler › Actions secret `UPDATE_WEBHOOK_SECRET` ile aynı (yeni sürüm anında görünür; yoksa en geç 5 dk) |
| `YT_UPDATE_ADMIN_USER` | `/status` kullanıcı adı (varsayılan `admin`) |
| `YT_UPDATE_ADMIN_PASSWORD` | `/status` parolası (en az 10 karakter) ya da `YT_UPDATE_ADMIN_PASSWORD_HASH` |
| `YT_UPDATE_REPO` | Varsayılan `yesilturkiyeist/wp-paketler` |
| `YT_UPDATE_CACHE_TTL` | Release listesi önbelleği, saniye (varsayılan 300) |
| `YT_UPDATE_DATA_DIR` | Varsayılan `./data` |

## Site eklemek

En kolayı `/status` › **Site anahtarı üret**. SSH varsa:

```
php bin/keygen.php "Hayır Kapısı"            # Docker: docker exec -it <container> php bin/keygen.php "Hayır Kapısı"
```

Çıktının 1. satırı sitenin `wp-config.php`'sine (`define( 'YT_UPDATE_KEY', 'ytu_…' );`),
2. satırı `YT_UPDATE_SITES` JSON'una gider. Sunucuda anahtarın kendisi değil yalnız sha256'sı durur.
Yerelde PHP yoksa: en az 20 karakterlik rastgele bir anahtar, hash'i `printf %s ANAHTAR | shasum -a 256`.

- Yalnız bazı paketler: `"packages":["hayirkapisi","yt-bagis","yt-form","yt-guncelleyici"]`
- Beta sürümler (`1.3.0-beta.1`): `"channel":"beta"`
- Siteyi kapatmak: JSON'dan öğesini silin.

Sitede: `yt-guncelleyici.zip`'i bir kez elle kurun, anahtarı `wp-config.php`'ye yazın,
**Ayarlar › YT Güncelleyici › Şimdi kontrol et**.

## Kontrol

- `https://update.ustageliyor.org/` → `{"ok":true,…,"configured":true}`
- `https://update.ustageliyor.org/config.php` → 403
- `https://update.ustageliyor.org/status` → paketler ve hangi site hangi sürümde

## Uç noktalar

| | |
|---|---|
| `GET /v1/health` | durum |
| `POST /v1/check` | site anahtarıyla; izinli paketlerin son sürümleri. Gövdede kurulu sürümler gelir, `/status`'ta görünür |
| `GET /v1/download/<slug>/<sürüm>.zip` | site anahtarıyla; zip'i GitHub'dan bir kez alıp `data/zips`'te saklar |
| `POST /v1/webhook` | `X-Hub-Signature-256` imzalı; release önbelleğini boşaltır |
| `GET /status` | basic auth; `?flush=1` GitHub'dan yeniden okur |

## Nginx

```
location ~ ^/(config\.php|config\.sample\.php|lib/|data/|bin/|\.) { deny all; }
location / { try_files $uri /index.php$is_args$args; }
location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass php; }
```

## Sorun giderme

| Belirti | Bakılacak yer |
|---|---|
| Sitede "Site anahtarı geçersiz" | `YT_UPDATE_SITES`'taki hash ile sitedeki `YT_UPDATE_KEY` eşleşiyor mu |
| Sitede "HTTP 502" | token geçersiz/süresi dolmuş ya da `YT_UPDATE_REPO` yanlış |
| Yeni sürüm görünmüyor | wp-paketler Actions işi yeşil mi, release'de `<slug>.zip` var mı; `/status?flush=1` |
| Kayıtlar | `data/access.log` (indirmeler, hatalı anahtarlar), PHP hata günlüğü |
