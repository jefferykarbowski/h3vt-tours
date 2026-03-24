# Gradient Picker for ACF — Design Spec

## Summary

A WordPress plugin that adds a "Gradient" field type to Advanced Custom Fields (ACF 6+). Users visually build CSS gradients using an interactive gradient bar powered by Grapick, with WordPress's native `wp-color-picker` for individual color stops. The field stores a complete CSS gradient string ready for use in templates.

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
- Gutenberg block integration

## Technical Requirements

- PHP 7.2+
- WordPress 6.0+
- ACF 6.0+ (free or PRO)
- No external dependencies at runtime (Grapick bundled)
- GPL-2.0+ license

## Architecture

### Plugin Bootstrap (`gradient-picker-for-acf.php`)

Standard WordPress plugin header. On `acf/include_field_types` hook, loads and instantiates the field class. Defines constants for version, path, URL. Checks for ACF availability before loading.

```php
// Hook into ACF field type registration
add_action( 'acf/include_field_types', function() {
    require_once GPFA_PATH . 'includes/class-gpfa-field.php';
    new GPFA_Field();
});
```

### Field Class (`includes/class-gpfa-field.php`)

Extends `acf_field`. Implements these methods:

#### `__construct()`
- `name`: `'gradient'`
- `label`: `'Gradient'`
- `category`: `'basic'`
- `defaults`: `['default_value' => '', 'direction' => '90deg']`

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
Enqueues assets only on admin pages where the field is used:
- `wp-color-picker` (core WP script + style)
- `grapick.min.js` + `grapick.min.css` from `assets/vendor/`
- `gpfa-field.js` + `gpfa-field.css` from `assets/`

#### `update_value( $value, $post_id, $field )`
Sanitizes and validates before save:
- `sanitize_text_field()` to strip tags/scripts
- Regex validation: must match `linear-gradient(...)` pattern
- Returns empty string if invalid

#### `format_value( $value, $post_id, $field )`
Returns the raw CSS string, escaped with `esc_attr()` for safe use in HTML attributes.

### JavaScript (`assets/js/gpfa-field.js`)

Initializes the Grapick instance per field, wires up `wp-color-picker` for each stop handler, and syncs changes back to the hidden input.

```javascript
// Pseudocode for field initialization
function initGradientField(el) {
    const input = el.querySelector('.gpfa-value');
    const bar = el.querySelector('.gpfa-gradient-bar');
    const directionSelect = el.querySelector('.gpfa-direction');
    const preview = el.querySelector('.gpfa-preview');

    const gp = new Grapick({ el: bar });

    // Wire wp-color-picker into each stop
    gp.setColorPicker(handler => {
        const wpPicker = createWpColorPicker(handler.getEl(), {
            defaultColor: handler.getColor(),
            change: (color) => handler.setColor(color),
            allowAlpha: true,
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
acf.addAction('ready append', function() {
    document.querySelectorAll('.gpfa-field:not(.gpfa-initialized)').forEach(el => {
        el.classList.add('gpfa-initialized');
        initGradientField(el);
    });
});
```

Key behaviors:
- Uses `acf.addAction('ready append', ...)` to handle fields inside repeaters/flexible content
- Parses existing gradient values to restore stop positions and colors on page load
- Custom angle input shown/hidden based on direction dropdown selection
- Alpha channel support via `wp-color-picker`'s `allowAlpha` option

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
    readme.txt                         # WordPress.org readme
    LICENSE                            # GPL-2.0+
```

## Data Flow

1. **Admin load**: ACF renders field -> `render_field()` outputs HTML -> JS initializes Grapick + wp-color-picker
2. **User edits**: Drags stops / picks colors / changes direction -> JS updates hidden input with full CSS string
3. **Save**: `update_value()` sanitizes and validates the CSS string -> stored in `wp_postmeta`
4. **Template**: `get_field('gradient')` returns `"linear-gradient(90deg, #ff6b00 0%, #1a1a1a 100%)"` via `format_value()`

## Validation & Security

- `update_value()`: `sanitize_text_field()` + regex whitelist for `linear-gradient(...)` pattern
- `format_value()`: `esc_attr()` for safe HTML attribute use
- No raw user input echoed without escaping
- Grapick bundled locally (no CDN/external requests)
- `escaping_html` support flag set for ACF 6.2.5+

## WordPress.org Compliance

- GPL-2.0+ license (Grapick MIT is compatible)
- No external API calls or tracking
- No premium upsells or nag notices
- All assets bundled locally
- Internationalized with `gradient-picker-for-acf` text domain
- `readme.txt` follows WordPress.org format with description, installation, FAQ, changelog, screenshots
- Tested up to latest WP and ACF versions

## Future Considerations (post-v1)

- Radial gradient support
- Repeating gradient support
- Gradient presets / saved gradients
- Copy/paste gradient values
- REST API field registration
- Gutenberg sidebar panel integration
