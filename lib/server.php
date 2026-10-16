<?php
/**
 * YT Update Server: private GitHub release'lerini WordPress sitelerine sunar.
 *
 * GitHub token yalnız bu sunucuda durur. Siteler kendi site anahtarlarıyla
 * sürüm bilgisini sorar ve zip'i buradan indirir.
 */

namespace YTUpdate;

const VERSION = '1.1.0';
const DAY_SECONDS = 86400;

class HttpError extends \Exception {}

final class Server {

	/** @var array */
	private $config;

	/** @var string */
	private $data_dir;

	public function __construct( array $config ) {
		$this->config = $config + array(
			'repo'                => '',
			'github_token'        => '',
			'webhook_secret'      => '',
			'cache_ttl'           => 300,
			'admin_user'          => '',
			'admin_password_hash' => '',
			'admin_password'      => '',
			'sites'               => array(),
			'data_dir'            => __DIR__ . '/../data',
			'github_api'          => 'https://api.github.com',
		);
		$this->data_dir = rtrim( $this->config['data_dir'], '/' );
	}

	/* ------------------------------------------------------------------ */
	/* Yönlendirme                                                         */
	/* ------------------------------------------------------------------ */

	public function handle( string $method, string $path ): void {
		$path = '/' . trim( $path, '/' );
		try {
			if ( '/' === $path || '/v1/health' === $path ) {
				$this->json(
					array(
						'ok'         => true,
						'service'    => 'yt-update-server',
						'version'    => VERSION,
						'configured' => '' !== (string) $this->config['github_token'] && ! empty( $this->config['sites'] ),
					)
				);
				return;
			}
			if ( '/v1/check' === $path ) {
				$this->route_check( $method );
				return;
			}
			if ( preg_match( '#^/v1/download/([a-z0-9][a-z0-9-]*)/([0-9][0-9A-Za-z.+-]*)\.zip$#', $path, $m ) ) {
				$this->route_download( $m[1], $m[2] );
				return;
			}
			if ( '/v1/webhook' === $path && 'POST' === $method ) {
				$this->route_webhook();
				return;
			}
			if ( '/admin' === $path || '/status' === $path ) {
				if ( '/status' === $path ) {
					header( 'Location: ' . $this->base_url() . '/admin', true, 302 );
					return;
				}
				( new Panel( $this, $this->config ) )->handle( $method );
				return;
			}
			throw new HttpError( 'Not found', 404 );
		} catch ( HttpError $e ) {
			$this->json( array( 'ok' => false, 'error' => $e->getMessage() ), $e->getCode() ?: 500 );
		} catch ( \Throwable $e ) {
			error_log( '[yt-update] ' . $e->getMessage() );
			$this->json( array( 'ok' => false, 'error' => 'Server error' ), 500 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* /v1/check                                                           */
	/* ------------------------------------------------------------------ */

	private function route_check( string $method ): void {
		$site = $this->auth_site();
		$body = array();
		if ( 'POST' === $method ) {
			$raw  = (string) file_get_contents( 'php://input', false, null, 0, 262144 );
			$body = json_decode( $raw, true );
			if ( ! is_array( $body ) ) {
				$body = array();
			}
		}

		$catalog = $this->catalog( $site['channel'] );
		$out     = array();
		foreach ( $catalog as $slug => $rel ) {
			if ( ! $this->site_allows( $site, $slug ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'         => $slug,
				'type'         => $rel['type'],
				'name'         => $rel['name'],
				'version'      => $rel['version'],
				'package'      => $this->base_url() . '/v1/download/' . $slug . '/' . rawurlencode( $rel['version'] ) . '.zip',
				'requires'     => $rel['requires'],
				'tested'       => $rel['tested'],
				'requires_php' => $rel['requires_php'],
				'changelog'    => $rel['changelog_html'],
				'released'     => $rel['published_at'],
				'size'         => $rel['size'],
			);
		}

		$this->remember_site( $site, $body );
		$this->json( array( 'ok' => true, 'site' => $site['name'], 'packages' => $out ) );
	}

	/* ------------------------------------------------------------------ */
	/* /v1/download/{slug}/{version}.zip                                   */
	/* ------------------------------------------------------------------ */

	private function route_download( string $slug, string $version ): void {
		$site = $this->auth_site();
		if ( ! $this->site_allows( $site, $slug ) ) {
			throw new HttpError( 'Package not allowed for this site', 403 );
		}

		$release = null;
		foreach ( $this->releases() as $r ) {
			if ( $r['slug'] === $slug && $r['version'] === $version ) {
				$release = $r;
				break;
			}
		}
		if ( ! $release || ! $release['asset_id'] ) {
			throw new HttpError( 'Version not found', 404 );
		}
		if ( $release['prerelease'] && 'beta' !== $site['channel'] ) {
			throw new HttpError( 'Version not found', 404 );
		}

		$file = $this->zip_path( $slug, $version );
		if ( ! is_file( $file ) || filesize( $file ) !== (int) $release['size'] ) {
			$this->fetch_asset( (int) $release['asset_id'], $file );
		}

		$this->log( 'download', $site['name'] . ' ' . $slug . ' ' . $version );

		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.zip"' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file );
	}

	private function zip_path( string $slug, string $version ): string {
		return $this->data_dir . '/zips/' . $slug . '/' . $slug . '-' . preg_replace( '/[^0-9A-Za-z.+-]/', '', $version ) . '.zip';
	}

	private function fetch_asset( int $asset_id, string $file ): void {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Cannot create ' . $dir );
		}
		$lock = fopen( $file . '.lock', 'c' );
		flock( $lock, LOCK_EX );
		try {
			clearstatcache( true, $file );
			$tmp = $file . '.part';
			$fp  = fopen( $tmp, 'wb' );
			$this->github( '/repos/' . $this->config['repo'] . '/releases/assets/' . $asset_id, 'application/octet-stream', $fp );
			fclose( $fp );
			$zip_magic = file_get_contents( $tmp, false, null, 0, 2 );
			if ( 'PK' !== $zip_magic ) {
				@unlink( $tmp );
				throw new \RuntimeException( 'Downloaded asset is not a zip' );
			}
			rename( $tmp, $file );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/* ------------------------------------------------------------------ */
	/* /v1/webhook (GitHub "Releases" olayı): önbelleği temizler           */
	/* ------------------------------------------------------------------ */

	private function route_webhook(): void {
		$secret = (string) $this->config['webhook_secret'];
		if ( '' === $secret ) {
			throw new HttpError( 'Webhook disabled', 404 );
		}
		$raw  = (string) file_get_contents( 'php://input', false, null, 0, 1048576 );
		$sig  = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
		$want = 'sha256=' . hash_hmac( 'sha256', $raw, $secret );
		if ( ! hash_equals( $want, $sig ) ) {
			throw new HttpError( 'Bad signature', 401 );
		}
		@unlink( $this->data_dir . '/releases.json' );
		$this->log( 'webhook', (string) ( $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '' ) );
		$this->json( array( 'ok' => true, 'flushed' => true ) );
	}

	/** Sitelerin son bildirdiği durum (sites.json): hash => {...}. */
	public function site_states(): array {
		return $this->read_json( $this->data_dir . '/sites.json' );
	}

	/** Release önbelleğini boşaltır; bir sonraki istek GitHub'dan okur. */
	public function flush_cache(): void {
		@unlink( $this->data_dir . '/releases.json' );
	}

	/** GitHub'a token ile erişilebiliyor mu (önbelleği kullanmadan). */
	public function test_github(): string {
		try {
			$repo = json_decode( $this->github( '/repos/' . $this->config['repo'] ), true );
			return is_array( $repo ) && ! empty( $repo['full_name'] ) ? '' : 'Beklenmeyen yanıt';
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Site doğrulama                                                      */
	/* ------------------------------------------------------------------ */

	private function auth_site(): array {
		$key = $_SERVER['HTTP_X_YT_SITE_KEY'] ?? '';
		$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		if ( '' === $key && preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
			$key = $m[1];
		}
		$key = trim( (string) $key );
		if ( strlen( $key ) < 20 ) {
			throw new HttpError( 'Missing site key', 401 );
		}
		$hash = hash( 'sha256', $key );
		foreach ( $this->config['sites'] as $h => $conf ) {
			if ( hash_equals( strtolower( (string) $h ), $hash ) ) {
				return array(
					'hash'     => $hash,
					'name'     => (string) ( $conf['name'] ?? 'Site' ),
					'packages' => $conf['packages'] ?? '*',
					'channel'  => ( $conf['channel'] ?? 'stable' ) === 'beta' ? 'beta' : 'stable',
				);
			}
		}
		usleep( 500000 );
		$this->log( 'auth-fail', $_SERVER['REMOTE_ADDR'] ?? '' );
		throw new HttpError( 'Invalid site key', 403 );
	}

	public function site_allows( array $site, string $slug ): bool {
		if ( '*' === $site['packages'] ) {
			return true;
		}
		return in_array( $slug, (array) $site['packages'], true );
	}

	private function remember_site( array $site, array $body ): void {
		$file = $this->data_dir . '/sites.json';
		$this->with_lock(
			$file,
			function () use ( $file, $site, $body ) {
				$all  = $this->read_json( $file );
				$pkgs = array();
				foreach ( (array) ( $body['packages'] ?? array() ) as $slug => $ver ) {
					if ( is_string( $slug ) && preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) && is_scalar( $ver ) ) {
						$pkgs[ $slug ] = substr( (string) $ver, 0, 32 );
					}
				}
				$all[ $site['hash'] ] = array(
					'name'      => $site['name'],
					'url'       => substr( (string) ( $body['site'] ?? '' ), 0, 200 ),
					'wp'        => substr( (string) ( $body['wp'] ?? '' ), 0, 20 ),
					'php'       => substr( (string) ( $body['php'] ?? '' ), 0, 20 ),
					'packages'  => $pkgs,
					'last_seen' => gmdate( 'Y-m-d H:i' ) . ' UTC',
					'last_seen_ts' => time(),
				);
				$this->write_json( $file, $all );
			}
		);
	}

	/* ------------------------------------------------------------------ */
	/* GitHub release'leri                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Son sürümler: slug => release. $channel 'stable' ise pre-release'ler atlanır.
	 */
	public function catalog( string $channel ): array {
		$out = array();
		foreach ( $this->releases() as $r ) {
			if ( ! $r['asset_id'] || ( $r['prerelease'] && 'beta' !== $channel ) ) {
				continue;
			}
			if ( ! isset( $out[ $r['slug'] ] ) || version_compare( $r['version'], $out[ $r['slug'] ]['version'], '>' ) ) {
				$out[ $r['slug'] ] = $r;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Tüm release'ler (önbellekli). Tag biçimi: <slug>-v<sürüm>.
	 */
	public function releases(): array {
		$file  = $this->data_dir . '/releases.json';
		$cache = $this->read_json( $file );
		if ( isset( $cache['at'], $cache['items'] ) && time() - (int) $cache['at'] < (int) $this->config['cache_ttl'] ) {
			return $cache['items'];
		}

		try {
			$items = array();
			for ( $page = 1; $page <= 10; $page++ ) {
				$list = json_decode( $this->github( '/repos/' . $this->config['repo'] . '/releases?per_page=100&page=' . $page ), true );
				if ( ! is_array( $list ) || ! $list ) {
					break;
				}
				foreach ( $list as $rel ) {
					$parsed = $this->parse_release( $rel );
					if ( $parsed ) {
						$items[] = $parsed;
					}
				}
				if ( count( $list ) < 100 ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			// GitHub'a ulaşılamazsa eski önbellekle devam et.
			error_log( '[yt-update] releases: ' . $e->getMessage() );
			if ( isset( $cache['items'] ) ) {
				return $cache['items'];
			}
			throw new HttpError( 'Release list unavailable', 502 );
		}

		$this->write_json( $file, array( 'at' => time(), 'items' => $items ) );
		return $items;
	}

	private function parse_release( array $rel ): ?array {
		if ( ! empty( $rel['draft'] ) ) {
			return null;
		}
		if ( ! preg_match( '/^([a-z0-9][a-z0-9-]*)-v([0-9][0-9A-Za-z.+-]*)$/', (string) ( $rel['tag_name'] ?? '' ), $m ) ) {
			return null;
		}
		$slug    = $m[1];
		$version = $m[2];
		$body    = (string) ( $rel['body'] ?? '' );
		$meta    = array();
		if ( preg_match( '/<!--\s*yt-meta\s+(\{.*?\})\s*-->/s', $body, $mm ) ) {
			$meta = json_decode( $mm[1], true ) ?: array();
			$body = trim( str_replace( $mm[0], '', $body ) );
		}

		$asset = null;
		foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) {
			if ( ( $a['name'] ?? '' ) === $slug . '.zip' ) {
				$asset = $a;
				break;
			}
		}

		return array(
			'slug'           => $slug,
			'version'        => $version,
			'tag'            => (string) $rel['tag_name'],
			'type'           => ( $meta['type'] ?? 'plugin' ) === 'theme' ? 'theme' : 'plugin',
			'name'           => (string) ( $meta['name'] ?? $slug ),
			'requires'       => (string) ( $meta['requires'] ?? '' ),
			'tested'         => (string) ( $meta['tested'] ?? '' ),
			'requires_php'   => (string) ( $meta['requires_php'] ?? '' ),
			'prerelease'     => ! empty( $rel['prerelease'] ),
			'published_at'   => (string) ( $rel['published_at'] ?? '' ),
			'asset_id'       => $asset ? (int) $asset['id'] : 0,
			'size'           => $asset ? (int) $asset['size'] : 0,
			'changelog_html' => self::markdown( $body ),
		);
	}

	/**
	 * GitHub API isteği. $sink verilirse gövde dosyaya yazılır.
	 * Asset indirmesinde GitHub başka bir host'a yönlendirir; curl Authorization
	 * başlığını başka host'a taşımaz (doğru davranış).
	 */
	private function github( string $path, string $accept = 'application/vnd.github+json', $sink = null ): string {
		$token = (string) $this->config['github_token'];
		if ( '' === $token ) {
			throw new \RuntimeException( 'github_token is empty' );
		}
		$ch = curl_init( rtrim( $this->config['github_api'], '/' ) . $path );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_HTTPHEADER     => array(
					'Accept: ' . $accept,
					'Authorization: Bearer ' . $token,
					'X-GitHub-Api-Version: 2022-11-28',
					'User-Agent: yt-update-server/' . VERSION,
				),
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => $sink ? 300 : 30,
				CURLOPT_FAILONERROR    => false,
			)
		);
		if ( $sink ) {
			curl_setopt( $ch, CURLOPT_FILE, $sink );
		} else {
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		}
		$res  = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$err  = curl_error( $ch );
		curl_close( $ch );
		if ( false === $res ) {
			throw new \RuntimeException( 'GitHub request failed: ' . $err );
		}
		if ( $code >= 400 ) {
			throw new \RuntimeException( 'GitHub HTTP ' . $code . ' for ' . $path );
		}
		return $sink ? '' : (string) $res;
	}

	/* ------------------------------------------------------------------ */
	/* Yardımcılar                                                         */
	/* ------------------------------------------------------------------ */

	/** Release notu için küçük, güvenli markdown (başlık, liste, kalın, kod, link). */
	public static function markdown( string $md ): string {
		$lines = preg_split( '/\r?\n/', trim( $md ) );
		$html  = '';
		$list  = false;
		foreach ( $lines as $line ) {
			$t = trim( $line );
			if ( preg_match( '/^[-*]\s+(.*)$/', $t, $m ) ) {
				if ( ! $list ) {
					$html .= '<ul>';
					$list  = true;
				}
				$html .= '<li>' . self::inline( $m[1] ) . '</li>';
				continue;
			}
			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( '' === $t ) {
				continue;
			}
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $t, $m ) ) {
				$html .= '<h4>' . self::inline( $m[2] ) . '</h4>';
			} else {
				$html .= '<p>' . self::inline( $t ) . '</p>';
			}
		}
		if ( $list ) {
			$html .= '</ul>';
		}
		return $html;
	}

	private static function inline( string $s ): string {
		$s = htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
		$s = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s );
		$s = preg_replace( '/\[([^\]]+)\]\((https:\/\/[^)\s"]+)\)/', '<a href="$2">$1</a>', $s );
		return $s;
	}

	public function base_url(): string {
		if ( ! empty( $this->config['base_url'] ) ) {
			return rtrim( $this->config['base_url'], '/' );
		}
		$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$host = preg_replace( '/[^A-Za-z0-9.:-]/', '', $host );
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https';
		return ( $https ? 'https' : 'http' ) . '://' . $host;
	}

	private function json( $data, int $code = 200 ): void {
		if ( ! headers_sent() ) {
			http_response_code( $code );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function read_json( string $file ): array {
		if ( ! is_file( $file ) ) {
			return array();
		}
		$d = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $d ) ? $d : array();
	}

	private function write_json( string $file, array $data ): void {
		if ( ! is_dir( $this->data_dir ) ) {
			mkdir( $this->data_dir, 0750, true );
		}
		$tmp = $file . '.' . getmypid() . '.tmp';
		file_put_contents( $tmp, json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		rename( $tmp, $file );
	}

	private function with_lock( string $file, callable $fn ): void {
		if ( ! is_dir( $this->data_dir ) ) {
			mkdir( $this->data_dir, 0750, true );
		}
		$lock = fopen( $file . '.lock', 'c' );
		flock( $lock, LOCK_EX );
		try {
			$fn();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function log( string $type, string $msg ): void {
		if ( ! is_dir( $this->data_dir ) ) {
			return;
		}
		$line = gmdate( 'c' ) . "\t" . $type . "\t" . str_replace( array( "\n", "\r", "\t" ), ' ', $msg ) . "\n";
		file_put_contents( $this->data_dir . '/access.log', $line, FILE_APPEND | LOCK_EX );
	}
}
