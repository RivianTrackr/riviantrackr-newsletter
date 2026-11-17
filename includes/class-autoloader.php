<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RivianTrackr\Newsletter\IssueBuilder → includes/class-issue-builder.php
 */
final class Autoloader {

	private static string $dir = '';

	public static function register( string $dir ): void {
		self::$dir = rtrim( $dir, '/' ) . '/';
		spl_autoload_register( [ self::class, 'autoload' ] );
	}

	public static function autoload( string $class_name ): void {
		$prefix = 'RivianTrackr\\Newsletter\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = self::$dir . 'class-' . strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $relative ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
