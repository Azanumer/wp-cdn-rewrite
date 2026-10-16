# WP CDN Rewrite

Serve your static assets from a CDN without touching a single template: on
every front-end page load the plugin rewrites `https://yoursite.com/…`
asset URLs (images, CSS, JS, fonts…) to protocol-relative
`//cdn.yoursite.com/…` URLs inside the final HTML — including `srcset`.

## How it works

1. On `template_redirect` (front end only) an output buffer is started.
2. A regex finds site-host URLs whose path ends in a configured extension,
   preserving query strings and fragments.
3. The host is swapped for your CDN host; per-URL filtering is available.

Skipped automatically: wp-admin, AJAX, cron, REST API, feeds, previews,
customizer, and the login page — so dynamic/admin behavior never changes.

## Settings (Settings → CDN Rewrite)

- **CDN host** — hostname only, e.g. `cdn.example.com`. Must serve your files
  (origin-pull CDN or a synced bucket); a pasted full URL is normalized.
- **Extensions** — space/comma separated list of what gets rewritten.
- **Uploads only** — restrict rewriting to `wp-content/uploads/` (safer when
  theme/plugin assets are not on the CDN).

## Filters

```php
// Change or veto the rewritten URL per asset (return '' to keep the original).
add_filter( 'wcdr_cdn_url', function ( $new, $original ) {
    if ( strpos( $original, '/private/' ) !== false ) {
        return ''; // never serve private files from the CDN
    }
    return $new;
}, 10, 2 );

// Override the extension list in code.
add_filter( 'wcdr_extensions', fn( $exts ) => array_merge( $exts, [ 'avif' ] ) );

// Disable rewriting for a specific request.
add_filter( 'wcdr_skip_rewrite', '__return_true' );
```

## Install

Upload the folder to `wp-content/plugins/`, activate, set your CDN host
under **Settings → CDN Rewrite**. View-source a front-end page to verify the
rewrite; purge the CDN once after enabling. Uninstalling removes the option.

MIT licensed.
