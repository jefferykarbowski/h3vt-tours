# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

H3vt Tours is a WordPress plugin (GPL2 licensed). The codebase is in its earliest stage — currently only the main plugin entry file exists.

## Development Environment

- **PHP version**: 7.2 (configured in PhpStorm project settings)
- **Local environment**: Local by Flywheel — WordPress root at `~/Local Sites/sandbox/app/public`
- **IDE**: PhpStorm with PHP_CodeSniffer (warning level), PHP-CS-Fixer, PHPStan, Psalm, and PHP Mess Detector configured

## WordPress Plugin Conventions

- Main entry point: `h3vt-tours.php` (plugin bootstrap with standard WordPress plugin header)
- Follow WordPress Coding Standards for PHP (https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use WordPress hook system (`add_action`, `add_filter`) for all integrations
- Prefix all functions, classes, constants, and custom post types with `h3vt_tours_` to avoid namespace collisions
- Escape all output (`esc_html`, `esc_attr`, `esc_url`), sanitize all input (`sanitize_text_field`, etc.), and use `$wpdb->prepare()` for database queries

## Testing Locally

To test, symlink or copy the plugin directory into the Local by Flywheel WordPress installation:
```
Local Sites/sandbox/app/public/wp-content/plugins/h3vt-tours/
```
Then activate via WP Admin > Plugins.
