<?php
/**
 * `wp usher check-phrases` - the enforcement half of
 * includes/forbidden-phrases.php. Scans every translatable string in the
 * plugin's own PHP files, plus readme.txt, for the banned phrases and
 * fails (non-zero exit) if it finds one. Meant to run before every
 * release, not at runtime on a live site - WP-CLI only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Usher_CLI_Check_Phrases {

	/**
	 * Scans this plugin's PHP source and readme.txt for banned phrases
	 * (see includes/forbidden-phrases.php) in any user-facing string.
	 *
	 * ## EXAMPLES
	 *
	 *     wp usher check-phrases
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$violations = array();

		foreach ( $this->find_php_files( USHER_PLUGIN_DIR ) as $file ) {
			$violations = array_merge( $violations, $this->scan_php_file( $file ) );
		}

		$readme = USHER_PLUGIN_DIR . 'readme.txt';
		if ( file_exists( $readme ) ) {
			$violations = array_merge( $violations, $this->scan_plain_text_file( $readme ) );
		}

		if ( empty( $violations ) ) {
			WP_CLI::success( 'No forbidden phrases found.' );
			return;
		}

		foreach ( $violations as $violation ) {
			WP_CLI::log(
				sprintf(
					'%s:%d - "%s" in: %s',
					$violation['file'],
					$violation['line'],
					$violation['phrase'],
					trim( $violation['snippet'] )
				)
			);
		}
		WP_CLI::error( sprintf( '%d forbidden phrase(s) found - see above.', count( $violations ) ) );
	}

	/**
	 * @param string $dir
	 * @return string[] Absolute paths of every .php file under $dir.
	 */
	private function find_php_files( $dir ) {
		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( rtrim( $dir, '/' ), FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file_info ) {
			if ( $file_info->isFile() && 'php' === strtolower( $file_info->getExtension() ) ) {
				$files[] = $file_info->getPathname();
			}
		}
		return $files;
	}

	/**
	 * Finds string literals passed as the first argument to a translation
	 * function - the class of string that ends up in front of a user.
	 * A line-based regex, not a PHP parser: good enough to catch an
	 * obvious violation before release, not a guarantee against every
	 * possible way a string could be constructed.
	 *
	 * @param string $file
	 * @return array[]
	 */
	private function scan_php_file( $file ) {
		$violations = array();
		$lines      = file( $file );
		if ( false === $lines ) {
			return $violations;
		}

		$fn_pattern = '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex)\(\s*[\'"](.*?)[\'"]\s*(?:,|\))/';

		foreach ( $lines as $line_number => $line ) {
			if ( ! preg_match_all( $fn_pattern, $line, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $string_literal ) {
				foreach ( usher_find_forbidden_phrases( $string_literal ) as $phrase ) {
					$violations[] = array(
						'file'    => str_replace( USHER_PLUGIN_DIR, '', $file ),
						'line'    => $line_number + 1,
						'phrase'  => $phrase,
						'snippet' => $line,
					);
				}
			}
		}

		return $violations;
	}

	/**
	 * @param string $file
	 * @return array[]
	 */
	private function scan_plain_text_file( $file ) {
		$violations = array();
		$lines      = file( $file );
		if ( false === $lines ) {
			return $violations;
		}

		foreach ( $lines as $line_number => $line ) {
			foreach ( usher_find_forbidden_phrases( $line ) as $phrase ) {
				$violations[] = array(
					'file'    => basename( $file ),
					'line'    => $line_number + 1,
					'phrase'  => $phrase,
					'snippet' => $line,
				);
			}
		}

		return $violations;
	}
}

WP_CLI::add_command( 'usher check-phrases', 'Usher_CLI_Check_Phrases' );
