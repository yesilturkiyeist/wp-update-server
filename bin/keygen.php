<?php
/**
 * Site anahtarı veya /status parolası üretir.
 *
 *   php bin/keygen.php "Hayır Kapısı"   → yeni site anahtarı + YT_UPDATE_SITES / config satırı
 *   php bin/keygen.php --password       → /status parolası için hash
 *
 * Docker'da: docker exec -it <container> php bin/keygen.php "Site adı"
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( ( $argv[1] ?? '' ) === '--password' ) {
	fwrite( STDOUT, 'Parola: ' );
	$pass = trim( (string) fgets( STDIN ) );
	if ( strlen( $pass ) < 10 ) {
		fwrite( STDERR, "En az 10 karakter olmalı.\n" );
		exit( 1 );
	}
	echo "\nYT_UPDATE_ADMIN_PASSWORD_HASH=" . password_hash( $pass, PASSWORD_DEFAULT ) . "\n";
	exit;
}

$name = trim( $argv[1] ?? '' );
if ( '' === $name ) {
	fwrite( STDERR, "Kullanım: php bin/keygen.php \"Site adı\"  |  php bin/keygen.php --password\n" );
	exit( 1 );
}

$key  = 'ytu_' . bin2hex( random_bytes( 24 ) );
$hash = hash( 'sha256', $key );
$site = array( 'name' => $name, 'packages' => '*', 'channel' => 'stable' );

echo "\n1) Sitenin wp-config.php dosyasına (anahtarın kendisi yalnız orada durur):\n\n";
echo "   define( 'YT_UPDATE_KEY', '" . $key . "' );\n\n";
echo "2) Sunucuda YT_UPDATE_SITES JSON'una eklenecek öğe:\n\n";
echo '   "' . $hash . '": ' . json_encode( $site, JSON_UNESCAPED_UNICODE ) . "\n\n";
echo "   (config.php kullanıyorsanız 'sites' içine:)\n";
echo "   '" . $hash . "' => " . str_replace( "\n", '', var_export( $site, true ) ) . ",\n\n";
