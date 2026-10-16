<?php
/**
 * Panelden girilen ayarlar: data/settings.json (web'den erişilemez).
 *
 * Alanlar: admin_user, admin_password_hash, secret (oturum imzası), github_token,
 * repo, webhook_secret, sites (hash => {name, packages, channel, created}).
 */

namespace YTUpdate;

final class Settings {

	public static function path( string $data_dir ): string {
		return rtrim( $data_dir, '/' ) . '/settings.json';
	}

	public static function load( string $data_dir ): array {
		$file = self::path( $data_dir );
		if ( ! is_file( $file ) ) {
			return array();
		}
		$d = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $d ) ? $d : array();
	}

	/** Verilen alanları kaydeder (diğer alanlar korunur). */
	public static function update( string $data_dir, array $changes ): array {
		if ( ! is_dir( $data_dir ) ) {
			mkdir( $data_dir, 0750, true );
		}
		$file = self::path( $data_dir );
		$lock = fopen( $file . '.lock', 'c' );
		flock( $lock, LOCK_EX );
		try {
			$data = self::load( $data_dir );
			foreach ( $changes as $k => $v ) {
				if ( null === $v ) {
					unset( $data[ $k ] );
				} else {
					$data[ $k ] = $v;
				}
			}
			$tmp = $file . '.' . getmypid() . '.tmp';
			file_put_contents( $tmp, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			@chmod( $tmp, 0640 );
			rename( $tmp, $file );
			return $data;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}
}
