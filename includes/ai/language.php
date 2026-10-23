<?php
/**
 * Which language AI suggestions are written in. Alt text and link text are
 * read out to the page's own visitors, so they have to be in the page's
 * language - the prompts are in English, and without being told otherwise
 * a model answers in English even on a site written in Ukrainian.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The locale a post's content is written in: the site's language. A
 * multilingual site (one language per post) can say otherwise per post
 * through the filter - Fettle Pro does, for WPML and Polylang.
 *
 * @param int $post_id
 * @return string Locale, e.g. "uk" or "de_DE".
 */
function fettle_ai_content_locale( $post_id ) {
	$site_locale = get_locale();

	/**
	 * @param string $locale  The site's locale.
	 * @param int    $post_id The post the suggestion is for.
	 */
	$locale = apply_filters( 'fettle_ai_content_locale', $site_locale, (int) $post_id );

	return ( is_string( $locale ) && '' !== $locale ) ? $locale : $site_locale;
}

/**
 * @param string $locale
 * @return string The language's English name ("Ukrainian"), or '' when PHP
 *                can't tell - the locale code alone is then enough for a model.
 */
function fettle_locale_language_name( $locale ) {
	if ( ! class_exists( 'Locale' ) ) {
		return '';
	}
	$name = Locale::getDisplayLanguage( $locale, 'en' );
	// intl echoes the language code back when it doesn't know the language.
	$code = strtolower( (string) Locale::getPrimaryLanguage( $locale ) );
	return ( is_string( $name ) && '' !== $name && ! in_array( strtolower( $name ), array( $code, strtolower( $locale ) ), true ) ) ? $name : '';
}

/**
 * The sentence appended to a text prompt so the reply comes back in the
 * post's language.
 *
 * @param int $post_id
 * @return string
 */
function fettle_ai_language_instruction( $post_id ) {
	$locale = fettle_ai_content_locale( $post_id );
	$name   = fettle_locale_language_name( $locale );

	return '' !== $name
		? sprintf( 'Write your reply in %1$s (locale %2$s), the language of the page.', $name, $locale )
		: sprintf( 'Write your reply in the language of the page, locale %s.', $locale );
}
