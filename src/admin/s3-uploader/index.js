/**
 * H3VT Tours — S3 Direct Upload integration for ACF image/file fields.
 *
 * Hooks into ACF's JS API to replace native media pickers with a custom
 * dropzone that uploads directly to S3 via presigned URLs.
 */

/* global acf, h3vtS3Config, jQuery */

( function ( $, acf ) {
	'use strict';

	if ( ! window.h3vtS3Config || ! h3vtS3Config.enabled ) {
		return;
	}

	/**
	 * ACF field keys that should use the S3 uploader.
	 * Matches both h3vt_tour and h3vt_tour_template post type fields.
	 */
	const H3VT_FIELD_NAMES = [
		'hero_image',
		'hero_video',
		'slide_image',
		'logo',
		'icon',
		'floorplan_image',
		'thumbnail',
	];

	/**
	 * Check if a field should use the S3 uploader.
	 *
	 * @param {Object} field ACF field jQuery wrapper.
	 * @return {boolean}
	 */
	function isH3vtField( field ) {
		const fieldName = field.data( 'name' ) || field.attr( 'data-name' ) || '';
		return H3VT_FIELD_NAMES.indexOf( fieldName ) !== -1;
	}

	/**
	 * Get accepted MIME types based on ACF field type.
	 *
	 * @param {string} fieldType 'image' or 'file'.
	 * @return {string} Comma-separated MIME types for accept attribute.
	 */
	function getAcceptTypes( fieldType ) {
		if ( fieldType === 'file' ) {
			return h3vtS3Config.allowed.video.join( ',' );
		}
		return h3vtS3Config.allowed.image.join( ',' );
	}

	/**
	 * Get all allowed types as a flat array.
	 *
	 * @return {string[]}
	 */
	function getAllAllowedTypes() {
		return [].concat( h3vtS3Config.allowed.image, h3vtS3Config.allowed.video );
	}

	/**
	 * Format file size for display.
	 *
	 * @param {number} bytes File size in bytes.
	 * @return {string}
	 */
	function formatSize( bytes ) {
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		if ( bytes < 1048576 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}
		return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
	}

	/**
	 * Create the S3 uploader UI for a single ACF field.
	 *
	 * @param {jQuery} $field ACF field wrapper element.
	 */
	function initFieldUploader( $field ) {
		// Avoid double-init.
		if ( $field.find( '.h3vt-s3-uploader' ).length ) {
			return;
		}

		const fieldType = $field.data( 'type' ); // 'image' or 'file'
		const acceptTypes = getAcceptTypes( fieldType );

		// Hide ACF's native controls.
		$field.find( '.acf-image-uploader .hide-if-value, .acf-file-uploader .hide-if-value' ).hide();
		$field.find( '.acf-image-uploader .show-if-value, .acf-file-uploader .show-if-value' ).hide();

		// Build uploader HTML.
		const $container = $( '<div class="h3vt-s3-uploader h3vt-s3-uploader--active"></div>' );

		const $dropzone = $( [
			'<div class="h3vt-s3-uploader__dropzone">',
			'  <div class="h3vt-s3-uploader__dropzone-text">',
			'    <strong>Drop file here or click to browse</strong>',
			'    Max size: ' + formatSize( h3vtS3Config.maxSize ) + ' &mdash; Uploads directly to S3',
			'  </div>',
			'  <input type="file" class="h3vt-s3-uploader__dropzone-input" accept="' + acceptTypes + '">',
			'</div>',
		].join( '\n' ) );

		const $progress = $( [
			'<div class="h3vt-s3-uploader__progress">',
			'  <div class="h3vt-s3-uploader__progress-bar-wrapper">',
			'    <div class="h3vt-s3-uploader__progress-bar"></div>',
			'  </div>',
			'  <div class="h3vt-s3-uploader__progress-text"></div>',
			'</div>',
		].join( '\n' ) );

		const $preview = $( '<div class="h3vt-s3-uploader__preview" style="display:none;"></div>' );
		const $error = $( '<div class="h3vt-s3-uploader__error"></div>' );

		$container.append( $dropzone, $progress, $preview, $error );

		// Insert before ACF's native uploader elements.
		const $acfUploader = $field.find( '.acf-image-uploader, .acf-file-uploader' );
		if ( $acfUploader.length ) {
			$acfUploader.prepend( $container );
		} else {
			$field.find( '.acf-input' ).prepend( $container );
		}

		// Load existing S3 value if present.
		loadExistingValue( $field, $preview, $dropzone );

		// File input change handler.
		$dropzone.find( 'input[type=file]' ).on( 'change', function () {
			if ( this.files && this.files[ 0 ] ) {
				handleFile( this.files[ 0 ], $field, $dropzone, $progress, $preview, $error );
			}
		} );

		// Drag and drop.
		$dropzone
			.on( 'dragenter dragover', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$( this ).addClass( 'h3vt-s3-uploader__dropzone--active' );
			} )
			.on( 'dragleave drop', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$( this ).removeClass( 'h3vt-s3-uploader__dropzone--active' );
			} )
			.on( 'drop', function ( e ) {
				const files = e.originalEvent.dataTransfer.files;
				if ( files && files[ 0 ] ) {
					handleFile( files[ 0 ], $field, $dropzone, $progress, $preview, $error );
				}
			} );
	}

	/**
	 * Load existing S3 value on page load.
	 *
	 * @param {jQuery} $field   ACF field wrapper.
	 * @param {jQuery} $preview Preview container.
	 * @param {jQuery} $dropzone Dropzone container.
	 */
	function loadExistingValue( $field, $preview, $dropzone ) {
		// First, check for the server-rendered S3 data attribute.
		// ACF renders the hidden input with just the ID (0), so JSON won't be in the input.
		const $s3Data = $field.find( '.h3vt-s3-data[data-s3-value]' ).first();
		if ( $s3Data.length ) {
			let s3Value;
			try {
				s3Value = JSON.parse( $s3Data.attr( 'data-s3-value' ) );
			} catch ( e ) {
				// Invalid JSON — fall through.
			}

			if ( s3Value && s3Value.url && s3Value.id === 0 ) {
				storeValue( $field, s3Value );
				showPreview( s3Value, $field, $preview, $dropzone );
				return;
			}
		}

		// Fallback: check the hidden input for a JSON value (e.g. just uploaded).
		const $input = $field.find( 'input[type=hidden].acf-image-value, input[type=hidden][data-name="value"]' ).first();

		if ( ! $input.length ) {
			return;
		}

		const rawValue = $input.val();
		if ( ! rawValue || rawValue === '0' || rawValue === '' ) {
			return;
		}

		// Try to parse as JSON (S3 value).
		let data;
		try {
			data = JSON.parse( rawValue );
		} catch ( e ) {
			// It's a regular attachment ID — let ACF handle it.
			return;
		}

		if ( ! data || ! data.url || data.id !== 0 ) {
			return;
		}

		// Show the S3 preview.
		showPreview( data, $field, $preview, $dropzone );
	}

	/**
	 * Handle a selected file — validate, presign, upload, store.
	 *
	 * @param {File}   file      The selected File object.
	 * @param {jQuery} $field    ACF field wrapper.
	 * @param {jQuery} $dropzone Dropzone container.
	 * @param {jQuery} $progress Progress container.
	 * @param {jQuery} $preview  Preview container.
	 * @param {jQuery} $error    Error container.
	 */
	function handleFile( file, $field, $dropzone, $progress, $preview, $error ) {
		// Clear previous state.
		hideError( $error );
		$preview.hide().empty();

		// Validate type.
		if ( getAllAllowedTypes().indexOf( file.type ) === -1 ) {
			showError( $error, 'File type "' + file.type + '" is not allowed.' );
			return;
		}

		// Validate size.
		if ( file.size > h3vtS3Config.maxSize ) {
			showError( $error, 'File is too large (' + formatSize( file.size ) + '). Maximum is ' + formatSize( h3vtS3Config.maxSize ) + '.' );
			return;
		}

		// Hide dropzone, show progress.
		$dropzone.hide();
		$progress.addClass( 'h3vt-s3-uploader__progress--active' );
		setProgress( $progress, 0, 'Requesting upload URL...' );

		// Step 1: Get presigned URL via AJAX.
		$.ajax( {
			url: h3vtS3Config.ajaxUrl,
			method: 'POST',
			data: {
				action: 'h3vt_tours_s3_presign',
				_ajax_nonce: h3vtS3Config.nonce,
				filename: file.name,
				content_type: file.type,
				post_id: h3vtS3Config.postId,
			},
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					showError( $error, response.data ? response.data.message : 'Failed to get presigned URL.' );
					resetUploader( $dropzone, $progress );
					return;
				}

				setProgress( $progress, 5, 'Uploading to S3...' );

				// Step 2: Upload directly to S3.
				uploadToS3(
					file,
					response.data.presigned_url,
					response.data.object_url,
					response.data.object_key,
					$field,
					$dropzone,
					$progress,
					$preview,
					$error
				);
			} )
			.fail( function ( xhr ) {
				let msg = 'Failed to request presigned URL.';
				try {
					const resp = JSON.parse( xhr.responseText );
					if ( resp.data && resp.data.message ) {
						msg = resp.data.message;
					}
				} catch ( e ) {
					// Use default message.
				}
				showError( $error, msg );
				resetUploader( $dropzone, $progress );
			} );
	}

	/**
	 * Upload a file directly to S3 via XHR with progress tracking.
	 *
	 * @param {File}   file         The file to upload.
	 * @param {string} presignedUrl Presigned PUT URL.
	 * @param {string} objectUrl    Public object URL.
	 * @param {string} objectKey    S3 object key.
	 * @param {jQuery} $field       ACF field wrapper.
	 * @param {jQuery} $dropzone    Dropzone container.
	 * @param {jQuery} $progress    Progress container.
	 * @param {jQuery} $preview     Preview container.
	 * @param {jQuery} $error       Error container.
	 */
	function uploadToS3( file, presignedUrl, objectUrl, objectKey, $field, $dropzone, $progress, $preview, $error ) {
		const xhr = new XMLHttpRequest();

		xhr.upload.addEventListener( 'progress', function ( e ) {
			if ( e.lengthComputable ) {
				const pct = Math.round( ( e.loaded / e.total ) * 90 ) + 5; // 5-95%
				setProgress( $progress, pct, 'Uploading... ' + formatSize( e.loaded ) + ' / ' + formatSize( e.total ) );
			}
		} );

		xhr.addEventListener( 'load', function () {
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				setProgress( $progress, 100, 'Upload complete!' );

				// Build ACF-compatible value.
				buildAcfValue( file, objectUrl, function ( acfValue ) {
					// Store in the hidden input.
					storeValue( $field, acfValue );

					// Show preview.
					showPreview( acfValue, $field, $preview, $dropzone );

					// Hide progress after brief delay.
					setTimeout( function () {
						$progress.removeClass( 'h3vt-s3-uploader__progress--active' );
					}, 500 );
				} );
			} else {
				showError( $error, 'S3 upload failed with HTTP ' + xhr.status + '.' );
				resetUploader( $dropzone, $progress );
			}
		} );

		xhr.addEventListener( 'error', function () {
			showError( $error, 'S3 upload failed. Check CORS configuration on your S3 bucket.' );
			resetUploader( $dropzone, $progress );
		} );

		xhr.open( 'PUT', presignedUrl, true );
		xhr.setRequestHeader( 'Content-Type', file.type );
		xhr.send( file );
	}

	/**
	 * Build an ACF-compatible value object from an uploaded file.
	 *
	 * @param {File}     file      The uploaded file.
	 * @param {string}   objectUrl The S3 public URL.
	 * @param {Function} callback  Callback receiving the value object.
	 */
	function buildAcfValue( file, objectUrl, callback ) {
		const value = {
			id: 0,
			url: objectUrl,
			alt: '',
			title: file.name.replace( /\.[^.]+$/, '' ),
			filename: file.name,
			mime_type: file.type,
			width: 0,
			height: 0,
		};

		// For images, read dimensions.
		if ( file.type.indexOf( 'image/' ) === 0 && file.type !== 'image/svg+xml' ) {
			const img = new Image();
			const objectURL = URL.createObjectURL( file );

			img.onload = function () {
				value.width = img.naturalWidth;
				value.height = img.naturalHeight;
				URL.revokeObjectURL( objectURL );
				callback( value );
			};

			img.onerror = function () {
				URL.revokeObjectURL( objectURL );
				callback( value );
			};

			img.src = objectURL;
		} else {
			callback( value );
		}
	}

	/**
	 * Store the S3 value into the ACF field's hidden input.
	 *
	 * @param {jQuery} $field ACF field wrapper.
	 * @param {Object} value  ACF-compatible value object.
	 */
	function storeValue( $field, value ) {
		// ACF image fields use an input with class 'acf-image-value' or data-name="value".
		const $input = $field.find( 'input[type=hidden]' ).filter( function () {
			return $( this ).hasClass( 'acf-image-value' ) ||
				$( this ).attr( 'data-name' ) === 'value' ||
				$( this ).attr( 'data-name' ) === 'id';
		} ).first();

		if ( $input.length ) {
			$input.val( JSON.stringify( value ) ).trigger( 'change' );
		}
	}

	/**
	 * Display a preview of the uploaded/existing S3 media.
	 *
	 * @param {Object} data      The stored value object.
	 * @param {jQuery} $field    ACF field wrapper.
	 * @param {jQuery} $preview  Preview container.
	 * @param {jQuery} $dropzone Dropzone container.
	 */
	function showPreview( data, $field, $preview, $dropzone ) {
		$preview.empty();
		$dropzone.hide();

		const $removeBtn = $( '<button type="button" class="h3vt-s3-uploader__remove-btn" title="Remove">&times;</button>' );

		if ( data.mime_type && data.mime_type.indexOf( 'video/' ) === 0 ) {
			// Video preview.
			const $videoPreview = $( [
				'<div class="h3vt-s3-uploader__preview-video">',
				'  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#646970" stroke-width="2">',
				'    <polygon points="5 3 19 12 5 21 5 3"></polygon>',
				'  </svg>',
				'  <span class="h3vt-s3-uploader__preview-filename">' + escapeHtml( data.filename || 'video' ) + '</span>',
				'</div>',
			].join( '\n' ) );
			$preview.append( $removeBtn, $videoPreview );
		} else {
			// Image preview.
			const $img = $( '<img>' )
				.attr( 'src', data.url )
				.attr( 'alt', data.alt || '' );
			const $filename = $( '<div class="h3vt-s3-uploader__preview-filename"></div>' )
				.text( data.filename || '' );
			$preview.append( $removeBtn, $img, $filename );
		}

		$preview.show();

		// Remove handler.
		$removeBtn.on( 'click', function () {
			$preview.hide().empty();
			clearValue( $field );
			$dropzone.show();
			// Reset the file input.
			$dropzone.find( 'input[type=file]' ).val( '' );
		} );
	}

	/**
	 * Clear the stored value in the ACF field.
	 *
	 * @param {jQuery} $field ACF field wrapper.
	 */
	function clearValue( $field ) {
		const $input = $field.find( 'input[type=hidden]' ).filter( function () {
			return $( this ).hasClass( 'acf-image-value' ) ||
				$( this ).attr( 'data-name' ) === 'value' ||
				$( this ).attr( 'data-name' ) === 'id';
		} ).first();

		if ( $input.length ) {
			$input.val( '' ).trigger( 'change' );
		}
	}

	/**
	 * Show an error message.
	 *
	 * @param {jQuery} $error   Error container.
	 * @param {string} message  Error text.
	 */
	function showError( $error, message ) {
		$error.text( message ).addClass( 'h3vt-s3-uploader__error--visible' );
	}

	/**
	 * Hide the error message.
	 *
	 * @param {jQuery} $error Error container.
	 */
	function hideError( $error ) {
		$error.text( '' ).removeClass( 'h3vt-s3-uploader__error--visible' );
	}

	/**
	 * Set progress bar value and text.
	 *
	 * @param {jQuery} $progress Progress container.
	 * @param {number} pct       Percentage (0-100).
	 * @param {string} text      Status text.
	 */
	function setProgress( $progress, pct, text ) {
		$progress.find( '.h3vt-s3-uploader__progress-bar' ).css( 'width', pct + '%' );
		$progress.find( '.h3vt-s3-uploader__progress-text' ).text( text );
	}

	/**
	 * Reset the uploader UI after an error.
	 *
	 * @param {jQuery} $dropzone Dropzone container.
	 * @param {jQuery} $progress Progress container.
	 */
	function resetUploader( $dropzone, $progress ) {
		$progress.removeClass( 'h3vt-s3-uploader__progress--active' );
		$dropzone.show();
		$dropzone.find( 'input[type=file]' ).val( '' );
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} str Raw string.
	 * @return {string} Escaped string.
	 */
	function escapeHtml( str ) {
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	/**
	 * Initialize the S3 uploader on an ACF field.
	 *
	 * @param {Object} field ACF field instance.
	 */
	function onFieldReady( field ) {
		const $el = field.$el || $( field );
		if ( ! isH3vtField( $el ) ) {
			return;
		}
		initFieldUploader( $el );
	}

	// Hook into ACF field ready events.
	if ( acf && acf.addAction ) {
		acf.addAction( 'ready_field/type=image', onFieldReady );
		acf.addAction( 'ready_field/type=file', onFieldReady );
		acf.addAction( 'append_field/type=image', onFieldReady );
		acf.addAction( 'append_field/type=file', onFieldReady );
	}
} )( jQuery, window.acf );

/**
 * Client-side ACF validation override for S3-stored image/file fields.
 *
 * ACF's native validation rejects image fields with id=0. This filter
 * intercepts validation and accepts S3 values (JSON with url + id=0).
 */
( function ( acf ) {
	'use strict';

	if ( ! acf || ! acf.addFilter ) {
		return;
	}

	acf.addFilter( 'validation_complete', function ( json, $form ) {
		if ( ! json || ! json.errors || ! json.errors.length ) {
			return json;
		}

		// Filter out errors for fields that have valid S3 values.
		json.errors = json.errors.filter( function ( error ) {
			var $input = $form.find( '[name="' + error.input + '"]' );
			if ( ! $input.length ) {
				return true; // Keep error — can't find input.
			}

			var val = $input.val();
			if ( ! val || val === '0' || val === '' ) {
				return true; // Keep error — genuinely empty.
			}

			// Check if it's a valid S3 JSON value.
			try {
				var parsed = JSON.parse( val );
				if ( parsed && parsed.url && parsed.id === 0 ) {
					return false; // Remove error — valid S3 value.
				}
			} catch ( e ) {
				// Not JSON — keep the error.
			}

			return true;
		} );

		return json;
	} );
} )( window.acf );
