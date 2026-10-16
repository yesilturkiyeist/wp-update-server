<?php
/**
 * Ayarları toplar: önce ortam değişkenleri, varsa config.php onları ezer.
 * Git'ten deploy edilen kurulumda (Coolify, Docker, hosting Git) yalnız ortam
 * değişkenleri yeterlidir; config.php hiç gerekmez.
 *
 *   YT_UPDATE_REPO                  owner/repo (varsayılan yesilturkiyeist/wp-paketler)
 *   YT_UPDATE_GITHUB_TOKEN          fine-grained token, Contents: Read-only
 *   YT_UPDATE_WEBHOOK_SECRET        Actions'taki UPDATE_WEBHOOK_SECRET ile aynı
 *   YT_UPDATE_BASE_URL              https://update.ustageliyor.org
 *   YT_UPDATE_SITES                 JSON: {"<sha256>":{"name":"Yeşil Türkiye","packages":"*","channel":"stable"}}
 *   YT_UPDATE_ADMIN_USER            /status kullanıcı adı (varsayılan admin)
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

	return $config;
}
