<?php
/**
 * YT Update Server giriş noktası. Tüm istekler buraya yönlenir (.htaccess).
 * Ayarlar ortam değişkenlerinden ya da config.php'den gelir (lib/config.php).
 */

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/server.php';

$yt_config = YTUpdate\load_config( __DIR__ );
$yt_path   = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

// Alt klasöre kurulduysa (ör. /update/), önekini at.
$yt_script = (string) ( $_SERVER['SCRIPT_NAME'] ?? '' );
$yt_base   = '/index.php' === substr( $yt_script, -10 ) ? rtrim( substr( $yt_script, 0, -10 ), '/' ) : '';
if ( '' !== $yt_base && 0 === strpos( $yt_path, $yt_base . '/' ) ) {
	$yt_path = substr( $yt_path, strlen( $yt_base ) );
}

( new YTUpdate\Server( $yt_config ) )->handle( $_SERVER['REQUEST_METHOD'] ?? 'GET', $yt_path );
