/**
 * H3VT Tours - Third-Party Embed Loader
 *
 * Include on any website to embed an H3VT tour:
 *
 *   <script
 *     src="https://example.com/wp-content/plugins/h3vt-tours/assets/js/h3vt-tours-embed.js"
 *     data-tour-id="123">
 *   </script>
 *
 * The script fetches the tour markup from the WordPress REST API,
 * injects the CSS and HTML, then loads the main JS engine.
 *
 * @package H3VT_Tours
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */
(function () {
	'use strict';

	var script = document.currentScript;
	if ( ! script ) {
		return;
	}

	var tourId = script.getAttribute( 'data-tour-id' );

	if ( ! tourId ) {
		console.error( 'H3VT Tours: data-tour-id attribute is required.' );
		return;
	}

	// Derive the host site from this script's own src URL.
	var scriptUrl = new URL( script.src );
	var site      = scriptUrl.origin;

	var apiUrl = site + '/wp-json/h3vt-tours/v1/tour/' + encodeURIComponent( tourId ) + '?format=html';

	// Create the embed container and insert it before the script tag.
	var container       = document.createElement( 'div' );
	container.className = 'h3vt-tour-embed';
	container.style.width    = '100%';
	container.style.height   = '100vh';
	container.style.position = 'relative';
	container.style.overflow = 'hidden';

	script.parentNode.insertBefore( container, script );

	fetch( apiUrl )
		.then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Tour not found (HTTP ' + response.status + ')' );
			}
			return response.json();
		})
		.then( function ( data ) {
			// Inject the tour stylesheet.
			if ( data.css_url ) {
				var link  = document.createElement( 'link' );
				link.rel  = 'stylesheet';
				link.href = data.css_url;
				document.head.appendChild( link );
			}

			// Inject theme stylesheet (if any).
			if ( data.theme_css_url ) {
				var themeLink  = document.createElement( 'link' );
				themeLink.rel  = 'stylesheet';
				themeLink.href = data.theme_css_url;
				document.head.appendChild( themeLink );
			}

			// Insert the tour markup.
			container.innerHTML = data.html;

			// Load the main tour JS engine so it can initialise the new markup.
			if ( data.js_url ) {
				var js  = document.createElement( 'script' );
				js.src  = data.js_url;
				document.body.appendChild( js );
			}

			// Load theme JS (if any).
			if ( data.theme_js_url ) {
				var themeJs  = document.createElement( 'script' );
				themeJs.src  = data.theme_js_url;
				document.body.appendChild( themeJs );
			}
		})
		.catch( function ( err ) {
			container.innerHTML =
				'<p style="color:#999;text-align:center;padding:40px;">Unable to load tour.</p>';
			console.error( 'H3VT Tours embed error:', err );
		});
})();
