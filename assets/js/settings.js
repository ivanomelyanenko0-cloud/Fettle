/**
 * Toggles the visible provider's API-key/model rows on the Settings page.
 * Moved here from an inline <script> block so the page has no inline JS at
 * all - enqueued only on Fettle's own settings screen, see
 * fettle_enqueue_settings_assets() in includes/settings-page.php.
 */
( function ( $ ) {
	function fettleToggleProviderRows() {
		var provider = $( '#fettle-provider-select' ).val();
		$( '.fettle-provider-row' ).hide();
		$( '.fettle-provider-row-' + provider ).show();
	}
	$( document ).on( 'change', '#fettle-provider-select', fettleToggleProviderRows );
	$( fettleToggleProviderRows );
} )( jQuery );
