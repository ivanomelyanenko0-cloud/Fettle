<?php
/**
 * Table header check: data tables need their header cells marked up as
 * <th> and tied unambiguously to the cells they describe, or a screen
 * reader reads a grid of values with no idea which column or row each one
 * belongs to.
 *
 * Detection only, no automatic fix: a core Table block stores its rows in
 * the HTML itself, and any rewrite that doesn't match the block's own
 * save() output exactly leaves it broken in the editor. Each finding
 * carries a short "how to fix" hint instead - for a Table block, that is
 * one toggle in its settings sidebar.
 *
 * Same split as image-scan.php: WP_HTML_Tag_Processor walks the structure
 * and attributes; cell text comes from an in-order regex pass, and is only
 * trusted when both passes count the same number of cells.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $html Rendered block HTML (post_content).
 * @return array[] Findings: each { type, severity, message, hint, instance_key }.
 */
function fettle_check_table_headers( $html ) {
	$html = (string) $html;
	if ( '' === trim( $html ) || ! class_exists( 'WP_HTML_Tag_Processor' ) || false === stripos( $html, '<table' ) ) {
		return array();
	}

	preg_match_all( '#<t[hd]\b[^>]*>#i', $html, $cell_matches, PREG_OFFSET_CAPTURE );
	$cell_openers = $cell_matches[0];

	$tables     = array();
	$stack      = array(); // Indexes into $tables of the currently open (possibly nested) tables.
	$cell_index = -1;

	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$tag    = $processor->get_tag();
		$closer = $processor->is_tag_closer();

		if ( 'TABLE' === $tag ) {
			if ( $closer ) {
				array_pop( $stack );
				continue;
			}
			$tables[] = array(
				'role'        => strtolower( trim( (string) $processor->get_attribute( 'role' ) ) ),
				'hidden'      => 'true' === $processor->get_attribute( 'aria-hidden' ),
				'has_caption' => false,
				'section'     => 'tbody',
				'rows'        => array(),
			);
			$stack[]  = count( $tables ) - 1;
			continue;
		}

		if ( in_array( $tag, array( 'TH', 'TD' ), true ) && ! $closer ) {
			++$cell_index; // Counted for every cell, nested tables included, to stay aligned with $cell_openers.
		}

		if ( empty( $stack ) ) {
			continue;
		}
		$t = end( $stack );

		if ( 'CAPTION' === $tag && ! $closer ) {
			$tables[ $t ]['has_caption'] = true;
		} elseif ( in_array( $tag, array( 'THEAD', 'TBODY', 'TFOOT' ), true ) ) {
			$tables[ $t ]['section'] = $closer ? 'tbody' : strtolower( $tag );
		} elseif ( 'TR' === $tag && ! $closer ) {
			$tables[ $t ]['rows'][] = array(
				'section' => $tables[ $t ]['section'],
				'cells'   => array(),
			);
		} elseif ( in_array( $tag, array( 'TH', 'TD' ), true ) && ! $closer ) {
			if ( empty( $tables[ $t ]['rows'] ) ) {
				$tables[ $t ]['rows'][] = array(
					'section' => $tables[ $t ]['section'],
					'cells'   => array(),
				);
			}

			$inner = isset( $cell_openers[ $cell_index ] )
				? fettle_table_cell_inner_html( $html, $cell_openers[ $cell_index ][1] + strlen( $cell_openers[ $cell_index ][0] ) )
				: '';

			$tables[ $t ]['rows'][ count( $tables[ $t ]['rows'] ) - 1 ]['cells'][] = array(
				'tag'     => strtolower( $tag ),
				'scope'   => strtolower( trim( (string) $processor->get_attribute( 'scope' ) ) ),
				'headers' => trim( (string) $processor->get_attribute( 'headers' ) ),
				'id'      => (string) $processor->get_attribute( 'id' ),
				'named'   => fettle_has_nonempty_attribute( $processor, array( 'aria-label', 'aria-labelledby' ) ),
				'text'    => fettle_visible_text( $inner ),
				'has_img' => false !== stripos( $inner, '<img' ),
				'bold'    => fettle_table_cell_is_all_bold( $inner ),
			);
		}
	}

	$text_reliable = count( $cell_openers ) === $cell_index + 1;
	$findings      = array();

	foreach ( $tables as $position => $table ) {
		foreach ( fettle_table_header_issues( $table, $text_reliable ) as $issue ) {
			$first_row_text = '';
			if ( ! empty( $table['rows'][0]['cells'] ) ) {
				$first_row_text = implode( '|', wp_list_pluck( $table['rows'][0]['cells'], 'text' ) );
			}

			$findings[] = array_merge(
				$issue,
				array(
					'type'         => 'table-headers',
					'instance_key' => md5( 'table|' . ( $position + 1 ) . '|' . $issue['code'] . '|' . $first_row_text ),
				)
			);
		}
	}

	return $findings;
}

/**
 * A cell's inner HTML: from the end of its opening tag up to whatever
 * comes next among the tags that can end it (its own closer, the next
 * cell or row, or the table structure around it) - closing tags for cells
 * and rows are optional in HTML, so the next opener counts as an end too.
 *
 * @param string $html
 * @param int    $start Offset just past the cell's opening tag.
 * @return string
 */
function fettle_table_cell_inner_html( $html, $start ) {
	if ( preg_match( '#</?(?:t[hdr]|table|thead|tbody|tfoot)\b#i', $html, $m, PREG_OFFSET_CAPTURE, $start ) ) {
		return substr( $html, $start, $m[0][1] - $start );
	}
	return substr( $html, $start );
}

/**
 * @param string $inner A cell's inner HTML.
 * @return bool True if the cell's entire text is wrapped in one <strong>/<b>.
 */
function fettle_table_cell_is_all_bold( $inner ) {
	$inner = trim( $inner );
	return '' !== fettle_visible_text( $inner ) && (bool) preg_match( '#^<(strong|b)\b[^>]*>.*</\1\s*>$#is', $inner );
}

/**
 * @param array $table          One table as built by fettle_check_table_headers().
 * @param bool  $text_reliable  Whether cell text was read reliably.
 * @return array[] Issues: each { code, severity, message, hint }.
 */
function fettle_table_header_issues( $table, $text_reliable ) {
	if ( $table['hidden'] ) {
		return array();
	}

	$rows = array_values(
		array_filter(
			$table['rows'],
			function ( $row ) {
				return ! empty( $row['cells'] );
			}
		)
	);
	if ( empty( $rows ) ) {
		return array();
	}

	$header_cells = array();
	$cell_ids     = array();
	$max_columns  = 0;
	foreach ( $rows as $r => $row ) {
		$max_columns = max( $max_columns, count( $row['cells'] ) );
		foreach ( $row['cells'] as $c => $cell ) {
			if ( 'th' === $cell['tag'] ) {
				$header_cells[] = array( $r, $c, $cell );
			}
			if ( '' !== $cell['id'] ) {
				$cell_ids[ $cell['id'] ] = true;
			}
		}
	}

	if ( in_array( $table['role'], array( 'presentation', 'none' ), true ) ) {
		if ( empty( $header_cells ) && ! $table['has_caption'] ) {
			return array(); // A genuine layout table - nothing to say.
		}
		return array(
			array(
				'code'     => 'layout-markup',
				'severity' => 'warning',
				'message'  => __( 'This table is marked role="presentation" (layout only) but uses data-table markup - header cells or a caption.', 'fettle' ),
				'hint'     => __( 'If it holds data, remove role="presentation". If it is only for layout, use regular <td> cells and no caption.', 'fettle' ),
			),
		);
	}

	$block_hint = __( 'In the block editor, select the table, open its block settings and turn on "Header section", then put the header text there. In a Custom HTML block, change the header cells from <td> to <th>.', 'fettle' );

	if ( empty( $header_cells ) ) {
		if ( count( $rows ) < 2 || $max_columns < 2 ) {
			return array();
		}

		$first_row = $rows[0]['cells'];
		$all_bold  = $text_reliable && count( $first_row ) >= 2;
		foreach ( $first_row as $cell ) {
			$all_bold = $all_bold && $cell['bold'];
		}

		if ( $all_bold ) {
			return array(
				array(
					'code'     => 'fake-header',
					'severity' => 'critical',
					'message'  => __( 'The first row of this table looks like a header (every cell is bold) but is marked up as ordinary cells, so screen readers don\'t treat it as one.', 'fettle' ),
					'hint'     => $block_hint,
				),
			);
		}

		return array(
			array(
				'code'     => 'no-headers',
				'severity' => 'warning',
				'message'  => __( 'This table has no header cells (<th>), so screen readers can\'t tell which column or row a value belongs to.', 'fettle' ),
				'hint'     => $block_hint,
			),
		);
	}

	$issues       = array();
	$uses_headers = false;
	$broken       = false;
	foreach ( $rows as $row ) {
		foreach ( $row['cells'] as $cell ) {
			if ( '' === $cell['headers'] ) {
				continue;
			}
			$uses_headers = true;
			foreach ( preg_split( '/\s+/', $cell['headers'] ) as $ref ) {
				if ( ! isset( $cell_ids[ $ref ] ) ) {
					$broken = true;
				}
			}
		}
	}

	if ( $broken ) {
		$issues[] = array(
			'code'     => 'broken-headers',
			'severity' => 'critical',
			'message'  => __( 'A cell\'s headers attribute points to an id that doesn\'t exist in this table, so that cell is tied to no header at all.', 'fettle' ),
			'hint'     => __( 'Every id listed in a cell\'s headers attribute must match the id of a header cell in the same table - correct or remove it in the table\'s HTML.', 'fettle' ),
		);
	}

	// Column headers: <th> in the first row or in <thead>. Row headers: a later body row that starts with a <th>. With both, which header a cell belongs to is ambiguous without scope (or headers).
	$has_column_headers = false;
	$has_row_headers    = false;
	foreach ( $header_cells as $header ) {
		list( $r, $c ) = $header;
		if ( 0 === $r || 'thead' === $rows[ $r ]['section'] ) {
			$has_column_headers = true;
		} elseif ( 0 === $c && 'tbody' === $rows[ $r ]['section'] ) {
			$has_row_headers = true;
		}
	}

	if ( $has_column_headers && $has_row_headers && ! $uses_headers ) {
		foreach ( $header_cells as $header ) {
			list( $r, $c, $cell ) = $header;
			if ( '' === $cell['scope'] && ! ( 0 === $r && 0 === $c ) ) {
				$issues[] = array(
					'code'     => 'missing-scope',
					'severity' => 'warning',
					'message'  => __( 'This table has both column and row headers, but some header cells have no scope attribute - screen readers may pair values with the wrong header.', 'fettle' ),
					'hint'     => __( 'Add scope="col" to column header cells and scope="row" to row header cells (in a Custom HTML block, or with "Edit as HTML" on the block).', 'fettle' ),
				);
				break;
			}
		}
	}

	if ( $text_reliable ) {
		foreach ( $header_cells as $header ) {
			list( $r, $c, $cell ) = $header;
			// The top-left corner of a two-way table is commonly, and harmlessly, left empty.
			if ( 0 === $r && 0 === $c ) {
				continue;
			}
			if ( '' === $cell['text'] && ! $cell['named'] && ! $cell['has_img'] ) {
				$issues[] = array(
					'code'     => 'empty-header',
					'severity' => 'warning',
					'message'  => __( 'This table has an empty header cell, which gives screen reader users a header with nothing to announce.', 'fettle' ),
					'hint'     => __( 'Give the header cell text, or make it a regular <td> cell if it isn\'t really a header.', 'fettle' ),
				);
				break;
			}
		}
	}

	return $issues;
}
