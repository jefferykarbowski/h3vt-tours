/**
 * H3VT Tours - Main Slideshow Engine & Interaction Handler
 *
 * Supports multiple tour instances per page. Each `.h3vt-tour` element
 * is initialised independently with its own state, autoplay timer,
 * navigation, modals, and keyboard/touch bindings.
 *
 * @package H3VT_Tours
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */
(function () {
	'use strict';

	/**
	 * Generate a poster image from the first frame of a video URL.
	 *
	 * Loads metadata, seeks just past the start, and snapshots the
	 * frame to a canvas. Returns a JPEG data URL via the callback,
	 * or null if capture is not possible (cross-origin taint, codec
	 * failure, missing browser support, timeout).
	 *
	 * @param {string} videoUrl
	 * @param {function(string|null)} callback
	 */
	function generateVideoPoster( videoUrl, callback ) {
		if ( ! videoUrl || typeof document.createElement !== 'function' ) {
			callback( null );
			return;
		}

		var video = document.createElement( 'video' );
		video.preload     = 'auto';
		video.muted       = true;
		video.playsInline = true;
		video.setAttribute( 'playsinline', '' );
		// Hint cross-origin so canvas reads succeed when the host serves CORS headers.
		video.crossOrigin = 'anonymous';
		video.src = videoUrl;

		var done = false;
		var finish = function ( result ) {
			if ( done ) {
				return;
			}
			done = true;
			try {
				video.removeAttribute( 'src' );
				video.load();
			} catch ( e ) {}
			callback( result );
		};

		var capture = function () {
			try {
				var w = video.videoWidth;
				var h = video.videoHeight;
				if ( ! w || ! h ) {
					finish( null );
					return;
				}
				var canvas = document.createElement( 'canvas' );
				canvas.width  = w;
				canvas.height = h;
				canvas.getContext( '2d' ).drawImage( video, 0, 0, w, h );
				finish( canvas.toDataURL( 'image/jpeg', 0.82 ) );
			} catch ( e ) {
				finish( null );
			}
		};

		video.addEventListener( 'loadedmetadata', function () {
			try {
				var target = Math.min( 0.1, Math.max( 0, ( video.duration || 1 ) - 0.05 ) );
				video.currentTime = target;
			} catch ( e ) {
				capture();
			}
		});
		video.addEventListener( 'seeked', capture );
		video.addEventListener( 'error', function () { finish( null ); });

		// Hard timeout — never block the UI waiting for a slow video.
		setTimeout( function () { finish( null ); }, 6000 );
	}

	/**
	 * Apply an auto-generated poster to a <video> element when it has
	 * none of its own. Safe to call repeatedly — it no-ops if a poster
	 * is already present.
	 *
	 * @param {HTMLVideoElement} videoEl
	 */
	function autoPosterVideo( videoEl ) {
		if ( ! videoEl || videoEl.poster ) {
			return;
		}
		var src = videoEl.currentSrc || videoEl.getAttribute( 'src' ) || '';
		if ( ! src ) {
			var source = videoEl.querySelector( 'source' );
			if ( source ) {
				src = source.src || source.getAttribute( 'src' ) || '';
			}
		}
		if ( ! src ) {
			return;
		}
		generateVideoPoster( src, function ( dataUrl ) {
			if ( dataUrl && ! videoEl.poster ) {
				videoEl.poster = dataUrl;
			}
		});
	}

	document.querySelectorAll( '.h3vt-tour' ).forEach( initTour );

	/**
	 * Initialise a single tour instance.
	 *
	 * @param {HTMLElement} tourEl Root element of the tour.
	 */
	function initTour( tourEl ) {

		/* ---------------------------------------------------------------
		 * 1. State & Element References
		 * ------------------------------------------------------------- */
		var slides       = tourEl.querySelectorAll( '.h3vt-tour__slide' );
		var totalSlides  = slides.length;
		var currentIndex = 0;
		var isPlaying    = true;
		var autoplayTimer = null;
		var autoplaySpeed = parseInt( tourEl.dataset.autoplaySpeed, 10 ) || 8000;

		tourEl.style.setProperty( '--h3vt-autoplay-speed', autoplaySpeed + 'ms' );

		// Modal focus-trap bookkeeping.
		var previouslyFocused = null;
		var wasPlaying        = false;
		var trapKeyHandler    = null;

		if ( totalSlides === 0 ) {
			return;
		}

		// Hero video detection — defer slideshow autoplay; ensure video plays on load.
		var heroVideo = slides[0].querySelector( '.h3vt-tour__slide-video' );
		if ( heroVideo ) {
			autoPosterVideo( heroVideo );
			isPlaying = false;

			var playIcons  = tourEl.querySelectorAll( '.h3vt-tour__icon--play' );
			var pauseIcons = tourEl.querySelectorAll( '.h3vt-tour__icon--pause' );
			playIcons.forEach( function ( el ) {
				el.style.display = '';
			});
			pauseIcons.forEach( function ( el ) {
				el.style.display = 'none';
			});

			// Explicitly trigger playback — some browsers ignore the autoplay attribute.
			var playPromise = heroVideo.play();
			if ( playPromise !== undefined ) {
				playPromise.catch( function () {} );
			}

			heroVideo.addEventListener( 'ended', function () {
				if ( currentIndex !== 0 ) {
					return;
				}
				isPlaying = true;

				playIcons.forEach( function ( el ) {
					el.style.display = 'none';
				});
				pauseIcons.forEach( function ( el ) {
					el.style.display = '';
				});

				nextSlide();
				resetAutoplay();
			});
		}

		/* ---------------------------------------------------------------
		 * 2. Slideshow Engine
		 * ------------------------------------------------------------- */

		/**
		 * Transition to a specific slide by index.
		 *
		 * @param {number} index Zero-based slide index.
		 */
		function goToSlide( index ) {
			if ( index < 0 ) {
				index = 0;
			} else if ( index >= totalSlides ) {
				index = totalSlides - 1;
			}

			slides[ currentIndex ].classList.remove( 'h3vt-tour__slide--active' );
			slides[ index ].classList.add( 'h3vt-tour__slide--active' );

			// Re-trigger Ken Burns animation on the incoming slide image (skip for video slides).
			if ( ! slides[ index ].hasAttribute( 'data-hero-video' ) ) {
				var img = slides[ index ].querySelector( '.h3vt-tour__slide-image' );
				if ( img ) {
					img.style.animationName = 'none';
					void img.offsetHeight; // force reflow
					img.style.animationName = '';
				}
			}

			// Replay hero video from the start when navigating back to it.
			var slideVideo = slides[ index ].querySelector( '.h3vt-tour__slide-video' );
			if ( slideVideo ) {
				slideVideo.currentTime = 0;
				slideVideo.play();
			}

			currentIndex = index;
			resetAutoplay();
		}

		function nextSlide() {
			goToSlide( ( currentIndex + 1 ) % totalSlides );
		}

		function prevSlide() {
			goToSlide( ( currentIndex - 1 + totalSlides ) % totalSlides );
		}

		function resetAutoplay() {
			clearInterval( autoplayTimer );
			autoplayTimer = null;
			if ( isPlaying ) {
				autoplayTimer = setInterval( nextSlide, autoplaySpeed );
			}
		}

		function togglePlayPause() {
			isPlaying = ! isPlaying;

			var playIcons  = tourEl.querySelectorAll( '.h3vt-tour__icon--play' );
			var pauseIcons = tourEl.querySelectorAll( '.h3vt-tour__icon--pause' );

			playIcons.forEach( function ( el ) {
				el.style.display = isPlaying ? 'none' : '';
			});
			pauseIcons.forEach( function ( el ) {
				el.style.display = isPlaying ? '' : 'none';
			});

			if ( isPlaying ) {
				resetAutoplay();
			} else {
				clearInterval( autoplayTimer );
				autoplayTimer = null;
			}
		}

		// Kick off autoplay.
		resetAutoplay();

		/* ---------------------------------------------------------------
		 * 3. Navigation
		 *
		 * Two modes, set per theme via nav_mode: 'dropdown' (default) lists
		 * a category's slides under the button, 'modal' opens the category
		 * as a gallery of its slides with prev/next arrows.
		 * ------------------------------------------------------------- */
		var navMode  = tourEl.getAttribute( 'data-nav-mode' ) || 'dropdown';
		var navItems = tourEl.querySelectorAll( '.h3vt-tour__nav-item' );

		/**
		 * Collapse the mobile hamburger menu, if there is one.
		 */
		function closeMobileMenu() {
			if ( ! hamburger ) {
				return;
			}

			hamburger.classList.remove( 'h3vt-tour__hamburger--open' );
			hamburger.setAttribute( 'aria-expanded', 'false' );

			var header = tourEl.querySelector( '.h3vt-tour__header' );
			if ( header ) {
				header.classList.remove( 'h3vt-tour__header--menu-open' );
			}
		}

		/**
		 * Show another slide within a category gallery, wrapping at both ends.
		 *
		 * @param {HTMLElement} gallery The gallery root.
		 * @param {number}      delta   1 for next, -1 for previous.
		 */
		function stepNavGallery( gallery, delta ) {
			var slides = gallery.querySelectorAll( '.h3vt-tour__nav-slide' );
			if ( slides.length < 2 ) {
				return;
			}

			var current = 0;
			slides.forEach( function ( slide, i ) {
				if ( slide.classList.contains( 'h3vt-tour__nav-slide--active' ) ) {
					current = i;
				}
			});

			var next = ( current + delta + slides.length ) % slides.length;
			slides[ current ].classList.remove( 'h3vt-tour__nav-slide--active' );
			slides[ next ].classList.add( 'h3vt-tour__nav-slide--active' );
		}

		if ( 'modal' === navMode ) {
			/**
			 * Publish the header / bottom-bar heights so the gallery can sit
			 * in the band between them, leaving both lit and clickable.
			 */
			function measureChrome() {
				var header = tourEl.querySelector( '.h3vt-tour__header' );
				var bar    = tourEl.querySelector( '.h3vt-tour__bottom-bar' );
				var top    = header ? header.offsetHeight : 0;
				var bottom = bar ? bar.offsetHeight : 0;

				// Room for the caption under the photo.
				var image = Math.max( 160, tourEl.clientHeight - top - bottom - 60 );

				tourEl.style.setProperty( '--h3vt-chrome-top', top + 'px' );
				tourEl.style.setProperty( '--h3vt-chrome-bottom', bottom + 'px' );
				tourEl.style.setProperty( '--h3vt-nav-img-max', image + 'px' );
			}

			measureChrome();
			window.addEventListener( 'resize', measureChrome );

			// Nav button opens its category gallery, or swaps the open one to it.
			tourEl.querySelectorAll( '.h3vt-tour__nav-button[data-nav-modal]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					var name = btn.getAttribute( 'data-nav-modal' );
					openModal( tourEl.querySelector(
						'.h3vt-tour__modal--nav[data-modal-name="' + name + '"]'
					) );
					closeMobileMenu();
				});
			});

			// Gallery arrows.
			tourEl.querySelectorAll( '.h3vt-tour__nav-gallery-arrow' ).forEach( function ( arrow ) {
				arrow.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					var gallery = arrow.closest( '.h3vt-tour__nav-gallery' );
					if ( gallery ) {
						stepNavGallery( gallery, 'next' === arrow.getAttribute( 'data-nav-gallery' ) ? 1 : -1 );
					}
				});
			});
		} else {
			navItems.forEach( function ( navItem ) {
				var btn = navItem.querySelector( 'button, .h3vt-tour__nav-btn' );
				if ( btn ) {
					btn.addEventListener( 'click', function ( e ) {
						e.stopPropagation();
						var wasOpen = navItem.classList.contains( 'h3vt-tour__nav-item--open' );

						// Close every other open nav item first.
						navItems.forEach( function ( item ) {
							item.classList.remove( 'h3vt-tour__nav-item--open' );
						});

						if ( ! wasOpen ) {
							navItem.classList.add( 'h3vt-tour__nav-item--open' );
						}
					});
				}

				// Dropdown item click — navigate to the target slide.
				navItem.querySelectorAll( '[data-slide-index]' ).forEach( function ( link ) {
					link.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						e.stopPropagation();
						var idx = parseInt( link.getAttribute( 'data-slide-index' ), 10 );
						if ( ! isNaN( idx ) ) {
							goToSlide( idx );
						}
						navItem.classList.remove( 'h3vt-tour__nav-item--open' );
						closeMobileMenu();
					});
				});
			});

			// Close dropdowns on outside click.
			document.addEventListener( 'click', function ( e ) {
				var nav = tourEl.querySelector( '.h3vt-tour__nav' );
				if ( nav && ! nav.contains( e.target ) ) {
					navItems.forEach( function ( item ) {
						item.classList.remove( 'h3vt-tour__nav-item--open' );
					});
				}
			});
		}

		/* ---------------------------------------------------------------
		 * 4. Hamburger Menu (mobile)
		 * ------------------------------------------------------------- */
		var hamburger = tourEl.querySelector( '.h3vt-tour__hamburger' );
		if ( hamburger ) {
			hamburger.addEventListener( 'click', function () {
				var isOpen = hamburger.classList.toggle( 'h3vt-tour__hamburger--open' );
				var header = tourEl.querySelector( '.h3vt-tour__header' );
				if ( header ) {
					header.classList.toggle( 'h3vt-tour__header--menu-open', isOpen );
				}
				hamburger.setAttribute( 'aria-expanded', String( isOpen ) );
			});
		}

		/* ---------------------------------------------------------------
		 * 5. Playback Controls
		 * ------------------------------------------------------------- */
		tourEl.querySelectorAll( '.h3vt-tour__control' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var action = btn.getAttribute( 'data-action' );
				switch ( action ) {
					case 'home':
						goToSlide( 0 );
						break;
					case 'prev':
						prevSlide();
						break;
					case 'next':
						nextSlide();
						break;
					case 'playpause':
						togglePlayPause();
						break;
				}
			});
		});

		/* ---------------------------------------------------------------
		 * 6. Modal Manager
		 * ------------------------------------------------------------- */

		/**
		 * Return all focusable elements inside a container.
		 *
		 * @param {HTMLElement} container
		 * @return {HTMLElement[]}
		 */
		function getFocusable( container ) {
			return Array.prototype.slice.call(
				container.querySelectorAll(
					'a[href], button:not([disabled]), textarea, input:not([type="hidden"]):not([disabled]), select, [tabindex]:not([tabindex="-1"])'
				)
			);
		}

		/**
		 * Open a modal or panel element.
		 *
		 * @param {HTMLElement} modalEl The modal / panel root element.
		 * @param {Object}      [opts]  keepPlaying: leave the slideshow
		 *                              running behind the overlay;
		 *                              noFocus: don't move focus into it.
		 */
		function openModal( modalEl, opts ) {
			if ( ! modalEl ) {
				return;
			}
			opts = opts || {};

			/*
			 * Only one overlay at a time. The header stays clickable above an
			 * open gallery, so this is the path that swaps one nav category
			 * for another without closing first.
			 */
			var current = tourEl.querySelector(
				'.h3vt-tour__modal:not([hidden]), .h3vt-tour__panel--open'
			);
			if ( current && current !== modalEl ) {
				closeModal( current );
			}

			// Pause slideshow while modal is open (unless told to keep going).
			if ( opts.keepPlaying ) {
				wasPlaying = false;
			} else {
				wasPlaying = isPlaying;
				clearInterval( autoplayTimer );
				autoplayTimer = null;
			}

			previouslyFocused = document.activeElement;

			modalEl.removeAttribute( 'hidden' );

			var isPanel = modalEl.classList.contains( 'h3vt-tour__panel' );
			if ( isPanel ) {
				requestAnimationFrame( function () {
					modalEl.classList.add( 'h3vt-tour__panel--open' );
				});
			}

			// Focus trap.
			var focusable = getFocusable( modalEl );
			if ( focusable.length && ! opts.noFocus ) {
				focusable[0].focus();
			}

			trapKeyHandler = function ( e ) {
				if ( e.key === 'Escape' || e.keyCode === 27 ) {
					e.preventDefault();
					closeModal( modalEl );
					return;
				}
				// Arrows page a category gallery, mirroring its prev/next arrows.
				var navGallery = modalEl.querySelector( '.h3vt-tour__nav-gallery' );
				if ( navGallery && ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) ) {
					e.preventDefault();
					stepNavGallery( navGallery, e.key === 'ArrowRight' ? 1 : -1 );
					return;
				}
				if ( e.key === 'Tab' || e.keyCode === 9 ) {
					var updated = getFocusable( modalEl );
					if ( updated.length === 0 ) {
						e.preventDefault();
						return;
					}
					var first = updated[0];
					var last  = updated[ updated.length - 1 ];
					if ( e.shiftKey ) {
						if ( document.activeElement === first ) {
							e.preventDefault();
							last.focus();
						}
					} else {
						if ( document.activeElement === last ) {
							e.preventDefault();
							first.focus();
						}
					}
				}
			};

			document.addEventListener( 'keydown', trapKeyHandler );
		}

		/**
		 * Close a modal or panel element.
		 *
		 * @param {HTMLElement} modalEl The modal / panel root element.
		 */
		function closeModal( modalEl ) {
			if ( ! modalEl ) {
				return;
			}

			// Clean up embedded content.
			cleanModalContent( modalEl );

			if ( trapKeyHandler ) {
				document.removeEventListener( 'keydown', trapKeyHandler );
				trapKeyHandler = null;
			}

			var isPanel = modalEl.classList.contains( 'h3vt-tour__panel' );

			if ( isPanel ) {
				modalEl.classList.remove( 'h3vt-tour__panel--open' );

				var onTransitionEnd = function () {
					modalEl.setAttribute( 'hidden', '' );
					modalEl.removeEventListener( 'transitionend', onTransitionEnd );
				};
				modalEl.addEventListener( 'transitionend', onTransitionEnd );

				// Fallback if transitionend never fires.
				setTimeout( function () {
					if ( ! modalEl.hasAttribute( 'hidden' ) ) {
						modalEl.setAttribute( 'hidden', '' );
						modalEl.removeEventListener( 'transitionend', onTransitionEnd );
					}
				}, 600 );
			} else {
				modalEl.setAttribute( 'hidden', '' );
			}

			// Restore focus.
			if ( previouslyFocused ) {
				previouslyFocused.focus();
				previouslyFocused = null;
			}

			// Resume slideshow.
			if ( wasPlaying ) {
				isPlaying = true;
				resetAutoplay();
			}
		}

		/**
		 * Remove iframes, videos, and other dynamic content from a modal.
		 *
		 * @param {HTMLElement} modalEl
		 */
		function cleanModalContent( modalEl ) {
			// Rewind a category gallery so it reopens on its first slide.
			var navSlides = modalEl.querySelectorAll( '.h3vt-tour__nav-slide' );
			navSlides.forEach( function ( slide, i ) {
				slide.classList.toggle( 'h3vt-tour__nav-slide--active', 0 === i );
			});

			// Remove 3D-tour iframes.
			var tourContainer = modalEl.querySelector( '.h3vt-tour__3dtour-container' );
			if ( tourContainer ) {
				tourContainer.innerHTML = '';
			}

			// Remove PDF iframes.
			var pdfContainer = modalEl.querySelector( '.h3vt-tour__pdf-container' );
			if ( pdfContainer ) {
				pdfContainer.innerHTML = '';
			}

			// Pause / remove testimonial videos and iframes.
			var videoContainer = modalEl.querySelector( '.h3vt-tour__testimonial-video' );
			if ( videoContainer ) {
				var videos = videoContainer.querySelectorAll( 'video' );
				videos.forEach( function ( v ) {
					v.pause();
					v.removeAttribute( 'src' );
					v.load();
				});
				var iframes = videoContainer.querySelectorAll( 'iframe' );
				iframes.forEach( function ( f ) {
					f.src = '';
				});
				videoContainer.innerHTML = '';
			}

			// Reset active testimonial thumb so the first one loads again next time.
			modalEl.querySelectorAll( '.h3vt-tour__testimonial-thumb--active' ).forEach( function ( t ) {
				t.classList.remove( 'h3vt-tour__testimonial-thumb--active' );
			});

			// Clear videos player.
			var videosPlayer = modalEl.querySelector( '.h3vt-tour__videos-player' );
			if ( videosPlayer ) {
				videosPlayer.innerHTML = '';
			}
		}

		// Bottom-bar buttons that trigger modals / panels.
		tourEl.querySelectorAll( '.h3vt-tour__bottom-btn[data-modal]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var value = btn.getAttribute( 'data-modal' );
				var modalEl = null;

				// 3D tour with an index: "3dtour-0", "3dtour-1", etc.
				var tourMatch = value.match( /^3dtour-(\d+)$/ );
				if ( tourMatch ) {
					modalEl = tourEl.querySelector(
						'.h3vt-tour__modal--3dtour[data-tour-index="' + tourMatch[1] + '"]'
					);
				}

				// Single video with an index: "video-0", "video-1", etc.
				var videoMatch = value.match( /^video-(\d+)$/ );
				if ( videoMatch ) {
					modalEl = tourEl.querySelector(
						'.h3vt-tour__modal--single-video[data-modal-name="' + value + '"]'
					);
				}

				if ( ! modalEl ) {
					modalEl = tourEl.querySelector( '.h3vt-tour__modal--' + value )
						   || tourEl.querySelector( '.h3vt-tour__panel--' + value );
				}

				openModal( modalEl );
			});
		});

		// Backdrop click closes modal.
		tourEl.querySelectorAll( '.h3vt-tour__modal-backdrop' ).forEach( function ( backdrop ) {
			backdrop.addEventListener( 'click', function () {
				var modal = backdrop.closest( '.h3vt-tour__modal' ) || backdrop.closest( '.h3vt-tour__panel' );
				if ( modal ) {
					closeModal( modal );
				}
			});
		});

		// Close buttons inside modals / panels.
		tourEl.querySelectorAll( '.h3vt-tour__modal-close, .h3vt-tour__panel-close' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var modal = btn.closest( '.h3vt-tour__modal' ) || btn.closest( '.h3vt-tour__panel' );
				if ( modal ) {
					closeModal( modal );
				}
			});
		});

		/* ---------------------------------------------------------------
		 * 7. Testimonials
		 * ------------------------------------------------------------- */

		/**
		 * Load a video (YouTube, Vimeo, or direct) into a container element.
		 *
		 * @param {HTMLElement} container The element to render the video into.
		 * @param {string}      videoUrl  The URL of the video to embed.
		 */
		function loadVideo( container, videoUrl, onEnded ) {
			if ( ! container || ! videoUrl ) {
				return;
			}

			container.innerHTML = '';

			var youtubeMatch = videoUrl.match(
				/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/
			);
			var vimeoMatch = videoUrl.match(
				/vimeo\.com\/(\d+)/
			);

			var el;

			if ( youtubeMatch ) {
				el = document.createElement( 'iframe' );
				el.src = 'https://www.youtube.com/embed/' + youtubeMatch[1] + '?autoplay=1&enablejsapi=1';
				el.width = '100%';
				el.height = '100%';
				el.frameBorder = '0';
				el.allow = 'autoplay; encrypted-media';
				el.allowFullscreen = true;
				el.id = 'h3vt-yt-player-' + Date.now();

				if ( onEnded ) {
					var onYTMessage = function ( event ) {
						var data;
						if ( typeof event.data === 'string' ) {
							try { data = JSON.parse( event.data ); } catch ( e ) { return; }
						} else {
							data = event.data;
						}
						if ( data && data.event === 'onStateChange' && data.info === 0 ) {
							window.removeEventListener( 'message', onYTMessage );
							onEnded();
						}
					};
					window.addEventListener( 'message', onYTMessage );
					el.addEventListener( 'load', function () {
						el.contentWindow.postMessage(
							JSON.stringify({ event: 'listening', id: el.id }),
							'*'
						);
					});
				}
			} else if ( vimeoMatch ) {
				el = document.createElement( 'iframe' );
				el.src = 'https://player.vimeo.com/video/' + vimeoMatch[1] + '?autoplay=1';
				el.width = '100%';
				el.height = '100%';
				el.frameBorder = '0';
				el.allow = 'autoplay';
				el.allowFullscreen = true;

				if ( onEnded ) {
					var onVimeoMessage = function ( event ) {
						var data;
						if ( typeof event.data === 'string' ) {
							try { data = JSON.parse( event.data ); } catch ( e ) { return; }
						} else {
							data = event.data;
						}
						if ( data && data.event === 'ended' ) {
							window.removeEventListener( 'message', onVimeoMessage );
							onEnded();
						}
					};
					window.addEventListener( 'message', onVimeoMessage );
					el.addEventListener( 'load', function () {
						el.contentWindow.postMessage(
							JSON.stringify({ method: 'addEventListener', value: 'ended' }),
							'*'
						);
					});
				}
			} else {
				el = document.createElement( 'video' );
				el.src = videoUrl;
				el.controls = true;
				el.autoplay = true;
				el.style.width = '100%';
				el.style.height = '100%';

				autoPosterVideo( el );

				if ( onEnded ) {
					el.addEventListener( 'ended', onEnded );
				}
			}

			container.appendChild( el );
		}

		/**
		 * Advance to the next testimonial video, or loop back to the first.
		 *
		 * @param {HTMLElement} modal The testimonials modal element.
		 */
		function advanceTestimonial( modal ) {
			var thumbs  = modal.querySelectorAll( '.h3vt-tour__testimonial-thumb' );
			var active  = modal.querySelector( '.h3vt-tour__testimonial-thumb--active' );
			if ( ! thumbs.length ) {
				return;
			}

			var currentIndex = active ? Array.prototype.indexOf.call( thumbs, active ) : 0;
			var nextIndex    = ( currentIndex + 1 ) % thumbs.length;
			thumbs[ nextIndex ].click();
		}

		/**
		 * Load a video into the testimonial video container.
		 * When the video ends it automatically advances to the next testimonial.
		 *
		 * @param {HTMLElement} modal     The testimonials modal element.
		 * @param {string}      videoUrl  The URL of the video to embed.
		 */
		function loadTestimonialVideo( modal, videoUrl ) {
			var container = modal.querySelector( '.h3vt-tour__testimonial-video' );
			loadVideo( container, videoUrl, function () {
				advanceTestimonial( modal );
			});
		}

		// Auto-load the first testimonial video when modal opens.
		var testimonialsModal = tourEl.querySelector( '.h3vt-tour__modal--testimonials' );
		if ( testimonialsModal ) {
			var tObserver = new MutationObserver( function () {
				if ( ! testimonialsModal.hasAttribute( 'hidden' ) ) {
					var active = testimonialsModal.querySelector( '.h3vt-tour__testimonial-thumb--active' );
					if ( ! active ) {
						var firstThumb = testimonialsModal.querySelector( '.h3vt-tour__testimonial-thumb' );
						if ( firstThumb ) {
							firstThumb.click();
						}
					}
				}
			});
			tObserver.observe( testimonialsModal, { attributes: true, attributeFilter: [ 'hidden' ] } );
		}

		// Auto-generate a thumbnail image for any testimonial whose
		// editor-supplied thumbnail is missing.
		tourEl.querySelectorAll( '.h3vt-tour__testimonial-thumb' ).forEach( function ( thumb ) {
			if ( thumb.querySelector( 'img' ) ) {
				return;
			}
			var url = thumb.getAttribute( 'data-video-url' );
			if ( ! url ) {
				return;
			}
			generateVideoPoster( url, function ( dataUrl ) {
				if ( ! dataUrl || thumb.querySelector( 'img' ) ) {
					return;
				}
				var img = document.createElement( 'img' );
				img.src = dataUrl;
				img.alt = '';
				thumb.insertBefore( img, thumb.firstChild );
			});
		});

		tourEl.querySelectorAll( '.h3vt-tour__testimonial-thumb' ).forEach( function ( thumb ) {
			thumb.addEventListener( 'click', function () {
				var modal    = thumb.closest( '.h3vt-tour__modal' ) || thumb.closest( '.h3vt-tour__panel' );
				var videoUrl = thumb.getAttribute( 'data-video-url' );

				if ( modal && videoUrl ) {
					// Update active state.
					modal.querySelectorAll( '.h3vt-tour__testimonial-thumb' ).forEach( function ( t ) {
						t.classList.remove( 'h3vt-tour__testimonial-thumb--active' );
					});
					thumb.classList.add( 'h3vt-tour__testimonial-thumb--active' );

					loadTestimonialVideo( modal, videoUrl );
				}
			});
		});

		/* ---------------------------------------------------------------
		 * 7b. Videos
		 * ------------------------------------------------------------- */
		var videosModal = tourEl.querySelector( '.h3vt-tour__modal--videos' );
		if ( videosModal ) {
			var vObserver = new MutationObserver( function () {
				if ( ! videosModal.hasAttribute( 'hidden' ) ) {
					// Auto-select first video button on open.
					var active = videosModal.querySelector( '.h3vt-tour__videos-btn--active' );
					if ( ! active ) {
						var firstBtn = videosModal.querySelector( '.h3vt-tour__videos-btn' );
						if ( firstBtn ) {
							firstBtn.click();
						}
					}
				}
			});
			vObserver.observe( videosModal, { attributes: true, attributeFilter: [ 'hidden' ] } );
		}

		tourEl.querySelectorAll( '.h3vt-tour__videos-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var modal    = btn.closest( '.h3vt-tour__modal' );
				var videoUrl = btn.getAttribute( 'data-video-url' );

				if ( modal && videoUrl ) {
					// Update active state.
					modal.querySelectorAll( '.h3vt-tour__videos-btn' ).forEach( function ( b ) {
						b.classList.remove( 'h3vt-tour__videos-btn--active' );
					});
					btn.classList.add( 'h3vt-tour__videos-btn--active' );

					var player = modal.querySelector( '.h3vt-tour__videos-player' );
					loadVideo( player, videoUrl );
				}
			});
		});

		// Auto-play single-video modals when opened.
		tourEl.querySelectorAll( '.h3vt-tour__modal--single-video' ).forEach( function ( singleModal ) {
			var svObserver = new MutationObserver( function () {
				if ( ! singleModal.hasAttribute( 'hidden' ) ) {
					var videoUrl = singleModal.getAttribute( 'data-video-url' );
					var player   = singleModal.querySelector( '.h3vt-tour__videos-player' );
					if ( player && videoUrl ) {
						loadVideo( player, videoUrl );
					}
				}
			});
			svObserver.observe( singleModal, { attributes: true, attributeFilter: [ 'hidden' ] } );
		});

		/* ---------------------------------------------------------------
		 * 8. Floor Plans
		 * ------------------------------------------------------------- */
		tourEl.querySelectorAll( '.h3vt-tour__floorplan-select' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				var panel = select.closest( '.h3vt-tour__panel' ) || select.closest( '.h3vt-tour__modal' );
				if ( ! panel ) {
					return;
				}

				panel.querySelectorAll( '.h3vt-tour__floorplan-container' ).forEach( function ( fp ) {
					fp.classList.remove( 'h3vt-tour__floorplan-container--active' );
				});

				var target = panel.querySelector(
					'.h3vt-tour__floorplan-container[data-floorplan-index="' + select.value + '"]'
				);
				if ( target ) {
					target.classList.add( 'h3vt-tour__floorplan-container--active' );
				}
			});
		});

		// Activate the first floorplan when the panel opens (handled via MutationObserver
		// on the panel's class list or checked when opening).
		var floorplanPanels = tourEl.querySelectorAll(
			'.h3vt-tour__panel--floorplans, .h3vt-tour__modal--floorplans'
		);
		floorplanPanels.forEach( function ( panel ) {
			var observer = new MutationObserver( function () {
				var isOpen = panel.classList.contains( 'h3vt-tour__panel--open' )
					|| ! panel.hasAttribute( 'hidden' );
				if ( isOpen ) {
					var active = panel.querySelector( '.h3vt-tour__floorplan-container--active' );
					if ( ! active ) {
						var first = panel.querySelector( '.h3vt-tour__floorplan-container' );
						if ( first ) {
							first.classList.add( 'h3vt-tour__floorplan-container--active' );
						}
						// Sync the select.
						var sel = panel.querySelector( '.h3vt-tour__floorplan-select' );
						if ( sel && first ) {
							sel.value = first.getAttribute( 'data-floorplan-index' ) || '';
						}
					}
				}
			});
			observer.observe( panel, { attributes: true, attributeFilter: [ 'class', 'hidden' ] } );
		});

		// Hotspot click — navigate to a slide and close the panel.
		tourEl.querySelectorAll( '.h3vt-tour__hotspot[data-slide-index]' ).forEach( function ( hotspot ) {
			hotspot.addEventListener( 'click', function () {
				var idx   = parseInt( hotspot.getAttribute( 'data-slide-index' ), 10 );
				var modal = hotspot.closest( '.h3vt-tour__modal' ) || hotspot.closest( '.h3vt-tour__panel' );

				if ( modal ) {
					closeModal( modal );
				}
				if ( ! isNaN( idx ) ) {
					goToSlide( idx );
				}
			});
		});

		/* ---------------------------------------------------------------
		 * 9. 3D Tour (Matterport / iframe embed)
		 * ------------------------------------------------------------- */
		tourEl.querySelectorAll( '.h3vt-tour__modal--3dtour' ).forEach( function ( modal ) {
			var observer = new MutationObserver( function () {
				if ( ! modal.hasAttribute( 'hidden' ) ) {
					var container = modal.querySelector( '.h3vt-tour__3dtour-container' );
					if ( container && container.children.length === 0 ) {
						var embedUrl = container.getAttribute( 'data-embed-url' );
						if ( embedUrl ) {
							var iframe = document.createElement( 'iframe' );
							iframe.src = embedUrl;
							iframe.width = '100%';
							iframe.height = '100%';
							iframe.frameBorder = '0';
							iframe.allowFullscreen = true;
							iframe.setAttribute( 'allow', 'xr-spatial-tracking' );
							container.appendChild( iframe );
						}
					}
				}
			});
			observer.observe( modal, { attributes: true, attributeFilter: [ 'hidden' ] } );
		});

		/* ---------------------------------------------------------------
		 * 9b. PDF Lightbox
		 * ------------------------------------------------------------- */
		tourEl.querySelectorAll( '.h3vt-tour__modal--pdf' ).forEach( function ( modal ) {
			var observer = new MutationObserver( function () {
				if ( ! modal.hasAttribute( 'hidden' ) ) {
					var container = modal.querySelector( '.h3vt-tour__pdf-container' );
					if ( container && container.children.length === 0 ) {
						var pdfUrl = container.getAttribute( 'data-pdf-url' );
						if ( pdfUrl ) {
							var iframe = document.createElement( 'iframe' );
							iframe.src = pdfUrl;
							iframe.width = '100%';
							iframe.height = '100%';
							iframe.frameBorder = '0';
							iframe.title = 'PDF Document';
							container.appendChild( iframe );
						}
					}
				}
			});
			observer.observe( modal, { attributes: true, attributeFilter: [ 'hidden' ] } );
		});

		/* ---------------------------------------------------------------
		 * 10. Fullscreen
		 * ------------------------------------------------------------- */
		var fullscreenBtn = tourEl.querySelector( '.h3vt-tour__fullscreen-btn' );

		if ( fullscreenBtn ) {
			var fullscreenEnabled = document.fullscreenEnabled || document.webkitFullscreenEnabled;

			if ( ! fullscreenEnabled ) {
				fullscreenBtn.style.display = 'none';
			} else {
				fullscreenBtn.addEventListener( 'click', function () {
					if ( document.fullscreenElement || document.webkitFullscreenElement ) {
						if ( document.exitFullscreen ) {
							document.exitFullscreen();
						} else if ( document.webkitExitFullscreen ) {
							document.webkitExitFullscreen();
						}
					} else {
						if ( tourEl.requestFullscreen ) {
							tourEl.requestFullscreen();
						} else if ( tourEl.webkitRequestFullscreen ) {
							tourEl.webkitRequestFullscreen();
						}
					}
				});

				var syncFullscreenClass = function () {
					var isFull = ( document.fullscreenElement === tourEl )
						|| ( document.webkitFullscreenElement === tourEl );
					tourEl.classList.toggle( 'h3vt-tour--fullscreen', isFull );
				};

				document.addEventListener( 'fullscreenchange', syncFullscreenClass );
				document.addEventListener( 'webkitfullscreenchange', syncFullscreenClass );
			}
		}

		/* ---------------------------------------------------------------
		 * 11. Touch Support
		 * ------------------------------------------------------------- */
		var touchStartX = 0;
		var touchStartY = 0;
		var slidesContainer = tourEl.querySelector( '.h3vt-tour__slides' );

		if ( slidesContainer ) {
			slidesContainer.addEventListener( 'touchstart', function ( e ) {
				if ( e.touches.length === 1 ) {
					touchStartX = e.touches[0].clientX;
					touchStartY = e.touches[0].clientY;
				}
			}, { passive: true } );

			slidesContainer.addEventListener( 'touchend', function ( e ) {
				if ( e.changedTouches.length === 1 ) {
					var dx = e.changedTouches[0].clientX - touchStartX;
					var dy = e.changedTouches[0].clientY - touchStartY;

					// Only register as a horizontal swipe if it's clearly horizontal.
					if ( Math.abs( dx ) > 50 && Math.abs( dx ) > Math.abs( dy ) * 1.5 ) {
						if ( dx < 0 ) {
							nextSlide();
						} else {
							prevSlide();
						}
					}
				}
			}, { passive: true } );
		}

		/* ---------------------------------------------------------------
		 * 12. Keyboard Navigation
		 * ------------------------------------------------------------- */
		var keyTarget = tourEl.hasAttribute( 'tabindex' ) ? tourEl : document;

		keyTarget.addEventListener( 'keydown', function ( e ) {
			// Don't intercept when typing into form fields.
			var tag = ( e.target.tagName || '' ).toLowerCase();
			if ( tag === 'input' || tag === 'textarea' || tag === 'select' ) {
				return;
			}

			var openOverlay = tourEl.querySelector(
				'.h3vt-tour__modal:not([hidden]), .h3vt-tour__panel--open'
			);

			if ( e.key === 'Escape' ) {
				if ( openOverlay ) {
					closeModal( openOverlay );
				}
				return;
			}

			// An open modal owns the keyboard — don't drive the slideshow
			// underneath it.
			if ( openOverlay ) {
				return;
			}

			switch ( e.key ) {
				case 'ArrowLeft':
					prevSlide();
					break;
				case 'ArrowRight':
					nextSlide();
					break;
				case ' ':
					e.preventDefault();
					togglePlayPause();
					break;
				case 'Home':
					goToSlide( 0 );
					break;
			}
		});

		/* ---------------------------------------------------------------
		 * 13. Voice-over
		 * ------------------------------------------------------------- */
		var voiceover = tourEl.querySelector( '.h3vt-tour__voiceover' );

		if ( voiceover ) {
			var voAudio = voiceover.querySelector( '.h3vt-tour__voiceover-audio' );
			var voBtn   = voiceover.querySelector( '.h3vt-tour__voiceover-btn' );
			var voLabel = voiceover.querySelector( '.h3vt-tour__voiceover-label' );

			// Collapse the intro hint to an icon after a delay so visitors
			// first notice the narration is available, then it gets out of the way.
			var voCollapseTimer = setTimeout( function () {
				voiceover.classList.add( 'h3vt-tour__voiceover--collapsed' );
			}, 6000 );

			voBtn.addEventListener( 'click', function () {
				if ( voAudio.paused ) {
					voAudio.play();
				} else {
					voAudio.pause();
				}
			});

			voAudio.addEventListener( 'play', function () {
				clearTimeout( voCollapseTimer );
				voiceover.classList.add( 'h3vt-tour__voiceover--collapsed' );
				voiceover.setAttribute( 'data-state', 'playing' );
				voBtn.setAttribute( 'aria-label', voBtn.getAttribute( 'data-pause-label' ) );
				if ( voLabel ) {
					voLabel.textContent = voBtn.getAttribute( 'data-pause-label' );
				}
			});

			voAudio.addEventListener( 'pause', function () {
				voiceover.setAttribute( 'data-state', 'paused' );
				voBtn.setAttribute( 'aria-label', voBtn.getAttribute( 'data-play-label' ) );
				if ( voLabel ) {
					voLabel.textContent = voBtn.getAttribute( 'data-play-label' );
				}
			});

			// Play once — reset to the start so the visitor can replay it.
			voAudio.addEventListener( 'ended', function () {
				voAudio.currentTime = 0;
				voiceover.setAttribute( 'data-state', 'paused' );
				voBtn.setAttribute( 'aria-label', voBtn.getAttribute( 'data-play-label' ) );
				if ( voLabel ) {
					voLabel.textContent = voBtn.getAttribute( 'data-play-label' );
				}
			});
		}

		/* ---------------------------------------------------------------
		 * 13b. Welcome / navigation instructions
		 *
		 * Themes that define a welcome card get it opened as soon as the
		 * tour loads. The slideshow keeps running behind it, focus stays
		 * put (so an embedded tour doesn't scroll the host page), and any
		 * other overlay opening replaces it.
		 * ------------------------------------------------------------- */
		var welcomeModal = tourEl.querySelector( '.h3vt-tour__modal--welcome' );

		if ( welcomeModal ) {
			openModal( welcomeModal, { keepPlaying: true, noFocus: true } );
		}

		/* ---------------------------------------------------------------
		 * 14. Exit Intent Popup
		 *
		 * Opens the lead-capture modal when the cursor leaves the page
		 * toward the browser chrome (or leaves the iframe upward when the
		 * tour is embedded). Fires at most once per session, and never in
		 * the first few seconds so quick bounces aren't interrupted.
		 * ------------------------------------------------------------- */
		var exitModal = tourEl.querySelector( '.h3vt-tour__modal--exit' );

		if ( exitModal ) {
			var exitStorageKey = 'h3vtExitIntentShown';
			var exitShown      = false;
			var exitArmed      = false;

			try {
				exitShown = sessionStorage.getItem( exitStorageKey ) === '1';
			} catch ( e ) {}

			setTimeout( function () {
				exitArmed = true;
			}, 5000 );

			document.addEventListener( 'mouseout', function ( e ) {
				if ( exitShown || ! exitArmed ) {
					return;
				}

				// Only when the cursor actually leaves the document.
				if ( e.relatedTarget || e.toElement ) {
					return;
				}

				// Heading up toward the tabs / address bar, not off the sides.
				if ( e.clientY > 24 ) {
					return;
				}

				// Don't stack on top of another open modal (the welcome card is fine to replace).
				if ( tourEl.querySelector( '.h3vt-tour__modal:not([hidden]):not(.h3vt-tour__modal--welcome), .h3vt-tour__panel--open' ) ) {
					return;
				}

				exitShown = true;
				try {
					sessionStorage.setItem( exitStorageKey, '1' );
				} catch ( e2 ) {}

				openModal( exitModal );
			});

			// "No thanks" link closes the modal.
			var exitDismiss = exitModal.querySelector( '.h3vt-tour__exit-dismiss' );
			if ( exitDismiss ) {
				exitDismiss.addEventListener( 'click', function () {
					closeModal( exitModal );
				});
			}

			// Submit the lead to the REST endpoint without leaving the tour.
			var exitForm = exitModal.querySelector( '.h3vt-tour__exit-form' );
			if ( exitForm ) {
				exitForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();

					var errorEl  = exitModal.querySelector( '.h3vt-tour__exit-error' );
					var submitEl = exitForm.querySelector( '.h3vt-tour__exit-submit' );
					var payload  = {};

					Array.prototype.forEach.call( exitForm.elements, function ( field ) {
						if ( field.name ) {
							payload[ field.name ] = field.value;
						}
					});

					if ( errorEl ) {
						errorEl.setAttribute( 'hidden', '' );
					}
					if ( submitEl ) {
						submitEl.disabled = true;
					}

					fetch( exitForm.action, {
						method:  'POST',
						headers: { 'Content-Type': 'application/json' },
						body:    JSON.stringify( payload )
					})
						.then( function ( response ) {
							if ( ! response.ok ) {
								throw new Error( 'Request failed' );
							}
							return response.json();
						})
						.then( function () {
							exitForm.setAttribute( 'hidden', '' );
							var intro = exitModal.querySelectorAll( '.h3vt-tour__modal-content > .h3vt-tour__exit-headline, .h3vt-tour__modal-content > .h3vt-tour__exit-message' );
							Array.prototype.forEach.call( intro, function ( el ) {
								el.setAttribute( 'hidden', '' );
							});
							var success = exitModal.querySelector( '.h3vt-tour__exit-success' );
							if ( success ) {
								success.removeAttribute( 'hidden' );
							}
						})
						.catch( function () {
							if ( errorEl ) {
								errorEl.removeAttribute( 'hidden' );
							}
							if ( submitEl ) {
								submitEl.disabled = false;
							}
						});
				});
			}
		}
	}
})();
