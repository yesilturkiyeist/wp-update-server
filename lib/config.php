<?php
/**
 * Ayarları toplar: ortam değişkenleri → config.php → panel (data/settings.json).
 * Panelden girilen dolu değerler en son uygulanır; siteler birleştirilir.
 * Git'ten deploy edilen kurulumda (Coolify, Docker, hosting Git) yalnız ortam
 * değişkenleri yeterlidir; config.php hiç gerekmez.
 *
 *   YT_UPDATE_REPO                  owner/repo (varsayılan yesilturkiyeist/wp-paketler)
 *   YT_UPDATE_GITHUB_TOKEN          fine-grained token, Contents: Read-only
 *   YT_UPDATE_WEBHOOK_SECRET        Actions'taki UPDATE_WEBHOOK_SECRET ile aynı
 *   YT_UPDATE_BASE_URL              https://update.yesilturkiyeist.org
 *   YT_UPDATE_SITES                 JSON: {"<sha256>":{"name":"Yeşil Türkiye","packages":"*","channel":"stable"}}
 *   YT_UPDATE_ADMIN_USER            /admin kullanıcı adı (varsayılan admin)
 *   YT_UPDATE_ADMIN_PASSWORD_HASH   password_hash() çıktısı  (ya da)
 *   YT_UPDATE_ADMIN_PASSWORD        düz parola
 *   YT_UPDATE_DATA_DIR              yazılabilir klasör (varsayılan ./data)
 *   YT_UPDATE_CACHE_TTL             saniye (varsayılan 300)
 */

namespace YTUpdate;

function load_config( string $root ): array {
	$env = static function ( string $name, $default = '' ) {
		$v = getenv( $name );
		if ( false === $v || '' === $v ) {
			$v = $_SERVER[ $name ] ?? ( $_ENV[ $name ] ?? null );
		}
		return ( null === $v || false === $v || '' === $v ) ? $default : $v;
	};

	$sites = array();
	$json  = (string) $env( 'YT_UPDATE_SITES' );
	if ( '' !== $json ) {
		$decoded = json_decode( $json, true );
		if ( is_array( $decoded ) ) {
			$sites = $decoded;
		} else {
			error_log( '[yt-update] YT_UPDATE_SITES geçerli JSON değil' );
		}
	}

	$config = array(
		'repo'                => (string) $env( 'YT_UPDATE_REPO', 'yesilturkiyeist/wp-paketler' ),
		'github_token'        => (string) $env( 'YT_UPDATE_GITHUB_TOKEN' ),
		'webhook_secret'      => (string) $env( 'YT_UPDATE_WEBHOOK_SECRET' ),
		'base_url'            => (string) $env( 'YT_UPDATE_BASE_URL' ),
		'cache_ttl'           => (int) $env( 'YT_UPDATE_CACHE_TTL', 300 ),
		'admin_user'          => (string) $env( 'YT_UPDATE_ADMIN_USER', 'admin' ),
		'admin_password_hash' => (string) $env( 'YT_UPDATE_ADMIN_PASSWORD_HASH' ),
		'admin_password'      => (string) $env( 'YT_UPDATE_ADMIN_PASSWORD' ),
		'sites'               => $sites,
		'data_dir'            => (string) $env( 'YT_UPDATE_DATA_DIR', $root . '/data' ),
	);

	if ( is_file( $root . '/config.php' ) ) {
		$file = require $root . '/config.php';
		if ( is_array( $file ) ) {
			foreach ( $file as $k => $v ) {
				// config.php'deki boş değerler ortam değişkenini ezmesin.
				if ( '' === $v || array() === $v || null === $v ) {
					continue;
				}
				$config[ $k ] = $v;
			}
		}
	}

	// 1.0.x örneğindeki yanlış adres kopyalandıysa yok say: adres istekten bulunur.
	if ( false !== stripos( (string) $config['base_url'], 'update.ustageliyor.org' ) ) {
		$config['base_url'] = '';
	}

	// Dosya/ortamdan gelen siteler panelde salt okunur gösterilir.
	foreach ( $config['sites'] as $h => $site ) {
		if ( is_array( $site ) ) {
			$config['sites'][ $h ]['source'] = 'file';
		}
	}

	require_once __DIR__ . '/settings.php';
	$panel = Settings::load( $config['data_dir'] );
	foreach ( array( 'github_token', 'repo', 'webhook_secret', 'admin_user', 'admin_password_hash', 'secret' ) as $k ) {
		if ( isset( $panel[ $k ] ) && '' !== $panel[ $k ] ) {
			$config[ $k ] = $panel[ $k ];
		}
	}
	foreach ( (array) ( $panel['sites'] ?? array() ) as $h => $site ) {
		if ( is_array( $site ) ) {
			$site['source']        = 'panel';
			$config['sites'][ $h ] = $site;
		}
	}
	// Panelde parola belirlendiyse config.php'deki düz parola artık kullanılmaz.
	if ( ! empty( $panel['admin_password_hash'] ) ) {
		$config['admin_password'] = '';
	}

	return $config;
}
