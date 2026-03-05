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
		 * 3. Navigation Dropdowns
		 * ------------------------------------------------------------- */
		var navItems = tourEl.querySelectorAll( '.h3vt-tour__nav-item' );

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

					// Collapse the mobile hamburger menu.
					if ( hamburger ) {
						hamburger.classList.remove( 'h3vt-tour__hamburger--open' );
						hamburger.setAttribute( 'aria-expanded', 'false' );
						var header = tourEl.querySelector( '.h3vt-tour__header' );
						if ( header ) {
							header.classList.remove( 'h3vt-tour__header--menu-open' );
						}
					}
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
		 */
		function openModal( modalEl ) {
			if ( ! modalEl ) {
				return;
			}

			// Pause slideshow while modal is open.
			wasPlaying = isPlaying;
			clearInterval( autoplayTimer );
			autoplayTimer = null;

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
			if ( focusable.length ) {
				focusable[0].focus();
			}

			trapKeyHandler = function ( e ) {
				if ( e.key === 'Escape' || e.keyCode === 27 ) {
					e.preventDefault();
					closeModal( modalEl );
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
			// Remove 3D-tour iframes.
			var tourContainer = modalEl.querySelector( '.h3vt-tour__3dtour-container' );
			if ( tourContainer ) {
				tourContainer.innerHTML = '';
			}

			// Pause / remove testimonial videos.
			var videoContainer = modalEl.querySelector( '.h3vt-tour__testimonial-video' );
			if ( videoContainer ) {
				var videos = videoContainer.querySelectorAll( 'video' );
				videos.forEach( function ( v ) {
					v.pause();
					v.removeAttribute( 'src' );
					v.load();
				});
				videoContainer.innerHTML = '';
			}

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
		function loadVideo( container, videoUrl ) {
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
				el.src = 'https://www.youtube.com/embed/' + youtubeMatch[1] + '?autoplay=1';
				el.width = '100%';
				el.height = '100%';
				el.frameBorder = '0';
				el.allow = 'autoplay; encrypted-media';
				el.allowFullscreen = true;
			} else if ( vimeoMatch ) {
				el = document.createElement( 'iframe' );
				el.src = 'https://player.vimeo.com/video/' + vimeoMatch[1] + '?autoplay=1';
				el.width = '100%';
				el.height = '100%';
				el.frameBorder = '0';
				el.allow = 'autoplay';
				el.allowFullscreen = true;
			} else {
				el = document.createElement( 'video' );
				el.src = videoUrl;
				el.controls = true;
				el.autoplay = true;
				el.style.width = '100%';
				el.style.height = '100%';
			}

			container.appendChild( el );
		}

		/**
		 * Load a video into the testimonial video container.
		 *
		 * @param {HTMLElement} modal     The testimonials modal element.
		 * @param {string}      videoUrl  The URL of the video to embed.
		 */
		function loadTestimonialVideo( modal, videoUrl ) {
			var container = modal.querySelector( '.h3vt-tour__testimonial-video' );
			loadVideo( container, videoUrl );
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
				case 'Escape':
					var openModal_ = tourEl.querySelector(
						'.h3vt-tour__modal:not([hidden]), .h3vt-tour__panel--open'
					);
					if ( openModal_ ) {
						closeModal( openModal_ );
					}
					break;
				case 'Home':
					goToSlide( 0 );
					break;
			}
		});
	}
})();
