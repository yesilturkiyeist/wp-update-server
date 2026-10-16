<?php
/**
 * İsteğe bağlı: ortam değişkeni kullanamayan hosting için.
 *
 * Git'ten deploy ederken (Coolify, Docker, Hostinger Git) bu dosyaya gerek yok;
 * ayarları ortam değişkeni olarak girin (README.md). Bu dosyayı config.php
 * adıyla kopyalarsanız buradaki dolu değerler ortam değişkenlerini ezer.
 * config.php git'e girmez.
 */
return array(
	'base_url'            => 'https://update.ustageliyor.org',
	'repo'                => 'yesilturkiyeist/wp-paketler',
	'github_token'        => '',   // fine-grained token, yalnız wp-paketler, Contents: Read-only
	'webhook_secret'      => '',   // Actions secret UPDATE_WEBHOOK_SECRET ile aynı
	'admin_user'          => 'admin',
	'admin_password_hash' => '',   // php bin/keygen.php --password

	// Anahtar = site anahtarının sha256'sı (php bin/keygen.php "Site adı").
	// packages: '*' ya da array( 'hayirkapisi', 'yt-bagis' ); channel: 'stable' | 'beta'.
	'sites' => array(
		// 'a1b2…' => array( 'name' => 'Yeşil Türkiye', 'packages' => '*', 'channel' => 'stable' ),
	),
);
