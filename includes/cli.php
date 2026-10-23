<?php
/**
 * WP-CLI commands:
 *
 * - `wp fettle scan` - the same scan as the Fettle page, from the command
 *   line or a deploy script, with an exit code a CI job can fail on.
 * - `wp fettle check-phrases` - the enforcement half of
 *   includes/forbidden-phrases.php. Scans every translatable string in the
 *   plugin's own PHP files, plus readme.txt, for the banned phrases and
 *   fails (non-zero exit) if it finds one. Meant to run before every
 *   release, not at runtime on a live site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Fettle_CLI_Scan {

	/**
	 * Scans published posts and pages for accessibility issues and lists
	 * what it finds. Uses the scan cache like the Fettle page does: only
	 * posts changed since their last scan are scanned again, unless --force.
	 * Findings marked "Not an issue" are left out.
	 *
	 * ## OPTIONS
	 *
	 * [<post-id>...]
	 * : Only these posts or pages. Default: every published post and page.
	 *
	 * [--force]
	 * : Rescan, ignoring the cache.
	 *
	 * [--type=<types>]
	 * : Only these checks, comma-separated (e.g. alt-text,contrast).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * [--fail-on=<severity>]
	 * : Exit with status 1 if there's a finding at this severity or worse.
	 * ---
	 * options:
	 *   - critical
	 *   - warning
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fettle scan
	 *     wp fettle scan 42 57 --force
	 *     wp fettle scan --type=alt-text,image-alt-quality --format=csv > images.csv
	 *     wp fettle scan --format=count --fail-on=critical
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function __invoke( $args, $assoc_args ) {
		$types   = array_values( array_filter( array_map( 'trim', explode( ',', (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', '' ) ) ) ) );
		$unknown = array_diff( $types, array_keys( fettle_get_registered_checks() ) );
		if ( $unknown ) {
			WP_CLI::error( sprintf( 'Unknown check type(s): %s. Known: %s.', implode( ', ', $unknown ), implode( ', ', array_keys( fettle_get_registered_checks() ) ) ) );
		}

		foreach ( $args as $post_id ) {
			if ( ! get_post( (int) $post_id ) ) {
				WP_CLI::error( sprintf( 'No post exists with ID %s.', $post_id ) );
			}
		}

		$rows = fettle_collect_finding_rows(
			array(
				'post_ids' => array_map( 'intval', $args ),
				'types'    => $types,
				'force'    => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ),
			)
		);

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $rows ) );
		} elseif ( empty( $rows ) && 'table' === $format ) {
			WP_CLI::success( 'No accessibility issues found.' );
		} else {
			\WP_CLI\Utils\format_items( $format, $rows, array( 'post_id', 'title', 'check', 'wcag', 'severity', 'message' ) );
		}

		$fail_on = \WP_CLI\Utils\get_flag_value( $assoc_args, 'fail-on', '' );
		if ( $fail_on ) {
			$failing = array_filter(
				$rows,
				function ( $row ) use ( $fail_on ) {
					return 'warning' === $fail_on || 'critical' === $row['severity'];
				}
			);
			if ( $failing ) {
				WP_CLI::halt( 1 );
			}
		}
	}
}

class Fettle_CLI_Check_Phrases {

	/**
	 * Scans this plugin's PHP source and readme.txt for banned phrases
	 * (see includes/forbidden-phrases.php) in any user-facing string.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fettle check-phrases
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$violations = array();

		foreach ( $this->find_php_files( FETTLE_PLUGIN_DIR ) as $file ) {
			$violations = array_merge( $violations, $this->scan_php_file( $file ) );
		}

		$readme = FETTLE_PLUGIN_DIR . 'readme.txt';
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
				foreach ( fettle_find_forbidden_phrases( $string_literal ) as $phrase ) {
					$violations[] = array(
						'file'    => str_replace( FETTLE_PLUGIN_DIR, '', $file ),
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
			foreach ( fettle_find_forbidden_phrases( $line ) as $phrase ) {
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

WP_CLI::add_command( 'fettle scan', 'Fettle_CLI_Scan' );
WP_CLI::add_command( 'fettle check-phrases', 'Fettle_CLI_Check_Phrases' );
