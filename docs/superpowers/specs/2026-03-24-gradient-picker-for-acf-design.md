# Gradient Picker for ACF — Design Spec

## Summary

A WordPress plugin that adds a "Gradient" field type to Advanced Custom Fields (ACF 6+). Users visually build CSS gradients using an interactive gradient bar powered by Grapick, with WordPress's native `wp-color-picker` (extended with `wp-color-picker-alpha` for opacity support) for individual color stops. The field stores a complete CSS gradient string ready for use in templates.

## Goals

- Provide a visual, intuitive gradient picker field for ACF
- Store full CSS gradient strings (`linear-gradient(...)`) in the database
- Integrate seamlessly with the WordPress admin UI
- Submit to the WordPress.org plugin directory
- Keep v1 focused: linear gradients only, radial in a future release

## Non-Goals (v1)

- Radial gradients
- Repeating gradients
- Gradient presets library
- Frontend rendering helpers beyond `get_field()`
- Gutenberg block integration (though fields will work in the Gutenberg sidebar via ACF's meta box)

## Technical Requirements

- PHP 7.2+
- WordPress 6.0+
- ACF 6.0+ (free or PRO)
- No external dependencies at runtime (Grapick and wp-color-picker-alpha bundled)
- GPL-2.0+ license

## Architecture

### Plugin Bootstrap (`gradient-picker-for-acf.php`)

Standard WordPress plugin header with `Requires Plugins: advanced-custom-fields` (WP 6.5+ dependency signaling). Defines constants for version, path, URL. On `acf/include_field_types` hook, loads and registers the field class via `acf_register_field_type()`. If ACF is not active, displays an admin notice and returns early. Calls `load_plugin_textdomain()` for i18n.

```php
/**
 * Plugin Name: Gradient Picker for ACF
 * Requires Plugins: advanced-custom-fields
 * Text Domain: gradient-picker-for-acf
 * License: GPL-2.0+
 */

define( 'GPFA_VERSION', '1.0.0' );
define( 'GPFA_PATH', plugin_dir_path( __FILE__ ) );
define( 'GPFA_URL', plugin_dir_url( __FILE__ ) );

// Load text domain.
add_action( 'init', function() {
    load_plugin_textdomain( 'gradient-picker-for-acf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});

// Register field type with ACF.
add_action( 'acf/include_field_types', function() {
    require_once GPFA_PATH . 'includes/class-gpfa-field.php';
    acf_register_field_type( 'GPFA_Field' );
});

// Admin notice if ACF is not active.
add_action( 'admin_notices', function() {
    if ( class_exists( 'ACF' ) ) {
        return;
    }
    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__( 'Gradient Picker for ACF requires Advanced Custom Fields 6.0 or later.', 'gradient-picker-for-acf' )
    );
});
```

### Field Class (`includes/class-gpfa-field.php`)

Extends `acf_field`. Implements these methods:

#### `__construct()`
- `name`: `'gradient'`
- `label`: `'Gradient'`
- `category`: `'basic'`
- `defaults`: `['default_value' => '', 'direction' => '90deg']`
- `supports`: `['escaping_html' => true]` (ACF 6.2.5+ safe output)

#### `render_field_settings( $field )`
Admin UI for configuring the field instance:
- **Default Value** — text input for a default CSS gradient string
- **Direction Presets** — checkbox group for which direction options to show (to right, to bottom, 45deg, 90deg, 135deg, 180deg, custom angle)

#### `render_field( $field )`
Outputs the admin field HTML:
- Hidden `<input>` storing the serialized gradient CSS value
- Container `<div>` for the Grapick gradient bar
- Direction selector dropdown with presets
- Small read-only text preview of the current CSS value

```html
<div class="gpfa-field" data-direction="{direction}">
    <input type="hidden" name="{field_name}" value="{value}" class="gpfa-value">
    <div class="gpfa-gradient-bar"></div>
    <div class="gpfa-controls">
        <select class="gpfa-direction">
            <option value="to right">To Right</option>
            <option value="to left">To Left</option>
            <option value="to bottom">To Bottom</option>
            <option value="to top">To Top</option>
            <option value="45deg">45 deg</option>
            <option value="90deg">90 deg</option>
            <option value="135deg">135 deg</option>
            <option value="180deg">180 deg</option>
        </select>
        <input type="number" class="gpfa-custom-angle" min="0" max="360" step="1" placeholder="deg">
    </div>
    <code class="gpfa-preview"></code>
</div>
```

#### `input_admin_enqueue_scripts()`
Enqueues assets only on admin pages where the field is used. All handles prefixed with `gpfa-`:
- `wp-color-picker` (core WP script + style)
- `gpfa-wp-color-picker-alpha` — bundled `wp-color-picker-alpha` shim for rgba/hsla support
- `gpfa-grapick` + `gpfa-grapick-css` from `assets/vendor/`
- `gpfa-field` + `gpfa-field-css` from `assets/`

#### `update_value( $value, $post_id, $field )`
Sanitizes and validates before save. The `$post_id` parameter may be a numeric post ID or a string like `"options"`, `"user_42"`, or `"term_5"` — the field handles all ACF storage contexts since it stores/returns a plain string.

- `sanitize_text_field()` to strip tags/scripts
- Regex validation against a strict whitelist pattern:

```php
$pattern = '/^linear-gradient\(\s*'
    . '(to\s+(top|bottom|left|right)(\s+(top|bottom|left|right))?'
    . '|\d{1,3}(\.\d+)?deg)'
    . '\s*,'
    . '(\s*(#[0-9a-fA-F]{3,8}'
    . '|rgba?\(\s*[\d.\s,%]+\)'
    . '|hsla?\(\s*[\d.\s,%]+\)'
    . '|[a-z]+)'
    . '\s+\d{1,3}(\.\d+)?%'
    . '\s*,?)+'
    . '\s*\)$/i';
```

- Returns empty string if invalid or if the value is empty (cleared field)

#### `format_value( $value, $post_id, $field )`
Returns the raw CSS string, escaped with `esc_attr()` for safe use in HTML attributes.

### JavaScript (`assets/js/gpfa-field.js`)

Initializes the Grapick instance per field, wires up `wp-color-picker` (with alpha shim) for each stop handler, and syncs changes back to the hidden input.

```javascript
// Pseudocode for field initialization
function initGradientField(el) {
    const input = el.querySelector('.gpfa-value');
    const bar = el.querySelector('.gpfa-gradient-bar');
    const directionSelect = el.querySelector('.gpfa-direction');
    const preview = el.querySelector('.gpfa-preview');

    const gp = new Grapick({ el: bar });

    // Wire wp-color-picker (with alpha shim) into each stop
    gp.setColorPicker(handler => {
        const $el = jQuery(handler.getEl());
        const $input = jQuery('<input type="text">').val(handler.getColor()).appendTo($el);
        $input.wpColorPicker({
            defaultColor: handler.getColor(),
            change: function(event, ui) {
                handler.setColor(ui.color.toString());
            },
            clear: function() {
                handler.setColor('transparent');
            },
            palettes: true,
        });
    });

    // Parse existing value and populate stops
    if (input.value) {
        parseAndApply(gp, input.value);
    } else {
        gp.addHandler(0, '#ffffff');
        gp.addHandler(100, '#000000');
    }

    // Sync changes to hidden input
    function updateValue() {
        // Prevent storing a gradient with no stops
        if (gp.getHandlers().length === 0) {
            input.value = '';
            preview.textContent = '';
            return;
        }
        const direction = directionSelect.value;
        const value = 'linear-gradient(' + direction + ', ' + gp.getSafeValue() + ')';
        input.value = value;
        preview.textContent = value;
    }

    gp.on('change', updateValue);
    directionSelect.addEventListener('change', updateValue);

    updateValue();
}

// Initialize on ACF ready and on repeater/flexible content row add
acf.addAction('ready', initAll);
acf.addAction('append', initAll);

function initAll() {
    document.querySelectorAll('.gpfa-field:not(.gpfa-initialized)').forEach(el => {
        el.classList.add('gpfa-initialized');
        initGradientField(el);
    });
}
```

Key behaviors:
- Uses separate `acf.addAction('ready', ...)` and `acf.addAction('append', ...)` calls for reliable ACF 6.x compatibility
- Handles repeaters, flexible content, clone fields, and Gutenberg sidebar meta boxes
- Parses existing gradient values to restore stop positions and colors on page load
- Custom angle input shown/hidden based on direction dropdown selection
- Alpha channel support via bundled `wp-color-picker-alpha` shim (core `wp-color-picker` does not natively support alpha)
- Empty state: if all stops are removed, the hidden input is cleared to an empty string

### CSS (`assets/css/gpfa-field.css`)

Minimal styling to integrate Grapick within the ACF field layout:
- Gradient bar height: ~40px, full width of the field container
- Direction controls inline below the bar
- Preview text in monospace, small font
- Consistent spacing with ACF's field padding

### Gradient Parsing

A small JS utility to parse existing `linear-gradient(...)` strings back into Grapick stops:

```javascript
function parseLinearGradient(value) {
    // Extract direction and stops from "linear-gradient(90deg, #fff 0%, #000 100%)"
    const match = value.match(/linear-gradient\((.+)\)/);
    if (!match) return null;

    const parts = match[1].split(',').map(s => s.trim());
    const direction = isDirection(parts[0]) ? parts.shift() : '90deg';
    const stops = parts.map(part => {
        const [color, position] = part.split(/\s+(?=\d)/);
        return { color: color.trim(), position: parseFloat(position) };
    });

    return { direction, stops };
}
```

### PHP Helper Function

Registered globally for theme developers:

```php
/**
 * Parse a CSS gradient string into its components.
 *
 * @param string $value CSS gradient value.
 * @return array|false Parsed gradient or false on failure.
 */
function gpfa_parse_gradient( $value ) {
    if ( ! preg_match( '/^linear-gradient\((.+)\)$/', $value, $m ) ) {
        return false;
    }

    $inner = $m[1];
    $parts = array_map( 'trim', str_getcsv( $inner ) );
    $direction = '180deg';
    $stops = array();

    // Check if first part is a direction
    if ( preg_match( '/^(to\s+\w+(\s+\w+)?|\d+deg)$/', $parts[0] ) ) {
        $direction = array_shift( $parts );
    }

    foreach ( $parts as $part ) {
        if ( preg_match( '/^(.+?)\s+([\d.]+%?)$/', $part, $sm ) ) {
            $stops[] = array(
                'color'    => $sm[1],
                'position' => $sm[2],
            );
        }
    }

    return array(
        'type'      => 'linear',
        'direction' => $direction,
        'stops'     => $stops,
    );
}
```

## File Structure

```
gradient-picker-for-acf/
    gradient-picker-for-acf.php        # Plugin bootstrap
    includes/
        class-gpfa-field.php           # ACF field class (extends acf_field)
    assets/
        css/
            gpfa-field.css             # Admin field styles
        js/
            gpfa-field.js              # Field init, Grapick + wp-color-picker wiring
        vendor/
            grapick.min.js             # Grapick library (MIT, bundled)
            grapick.min.css            # Grapick styles (MIT, bundled)
            wp-color-picker-alpha.min.js  # Alpha shim (GPL-2.0+, bundled)
    languages/                         # i18n .pot/.po/.mo files
    readme.txt                         # WordPress.org readme
    LICENSE                            # GPL-2.0+
```

Note: Bundled vendor files must include copyright/license header comments at the top of each file for WordPress.org compliance.

## Data Flow

1. **Admin load**: ACF renders field -> `render_field()` outputs HTML -> JS initializes Grapick + wp-color-picker
2. **User edits**: Drags stops / picks colors / changes direction -> JS updates hidden input with full CSS string
3. **Save**: `update_value()` sanitizes and validates the CSS string -> stored in `wp_postmeta` (or options/termmeta/usermeta depending on ACF context)
4. **Template**: `get_field('gradient')` returns `"linear-gradient(90deg, #ff6b00 0%, #1a1a1a 100%)"` via `format_value()`

## Validation & Security

- `update_value()`: `sanitize_text_field()` + strict regex whitelist for `linear-gradient(...)` pattern (see regex in update_value section above)
- `format_value()`: `esc_attr()` for safe HTML attribute use
- No raw user input echoed without escaping
- Grapick and wp-color-picker-alpha bundled locally (no CDN/external requests)
- `escaping_html` support flag set for ACF 6.2.5+
- Empty/cleared fields store an empty string (no invalid partial values)

## WordPress.org Compliance

- GPL-2.0+ license (Grapick MIT and wp-color-picker-alpha GPL-2.0+ are compatible)
- `Requires Plugins: advanced-custom-fields` header for WP 6.5+ dependency signaling
- No external API calls or tracking
- No premium upsells or nag notices
- All assets bundled locally with copyright/license headers in vendor files
- Internationalized with `gradient-picker-for-acf` text domain and `load_plugin_textdomain()`
- `readme.txt` follows WordPress.org format with description, installation, FAQ, changelog, screenshots
- Tested up to: WordPress 6.7, ACF 6.3
- Requires at least: WordPress 6.0
- All enqueue handles prefixed with `gpfa-` to avoid collisions
- No `uninstall.php` in v1 — field data is stored via ACF's standard postmeta mechanism and should persist if plugin is deactivated (standard ACF field behavior)

## Future Considerations (post-v1)

- Radial gradient support
- Repeating gradient support
- Gradient presets / saved gradients
- Copy/paste gradient values
- REST API field registration
- Gutenberg sidebar panel integration
