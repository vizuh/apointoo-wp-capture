<?php
/**
 * PSR-4-ish runtime autoloader.
 *
 * Maps `Apointoo\Capture\Integrations\Forms\CF7_Adapter` to
 * `includes/integrations/forms/class-cf7-adapter.php`, deriving the WordPress
 * file prefix (`class-` or `interface-`) from the symbol name.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader.
 */
class Autoloader {

	/**
	 * Root namespace this autoloader is responsible for.
	 *
	 * @var string
	 */
	const ROOT = 'Apointoo\\Capture\\';

	/**
	 * Register the autoloader with the SPL stack.
	 *
	 * @return void
	 */
	public static function run() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve and load a class file.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	private static function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, self::ROOT ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::ROOT ) );
		$parts    = explode( '\\', $relative );
		$symbol   = array_pop( $parts );

		$dir = '';
		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		$file = APOINTOO_CAPTURE_DIR . 'includes/' . $dir . self::file_name( $symbol );

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Derive the WordPress file name for a class/interface/abstract symbol.
	 *
	 * @param string $symbol Bare class name (no namespace).
	 * @return string File name, e.g. `class-cf7-adapter.php`.
	 */
	private static function file_name( $symbol ) {
		if ( '_Interface' === substr( $symbol, -10 ) ) {
			$prefix = 'interface-';
			$base   = substr( $symbol, 0, -10 );
		} else {
			// Everything else — including abstract classes — uses `class-`,
			// per WordPress.Files.FileName (an abstract class is still a class).
			$prefix = 'class-';
			$base   = $symbol;
		}

		return $prefix . strtolower( str_replace( '_', '-', $base ) ) . '.php';
	}
}
