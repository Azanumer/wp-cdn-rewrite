<?php
/**
 * Plugin Name: WP CDN Rewrite
 * Description: Rewrites static asset URLs (images, CSS, JS, fonts) to your CDN origin-pull hostname on the front end. Zero template changes.
 * Version:     1.0.0
 * Author:      Azan Umer
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-cdn-rewrite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCDR_VERSION', '1.0.0' );
define( 'WCDR_OPTION', 'wcdr_settings' );

/**
 * Settings with defaults.
 *
 * @return array
 */
function wcdr_get_settings() {
    $defaults = array(
        'enabled'      => 0,
        'cdn_host'     => '',   // e.g. cdn.example.com (no scheme, no path)
        'extensions'   => 'jpg jpeg png gif webp avif svg ico css js mjs woff woff2 ttf eot mp4 webm pdf',
        'uploads_only' => 0,   // only rewrite URLs under wp-content/uploads
    );
    $saved = get_option( WCDR_OPTION, array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Normalized extension list (lowercase, no dots).
 *
 * @return array
 */
function wcdr_extensions() {
    $settings = wcdr_get_settings();
    $raw      = preg_split( '/[\s,]+/', strtolower( $settings['extensions'] ) );
    $exts     = array();
    foreach ( (array) $raw as $ext ) {
        $ext = trim( ltrim( $ext, '.' ) );
        if ( $ext !== '' ) {
            $exts[] = $ext;
        }
    }
    $exts = array_unique( $exts );
    return apply_filters( 'wcdr_extensions', $exts );
}

/**
 * Decide whether the rewrite engine should run for this request.
 *
 * @return bool
 */
function wcdr_should_run() {
    $settings = wcdr_get_settings();
    if ( empty( $settings['enabled'] ) || empty( $settings['cdn_host'] ) ) {
        return false;
    }
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
        return false;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }
    if ( is_preview() || is_customize_preview() || is_feed() || is_robots() || is_trackback() ) {
        return false;
    }
    if ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' ) {
        return false;
    }
    return apply_filters( 'wcdr_skip_rewrite', false ) ? false : true;
}

/**
 * Output-buffer callback: rewrite asset URLs to the CDN host.
 *
 * Matches http(s)://site-host and //site-host URLs whose path ends with a
 * configured extension (query string / fragment preserved), and swaps the
 * host for the CDN host (protocol-relative).
 *
 * @param string $html Full page HTML.
 * @return string
 */
function wcdr_rewrite_buffer( $html ) {
    $settings = wcdr_get_settings();
    $cdn_host = strtolower( trim( $settings['cdn_host'], " \t\n\r\0\x0B/." ) );
    $site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    $exts     = wcdr_extensions();

    if ( $cdn_host === '' || $site_host === '' || empty( $exts ) || $cdn_host === $site_host ) {
        return $html;
    }

    $ext_pattern = implode( '|', array_map( function ( $e ) {
        return preg_quote( $e, '#' );
    }, $exts ) );

    // Optional uploads-only restriction: path must start with the uploads dir.
    $path_prefix = '';
    if ( ! empty( $settings['uploads_only'] ) ) {
        $uploads = wp_parse_url( wp_upload_dir()['baseurl'], PHP_URL_PATH );
        if ( $uploads ) {
            $path_prefix = preg_quote( rtrim( $uploads, '/' ), '#' );
        }
    }

    // Scheme (optional) + site host + path ending in an allowed extension +
    // optional query/fragment. Stops at quote, whitespace, <, or ).
    $pattern = '#(?P<prefix>(?:https?:)?//'
        . preg_quote( $site_host, '#' )
        . ')(?P<path>' . $path_prefix . '/[^\s"\'<>()]*?\.(?:' . $ext_pattern . '))'
        . '(?P<tail>(?:[?#][^\s"\'<>()]*)?)(?=[\s"\'<>()]|$)#i';

    return preg_replace_callback(
        $pattern,
        function ( $m ) use ( $cdn_host ) {
            $original = $m['prefix'] . $m['path'] . $m['tail'];
            $new      = '//' . $cdn_host . $m['path'] . $m['tail'];
            // Let themes/plugins tweak or veto per URL (return '' to skip).
            $new = apply_filters( 'wcdr_cdn_url', $new, $original );
            return $new === '' ? $original : $new;
        },
        $html
    );
}

function wcdr_start_buffer() {
    if ( wcdr_should_run() ) {
        ob_start( 'wcdr_rewrite_buffer' );
    }
}
add_action( 'template_redirect', 'wcdr_start_buffer', 1 );

// --- Settings page: Settings → CDN Rewrite ------------------------------------

function wcdr_register_settings() {
    register_setting( 'wcdr_settings_group', WCDR_OPTION, 'wcdr_sanitize_settings' );
}
add_action( 'admin_init', 'wcdr_register_settings' );

function wcdr_sanitize_settings( $input ) {
    $out = array();
    $out['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;
    $host = isset( $input['cdn_host'] ) ? strtolower( trim( $input['cdn_host'] ) ) : '';
    // Accept a pasted full URL gracefully: keep only the host.
    if ( preg_match( '#^https?://#i', $host ) ) {
        $host = (string) wp_parse_url( $host, PHP_URL_HOST );
    }
    $host = trim( $host, " \t\n\r\0\x0B/." );
    $out['cdn_host'] = preg_match( '/^[a-z0-9.-]+$/', $host ) ? $host : '';
    $exts = isset( $input['extensions'] ) ? $input['extensions'] : '';
    $list = array();
    foreach ( preg_split( '/[\s,]+/', strtolower( $exts ) ) as $ext ) {
        $ext = trim( ltrim( $ext, '.' ) );
        if ( $ext !== '' && preg_match( '/^[a-z0-9]{1,5}$/', $ext ) ) {
            $list[] = $ext;
        }
    }
    $out['extensions']   = implode( ' ', array_unique( $list ) );
    $out['uploads_only'] = ! empty( $input['uploads_only'] ) ? 1 : 0;
    return $out;
}

function wcdr_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $settings = wcdr_get_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'CDN Rewrite', 'wp-cdn-rewrite' ); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'wcdr_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable', 'wp-cdn-rewrite' ); ?></th>
                    <td><label>
                        <input type="checkbox" name="<?php echo esc_attr( WCDR_OPTION ); ?>[enabled]" value="1"
                            <?php checked( $settings['enabled'], 1 ); ?> />
                        <?php esc_html_e( 'Rewrite static asset URLs to the CDN host', 'wp-cdn-rewrite' ); ?>
                    </label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'CDN host', 'wp-cdn-rewrite' ); ?></th>
                    <td>
                        <input type="text" class="regular-text" name="<?php echo esc_attr( WCDR_OPTION ); ?>[cdn_host]"
                            value="<?php echo esc_attr( $settings['cdn_host'] ); ?>"
                            placeholder="cdn.example.com" />
                        <p class="description"><?php esc_html_e( 'Hostname only — no scheme, no path. Must point at your origin (origin-pull) or a synced bucket.', 'wp-cdn-rewrite' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Extensions', 'wp-cdn-rewrite' ); ?></th>
                    <td>
                        <input type="text" class="large-text" name="<?php echo esc_attr( WCDR_OPTION ); ?>[extensions]"
                            value="<?php echo esc_attr( $settings['extensions'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Space or comma separated. Only URLs ending in these are rewritten.', 'wp-cdn-rewrite' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Uploads only', 'wp-cdn-rewrite' ); ?></th>
                    <td><label>
                        <input type="checkbox" name="<?php echo esc_attr( WCDR_OPTION ); ?>[uploads_only]" value="1"
                            <?php checked( $settings['uploads_only'], 1 ); ?> />
                        <?php esc_html_e( 'Only rewrite URLs under wp-content/uploads (safer when theme/plugin assets are not on the CDN)', 'wp-cdn-rewrite' ); ?>
                    </label></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php if ( ! empty( $settings['cdn_host'] ) ) : ?>
            <h2><?php esc_html_e( 'Live preview', 'wp-cdn-rewrite' ); ?></h2>
            <p><code><?php
                $sample = home_url( '/wp-content/uploads/2026/10/photo.jpg' );
                echo esc_html( $sample . '  →  //' . $settings['cdn_host'] . '/wp-content/uploads/2026/10/photo.jpg' );
            ?></code></p>
            <p class="description"><?php esc_html_e( 'Skipped automatically: wp-admin, AJAX, cron, REST, feeds, previews, customizer, login page.', 'wp-cdn-rewrite' ); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

function wcdr_admin_menu() {
    add_options_page(
        __( 'CDN Rewrite', 'wp-cdn-rewrite' ),
        __( 'CDN Rewrite', 'wp-cdn-rewrite' ),
        'manage_options',
        'wcdr-settings',
        'wcdr_settings_page'
    );
}
add_action( 'admin_menu', 'wcdr_admin_menu' );
