/**
 * Hotspot Editor — vanilla JS module for visual hotspot placement.
 *
 * Injected into the ACF gallery sidebar (Edit Image form) on the
 * h3vt_tour edit screen, where the floorplan hotspot fields live as
 * attachment fields. Reads floorplan data and previously saved hotspot
 * placements from wp_localize_script (h3vtHotspotData).
 */
( function () {
	'use strict';

	var floorplans = ( window.h3vtHotspotData && window.h3vtHotspotData.floorplans ) || [];
	var savedHotspots = ( window.h3vtHotspotData && window.h3vtHotspotData.hotspots ) || {};

	/**
	 * Get the floorplan image URL by index.
	 */
	function getFloorplanImage( index ) {
		for ( var i = 0; i < floorplans.length; i++ ) {
			if ( String( floorplans[ i ].index ) === String( index ) ) {
				return floorplans[ i ].image || '';
			}
		}
		return '';
	}

	/**
	 * Find the hotspot input fields within a gallery sidebar form.
	 */
	function getSidebarFields( container ) {
		var selectField = container.querySelector( '.h3vt-hotspot-floorplan-select select' );
		var xInput = container.querySelector( '.h3vt-hotspot-x-field input[type="number"]' );
		var yInput = container.querySelector( '.h3vt-hotspot-y-field input[type="number"]' );
		return { selectField: selectField, xInput: xInput, yInput: yInput };
	}

	/**
	 * Parse the attachment ID from a sidebar input name, e.g.
	 * "attachments[123][field_h3vt_attachment_slide_floorplan]".
	 */
	function getAttachmentId( fields ) {
		var name = fields.selectField ? fields.selectField.getAttribute( 'name' ) || '' : '';
		var match = name.match( /attachments\[(\d+)\]/ );
		return match ? match[ 1 ] : '';
	}

	/**
	 * Collect saved hotspot positions of the other slides for context dots.
	 */
	function getOtherHotspots( currentAttachmentId, fpIndex ) {
		var dots = [];
		Object.keys( savedHotspots ).forEach( function ( attId ) {
			if ( String( attId ) === String( currentAttachmentId ) ) {
				return;
			}
			var spot = savedHotspots[ attId ];
			if ( spot && String( spot.floorplan ) === String( fpIndex ) ) {
				dots.push( { x: parseFloat( spot.x ), y: parseFloat( spot.y ) } );
			}
		} );
		return dots;
	}

	/**
	 * Initialize the hotspot editor within a gallery sidebar form.
	 */
	function initEditor( container ) {
		var fields = getSidebarFields( container );
		if ( ! fields.selectField || fields.selectField.dataset.h3vtHotspotInit ) {
			return;
		}
		fields.selectField.dataset.h3vtHotspotInit = '1';

		var attachmentId = getAttachmentId( fields );

		// Create editor container after the select field's wrapper.
		var editorWrap = document.createElement( 'div' );
		editorWrap.className = 'h3vt-hotspot-editor';

		var selectWrapper = fields.selectField.closest( '.h3vt-hotspot-floorplan-select' );
		if ( selectWrapper ) {
			selectWrapper.parentNode.insertBefore( editorWrap, selectWrapper.nextSibling );
		} else {
			fields.selectField.parentNode.appendChild( editorWrap );
		}

		/**
		 * Render the editor contents for the current floorplan selection.
		 */
		function renderEditor() {
			editorWrap.innerHTML = '';

			var fpIndex = fields.selectField.value;
			if ( ! fpIndex && fpIndex !== 0 && fpIndex !== '0' ) {
				editorWrap.innerHTML = '<div class="h3vt-hotspot-editor__empty">Select a floor plan above to place a hotspot.</div>';
				return;
			}

			var imgUrl = getFloorplanImage( fpIndex );
			if ( ! imgUrl ) {
				editorWrap.innerHTML = '<div class="h3vt-hotspot-editor__empty">No image available for this floor plan.</div>';
				return;
			}

			// Floorplan image.
			var img = document.createElement( 'img' );
			img.src = imgUrl;
			img.alt = 'Floor plan';
			editorWrap.appendChild( img );

			// Other slides' hotspots (context dots).
			var otherDots = getOtherHotspots( attachmentId, fpIndex );
			otherDots.forEach( function ( dot ) {
				var el = document.createElement( 'div' );
				el.className = 'h3vt-hotspot-editor__dot h3vt-hotspot-editor__dot--other';
				el.style.left = dot.x + '%';
				el.style.top = dot.y + '%';
				editorWrap.appendChild( el );
			} );

			// Active hotspot dot.
			var activeDot = null;
			if ( fields.xInput && fields.yInput && fields.xInput.value && fields.yInput.value ) {
				activeDot = document.createElement( 'div' );
				activeDot.className = 'h3vt-hotspot-editor__dot';
				activeDot.style.left = parseFloat( fields.xInput.value ) + '%';
				activeDot.style.top = parseFloat( fields.yInput.value ) + '%';
				editorWrap.appendChild( activeDot );
			}

			// Click handler to place/move the hotspot.
			editorWrap.addEventListener( 'click', function ( e ) {
				var rect = img.getBoundingClientRect();
				var x = ( ( e.clientX - rect.left ) / rect.width ) * 100;
				var y = ( ( e.clientY - rect.top ) / rect.height ) * 100;

				// Clamp to 0–100.
				x = Math.max( 0, Math.min( 100, x ) );
				y = Math.max( 0, Math.min( 100, y ) );

				// Round to 1 decimal.
				x = Math.round( x * 10 ) / 10;
				y = Math.round( y * 10 ) / 10;

				// Update the hidden ACF number fields.
				if ( fields.xInput ) {
					fields.xInput.value = x;
					fields.xInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				if ( fields.yInput ) {
					fields.yInput.value = y;
					fields.yInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}

				// Keep the local context map fresh so switching images
				// within this session shows the new placement.
				if ( attachmentId ) {
					savedHotspots[ attachmentId ] = {
						floorplan: String( fields.selectField.value ),
						x: x,
						y: y,
					};
				}

				// Move or create the active dot.
				if ( ! activeDot ) {
					activeDot = document.createElement( 'div' );
					activeDot.className = 'h3vt-hotspot-editor__dot';
					editorWrap.appendChild( activeDot );
				}
				activeDot.style.left = x + '%';
				activeDot.style.top = y + '%';
			} );
		}

		// Initial render.
		renderEditor();

		// Re-render when floorplan selection changes.
		fields.selectField.addEventListener( 'change', renderEditor );
	}

	/**
	 * Scan for uninitialized sidebar forms.
	 */
	function initAll() {
		var selects = document.querySelectorAll( '.h3vt-hotspot-floorplan-select select' );
		selects.forEach( function ( select ) {
			var sideData = select.closest( '.acf-gallery-side-data' ) || select.closest( '.acf-gallery-side' );
			if ( sideData ) {
				initEditor( sideData );
			}
		} );
	}

	/**
	 * The gallery sidebar loads its fields via AJAX each time an image is
	 * selected — watch the document for the hotspot fields appearing.
	 */
	function observeSidebars() {
		var observer = new MutationObserver( function () {
			initAll();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	// Hook into ACF ready event.
	if ( window.acf ) {
		window.acf.addAction( 'ready', function () {
			initAll();
			observeSidebars();
		} );
	} else {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
			observeSidebars();
		} );
	}
} )();
