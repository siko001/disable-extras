<?php

namespace App;

use App\Traits\Singleton;

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists(__NAMESPACE__ . '\\DisableExtras')) {
    class DisableExtras
    {
        use Singleton;

        private const OPTION_ENABLED = 'disable_extras_enabled';
        private const OPTION_OWNER_ID = 'disable_extras_owner_user_id';
        private const OPTION_ALLOW_CO_MANAGERS = 'disable_extras_allow_co_managers';
        private const OPTION_CO_MANAGER_IDS = 'disable_extras_co_manager_user_ids';
        private const OPTION_USER_RULES = 'disable_extras_user_rules';

        private const PAGE_SLUG = 'disable-extras-settings';
        private const USER_RULES_SLUG = 'disable-extras-user-rules';

        private const FEATURES = [
            'disable_admin_menu',
            'disable_file_editors',
            'disable_updates',
            'disable_plugin_theme_deletion',
            'disable_plugin_theme_activation',
            'disable_theme_plugin_installation',
            'disable_customizer_options',
        ];

        private const FEATURE_LABELS = [
            'disable_admin_menu' => 'Hide Admin Menu Items',
            'disable_file_editors' => 'Disable File Editors',
            'disable_updates' => 'Disable Updates',
            'disable_plugin_theme_deletion' => 'Disable Plugin/Theme Deletion',
            'disable_plugin_theme_activation' => 'Disable Plugin/Theme Activation',
            'disable_theme_plugin_installation' => 'Disable Theme/Plugin Installation',
            'disable_customizer_options' => 'Disable Customizer Options',
        ];

        private function __construct()
        {
            $this->registerManagementHooks();

            if (! $this->isEnabled()) {
                return;
            }

            $rules = $this->getCurrentUserRules();

            if (! empty($rules['disable_admin_menu'])) {
                $this->disableAdminMenu();
            }
            if (! empty($rules['disable_file_editors'])) {
                $this->disableFileEditors();
            }
            if (! empty($rules['disable_updates'])) {
                $this->disableUpdates();
            }
            if (! empty($rules['disable_plugin_theme_deletion'])) {
                $this->disablePluginThemeDeletion();
            }
            if (! empty($rules['disable_plugin_theme_activation'])) {
                $this->disablePluginThemeActivation();
            }
            if (! empty($rules['disable_theme_plugin_installation'])) {
                $this->disableThemePluginInstallation();
            }
            if (! empty($rules['disable_customizer_options'])) {
                $this->disableCustomizerOptions();
            }
        }

        private function registerManagementHooks()
        {
            $owner_id = $this->getOwnerId();
            if ($owner_id <= 0) {
                $fallback_owner_id = get_current_user_id();
                if ($fallback_owner_id <= 0) {
                    $admins = get_users([
                        'role'   => 'administrator',
                        'number' => 1,
                        'fields' => ['ID'],
                    ]);
                    $fallback_owner_id = ! empty($admins) ? (int) $admins[0]->ID : 0;
                }
                if ($fallback_owner_id > 0) {
                    update_option(self::OPTION_OWNER_ID, $fallback_owner_id);
                }
            }

            add_action('admin_menu', function () {
                if (! $this->canCurrentUserManageSettings()) {
                    return;
                }

                add_menu_page(
                    'Disable Extras',
                    'Disable Extras',
                    'manage_options',
                    self::PAGE_SLUG,
                    [$this, 'renderSettingsPage'],
                    'dashicons-shield',
                    59
                );

                add_submenu_page(
                    self::PAGE_SLUG,
                    'Disable Extras User Rules',
                    'User Rules',
                    'manage_options',
                    self::USER_RULES_SLUG,
                    [$this, 'renderUserRulesPage']
                );
            }, 60);

            add_action('delete_user', [$this, 'handleOwnerDeletion'], 10, 3);
        }

        private function isEnabled(): bool
        {
            return (int) get_option(self::OPTION_ENABLED, 1) === 1;
        }

        private function getOwnerId(): int
        {
            return (int) get_option(self::OPTION_OWNER_ID, 0);
        }

        private function isCurrentUserOwner(): bool
        {
            $current_user_id = get_current_user_id();
            return $current_user_id > 0 && $current_user_id === $this->getOwnerId();
        }

        private function allowCoManagers(): bool
        {
            return (int) get_option(self::OPTION_ALLOW_CO_MANAGERS, 0) === 1;
        }

        private function getCoManagerIds(): array
        {
            $ids = get_option(self::OPTION_CO_MANAGER_IDS, []);
            if (! is_array($ids)) {
                return [];
            }
            return array_values(array_unique(array_map('intval', $ids)));
        }

        private function canCurrentUserManageSettings(): bool
        {
            $current_user_id = get_current_user_id();
            if ($current_user_id <= 0) {
                return false;
            }
            if ($this->isCurrentUserOwner()) {
                return true;
            }
            if (! $this->allowCoManagers()) {
                return false;
            }
            return in_array($current_user_id, $this->getCoManagerIds(), true);
        }

        private function getAllUserRules(): array
        {
            $rules = get_option(self::OPTION_USER_RULES, []);
            return is_array($rules) ? $rules : [];
        }

        private function getDefaultFeatureRules(): array
        {
            $defaults = [];
            foreach (self::FEATURES as $feature) {
                $defaults[$feature] = 1;
            }
            return $defaults;
        }

        private function normalizeFeatureRules(array $raw): array
        {
            $normalized = $this->getDefaultFeatureRules();
            foreach (self::FEATURES as $feature) {
                $normalized[$feature] = ! empty($raw[$feature]) ? 1 : 0;
            }
            return $normalized;
        }

        private function getCurrentUserRules(): array
        {
            $user_id = get_current_user_id();
            if ($user_id <= 0) {
                return $this->getDefaultFeatureRules();
            }

            $all = $this->getAllUserRules();
            if (! isset($all[$user_id]) || ! is_array($all[$user_id])) {
                return $this->getDefaultFeatureRules();
            }

            return $this->normalizeFeatureRules($all[$user_id]);
        }

        private function getEligibleBackendUsers(): array
        {
            $users = get_users([
                'fields' => ['ID', 'display_name', 'user_login', 'roles'],
            ]);

            return array_values(array_filter($users, function ($user) {
                $roles = is_array($user->roles ?? null) ? $user->roles : [];
                if (in_array('subscriber', $roles, true) || in_array('customer', $roles, true)) {
                    return false;
                }
                return user_can((int) $user->ID, 'read');
            }));
        }

        public function handleOwnerDeletion($user_id, $reassign = null, $user = null)
        {
            $owner_id = $this->getOwnerId();
            if ((int) $user_id === $owner_id) {
                $new_owner_id = (int) get_current_user_id();
                if ($new_owner_id > 0) {
                    update_option(self::OPTION_OWNER_ID, $new_owner_id);
                }
            }

            $co_manager_ids = $this->getCoManagerIds();
            if (! empty($co_manager_ids)) {
                $co_manager_ids = array_values(array_filter(
                    $co_manager_ids,
                    fn($id) => (int) $id !== (int) $user_id
                ));
                update_option(self::OPTION_CO_MANAGER_IDS, $co_manager_ids);
            }

            $all_rules = $this->getAllUserRules();
            if (isset($all_rules[(int) $user_id])) {
                unset($all_rules[(int) $user_id]);
                update_option(self::OPTION_USER_RULES, $all_rules);
            }
        }

        public function renderSettingsPage()
        {
            if (! current_user_can('manage_options') || ! $this->canCurrentUserManageSettings()) {
                wp_die('You are not allowed to access this page.');
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                check_admin_referer('disable_extras_settings_action', 'disable_extras_settings_nonce');

                $enabled = isset($_POST['disable_extras_enabled']) ? 1 : 0;
                update_option(self::OPTION_ENABLED, $enabled);

                if ($this->isCurrentUserOwner() && isset($_POST['disable_extras_owner_user_id'])) {
                    $new_owner_id = (int) $_POST['disable_extras_owner_user_id'];
                    $new_owner = get_user_by('id', $new_owner_id);
                    if ($new_owner && user_can($new_owner_id, 'manage_options')) {
                        update_option(self::OPTION_OWNER_ID, $new_owner_id);
                    }
                }

                if ($this->isCurrentUserOwner()) {
                    $allow_co_managers = isset($_POST['disable_extras_allow_co_managers']) ? 1 : 0;
                    update_option(self::OPTION_ALLOW_CO_MANAGERS, $allow_co_managers);

                    $selected_ids = isset($_POST['disable_extras_co_manager_user_ids'])
                        ? (array) $_POST['disable_extras_co_manager_user_ids']
                        : [];

                    $owner_id = $this->getOwnerId();
                    $normalized_ids = [];
                    foreach ($selected_ids as $selected_id) {
                        $selected_id = (int) $selected_id;
                        if ($selected_id <= 0 || $selected_id === $owner_id) {
                            continue;
                        }
                        if (user_can($selected_id, 'manage_options')) {
                            $normalized_ids[] = $selected_id;
                        }
                    }

                    update_option(self::OPTION_CO_MANAGER_IDS, array_values(array_unique($normalized_ids)));
                }

                echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
            }

            $enabled = $this->isEnabled();
            $owner_id = $this->getOwnerId();
            $allow_co_managers = $this->allowCoManagers();
            $co_manager_ids = $this->getCoManagerIds();
            $users = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
            $eligible_users = array_filter($users, fn($user) => user_can((int) $user->ID, 'manage_options'));
            ?>
            <div class="wrap">
                <h1>Disable Extras</h1>
                <p>Owner can transfer ownership and optionally assign co-managers.</p>

                <form method="post">
                    <?php wp_nonce_field('disable_extras_settings_action', 'disable_extras_settings_nonce'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Disable Extras Status</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="disable_extras_enabled" value="1" <?php checked($enabled); ?>>
                                    Enabled
                                </label>
                                <p class="description">Toggle all Disable Extras restrictions on/off.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Assigned Owner</th>
                            <td>
                                <?php if ($this->isCurrentUserOwner()): ?>
                                    <select name="disable_extras_owner_user_id">
                                        <?php foreach ($eligible_users as $user): ?>
                                            <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($owner_id, (int) $user->ID); ?>>
                                                <?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <?php
                                    $owner_user = get_user_by('id', $owner_id);
                                    echo esc_html($owner_user ? ($owner_user->display_name . ' (' . $owner_user->user_login . ')') : 'Unknown');
                                    ?>
                                <?php endif; ?>
                                <p class="description">Owner always has access.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Allow Co-Managers</th>
                            <td>
                                <?php if ($this->isCurrentUserOwner()): ?>
                                    <label>
                                        <input type="checkbox" name="disable_extras_allow_co_managers" value="1" <?php checked($allow_co_managers); ?>>
                                        Allow additional admins to manage this page
                                    </label>
                                <?php else: ?>
                                    <strong><?php echo $allow_co_managers ? 'Enabled' : 'Disabled'; ?></strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Co-Managers</th>
                            <td>
                                <?php if ($this->isCurrentUserOwner()): ?>
                                    <select name="disable_extras_co_manager_user_ids[]" multiple size="8" style="min-width: 360px;">
                                        <?php foreach ($eligible_users as $user): ?>
                                            <?php if ((int) $user->ID === $owner_id) continue; ?>
                                            <option value="<?php echo esc_attr($user->ID); ?>" <?php selected(in_array((int) $user->ID, $co_manager_ids, true)); ?>>
                                                <?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Hold Cmd/Ctrl to select multiple admins.</p>
                                <?php else: ?>
                                    <?php
                                    if (empty($co_manager_ids)) {
                                        echo 'None';
                                    } else {
                                        $labels = [];
                                        foreach ($co_manager_ids as $id) {
                                            $u = get_user_by('id', $id);
                                            if ($u) {
                                                $labels[] = $u->display_name . ' (' . $u->user_login . ')';
                                            }
                                        }
                                        echo esc_html(implode(', ', $labels));
                                    }
                                    ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Save Settings'); ?>
                </form>
            </div>
            <?php
        }

        public function renderUserRulesPage()
        {
            if (! current_user_can('manage_options') || ! $this->canCurrentUserManageSettings()) {
                wp_die('You are not allowed to access this page.');
            }

            $eligible_users = $this->getEligibleBackendUsers();
            $all_rules = $this->getAllUserRules();

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                check_admin_referer('disable_extras_user_rules_action', 'disable_extras_user_rules_nonce');

                $posted_rules = isset($_POST['user_rules']) && is_array($_POST['user_rules'])
                    ? $_POST['user_rules']
                    : [];

                $new_rules = [];
                foreach ($eligible_users as $user) {
                    $uid = (int) $user->ID;
                    $raw = isset($posted_rules[$uid]) && is_array($posted_rules[$uid])
                        ? $posted_rules[$uid]
                        : [];
                    $new_rules[$uid] = $this->normalizeFeatureRules($raw);
                }

                update_option(self::OPTION_USER_RULES, $new_rules);
                $all_rules = $new_rules;
                echo '<div class="notice notice-success is-dismissible"><p>User rules saved.</p></div>';
            }
            ?>
            <div class="wrap">
                <h1>Disable Extras: User Rules</h1>
                <p>Set feature restrictions per backend user (custom roles included; subscribers/customers excluded).</p>

                <form method="post">
                    <?php wp_nonce_field('disable_extras_user_rules_action', 'disable_extras_user_rules_nonce'); ?>
                    <table class="widefat striped" style="table-layout: auto;">
                        <thead>
                            <tr>
                                <th>User</th>
                                <?php foreach (self::FEATURES as $feature): ?>
                                    <th><?php echo esc_html(self::FEATURE_LABELS[$feature]); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eligible_users as $user): ?>
                                <?php
                                $uid = (int) $user->ID;
                                $rules = isset($all_rules[$uid]) && is_array($all_rules[$uid])
                                    ? $this->normalizeFeatureRules($all_rules[$uid])
                                    : $this->getDefaultFeatureRules();
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($user->display_name); ?></strong><br>
                                        <code><?php echo esc_html($user->user_login); ?></code> · ID <?php echo esc_html((string) $uid); ?>
                                    </td>
                                    <?php foreach (self::FEATURES as $feature): ?>
                                        <td>
                                            <label>
                                                <input
                                                    type="checkbox"
                                                    name="user_rules[<?php echo esc_attr((string) $uid); ?>][<?php echo esc_attr($feature); ?>]"
                                                    value="1"
                                                    <?php checked(! empty($rules[$feature])); ?>
                                                >
                                                On
                                            </label>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php submit_button('Save User Rules'); ?>
                </form>
            </div>
            <?php
        }

        private function disableAdminMenu()
        {
            add_action('admin_menu', function () {
                // remove_menu_page('edit.php');                  // Posts
                // remove_menu_page('upload.php');                // Media
                // remove_menu_page('edit.php?post_type=page');   // Pages
                remove_menu_page('edit-comments.php');            // Comments
                remove_menu_page('tools.php');                    // Tools
                remove_menu_page('widgets.php');                  // Widgets
                remove_submenu_page('themes.php', 'widgets.php'); // Widgets under Appearance
            }, 999);
        }

        private function disableFileEditors()
        {
            add_action('admin_init', function () {
                if (! defined('DISALLOW_FILE_EDIT')) {
                    define('DISALLOW_FILE_EDIT', true);
                }
            });
        }

        public function disableUpdates()
        {
            // Auto-updates off everywhere
            add_filter('auto_update_plugin', '__return_false');
            add_filter('auto_update_theme', '__return_false');
            add_filter('auto_update_core', '__return_false');

            // Let WP-CLI see real update data (`wp plugin list`, `wp plugin update --all`).
            // Update cron still runs and populates the transients; we only hide them from the admin UI below.
            if (defined('WP_CLI') && \WP_CLI) {
                return;
            }

            // Hide updates from admin reads (transients still hold the real data for CLI).
            add_filter('site_transient_update_plugins', fn() => (object) ['last_checked' => time(), 'response' => [], 'checked' => []]);
            add_filter('site_transient_update_themes', fn() => (object) ['last_checked' => time(), 'response' => [], 'checked' => []]);
            add_filter('site_transient_update_core', fn() => (object) ['last_checked' => time(), 'version_checked' => time(), 'updates' => []]);

            // Hide update notices
            add_action('admin_init', function () {
                remove_action('admin_notices', 'update_nag', 3);
                remove_action('admin_notices', 'maintenance_nag', 10);
                remove_action('load-plugins.php', 'wp_plugin_update_rows');
                remove_action('load-themes.php', 'wp_theme_update_rows');

                // Hide update counts
                add_filter('wp_get_update_data', fn() => ['counts' => ['plugins' => 0, 'themes' => 0, 'wordpress' => 0, 'translations' => 0, 'total' => 0], 'title' => '']);
            }, 20);

            // Remove update links
            add_filter('plugin_action_links', fn($actions) => array_diff_key($actions, ['update' => true]), 20);
            add_filter('theme_action_links', fn($actions) => array_diff_key($actions, ['update' => true]), 20);

            // Hide update menus from admin bar
            add_action('wp_before_admin_bar_render', function () {
                global $wp_admin_bar;
                $wp_admin_bar->remove_node('updates');
            });

            // Disable update capability
            add_filter('map_meta_cap', function ($caps, $cap) {
                $update_caps = ['update_plugins', 'update_themes', 'update_core', 'update_languages'];
                return in_array($cap, $update_caps, true) ? ['do_not_allow'] : $caps;
            }, 10, 2);
        }

        private function disablePluginThemeActivation()
        {
            // Disable activating and deactivating plugins and themes.
            add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
                $disabled_caps = ['activate_plugin', 'deactivate_plugin', 'activate_theme', 'deactivate_theme'];
                if (in_array($cap, $disabled_caps, true)) {
                    return ['do_not_allow'];
                }
                return $caps;
            }, 999, 4);

            // Block theme activation completely.
            add_action('admin_init', function () {
                if (isset($_GET['action']) && $_GET['action'] === 'activate') {
                    wp_die('Theme activation is disabled for security reasons.');
                }
            });
        }

        private function disablePluginThemeDeletion()
        {
            add_filter('plugin_action_links', function ($actions, $plugin_file, $plugin_data, $context) {
                if (isset($actions['delete'])) {
                    unset($actions['delete']);
                }
                return $actions;
            }, 10, 4);

            add_filter('theme_action_links', function ($actions, $theme) {
                if (isset($actions['delete'])) {
                    unset($actions['delete']);
                }
                return $actions;
            }, 10, 2);
        }

        public function disableThemePluginInstallation()
        {
            add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
                $disabled_caps = ['install_themes', 'install_plugins'];

                if (in_array($cap, $disabled_caps, true)) {
                    return ['do_not_allow'];
                }

                return $caps;
            }, 10, 4);
        }

        private function disableCustomizerOptions()
        {
            add_filter('customize_loaded_components', fn($components) => array_diff($components, ['widgets']));

            add_action('customize_register', function ($wp_customize) {
                $wp_customize->remove_section('custom_css');
                $wp_customize->remove_control('custom_css');
            }, 20);
        }
    }
}
