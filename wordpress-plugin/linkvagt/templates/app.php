<?php

declare(strict_types=1);

use LinkVagt\Access;
use LinkVagt\Auth;

if (!defined('ABSPATH')) {
    exit;
}

if (!Access::can_access()) {
    status_header(401);
    nocache_headers();
    ?><!doctype html>
    <html lang="da">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>LinkVagt</title>
        <link rel="stylesheet" href="<?php echo esc_url(LINKVAGT_URL . 'assets/app.css?ver=' . LINKVAGT_VERSION); ?>">
    </head>
    <body>
        <main class="linkvagt-gate">
            <h1>LinkVagt</h1>
            <?php if (isset($_GET['linkvagt_logout'])) : ?>
                <p>Du er logget ud.</p>
            <?php endif; ?>
            <?php if (!is_user_logged_in()) : ?>
                <p>Log ind med din WordPress-konto for at fortsætte.</p>
                <p><a class="linkvagt-login-button" href="<?php echo esc_url(Auth::instance()->login_url()); ?>">Log ind</a></p>
            <?php else : ?>
                <p class="linkvagt-error">Kontoen <?php echo esc_html((string) wp_get_current_user()->user_email); ?> har ikke adgang til LinkVagt.</p>
                <p>Bed ejeren om en invitation, eller log ind med en anden konto.</p>
                <p><a class="linkvagt-login-button" href="<?php echo esc_url(Auth::instance()->logout_url()); ?>">Log ud</a></p>
            <?php endif; ?>
        </main>
    </body>
    </html><?php
    exit;
}

$shell = file_get_contents(LINKVAGT_DIR . 'assets/dashboard.html');
if (!is_string($shell)) {
    wp_die(esc_html__('LinkVagt-dashboardet kunne ikke indlæses.', 'linkvagt'));
}

$configuration = [
    'restRoot' => esc_url_raw(rest_url('linkvagt/v1')),
    'nonce' => wp_create_nonce('wp_rest'),
    'exportUrl' => esc_url_raw(admin_url('admin-post.php?action=linkvagt_export')),
    'exportNonce' => wp_create_nonce('linkvagt_export'),
    'currentUser' => [
        'email' => (string) wp_get_current_user()->user_email,
    ],
    'version' => LINKVAGT_VERSION,
];
$bootstrap = '<script>window.LINKVAGT='
    . wp_json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . ';</script>';

$shell = str_replace(
    ['href="/styles.css"', '<script type="module" src="/app.js"></script>'],
    [
        'href="' . esc_url(LINKVAGT_URL . 'assets/dashboard.css?ver=' . LINKVAGT_VERSION) . '"',
        $bootstrap . '<script type="module" src="' . esc_url(LINKVAGT_URL . 'assets/dashboard.js?ver=' . LINKVAGT_VERSION) . '"></script>',
    ],
    $shell
);

echo $shell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted bundled template.
