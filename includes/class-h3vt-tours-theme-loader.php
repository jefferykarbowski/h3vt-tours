<?php
/**
 * Theme discovery and asset serving.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility class that discovers themes from the themes/ directory
 * and provides asset enqueuing, head markup, and ACF choices.
 */
class H3VT_Tours_Theme_Loader {

	/**
	 * Cached theme configs (slug => config array).
	 *
	 * @var array|null
	 */
	private static $themes = null;

	/**
	 * Scan the themes/ directory and return all discovered theme configs.
	 *
	 * Each theme lives in themes/{slug}/ and must contain a theme.php that
	 * returns a config array. Results are cached for the current request.
	 *
	 * @return array Slug => config array.
	 */
	public static function get_all_themes() {
		if ( null !== self::$themes ) {
			return self::$themes;
		}

		self::$themes = array();
		$themes_dir   = H3VT_TOURS_PATH . 'themes/';

		if ( ! is_dir( $themes_dir ) ) {
			return self::$themes;
		}

		$entries = scandir( $themes_dir );
		if ( ! is_array( $entries ) ) {
			return self::$themes;
		}

		foreach ( $entries as $slug ) {
			if ( '.' === $slug || '..' === $slug ) {
				continue;
			}

			$theme_dir  = $themes_dir . $slug . '/';
			$theme_file = $theme_dir . 'theme.php';

			if ( ! is_dir( $theme_dir ) || ! file_exists( $theme_file ) ) {
				continue;
			}

			$config = include $theme_file;

			if ( ! is_array( $config ) || empty( $config['name'] ) ) {
				continue;
			}

			$config['slug']        = $slug;
			self::$themes[ $slug ] = $config;
		}

		return self::$themes;
	}

	/**
	 * Get a single theme's config by slug.
	 *
	 * @param string $slug Theme slug.
	 * @return array|null Config array or null if not found.
	 */
	public static function get_theme( $slug ) {
		$themes = self::get_all_themes();
		return isset( $themes[ $slug ] ) ? $themes[ $slug ] : null;
	}

	/**
	 * Build the slug => label choices array for ACF select fields.
	 *
	 * Always includes 'classic' => 'Classic' as the first entry.
	 *
	 * @return array
	 */
	public static function get_theme_choices() {
		$choices = array( 'classic' => 'Classic' );

		foreach ( self::get_all_themes() as $slug => $config ) {
			$choices[ $slug ] = $config['name'];
		}

		return $choices;
	}

	/**
	 * Enqueue a theme's CSS and optional JS with a dependency on the base
	 * h3vt-tours stylesheet/script.
	 *
	 * Does nothing for 'classic' or unknown slugs.
	 *
	 * @param string $slug Theme slug.
	 */
	public static function enqueue_theme_assets( $slug ) {
		if ( 'classic' === $slug || empty( $slug ) ) {
			return;
		}

		$theme_dir = H3VT_TOURS_PATH . 'themes/' . $slug . '/';
		$theme_url = H3VT_TOURS_URL  . 'themes/' . $slug . '/';

		if ( file_exists( $theme_dir . 'style.css' ) ) {
			wp_enqueue_style(
				'h3vt-tours-theme-' . $slug,
				$theme_url . 'style.css',
				array( 'h3vt-tours' ),
				H3VT_TOURS_VERSION
			);
		}

		if ( file_exists( $theme_dir . 'script.js' ) ) {
			wp_enqueue_script(
				'h3vt-tours-theme-' . $slug,
				$theme_url . 'script.js',
				array( 'h3vt-tours' ),
				H3VT_TOURS_VERSION,
				true
			);
		}
	}

	/**
	 * Return theme asset URLs for the REST API response.
	 *
	 * @param string $slug Theme slug.
	 * @return array With keys 'theme_css_url' and 'theme_js_url' (null when absent).
	 */
	public static function get_theme_asset_urls( $slug ) {
		$urls = array(
			'theme_css_url' => null,
			'theme_js_url'  => null,
		);

		if ( 'classic' === $slug || empty( $slug ) ) {
			return $urls;
		}

		$theme_dir = H3VT_TOURS_PATH . 'themes/' . $slug . '/';
		$theme_url = H3VT_TOURS_URL  . 'themes/' . $slug . '/';

		if ( file_exists( $theme_dir . 'style.css' ) ) {
			$urls['theme_css_url'] = $theme_url . 'style.css';
		}

		if ( file_exists( $theme_dir . 'script.js' ) ) {
			$urls['theme_js_url'] = $theme_url . 'script.js';
		}

		return $urls;
	}

	/**
	 * Return the HTML head markup (e.g. Google Font link) for a theme.
	 *
	 * @param string $slug Theme slug.
	 * @return string HTML string or empty.
	 */
	public static function get_head_markup( $slug ) {
		if ( 'classic' === $slug || empty( $slug ) ) {
			return '';
		}

		$config = self::get_theme( $slug );

		if ( ! $config || empty( $config['head_markup'] ) ) {
			return '';
		}

		return $config['head_markup'];
	}
}
