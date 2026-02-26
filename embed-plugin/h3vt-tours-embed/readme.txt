=== H3VT Tours Embed ===
Contributors: h3vt
Tags: virtual tour, embed, shortcode
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed virtual tours from an H3VT Tours host site using shortcodes.

== Description ==

H3VT Tours Embed allows you to display virtual tours on any WordPress site by
fetching tour content from a central H3VT Tours host via its REST API. Tours are
rendered server-side for SEO and cached using WordPress transients.

== Installation ==

1. Upload the `h3vt-tours-embed` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Go to **Settings → H3VT Tours** and enter the Tour Host URL.
4. Click **Test Connection** to verify.

== Usage ==

Embed a tour by ID:

    [h3vt_tour id="338"]

Embed a tour by slug:

    [h3vt_tour slug="arden-courts-of-anderson"]

Custom height:

    [h3vt_tour id="338" height="600px"]

Override the host URL for a single embed:

    [h3vt_tour id="338" host="https://other-host.example.com"]

== Frequently Asked Questions ==

= What does the host site need? =

The host site must be running the full H3VT Tours plugin, which provides the
REST API endpoint used by this embed plugin.

= How does caching work? =

Tour HTML is cached in WordPress transients. You can adjust the cache duration
in Settings or clear the cache manually at any time.

== Changelog ==

= 1.0.0 =
* Initial release.
