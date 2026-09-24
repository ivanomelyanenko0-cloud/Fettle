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
 * @param string $context_text Nearby paragraph text, or ''.
 * @return string
 */
function fettle_build_alt_text_prompt( $context_text ) {
	$prompt = "Write concise, descriptive alt text for this image, for a screen reader user who cannot see it. "
		. "One sentence, no more than 125 characters. Describe what is actually shown, not a guess at intent. "
		. "Do not start with \"Image of\" or \"Picture of\" - a screen reader already announces it as an image. "
		. 'Reply with only the alt text itself, nothing else.';

	if ( '' !== trim( $context_text ) ) {
		$prompt .= "\n\nFor context, this is the text immediately next to the image on the page (use it to understand context, but describe the image itself, not the text): "
			. $context_text;
	}

	return $prompt;
}

/**
 * Generates an alt-text suggestion for one finding. Does not apply
 * anything - the caller (admin-page.php) is responsible for the
 * preview/confirm step.
 *
 * @param int   $post_id
 * @param array $finding A finding from fettle_check_alt_text(), i.e. has
 *                        'src', 'attachment_id', 'context_text'.
 * @return string|WP_Error Suggested alt text, or WP_Error.
 */
function fettle_generate_alt_text_suggestion( $post_id, $finding ) {
	if ( ! fettle_ai_is_configured() ) {
		return new WP_Error( 'fettle_ai_not_configured', __( 'No AI provider is configured yet. Add an API key in Fettle settings.', 'fettle' ) );
	}

	$image = fettle_get_image_reference_for_ai( $post_id, $finding['src'] ?? '', (int) ( $finding['attachment_id'] ?? 0 ) );
	if ( is_wp_error( $image ) ) {
		return $image;
	}

	$provider = fettle_get_current_provider();
	$model    = fettle_get_model_for_provider( $provider );
	$api_key  = fettle_get_api_key_for_provider( $provider );
	$prompt   = fettle_build_alt_text_prompt( $finding['context_text'] ?? '' );

	$result = fettle_ai_call_vision( $provider, $model, $prompt, $image, $api_key, array( 'max_tokens' => 200 ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Trim surrounding quotes a model sometimes wraps its answer in despite the prompt asking for "only the alt text".
	$text = trim( $result, " \t\n\r\0\x0B\"'" );

	if ( '' === $text ) {
		return new WP_Error( 'fettle_ai_empty_response', __( 'The AI provider returned an empty suggestion.', 'fettle' ) );
	}

	return $text;
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
 * benefit as well.
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

	if ( $attachment_id ) {
		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' === trim( (string) $existing ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}
	}

	return true;
}
