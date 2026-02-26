/**
 * Hotspot Editor — vanilla JS module for visual hotspot placement.
 *
 * Injected into ACF slide repeater rows on the h3vt_tour edit screen.
 * Reads floorplan data from wp_localize_script (h3vtHotspotData).
 */
( function () {
	'use strict';

	var floorplans = ( window.h3vtHotspotData && window.h3vtHotspotData.floorplans ) || [];

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
	 * Find ACF input fields within a slide row.
	 */
	function getRowFields( row ) {
		var selectField = row.querySelector( '.h3vt-hotspot-floorplan-select select' );
		var xInput = row.querySelector( '.h3vt-hotspot-x-field input[type="number"]' );
		var yInput = row.querySelector( '.h3vt-hotspot-y-field input[type="number"]' );
		return { selectField: selectField, xInput: xInput, yInput: yInput };
	}

	/**
	 * Collect all hotspot positions from other slide rows for the given floorplan index.
	 */
	function getOtherHotspots( currentRow, fpIndex ) {
		var dots = [];
		var allRows = document.querySelectorAll( '.acf-row:not(.acf-clone)' );

		allRows.forEach( function ( row ) {
			if ( row === currentRow ) {
				return;
			}
			var fields = getRowFields( row );
			if ( ! fields.selectField || ! fields.xInput || ! fields.yInput ) {
				return;
			}
			if ( String( fields.selectField.value ) === String( fpIndex ) && fields.xInput.value && fields.yInput.value ) {
				dots.push( {
					x: parseFloat( fields.xInput.value ),
					y: parseFloat( fields.yInput.value ),
				} );
			}
		} );

		return dots;
	}

	/**
	 * Initialize the hotspot editor for a single slide row.
	 */
	function initEditor( row ) {
		if ( row.querySelector( '.h3vt-hotspot-editor' ) ) {
			return; // Already initialized.
		}

		var fields = getRowFields( row );
		if ( ! fields.selectField ) {
			return;
		}

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
			var otherDots = getOtherHotspots( row, fpIndex );
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

				// Update ACF hidden fields.
				if ( fields.xInput ) {
					fields.xInput.value = x;
					fields.xInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				if ( fields.yInput ) {
					fields.yInput.value = y;
					fields.yInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
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
	 * Initialize all existing slide rows.
	 */
	function initAllRows() {
		var repeaterField = document.querySelector( '[data-key="field_h3vt_navigation_slides"]' );
		if ( ! repeaterField ) {
			return;
		}

		var rows = repeaterField.querySelectorAll( '.acf-row:not(.acf-clone)' );
		rows.forEach( initEditor );
	}

	/**
	 * Observe ACF repeater for new rows and init editors on them.
	 */
	function observeNewRows() {
		var repeaterField = document.querySelector( '[data-key="field_h3vt_navigation_slides"]' );
		if ( ! repeaterField ) {
			return;
		}

		var tbody = repeaterField.querySelector( '.acf-repeater-rows, tbody' );
		if ( ! tbody ) {
			return;
		}

		var observer = new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType === 1 && node.classList.contains( 'acf-row' ) && ! node.classList.contains( 'acf-clone' ) ) {
						// Short delay to let ACF finish initializing the row.
						setTimeout( function () {
							initEditor( node );
						}, 100 );
					}
				} );
			} );
		} );

		observer.observe( tbody, { childList: true } );
	}

	// Hook into ACF ready event.
	if ( window.acf ) {
		window.acf.addAction( 'ready', function () {
			initAllRows();
			observeNewRows();
		} );
	} else {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAllRows();
			observeNewRows();
		} );
	}
} )();
