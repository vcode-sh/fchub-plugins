<?php
/**
 * Admin Vue.js app template.
 *
 * @package FCHubStream
 */

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>FCHub Stream - Admin</title>
	<?php
	// Enqueue Vue app assets (will be built from admin-app/).
	$fchub_stream_asset_path = FCHUB_STREAM_URL . 'admin/dist/assets/';
	$fchub_stream_version    = FCHUB_STREAM_VERSION;
	?>
	<link rel="stylesheet" href="<?php echo esc_url( $fchub_stream_asset_path . 'main.css?ver=' . $fchub_stream_version . '&t=' . time() ); /* phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet */ ?>">
	<style>
		/* Fix WordPress admin wrapper for Vue app */
		/* Global override for all fchub-stream pages */
		body[class*="fchub-stream"] #wpcontent {
			padding-left: 0 !important;
			padding-right: 0 !important;
		}
		body[class*="fchub-stream"] #wpbody-content {
			padding-bottom: 0 !important;
			float: none !important;
			width: auto !important;
			overflow: visible !important;
		}
		/* Specific selectors for compatibility */
		body.toplevel_page_fchub-stream #wpcontent,
		body.toplevel_page_fchub-stream-settings #wpcontent {
			padding-left: 0 !important;
			padding-right: 0 !important;
		}
		body.toplevel_page_fchub-stream #wpbody-content,
		body.toplevel_page_fchub-stream-settings #wpbody-content {
			padding-bottom: 0 !important;
			float: none !important;
			width: auto !important;
			overflow: visible !important;
		}
		/* Additional WordPress admin overrides */
		body[class*="fchub-stream"] #wpcontent .wrap,
		body.toplevel_page_fchub-stream #wpcontent .wrap,
		body.toplevel_page_fchub-stream-settings #wpcontent .wrap {
			margin: 0 !important;
			padding: 0 !important;
		}
		body[class*="fchub-stream"] #wpbody-content .wrap,
		body.toplevel_page_fchub-stream #wpbody-content .wrap,
		body.toplevel_page_fchub-stream-settings #wpbody-content .wrap {
			margin: 0 !important;
			padding: 0 !important;
		}
		/* Ensure Tailwind utilities work - override WordPress admin styles */
		#app .flex { display: flex !important; }
		#app .grid { display: grid !important; }
		#app .hidden { display: none !important; }
		#app .bg-gray-50 { background-color: rgb(249, 250, 251) !important; }
		#app .bg-white { background-color: rgb(255, 255, 255) !important; }
		#app .min-h-screen { min-height: 100vh !important; }
		#app .p-4 { padding: 1rem !important; }
		#app .p-8 { padding: 2rem !important; }
		#app .px-4 { padding-left: 1rem !important; padding-right: 1rem !important; }
		#app .px-6 { padding-left: 1.5rem !important; padding-right: 1.5rem !important; }
		#app .py-3 { padding-top: 0.75rem !important; padding-bottom: 0.75rem !important; }
		#app .mt-0\.5 { margin-top: 0.125rem !important; }
		#app .w-8 { width: 2rem !important; }
		#app .h-8 { height: 2rem !important; }
		#app .w-10 { width: 2.5rem !important; }
		#app .h-10 { height: 2.5rem !important; }
		#app .w-5 { width: 1.25rem !important; }
		#app .h-5 { height: 1.25rem !important; }
		#app .w-12 { width: 3rem !important; }
		#app .h-12 { height: 3rem !important; }
		#app .w-20 { width: 5rem !important; }
		#app .h-20 { height: 5rem !important; }
		/* SVG icons - force proper sizing */
		#app svg { display: block !important; max-width: 100% !important; max-height: 100% !important; }
		#app .w-3\.5 svg { width: 0.875rem !important; height: 0.875rem !important; }
		#app .w-4 svg { width: 1rem !important; height: 1rem !important; }
		#app .w-5 svg { width: 1.25rem !important; height: 1.25rem !important; }
		#app .w-12 svg { width: 3rem !important; height: 3rem !important; }
		#app svg.w-3\.5 { width: 0.875rem !important; height: 0.875rem !important; }
		#app svg.w-4 { width: 1rem !important; height: 1rem !important; }
		#app svg.w-5 { width: 1.25rem !important; height: 1.25rem !important; }
		#app svg.w-12 { width: 3rem !important; height: 3rem !important; }
		/* Typography reset - override WordPress styles */
		/* Reset all HTML elements */
		#app *,
		#app *::before,
		#app *::after {
			box-sizing: border-box !important;
		}
		/* Headings - reset margins and override WordPress color */
		#app h1, #app h2, #app h3, #app h4, #app h5, #app h6 {
			margin-top: 0 !important;
			margin-bottom: 0 !important;
			font-weight: inherit !important;
			line-height: inherit !important;
			font-size: inherit !important;
			/* Remove WordPress admin color - let Tailwind classes control */
		}
		/* Specific override for WordPress admin h1 color */
		body.toplevel_page_fchub-stream #app h1,
		body.toplevel_page_fchub-stream #app h2,
		body.toplevel_page_fchub-stream #app h3,
		body.toplevel_page_fchub-stream #app h4,
		body.toplevel_page_fchub-stream #app h5,
		body.toplevel_page_fchub-stream #app h6,
		body.toplevel_page_fchub-stream-settings #app h1,
		body.toplevel_page_fchub-stream-settings #app h2,
		body.toplevel_page_fchub-stream-settings #app h3,
		body.toplevel_page_fchub-stream-settings #app h4,
		body.toplevel_page_fchub-stream-settings #app h5,
		body.toplevel_page_fchub-stream-settings #app h6 {
			color: unset !important;
		}
		/* Force white color for on-primary classes on headings */
		body.toplevel_page_fchub-stream #app h1.text-onprimary,
		body.toplevel_page_fchub-stream #app h2.text-onprimary,
		body.toplevel_page_fchub-stream #app h3.text-onprimary,
		body.toplevel_page_fchub-stream #app h4.text-onprimary,
		body.toplevel_page_fchub-stream #app h5.text-onprimary,
		body.toplevel_page_fchub-stream #app h6.text-onprimary,
		body.toplevel_page_fchub-stream-settings #app h1.text-onprimary,
		body.toplevel_page_fchub-stream-settings #app h2.text-onprimary,
		body.toplevel_page_fchub-stream-settings #app h3.text-onprimary,
		body.toplevel_page_fchub-stream-settings #app h4.text-onprimary,
		body.toplevel_page_fchub-stream-settings #app h5.text-onprimary,
		body.toplevel_page_fchub-stream-settings #app h6.text-onprimary {
			color: #ffffff !important;
		}
		/* Fallback: if class doesn't exist, use direct selector */
		body.toplevel_page_fchub-stream #app .bg-gradient-to-br h1,
		body.toplevel_page_fchub-stream #app .bg-gradient-to-br h2,
		body.toplevel_page_fchub-stream #app .bg-gradient-to-br h3,
		body.toplevel_page_fchub-stream-settings #app .bg-gradient-to-br h1,
		body.toplevel_page_fchub-stream-settings #app .bg-gradient-to-br h2,
		body.toplevel_page_fchub-stream-settings #app .bg-gradient-to-br h3 {
			color: #ffffff !important;
		}
		/* Paragraphs */
		#app p {
			margin-top: 0 !important;
			margin-bottom: 0 !important;
			font-size: inherit !important;
			line-height: inherit !important;
			/* Don't reset color - let Tailwind classes control */
		}
		/* Pre and code */
		#app pre, #app code {
			margin: 0 !important;
			padding: 0 !important;
			font-size: inherit !important;
			font-family: inherit !important;
			line-height: inherit !important;
		}
		/* Lists */
		#app ul, #app ol, #app li {
			margin: 0 !important;
			padding: 0 !important;
			list-style: none !important;
		}
		/* Links */
		#app a {
			text-decoration: none !important;
			/* Don't reset color - let Tailwind classes control */
		}
		/* Buttons - reset WordPress defaults but allow Tailwind classes */
		#app button {
			font-size: inherit !important;
			font-family: inherit !important;
			line-height: inherit !important;
			/* Don't reset margin/padding/background/border - let Tailwind control */
		}
		/* Form elements */
		#app input, #app textarea, #app select {
			margin: 0 !important;
			font-size: inherit !important;
			font-family: inherit !important;
			line-height: inherit !important;
		}
		/* Tailwind color utilities - ensure they override WordPress */
		#app .text-onprimary,
		body.toplevel_page_fchub-stream #app .text-onprimary,
		body.toplevel_page_fchub-stream-settings #app .text-onprimary {
			color: #ffffff !important;
		}
		#app .text-onprimarylight,
		body.toplevel_page_fchub-stream #app .text-onprimarylight,
		body.toplevel_page_fchub-stream-settings #app .text-onprimarylight {
			color: rgba(255, 255, 255, 0.8) !important;
		}
		#app .text-onprimarymuted,
		body.toplevel_page_fchub-stream #app .text-onprimarymuted,
		body.toplevel_page_fchub-stream-settings #app .text-onprimarymuted {
			color: rgba(255, 255, 255, 0.6) !important;
		}
		#app .text-gray-900 { color: #111827 !important; }
		#app .text-gray-500 { color: #6b7280 !important; }
		#app .text-gray-600 { color: #4b5563 !important; }
		#app .text-gray-700 { color: #374151 !important; }
		#app .text-primary-600 { color: #9333ea !important; }
		#app .text-primary-700 { color: #9333ea !important; }
		#app .text-primary-800 { color: #7e22ce !important; }
		#app .text-primary-900 { color: #581c87 !important; }
		#app .text-xs { font-size: 0.75rem !important; line-height: 1.5 !important; }
		#app .text-sm { font-size: 0.875rem !important; line-height: 1.5 !important; }
		#app .text-base { font-size: 1rem !important; line-height: 1.5 !important; }
		#app .text-2xl { font-size: 1.5rem !important; line-height: 1.2 !important; }
		#app .font-semibold { font-weight: 600 !important; }
		#app .font-bold { font-weight: 700 !important; }
		#app .font-medium { font-weight: 500 !important; }
		#app .mb-1 { margin-bottom: 0.25rem !important; }
		#app .mb-2 { margin-bottom: 0.5rem !important; }
		#app .mb-3 { margin-bottom: 0.75rem !important; }
		#app .mb-4 { margin-bottom: 1rem !important; }
		#app .mt-0\.5 { margin-top: 0.125rem !important; }
		#app .leading-none { line-height: 1 !important; }
	</style>
</head>
<body>
	<div id="app"></div>

	<script>
		// Pass WordPress data to Vue app.
		<?php
		// Get component from filter (set by load_vue_app method).
		$fchub_stream_active_component = apply_filters( 'fchub_stream_admin_component', 'welcome' );

		// Fallback: if filter didn't work, check $_GET['page'] directly.
		// This handles cases where filter might not be applied correctly.
		if ( 'welcome' === $fchub_stream_active_component ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading page slug for component routing only.
			$fchub_stream_current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			if ( 'fchub-stream-settings' === $fchub_stream_current_page ) {
				$fchub_stream_active_component = 'settings';
			}
		}
		?>
		window.fchubStream = {
			restUrl: '<?php echo esc_url( rest_url( 'fluent-community/v2/stream' ) ); ?>',
			nonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
			ajaxUrl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
			pluginUrl: '<?php echo esc_url( FCHUB_STREAM_URL ); ?>',
			version: '<?php echo esc_js( FCHUB_STREAM_VERSION ); ?>',
			activeComponent: '<?php echo esc_js( $fchub_stream_active_component ); ?>'
		};
	</script>
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Vue.js SPA template requires direct script inclusion. ?>
	<script type="module" src="<?php echo esc_url( $fchub_stream_asset_path . 'main.js?ver=' . $fchub_stream_version . '&t=' . time() ); ?>"></script>
</body>
</html>
<?php
exit;