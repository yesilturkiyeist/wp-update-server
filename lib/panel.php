<?php
/**
 * /admin: kurulum, giriş, GitHub ayarı, siteler (anahtar üret/test/sil), paketler.
 *
 * config.php düzenlemeden her şey buradan yapılır. Ayarlar data/settings.json'a yazılır.
 */

namespace YTUpdate;

final class Panel {

	const COOKIE = 'ytu_admin';
	const TTL    = 43200; // 12 saat

	/** @var Server */
	private $server;

	/** @var array */
	private $config;

	/** @var string */
	private $data_dir;

	/** @var string */
	private $notice = '';

	/** @var string */
	private $notice_type = 'ok';

	public function __construct( Server $server, array $config ) {
		$this->server   = $server;
		$this->config   = $config;
		$this->data_dir = rtrim( (string) $config['data_dir'], '/' );
	}

	/* ------------------------------------------------------------------ */
	/* Giriş noktası                                                       */
	/* ------------------------------------------------------------------ */

	public function handle( string $method ): void {
		header( 'Cache-Control: no-store' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );

		$action = 'POST' === $method ? (string) ( $_POST['action'] ?? '' ) : '';

		if ( ! $this->has_admin() ) {
			if ( 'setup' === $action ) {
				$this->do_setup();
				return;
			}
			$this->page( 'Kurulum', array( $this, 'view_setup' ) );
			return;
		}

		$user = $this->current_user();
		if ( '' === $user ) {
			if ( 'login' === $action ) {
				$this->do_login();
				return;
			}
			$this->page( 'Giriş', array( $this, 'view_login' ) );
			return;
		}

		if ( '' !== $action ) {
			if ( ! hash_equals( $this->csrf(), (string) ( $_POST['csrf'] ?? '' ) ) ) {
				$this->notice( 'Oturum süresi doldu, tekrar deneyin.', 'err' );
				$this->page( 'Panel', array( $this, 'view_dashboard' ) );
				return;
			}
			$this->dispatch( $action );
			return;
		}

		$msgs = array(
			'github'  => array( 'GitHub ayarları kaydedildi.', 'ok' ),
			'deleted' => array( 'Site silindi. O sitenin anahtarı artık çalışmaz.', 'ok' ),
			'updated' => array( 'Site güncellendi.', 'ok' ),
			'flushed' => array( 'Paket listesi GitHub\'dan yeniden okundu.', 'ok' ),
			'pw'      => array( 'Parola değiştirildi.', 'ok' ),
			'webhook' => array( 'Webhook secret üretildi. GitHub\'a eklemeyi unutmayın.', 'ok' ),
		);
		$m = (string) ( $_GET['m'] ?? '' );
		if ( isset( $msgs[ $m ] ) ) {
			$this->notice( $msgs[ $m ][0], $msgs[ $m ][1] );
		}
		$this->page( 'Panel', array( $this, 'view_dashboard' ) );
	}

	private function dispatch( string $action ): void {
		switch ( $action ) {
			case 'logout':
				$this->set_cookie( '', time() - 3600 );
				$this->redirect();
				return;

			case 'save_github':
				$changes = array( 'repo' => $this->clean_repo( (string) ( $_POST['repo'] ?? '' ) ) );
				$token   = trim( (string) ( $_POST['github_token'] ?? '' ) );
				if ( '' !== $token ) {
					$changes['github_token'] = $token;
				}
				Settings::update( $this->data_dir, $changes );
				$this->server->flush_cache();
				$this->redirect( 'github' );
				return;

			case 'gen_webhook':
				Settings::update( $this->data_dir, array( 'webhook_secret' => bin2hex( random_bytes( 24 ) ) ) );
				$this->redirect( 'webhook' );
				return;

			case 'flush':
				$this->server->flush_cache();
				$this->redirect( 'flushed' );
				return;

			case 'add_site':
				$this->do_add_site();
				return;

			case 'update_site':
				$this->do_update_site();
				return;

			case 'delete_site':
				$hash  = (string) ( $_POST['hash'] ?? '' );
				$sites = $this->panel_sites();
				unset( $sites[ $hash ] );
				Settings::update( $this->data_dir, array( 'sites' => $sites ) );
				$this->redirect( 'deleted' );
				return;

			case 'test_key':
				$this->page( 'Panel', array( $this, 'view_dashboard' ), array( 'test_key' => trim( (string) ( $_POST['key'] ?? '' ) ) ) );
				return;

			case 'change_password':
				$p1 = (string) ( $_POST['password'] ?? '' );
				if ( strlen( $p1 ) < 10 || $p1 !== (string) ( $_POST['password2'] ?? '' ) ) {
					$this->notice( 'Parola en az 10 karakter olmalı ve iki alan aynı olmalı.', 'err' );
					$this->page( 'Panel', array( $this, 'view_dashboard' ) );
					return;
				}
				Settings::update( $this->data_dir, array( 'admin_password_hash' => password_hash( $p1, PASSWORD_DEFAULT ) ) );
				$this->config['admin_password_hash'] = Settings::load( $this->data_dir )['admin_password_hash'];
				$this->login_as( $this->admin_user() );
				$this->redirect( 'pw' );
				return;
		}
		$this->redirect();
	}

	/* ------------------------------------------------------------------ */
	/* Kurulum ve giriş                                                    */
	/* ------------------------------------------------------------------ */

	private function has_admin(): bool {
		return '' !== (string) $this->config['admin_password_hash'] || strlen( (string) $this->config['admin_password'] ) >= 10;
	}

	private function admin_user(): string {
		$u = (string) ( $this->config['admin_user'] ?? '' );
		return '' !== $u ? $u : 'admin';
	}

	private function do_setup(): void {
		$user = trim( (string) ( $_POST['user'] ?? '' ) );
		$p1   = (string) ( $_POST['password'] ?? '' );
		$p2   = (string) ( $_POST['password2'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9._@-]{3,40}$/', $user ) || strlen( $p1 ) < 10 || $p1 !== $p2 ) {
			$this->notice( 'Kullanıcı adı 3–40 karakter (harf, rakam, . _ - @), parola en az 10 karakter ve iki alan aynı olmalı.', 'err' );
			$this->page( 'Kurulum', array( $this, 'view_setup' ) );
			return;
		}
		$data = Settings::update(
			$this->data_dir,
			array(
				'admin_user'          => $user,
				'admin_password_hash' => password_hash( $p1, PASSWORD_DEFAULT ),
				'secret'              => bin2hex( random_bytes( 32 ) ),
			)
		);
		if ( ! is_file( Settings::path( $this->data_dir ) ) ) {
			$this->notice( 'data/ klasörüne yazılamadı. Klasörün yazılabilir olduğundan emin olun.', 'err' );
			$this->page( 'Kurulum', array( $this, 'view_setup' ) );
			return;
		}
		$this->config = array_merge( $this->config, $data );
		$this->login_as( $user );
		$this->redirect();
	}

	private function do_login(): void {
		$user = (string) ( $_POST['user'] ?? '' );
		$pass = (string) ( $_POST['password'] ?? '' );
		$hash = (string) $this->config['admin_password_hash'];
		$ok   = '' !== $hash ? password_verify( $pass, $hash ) : hash_equals( (string) $this->config['admin_password'], $pass );
		if ( ! $ok || ! hash_equals( $this->admin_user(), $user ) ) {
			sleep( 1 );
			$this->notice( 'Kullanıcı adı veya parola hatalı.', 'err' );
			$this->page( 'Giriş', array( $this, 'view_login' ) );
			return;
		}
		$this->login_as( $user );
		$this->redirect();
	}

	private function secret(): string {
		$s = (string) ( $this->config['secret'] ?? '' );
		if ( '' === $s ) {
			$s = bin2hex( random_bytes( 32 ) );
			Settings::update( $this->data_dir, array( 'secret' => $s ) );
			$this->config['secret'] = $s;
		}
		return $s;
	}

	/** İmza parolaya bağlı: parola değişince eski oturumlar düşer. */
	private function sign( string $payload ): string {
		$pw = (string) $this->config['admin_password_hash'] . (string) $this->config['admin_password'];
		return hash_hmac( 'sha256', $payload . '|' . substr( hash( 'sha256', $pw ), 0, 16 ), $this->secret() );
	}

	private function login_as( string $user ): void {
		$exp = time() + self::TTL;
		$val = base64_encode( $user ) . '.' . $exp;
		$this->set_cookie( $val . '.' . $this->sign( $val ), $exp );
		$_COOKIE[ self::COOKIE ] = $val . '.' . $this->sign( $val );
	}

	private function current_user(): string {
		$c     = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
		$parts = explode( '.', $c );
		if ( 3 !== count( $parts ) ) {
			return '';
		}
		list( $u, $exp, $sig ) = $parts;
		if ( (int) $exp < time() || ! hash_equals( $this->sign( $u . '.' . $exp ), $sig ) ) {
			return '';
		}
		$user = (string) base64_decode( $u );
		return hash_equals( $this->admin_user(), $user ) ? $user : '';
	}

	private function set_cookie( string $value, int $exp ): void {
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https';
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $exp,
				'path'     => '/admin',
				'secure'   => $https,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	private function csrf(): string {
		return hash_hmac( 'sha256', 'csrf|' . (string) ( $_COOKIE[ self::COOKIE ] ?? '' ), $this->secret() );
	}

	/* ------------------------------------------------------------------ */
	/* Siteler                                                             */
	/* ------------------------------------------------------------------ */

	private function panel_sites(): array {
		$s = Settings::load( $this->data_dir );
		return is_array( $s['sites'] ?? null ) ? $s['sites'] : array();
	}

	private function packages_from_post() {
		if ( 'all' === ( $_POST['scope'] ?? 'all' ) ) {
			return '*';
		}
		$out = array();
		foreach ( (array) ( $_POST['packages'] ?? array() ) as $slug ) {
			if ( is_string( $slug ) && preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) ) {
				$out[] = $slug;
			}
		}
		if ( ! in_array( 'yt-guncelleyici', $out, true ) ) {
			$out[] = 'yt-guncelleyici';
		}
		sort( $out );
		return $out;
	}

	private function do_add_site(): void {
		$name = trim( (string) ( $_POST['name'] ?? '' ) );
		if ( '' === $name ) {
			$this->notice( 'Site adı girin.', 'err' );
			$this->page( 'Panel', array( $this, 'view_dashboard' ) );
			return;
		}
		$key   = 'ytu_' . bin2hex( random_bytes( 24 ) );
		$hash  = hash( 'sha256', $key );
		$sites = $this->panel_sites();
		$sites[ $hash ] = array(
			'name'     => mb_substr( $name, 0, 80 ),
			'packages' => $this->packages_from_post(),
			'channel'  => 'beta' === ( $_POST['channel'] ?? '' ) ? 'beta' : 'stable',
			'created'  => gmdate( 'Y-m-d H:i' ) . ' UTC',
		);
		Settings::update( $this->data_dir, array( 'sites' => $sites ) );
		$this->config['sites'][ $hash ] = $sites[ $hash ] + array( 'source' => 'panel' );
		$this->page( 'Panel', array( $this, 'view_dashboard' ), array( 'new_key' => $key, 'new_name' => $sites[ $hash ]['name'] ) );
	}

	private function do_update_site(): void {
		$hash  = (string) ( $_POST['hash'] ?? '' );
		$sites = $this->panel_sites();
		if ( isset( $sites[ $hash ] ) ) {
			$name = trim( (string) ( $_POST['name'] ?? '' ) );
			if ( '' !== $name ) {
				$sites[ $hash ]['name'] = mb_substr( $name, 0, 80 );
			}
			$sites[ $hash ]['packages'] = $this->packages_from_post();
			$sites[ $hash ]['channel']  = 'beta' === ( $_POST['channel'] ?? '' ) ? 'beta' : 'stable';
			Settings::update( $this->data_dir, array( 'sites' => $sites ) );
		}
		$this->redirect( 'updated' );
	}

	/* ------------------------------------------------------------------ */
	/* Görünümler                                                          */
	/* ------------------------------------------------------------------ */

	private static function e( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	private function notice( string $msg, string $type = 'ok' ): void {
		$this->notice      = $msg;
		$this->notice_type = $type;
	}

	private function redirect( string $msg = '' ): void {
		header( 'Location: ' . $this->server->base_url() . '/admin' . ( $msg ? '?m=' . rawurlencode( $msg ) : '' ), true, 303 );
	}

	private function hidden( string $action ): string {
		return '<input type="hidden" name="action" value="' . self::e( $action ) . '"><input type="hidden" name="csrf" value="' . self::e( $this->csrf() ) . '">';
	}

	private function page( string $title, callable $view, array $args = array() ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		$e = array( __CLASS__, 'e' );
		?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo $e( $title ); ?> · YT Update Server</title>
<style>
:root{--bg:#f4f7f5;--card:#fff;--line:#e2e8e4;--text:#1d2a22;--muted:#66756b;--green:#247833;--green-bg:#e6f4ea;--red:#c0392b;--red-bg:#fcecea;--amber:#9a6700;--amber-bg:#fff6dd}
*{box-sizing:border-box}body{margin:0;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--text)}
.wrap{max-width:1080px;margin:0 auto;padding:20px 16px 60px}
header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
h1{font-size:20px;margin:0}h1 small{font-weight:400;color:var(--muted);font-size:13px;margin-left:6px}
h2{font-size:16px;margin:0 0 12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:16px}
.grid{display:grid;gap:16px;grid-template-columns:1fr 1fr}@media(max-width:800px){.grid{grid-template-columns:1fr}}
label{display:block;font-weight:600;margin:10px 0 4px}
input[type=text],input[type=password],select{width:100%;padding:8px 10px;border:1px solid #cfd8d2;border-radius:6px;font:inherit;background:#fff}
.btn{display:inline-block;padding:8px 14px;border-radius:6px;border:1px solid var(--green);background:var(--green);color:#fff;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
.btn.sec{background:#fff;color:var(--green)}.btn.danger{background:#fff;color:var(--red);border-color:#e3b3ad}.btn.sm{padding:4px 10px;font-size:13px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.notice{padding:10px 14px;border-radius:8px;margin-bottom:16px}.notice.ok{background:var(--green-bg);color:#14531f}.notice.err{background:var(--red-bg);color:#7d1f14}
.pill{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
.pill.ok{background:var(--green-bg);color:var(--green)}.pill.err{background:var(--red-bg);color:var(--red)}.pill.warn{background:var(--amber-bg);color:var(--amber)}.pill.muted{background:#eef1ef;color:var(--muted)}
.pill::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;color:var(--muted);font-weight:600}
.muted{color:var(--muted)}.small{font-size:12px}code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px}
pre{background:#f6f8f7;border:1px solid var(--line);border-radius:6px;padding:10px;white-space:pre-wrap;word-break:break-all;margin:6px 0}
.key{background:#fffbe8;border-color:#f0dc9a}
.checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:4px 12px;margin-top:6px}.checks label{font-weight:400;margin:0;display:flex;gap:6px;align-items:center}
details summary{cursor:pointer;color:var(--green);font-weight:600}
.table-wrap{overflow-x:auto}
</style>
</head>
<body><div class="wrap">
<header>
	<h1>YT Update Server <small><?php echo $e( VERSION ); ?></small></h1>
	<?php if ( '' !== $this->current_user() ) : ?>
		<form method="post"><?php echo $this->hidden( 'logout' ); ?><button class="btn sec sm">Çıkış</button></form>
	<?php endif; ?>
</header>
<?php if ( $this->notice ) : ?>
	<div class="notice <?php echo $e( $this->notice_type ); ?>"><?php echo $e( $this->notice ); ?></div>
<?php endif; ?>
<?php call_user_func( $view, $args ); ?>
</div></body></html>
		<?php
	}

	public function view_setup( array $args ): void {
		?>
		<div class="card" style="max-width:460px">
			<h2>İlk kurulum</h2>
			<p class="muted">Bu panelin yönetici hesabını oluşturun. Kurulum bir kez yapılır; sonrasında bu sayfa giriş ekranına döner.</p>
			<form method="post" autocomplete="off">
				<input type="hidden" name="action" value="setup">
				<label>Kullanıcı adı</label><input type="text" name="user" value="admin" required>
				<label>Parola (en az 10 karakter)</label><input type="password" name="password" required minlength="10">
				<label>Parola tekrar</label><input type="password" name="password2" required minlength="10">
				<p><button class="btn">Kurulumu tamamla</button></p>
			</form>
		</div>
		<?php
	}

	public function view_login( array $args ): void {
		?>
		<div class="card" style="max-width:400px">
			<h2>Giriş</h2>
			<form method="post">
				<input type="hidden" name="action" value="login">
				<label>Kullanıcı adı</label><input type="text" name="user" required autocomplete="username">
				<label>Parola</label><input type="password" name="password" required autocomplete="current-password">
				<p><button class="btn">Giriş yap</button></p>
			</form>
		</div>
		<?php
	}

	public function view_dashboard( array $args ): void {
		$e        = array( __CLASS__, 'e' );
		$token    = (string) $this->config['github_token'];
		$gh_error = '';
		$catalog  = array();
		$stable   = array();
		if ( '' === $token ) {
			$gh_error = 'GitHub token girilmemiş.';
		} else {
			try {
				$catalog = $this->server->catalog( 'beta' );
				$stable  = $this->server->catalog( 'stable' );
			} catch ( \Throwable $ex ) {
				$gh_error = $this->server->test_github();
				$gh_error = $gh_error ? 'GitHub\'a erişilemedi: ' . $gh_error : 'GitHub release listesi okunamadı.';
			}
		}
		$states = $this->server->site_states();
		$sites  = (array) $this->config['sites'];

		// Yeni üretilen anahtar: yalnız bu yanıtta görünür.
		if ( ! empty( $args['new_key'] ) ) {
			$key = $args['new_key'];
			?>
			<div class="card" style="border-color:#e6cf7a">
				<h2>Yeni site anahtarı: <?php echo $e( $args['new_name'] ); ?></h2>
				<p><b>Bu anahtar bir daha gösterilmez.</b> Şimdi kopyalayın ve sitenin <b>WordPress › Ayarlar › YT Güncelleyici</b> ekranında <i>Site anahtarı</i> alanına yapıştırın. Sunucu adresi:</p>
				<pre><?php echo $e( $this->server->base_url() ); ?></pre>
				<p>Site anahtarı:</p>
				<pre class="key" id="newkey"><?php echo $e( $key ); ?></pre>
				<div class="row">
					<button class="btn sec sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('newkey').textContent);this.textContent='Kopyalandı'">Anahtarı kopyala</button>
					<form method="post"><?php echo $this->hidden( 'test_key' ); ?><input type="hidden" name="key" value="<?php echo $e( $key ); ?>"><button class="btn sec sm">Bu anahtarı test et</button></form>
				</div>
				<p class="muted small">Sunucu artık bu anahtarın yalnız özetini (sha256) saklar. Kaybederseniz siteyi silip yeni anahtar üretin.</p>
			</div>
			<?php
		}

		if ( isset( $args['test_key'] ) ) {
			$this->view_key_test( (string) $args['test_key'], $catalog, $stable, $states );
		}
		?>
		<div class="grid">
			<div class="card">
				<h2>GitHub</h2>
				<p>
					<?php if ( $gh_error ) : ?>
						<span class="pill err">Bağlı değil</span> <span class="small"><?php echo $e( $gh_error ); ?></span>
					<?php else : ?>
						<span class="pill ok">Bağlı</span> <span class="small"><?php echo count( $catalog ); ?> paket okundu</span>
					<?php endif; ?>
				</p>
				<form method="post" autocomplete="off">
					<?php echo $this->hidden( 'save_github' ); ?>
					<label>Repo</label>
					<input type="text" name="repo" value="<?php echo $e( $this->config['repo'] ); ?>" placeholder="yesilturkiyeist/wp-paketler">
					<label>Token <span class="muted small">(fine-grained, yalnız bu repo, Contents: Read-only)</span></label>
					<input type="password" name="github_token" placeholder="<?php echo $token ? $e( 'Kayıtlı: …' . substr( $token, -4 ) . ' (değiştirmek için yenisini yazın)' ) : 'github_pat_…'; ?>">
					<p class="row"><button class="btn">Kaydet ve test et</button></p>
				</form>
				<form method="post"><?php echo $this->hidden( 'flush' ); ?><button class="btn sec sm">Paketleri yeniden oku</button></form>
			</div>

			<div class="card">
				<h2>Site ekle</h2>
				<form method="post">
					<?php echo $this->hidden( 'add_site' ); ?>
					<label>Site adı</label>
					<input type="text" name="name" placeholder="ör. Hayır Kapısı" required>
					<?php $this->packages_field( $catalog, '*' ); ?>
					<label>Kanal</label>
					<select name="channel"><option value="stable">Kararlı sürümler</option><option value="beta">Beta dahil</option></select>
					<p><button class="btn">Anahtar üret</button></p>
				</form>
				<details>
					<summary>Var olan bir anahtarı test et</summary>
					<form method="post" class="row" style="margin-top:8px">
						<?php echo $this->hidden( 'test_key' ); ?>
						<input type="password" name="key" placeholder="ytu_…" required style="flex:1;min-width:200px" autocomplete="off">
						<button class="btn sec">Test et</button>
					</form>
				</details>
			</div>
		</div>

		<div class="card">
			<h2>Siteler</h2>
			<?php if ( ! $sites ) : ?>
				<p class="muted">Henüz site yok. "Site ekle" ile ilk anahtarı üretin.</p>
			<?php else : ?>
			<div class="table-wrap"><table>
				<tr><th>Site</th><th>Durum</th><th>Kurulu paketler</th><th>İzin</th><th></th></tr>
				<?php foreach ( $sites as $hash => $conf ) : ?>
					<?php
					$st  = $states[ $hash ] ?? array();
					$ts  = (int) ( $st['last_seen_ts'] ?? 0 );
					$pkg = array();
					foreach ( (array) ( $st['packages'] ?? array() ) as $slug => $ver ) {
						$latest = $catalog[ $slug ]['version'] ?? null;
						$old    = $latest && version_compare( (string) $ver, $latest, '<' );
						$pkg[]  = $e( $slug ) . ' <b style="color:' . ( $old ? 'var(--amber)' : 'var(--green)' ) . '">' . $e( $ver ) . '</b>' . ( $old ? ' <span class="muted">→ ' . $e( $latest ) . '</span>' : '' );
					}
					?>
					<tr>
						<td><b><?php echo $e( $conf['name'] ?? '?' ); ?></b><br><span class="small muted"><?php echo $e( $st['url'] ?? '' ); ?></span>
							<?php if ( ! empty( $st['wp'] ) ) : ?><br><span class="small muted">WP <?php echo $e( $st['wp'] ); ?> · PHP <?php echo $e( $st['php'] ?? '' ); ?></span><?php endif; ?></td>
						<td>
							<?php if ( ! $ts && empty( $st['last_seen'] ) ) : ?>
								<span class="pill muted">Hiç bağlanmadı</span>
							<?php elseif ( $ts && time() - $ts > 2 * DAY_SECONDS ) : ?>
								<span class="pill warn"><?php echo (int) floor( ( time() - $ts ) / DAY_SECONDS ); ?> gündür yok</span>
							<?php else : ?>
								<span class="pill ok">Bağlı</span>
							<?php endif; ?>
							<br><span class="small muted"><?php echo $e( $st['last_seen'] ?? '' ); ?></span>
						</td>
						<td class="small"><?php echo $pkg ? implode( '<br>', $pkg ) : '<span class="muted">—</span>'; ?></td>
						<td class="small"><?php echo '*' === ( $conf['packages'] ?? '*' ) ? 'Tüm paketler' : $e( implode( ', ', (array) $conf['packages'] ) ); ?><br><span class="muted"><?php echo 'beta' === ( $conf['channel'] ?? '' ) ? 'Beta dahil' : 'Kararlı'; ?></span></td>
						<td>
							<?php if ( 'panel' === ( $conf['source'] ?? '' ) ) : ?>
								<details><summary>Düzenle</summary>
									<form method="post" style="min-width:240px">
										<?php echo $this->hidden( 'update_site' ); ?>
										<input type="hidden" name="hash" value="<?php echo $e( $hash ); ?>">
										<label>Ad</label><input type="text" name="name" value="<?php echo $e( $conf['name'] ?? '' ); ?>">
										<?php $this->packages_field( $catalog, $conf['packages'] ?? '*' ); ?>
										<label>Kanal</label>
										<select name="channel"><option value="stable">Kararlı</option><option value="beta" <?php echo 'beta' === ( $conf['channel'] ?? '' ) ? 'selected' : ''; ?>>Beta dahil</option></select>
										<p><button class="btn sm">Kaydet</button></p>
									</form>
									<form method="post" onsubmit="return confirm('Bu site silinsin mi? Anahtarı hemen geçersiz olur.')">
										<?php echo $this->hidden( 'delete_site' ); ?>
										<input type="hidden" name="hash" value="<?php echo $e( $hash ); ?>">
										<button class="btn danger sm">Siteyi sil</button>
									</form>
								</details>
							<?php else : ?>
								<span class="small muted">config.php'den</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table></div>
			<?php endif; ?>
		</div>

		<div class="card">
			<h2>Paketler</h2>
			<?php if ( ! $catalog ) : ?>
				<p class="muted">Paket yok. GitHub token'ı girin; wp-paketler'de release yoksa bir pakete sürüm çıkın.</p>
			<?php else : ?>
			<div class="table-wrap"><table>
				<tr><th>Paket</th><th>Tür</th><th>Son sürüm</th><th>Yayın</th></tr>
				<?php foreach ( $catalog as $slug => $r ) : ?>
					<tr>
						<td><b><?php echo $e( $r['name'] ); ?></b><br><span class="small muted"><?php echo $e( $slug ); ?></span></td>
						<td><?php echo 'theme' === $r['type'] ? 'Tema' : 'Eklenti'; ?></td>
						<td><?php echo $e( $r['version'] ); ?><?php echo ( isset( $stable[ $slug ] ) && $stable[ $slug ]['version'] === $r['version'] ) ? '' : ' <span class="pill warn">beta</span>'; ?></td>
						<td class="small"><?php echo $e( str_replace( 'T', ' ', substr( $r['published_at'], 0, 16 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table></div>
			<?php endif; ?>
		</div>

		<div class="grid">
			<div class="card">
				<h2>Anında yayın (isteğe bağlı)</h2>
				<p class="small muted">Yeni sürüm en geç 5 dakikada görünür. Anında görünsün isterseniz bu secret'ı wp-paketler › Settings › Secrets › Actions'a <code>UPDATE_WEBHOOK_SECRET</code> adıyla ekleyin; repo değişkeni <code>UPDATE_SERVER</code> = <code><?php echo $e( $this->server->base_url() ); ?></code>.</p>
				<?php if ( ! empty( $this->config['webhook_secret'] ) ) : ?>
					<pre><?php echo $e( $this->config['webhook_secret'] ); ?></pre>
				<?php endif; ?>
				<form method="post"><?php echo $this->hidden( 'gen_webhook' ); ?><button class="btn sec sm"><?php echo empty( $this->config['webhook_secret'] ) ? 'Secret üret' : 'Yeni secret üret'; ?></button></form>
			</div>
			<div class="card">
				<h2>Parola değiştir</h2>
				<form method="post" autocomplete="off">
					<?php echo $this->hidden( 'change_password' ); ?>
					<label>Yeni parola</label><input type="password" name="password" minlength="10" required>
					<label>Tekrar</label><input type="password" name="password2" minlength="10" required>
					<p><button class="btn sec sm">Değiştir</button></p>
				</form>
			</div>
		</div>
		<?php
	}

	private function packages_field( array $catalog, $current ): void {
		$all = '*' === $current;
		$sel = $all ? array() : (array) $current;
		$id  = 'p' . bin2hex( random_bytes( 3 ) );
		?>
		<label>Paketler</label>
		<select name="scope" onchange="document.getElementById('<?php echo self::e( $id ); ?>').style.display=this.value==='all'?'none':'grid'">
			<option value="all" <?php echo $all ? 'selected' : ''; ?>>Tüm paketler (yeni eklenenler dahil)</option>
			<option value="some" <?php echo $all ? '' : 'selected'; ?>>Yalnız seçilenler</option>
		</select>
		<div class="checks" id="<?php echo self::e( $id ); ?>" style="display:<?php echo $all ? 'none' : 'grid'; ?>">
			<?php foreach ( $catalog as $slug => $r ) : ?>
				<label><input type="checkbox" name="packages[]" value="<?php echo self::e( $slug ); ?>" <?php echo ( in_array( $slug, $sel, true ) || 'yt-guncelleyici' === $slug ) ? 'checked' : ''; ?> <?php echo 'yt-guncelleyici' === $slug ? 'disabled' : ''; ?>> <?php echo self::e( $r['name'] ); ?></label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function view_key_test( string $key, array $catalog, array $stable, array $states ): void {
		$e     = array( __CLASS__, 'e' );
		$hash  = hash( 'sha256', $key );
		$found = null;
		foreach ( (array) $this->config['sites'] as $h => $conf ) {
			if ( hash_equals( strtolower( (string) $h ), $hash ) ) {
				$found = $conf;
				break;
			}
		}
		echo '<div class="card"><h2>Anahtar testi</h2>';
		if ( ! $found ) {
			echo '<p><span class="pill err">Geçersiz</span> Bu anahtar kayıtlı bir siteye ait değil.</p></div>';
			return;
		}
		$site    = array(
			'packages' => $found['packages'] ?? '*',
			'channel'  => ( $found['channel'] ?? 'stable' ) === 'beta' ? 'beta' : 'stable',
		);
		$visible = array();
		foreach ( ( 'beta' === $site['channel'] ? $catalog : $stable ) as $slug => $r ) {
			if ( $this->server->site_allows( $site, $slug ) ) {
				$visible[] = $r['name'] . ' ' . $r['version'];
			}
		}
		$seen = $states[ $hash ]['last_seen'] ?? '';
		echo '<p><span class="pill ok">Geçerli</span> <b>' . $e( $found['name'] ?? '' ) . '</b> · ' . ( 'beta' === $site['channel'] ? 'Beta dahil' : 'Kararlı' ) . '</p>';
		echo '<p class="small">Görebileceği paketler (' . count( $visible ) . '): ' . $e( implode( ', ', $visible ) ?: '—' ) . '</p>';
		echo '<p class="small">' . ( $seen ? 'Site en son ' . $e( $seen ) . ' bağlandı.' : 'Site henüz bağlanmadı: WordPress › Ayarlar › YT Güncelleyici › "Bağlantıyı test et".' ) . '</p>';
		echo '</div>';
	}

	private function clean_repo( string $repo ): string {
		$repo = trim( preg_replace( '#^https?://github\.com/#', '', trim( $repo ) ), '/' );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : (string) $this->config['repo'];
	}
}
