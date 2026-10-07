<?php
/**
 * AI-fix flow for missing alt text - the flagship example this plugin's
 * AI-fix flow is built around. One image at a time, always human-confirmed.
 *
 * Draft/private vs published is resolved to *how the image reaches the AI
 * provider*, not just which request shape is used: for anything not
 * publicly published, the image is read straight off local disk and sent
 * as base64 - its URL is never even constructed, let alone transmitted,
 * because a URL an AI provider (or anything logging its requests) could
 * later re-fetch is exactly the leak this avoids. Only for a publicly
 * published, public post does this hand the provider a URL to fetch itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FETTLE_AI_MAX_IMAGE_BYTES', 8 * 1024 * 1024 ); // 8MB - generous for a web image, small enough to not blow past a provider's own request-size limit or run up needless cost on an oversized upload.

/**
 * @param int $post_id
 * @return bool True if this post is published, publicly visible, and not password-protected.
 */
function fettle_post_is_publicly_visible( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}
	if ( 'publish' !== $post->post_status ) {
		return false;
	}
	if ( '' !== $post->post_password ) {
		return false;
	}

	return true;
}

/**
 * Resolves an <img> reference to what the AI dispatcher needs, choosing
 * base64-from-disk vs URL-passthrough based on the post's visibility (see
 * file docblock) rather than on the image attachment's own status, since
 * what matters is whether *this post's use of it* is public yet.
 *
 * @param int    $post_id
 * @param string $src           The <img> src as it appears in the content.
 * @param int    $attachment_id 0 if not a registered Media Library attachment.
 * @return array{base64?: string, url?: string, mime: string}|WP_Error
 */
function fettle_get_image_reference_for_ai( $post_id, $src, $attachment_id ) {
	if ( fettle_post_is_publicly_visible( $post_id ) ) {
		$mime = wp_check_filetype( $src )['type'] ?? 'image/jpeg';
		return array(
			'url'  => $src,
			'mime' => $mime ?: 'image/jpeg',
		);
	}

	// Not public: read from local disk when we can, so no URL for this image is ever put on the wire.
	$local_path = $attachment_id ? get_attached_file( $attachment_id ) : '';
	if ( $local_path && file_exists( $local_path ) ) {
		if ( filesize( $local_path ) > FETTLE_AI_MAX_IMAGE_BYTES ) {
			return new WP_Error( 'fettle_ai_image_too_large', __( 'This image is too large to send to an AI provider (over 8MB).', 'fettle' ) );
		}
		$bytes = file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local Media Library file already resolved via get_attached_file(), not a remote fetch.
		if ( false === $bytes ) {
			return new WP_Error( 'fettle_ai_image_read_failed', __( 'Could not read the image file from disk.', 'fettle' ) );
		}
		$mime = wp_check_filetype( $local_path )['type'] ?? 'image/jpeg';
		return array(
			'base64' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for a vision API request, not obfuscation.
			'mime'   => $mime ?: 'image/jpeg',
		);
	}

	// Not a registered attachment we can read locally (e.g. an externally hosted image) - the only option left is fetching it, but still base64-encoded ourselves so the URL itself is never handed to the provider.
	if ( '' === $src ) {
		return new WP_Error( 'fettle_ai_no_image_src', __( 'This image has no readable source.', 'fettle' ) );
	}

	return fettle_image_to_base64( array( 'url' => $src, 'mime' => 'image/jpeg' ) );
}

/**
 * The exact reply the alt-text prompt asks for when the model judges an
 * image purely decorative.
 */
define( 'FETTLE_AI_DECORATIVE_REPLY', 'DECORATIVE' );

/**
 * @param string $context_text Nearby paragraph text, or ''.
 * @param string $link_href    When the image is the only content of a link,
 *                             that link's URL - its alt text is then the
 *                             link's name, and must describe the link.
 * @return string
 */
function fettle_build_alt_text_prompt( $context_text, $link_href = '' ) {
	if ( '' !== $link_href ) {
		$prompt = "This image is the only content of a link, so its alt text is the only name screen reader users hear for that link. "
			. "Write alt text that names where the link goes, in a few words a visitor would recognise - for example \"Meet our team\" or \"Shop women's shoes\". "
			. "Use the link's URL and what the image shows to work out the destination. No more than 100 characters. "
			. "Do not use the words \"link\", \"button\", \"image\" or \"picture\" - a screen reader already announces it as a link. "
			. "The link points to: " . $link_href . " "
			. 'Reply with only the alt text itself, nothing else.';
	} else {
		$prompt = "Write concise, descriptive alt text for this image, for a screen reader user who cannot see it. "
			. "One sentence, no more than 125 characters. Describe what is actually shown, not a guess at intent. "
			. "Do not start with \"Image of\" or \"Picture of\" - a screen reader already announces it as an image. "
			. 'If the image is purely decorative - an ornament, divider, background texture or spacer that carries no information at all - reply with exactly the word ' . FETTLE_AI_DECORATIVE_REPLY . ' instead. Only do that when you are sure; if in doubt, describe it. '
			. 'Otherwise reply with only the alt text itself, nothing else.';
	}

	if ( '' !== trim( $context_text ) ) {
		$prompt .= "\n\nFor context, this is the text immediately next to the image on the page (use it to understand context, but describe the image itself, not the text): "
			. $context_text;
	}

	return $prompt;
}

/**
 * The suggestion shape for "make this image decorative": an empty alt, with
 * a display text that says so plainly instead of showing an empty string.
 *
 * @param string $display
 * @return array{value: string, display: string}
 */
function fettle_decorative_alt_suggestion( $display ) {
	return array(
		'value'   => '',
		'display' => $display,
	);
}

/**
 * Generates an alt-text suggestion for one finding. Does not apply
 * anything - the caller (admin-page.php) is responsible for the
 * preview/confirm step.
 *
 * The model may judge the image purely decorative instead; that comes back
 * as an empty-alt suggestion the admin confirms like any other. Never for an
 * image that is a link's only content - a link always needs a name.
 *
 * @param int   $post_id
 * @param array $finding An image finding, i.e. has 'src', 'attachment_id',
 *                       'context_text' and, for an image that names its
 *                       link, 'link_href'.
 * @return string|array|WP_Error Suggested alt text, an empty-alt suggestion
 *                               from fettle_decorative_alt_suggestion(), or WP_Error.
 */
function fettle_generate_alt_text_suggestion( $post_id, $finding ) {
	if ( ! fettle_ai_is_configured() ) {
		return new WP_Error( 'fettle_ai_not_configured', __( 'No AI provider is configured yet. Add an API key in Fettle settings.', 'fettle' ) );
	}

	$image = fettle_get_image_reference_for_ai( $post_id, $finding['src'] ?? '', (int) ( $finding['attachment_id'] ?? 0 ) );
	if ( is_wp_error( $image ) ) {
		return $image;
	}

	$link_href = (string) ( $finding['link_href'] ?? '' );
	$provider  = fettle_get_current_provider();
	$model     = fettle_get_model_for_provider( $provider );
	$api_key   = fettle_get_api_key_for_provider( $provider );
	$prompt    = fettle_build_alt_text_prompt( $finding['context_text'] ?? '', $link_href );

	$result = fettle_ai_call_vision( $provider, $model, $prompt, $image, $api_key, array( 'max_tokens' => 200 ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Trim surrounding quotes a model sometimes wraps its answer in despite the prompt asking for "only the alt text".
	$text = trim( $result, " \t\n\r\0\x0B\"'" );

	if ( '' === $text ) {
		return new WP_Error( 'fettle_ai_empty_response', __( 'The AI provider returned an empty suggestion.', 'fettle' ) );
	}

	if ( FETTLE_AI_DECORATIVE_REPLY === strtoupper( rtrim( $text, '.!' ) ) ) {
		if ( '' !== $link_href ) {
			return new WP_Error( 'fettle_ai_no_link_name', __( 'The AI could not suggest a name for this link. Write the alt text by hand in the editor.', 'fettle' ) );
		}
		return fettle_decorative_alt_suggestion( __( 'Empty alt (alt="") - the AI considers this image purely decorative.', 'fettle' ) );
	}

	return $text;
}

/**
 * Suggestion for an image marked decorative that probably isn't: its own
 * Media Library alt text when it has one - no AI call needed, and it's the
 * text the site's own author already wrote for this image - otherwise an
 * AI suggestion.
 *
 * @param int   $post_id
 * @param array $finding An image-empty-alt finding.
 * @return string|array|WP_Error
 */
function fettle_generate_empty_alt_suggestion( $post_id, $finding ) {
	$library_alt = trim( (string) ( $finding['library_alt'] ?? '' ) );
	if ( '' !== $library_alt ) {
		return array(
			'value'   => $library_alt,
			/* translators: %s: alt text taken from the Media Library */
			'display' => sprintf( __( '"%s" (from the Media Library)', 'fettle' ), $library_alt ),
		);
	}

	return fettle_generate_alt_text_suggestion( $post_id, $finding );
}

/**
 * Suggestion for an image whose alt only repeats nearby text (or
 * contradicts role="presentation"): mark it decorative. Deterministic, no
 * AI call.
 *
 * @param int   $post_id
 * @param array $finding An image-redundant-alt finding.
 * @return array{value: string, display: string}
 */
function fettle_generate_redundant_alt_suggestion( $post_id, $finding ) {
	unset( $post_id, $finding );
	return fettle_decorative_alt_suggestion( __( 'Empty alt (alt="") - mark this image as decorative, since the text next to it already says the same.', 'fettle' ) );
}

/**
 * A generated-but-not-yet-confirmed suggestion, held just long enough for
 * the admin to see the preview and click Apply or Discard (synchronous
 * form-post flow, not AJAX, for this first pass - see admin-page.php).
 * A transient rather than postmeta: this is disposable UI state, not
 * something that should survive indefinitely if never confirmed.
 */
define( 'FETTLE_PENDING_FIX_TTL', 15 * MINUTE_IN_SECONDS );

/**
 * @param int    $post_id
 * @param string $instance_key
 * @return string
 */
function fettle_pending_fix_transient_key( $post_id, $instance_key ) {
	return 'fettle_fix_' . $post_id . '_' . substr( $instance_key, 0, 20 );
}

/**
 * @param int    $post_id
 * @param string $instance_key
 * @param string $suggestion
 */
function fettle_store_pending_fix( $post_id, $instance_key, $suggestion ) {
	set_transient( fettle_pending_fix_transient_key( $post_id, $instance_key ), $suggestion, FETTLE_PENDING_FIX_TTL );
}

/**
 * @param int    $post_id
 * @param string $instance_key
 * @return string|false
 */
function fettle_get_pending_fix( $post_id, $instance_key ) {
	return get_transient( fettle_pending_fix_transient_key( $post_id, $instance_key ) );
}

/**
 * @param int    $post_id
 * @param string $instance_key
 */
function fettle_clear_pending_fix( $post_id, $instance_key ) {
	delete_transient( fettle_pending_fix_transient_key( $post_id, $instance_key ) );
}

/**
 * Applies a confirmed alt-text suggestion: sets the alt attribute on the
 * specific <img> occurrence identified by instance_key, and - only if it
 * is currently unset, never overwriting a different existing value - the
 * attachment's own default alt text too, so later uses of the same image
 * benefit as well. An empty value (mark decorative) only touches this one
 * occurrence: the same image can be decorative here and meaningful elsewhere.
 *
 * @param int    $post_id
 * @param string $instance_key
 * @param string $alt_text
 * @return true|WP_Error
 */
function fettle_apply_alt_text_fix( $post_id, $instance_key, $alt_text ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'fettle_post_not_found', __( 'No post exists with that ID.', 'fettle' ) );
	}
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return new WP_Error( 'fettle_no_tag_processor', __( 'This WordPress version does not support the required HTML processor.', 'fettle' ) );
	}

	$processor      = new WP_HTML_Tag_Processor( $post->post_content );
	$position       = 0;
	$applied        = false;
	$attachment_id  = 0;

	while ( $processor->next_tag( 'img' ) ) {
		++$position;
		$src = (string) $processor->get_attribute( 'src' );
		$key = md5( 'img|' . $position . '|' . $src );

		if ( $key !== $instance_key ) {
			continue;
		}

		$processor->set_attribute( 'alt', $alt_text );
		$class = (string) $processor->get_attribute( 'class' );
		if ( preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ) {
			$attachment_id = (int) $m[1];
		}
		$applied = true;
		break;
	}

	if ( ! $applied ) {
		return new WP_Error( 'fettle_finding_not_found', __( 'This image could not be found in the current content - it may have already changed since the last scan.', 'fettle' ) );
	}

	$update = wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $processor->get_updated_html(),
		),
		true
	);

	if ( is_wp_error( $update ) ) {
		return $update;
	}

	if ( $attachment_id && '' !== $alt_text ) {
		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' === trim( (string) $existing ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}
	}

	return true;
}
