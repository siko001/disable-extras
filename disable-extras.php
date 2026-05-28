<?php
/**
 * Plugin Name: Disable Extras
 * Description: Disables selected admin features and capabilities (updates, editors, installs, activation, customizer pieces).
<<<<<<< HEAD
 * Version: 1.0.3
=======
 * Version: 1.0.0
>>>>>>> fd32b74578b5786d79d93dee7520d53d2069c005
 * Author: ATX - Neil VM
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/Traits/Singleton.php';
require_once __DIR__ . '/src/DisableExtras.php';
require_once __DIR__ . '/src/Support/GitHubPluginUpdater.php';

register_activation_hook(__FILE__, function () {
    if (get_option('disable_extras_enabled', null) === null) {
        add_option('disable_extras_enabled', 1);
    }

    if (get_option('disable_extras_owner_user_id', null) === null) {
        $owner_id = get_current_user_id();
        if (! $owner_id) {
            $admins = get_users([
                'role'   => 'administrator',
                'number' => 1,
                'fields' => ['ID'],
            ]);
            $owner_id = ! empty($admins) ? (int) $admins[0]->ID : 0;
        }
        add_option('disable_extras_owner_user_id', (int) $owner_id);
    }
});

add_action('plugins_loaded', function () {
    \App\DisableExtras::getInstance();

    if (is_admin() && class_exists('\\Vendor\\Plugin\\Support\\GitHubPluginUpdater')) {
        (new \Vendor\Plugin\Support\GitHubPluginUpdater(__FILE__, __DIR__))->register();
    }
});
