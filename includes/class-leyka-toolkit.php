<?php
if (!defined('ABSPATH')) {
    exit;
}

class Leyka_Toolkit {

    const OPTION_KEY = 'leyka_toolkit_settings';

    private static $instance = null;
    private static $settings_cache = null;
    private static $render_count = 0;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        if (!defined('LEYKA_VERSION')) {
            deactivate_plugins(plugin_basename(LEYKA_TOOLKIT_FILE));
            wp_die(
                'Плагин Leyka Toolkit требует установленного и активного плагина Leyka.',
                'Leyka Toolkit — ошибка активации',
                ['back_link' => true]
            );
        }

        $current = get_option(self::OPTION_KEY, []);
        if (!is_array($current)) {
            $current = [];
        }
        update_option(self::OPTION_KEY, array_merge(self::defaults(), $current));

        // Create log directory on activation.
        $dir = self::get_log_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/.htaccess', 'deny from all');
        }
    }

    public static function defaults() {
        return [
            'enabled'     => 1,
            'label'       => 'Подписаться на новости',
            'checked'     => 0,
            'tag'         => 'newsletter',
            'log_enabled' => 0,
        ];
    }

    public static function get_log_dir() {
        $upload = wp_upload_dir();
        return $upload['basedir'] . '/leyka-toolkit';
    }

    public static function get_log_file() {
        return self::get_log_dir() . '/toolkit.log';
    }

    public static function log($message) {
        $s = self::settings_data();
        if (empty($s['log_enabled'])) {
            return;
        }

        $dir = self::get_log_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/.htaccess', 'deny from all');
        }

        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
        file_put_contents(self::get_log_file(), $line, FILE_APPEND | LOCK_EX);
    }

    public static function settings_data() {
        if (self::$settings_cache !== null) {
            return self::$settings_cache;
        }

        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        self::$settings_cache = array_merge(self::defaults(), $settings);
        return self::$settings_cache;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Render a temporary block near submit, then move its real checkbox nodes
        // into the native agreements block on DOM ready.
        $templates = apply_filters('leyka_toolkit_supported_templates', ['need-help', 'star', 'revo']);
        foreach ($templates as $tpl) {
            add_filter(
                'leyka_' . sanitize_key($tpl) . '_template_final_submit',
                [$this, 'render_subscribe_block'],
                5
            );
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_front_assets']);

        // Save subscribe checkbox value and defer tag assignment.
        add_action('leyka_new_donation_added', [$this, 'handle_subscribe'], 20, 1);

        // Assign donor tag when donation becomes funded (callback from payment gateway).
        add_action('transition_post_status', [$this, 'on_donation_funded'], 9999, 3);
    }

    public function register_menu() {
        add_menu_page(
            'Leyka Toolkit',
            'Leyka Toolkit',
            'manage_options',
            'leyka-toolkit',
            [$this, 'render_admin_page'],
            'dashicons-admin-generic',
            58
        );

        add_submenu_page(
            'leyka-toolkit',
            'Настройки — Leyka Toolkit',
            'Настройки',
            'manage_options',
            'leyka-toolkit',
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            'leyka-toolkit',
            'Лог — Leyka Toolkit',
            'Лог',
            'manage_options',
            'leyka-toolkit-log',
            [$this, 'render_log_page']
        );
    }

    public function register_settings() {
        register_setting(
            'leyka_toolkit_group',
            self::OPTION_KEY,
            [$this, 'sanitize_settings']
        );
    }

    public function sanitize_settings($input) {
        $defaults = self::defaults();

        if (!is_array($input)) {
            return $defaults;
        }

        $output = [];
        $output['enabled']     = empty($input['enabled']) ? 0 : 1;
        $output['checked']     = empty($input['checked']) ? 0 : 1;
        $output['log_enabled'] = empty($input['log_enabled']) ? 0 : 1;

        $label = isset($input['label']) ? sanitize_text_field(wp_unslash($input['label'])) : $defaults['label'];
        $output['label'] = $label !== '' ? $label : $defaults['label'];

        $tag = isset($input['tag']) ? sanitize_key(wp_unslash($input['tag'])) : $defaults['tag'];
        $output['tag'] = $tag !== '' ? $tag : $defaults['tag'];

        self::$settings_cache = null;
        return $output;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = self::settings_data();
        ?>
        <div class="wrap">
            <h1>Leyka Toolkit</h1>
            <p>Версия 0.2.0</p>

            <form method="post" action="options.php">
                <?php settings_fields('leyka_toolkit_group'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Включить чекбокс</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" <?php checked(!empty($s['enabled'])); ?>>
                                    Да
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Текст чекбокса</th>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[label]" value="<?php echo esc_attr($s['label']); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Отмечен по умолчанию</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checked]" value="1" <?php checked(!empty($s['checked'])); ?>>
                                    Да
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Метка донора</th>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tag]" value="<?php echo esc_attr($s['tag']); ?>">
                                <p class="description">Добавляется донору после успешного пожертвования с отмеченным чекбоксом.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button('Сохранить настройки'); ?>
            </form>
        </div>
        <?php
    }

    public function render_log_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle clear log.
        if (isset($_POST['leyka_toolkit_clear_log']) && check_admin_referer('leyka_toolkit_log_actions')) {
            $file = self::get_log_file();
            if (file_exists($file)) {
                unlink($file);
            }
            echo '<div class="updated"><p>Лог очищен.</p></div>';
        }

        // Handle toggle log.
        if (isset($_POST['leyka_toolkit_save_log_setting']) && check_admin_referer('leyka_toolkit_log_actions')) {
            $current = get_option(self::OPTION_KEY, []);
            if (!is_array($current)) {
                $current = [];
            }
            $current['log_enabled'] = empty($_POST['log_enabled']) ? 0 : 1;
            update_option(self::OPTION_KEY, $current);
            self::$settings_cache = null;
            echo '<div class="updated"><p>Настройка сохранена.</p></div>';
        }

        $s = self::settings_data();
        $log_content = '';
        $file = self::get_log_file();
        if (file_exists($file)) {
            $log_content = file_get_contents($file);
        }
        ?>
        <div class="wrap">
            <h1>Leyka Toolkit — Лог</h1>

            <form method="post">
                <?php wp_nonce_field('leyka_toolkit_log_actions'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Вести логирование</th>
                        <td>
                            <label>
                                <input type="checkbox" name="log_enabled" value="1" <?php checked(!empty($s['log_enabled'])); ?>>
                                Да
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Сохранить', 'primary', 'leyka_toolkit_save_log_setting'); ?>
            </form>

            <hr>

            <h2>Содержимое лога</h2>

            <?php if ($log_content !== '') : ?>
                <textarea readonly rows="25" style="width:100%;font-family:monospace;font-size:13px;"><?php echo esc_textarea($log_content); ?></textarea>
                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field('leyka_toolkit_log_actions'); ?>
                    <?php submit_button('Очистить лог', 'delete', 'leyka_toolkit_clear_log', false); ?>
                </form>
            <?php else : ?>
                <p>Лог пуст.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_subscribe_block($html) {
        $s = self::settings_data();

        if (empty($s['enabled'])) {
            return $html;
        }

        self::$render_count++;
        $field_id = 'leyka-toolkit-subscribe-' . self::$render_count;
        $checked = !empty($s['checked']) ? ' checked="checked"' : '';

        $block = '
<div class="donor__oferta studioavp-leyka-addon-subscribe" aria-hidden="true">
    <span class="studioavp-leyka-addon-subscribe-inner">
        <input type="checkbox" name="leyka_donor_subscribed" id="' . esc_attr($field_id) . '" value="1"' . $checked . '>
        <label for="' . esc_attr($field_id) . '">
            <svg class="svg-icon icon-checkbox-check"><use xlink:href="#icon-checkbox-check"></use></svg>
            ' . esc_html($s['label']) . '
        </label>
    </span>
</div>';

        return $block . $html;
    }

    public function enqueue_front_assets() {
        if (is_admin()) {
            return;
        }

        $s = self::settings_data();
        if (empty($s['enabled'])) {
            return;
        }

        $deps = wp_script_is('leyka-public', 'registered') ? ['leyka-public'] : [];
        wp_register_script('leyka-toolkit-front', '', $deps, LEYKA_TOOLKIT_VERSION, true);
        wp_enqueue_script('leyka-toolkit-front');

        $js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    var blocks = document.querySelectorAll('.studioavp-leyka-addon-subscribe');
    if (!blocks.length) return;

    blocks.forEach(function (block) {
        var form = block.closest('form');
        if (!form) return;

        var agreementSpan = form.querySelector('[data-leyka-toolkit-target="subscribe"]')
            || form.querySelector('.section__fields.agreements .donor__oferta span')
            || form.querySelector('.donor__oferta:not(.studioavp-leyka-addon-subscribe) span');
        var inner = block.querySelector('.studioavp-leyka-addon-subscribe-inner');

        if (!agreementSpan || !inner) {
            block.style.setProperty('display', 'block', 'important');
            block.setAttribute('aria-hidden', 'false');
            return;
        }

        while (inner.firstChild) {
            agreementSpan.appendChild(inner.firstChild);
        }

        block.parentNode.removeChild(block);
    });
});
JS;
        wp_add_inline_script('leyka-toolkit-front', $js);

        wp_register_style('leyka-toolkit-front', false, [], LEYKA_TOOLKIT_VERSION);
        wp_enqueue_style('leyka-toolkit-front');

        $css = <<<CSS
.studioavp-leyka-addon-subscribe{
    display:none !important;
}
CSS;
        wp_add_inline_style('leyka-toolkit-front', $css);
    }

    public function handle_subscribe($donation_id) {
        if (empty($_POST['leyka_donor_subscribed'])) {
            return;
        }

        self::log('handle_subscribe: start, donation_id=' . $donation_id);

        $s = self::settings_data();
        if (empty($s['enabled'])) {
            self::log('handle_subscribe: exit — plugin disabled');
            return;
        }

        // 1. Save subscription flag (Leyka's native meta key).
        update_post_meta((int) $donation_id, 'leyka_donor_subscribed', 1);
        self::log('handle_subscribe: meta saved');

        // 2. Set magic property on donation object (if available).
        $donor_email = '';
        if (function_exists('leyka_get_donation')) {
            $donation = leyka_get_donation((int) $donation_id);
            if ($donation) {
                $donation->donor_subscribed = 1;
                $donor_email = !empty($donation->donor_email) ? $donation->donor_email : '';
                self::log('handle_subscribe: donation object updated, email=' . $donor_email);
            }
        }

        // Fallback: get email from meta.
        if (!$donor_email) {
            $donor_email = get_post_meta((int) $donation_id, 'leyka_donor_email', true);
            self::log('handle_subscribe: email from meta=' . $donor_email);
        }

        if (!$donor_email) {
            self::log('handle_subscribe: exit — no donor email');
            return;
        }

        // 3. Defer tag assignment to shutdown — Leyka creates donor later.
        $tag = !empty($s['tag']) ? $s['tag'] : 'newsletter';

        add_action('shutdown', function () use ($donor_email, $tag) {
            self::assign_donor_tag($donor_email, $tag);
        });

        self::log('handle_subscribe: deferred to shutdown, email=' . $donor_email . ', tag=' . $tag);
    }

    public function on_donation_funded($new_status, $old_status, $post) {
        if (!$post || $post->post_type !== 'leyka_donation') {
            return;
        }

        if ($new_status !== 'funded') {
            return;
        }

        $donation_id = $post->ID;
        $subscribed = get_post_meta($donation_id, 'leyka_donor_subscribed', true);

        if (empty($subscribed)) {
            return;
        }

        self::log('on_donation_funded: donation #' . $donation_id . ' funded, subscribed=1');

        $s = self::settings_data();
        if (empty($s['enabled'])) {
            self::log('on_donation_funded: exit — plugin disabled');
            return;
        }

        $donor_email = get_post_meta($donation_id, 'leyka_donor_email', true);
        if (!$donor_email) {
            self::log('on_donation_funded: exit — no email');
            return;
        }

        $tag = !empty($s['tag']) ? $s['tag'] : 'newsletter';

        // Defer to shutdown — Leyka may create WP user later in this request.
        add_action('shutdown', function () use ($donor_email, $tag) {
            self::assign_donor_tag($donor_email, $tag);
        });

        self::log('on_donation_funded: deferred to shutdown, email=' . $donor_email);
    }

    public static function assign_donor_tag($donor_email, $tag) {
        self::log('assign_donor_tag: start, email=' . $donor_email . ', tag=' . $tag);

        $donor_id = 0;

        // Flush user cache to avoid stale "not found" from earlier in this request.
        wp_cache_delete($donor_email, 'useremail');

        // Strategy 1: WP user by email (Leyka with personal accounts).
        $wp_user = get_user_by('email', $donor_email);
        if ($wp_user) {
            $donor_id = (int) $wp_user->ID;
            self::log('assign_donor_tag: found WP user #' . $donor_id);
        }

        // Strategy 2: leyka_donor post by meta (if post type exists).
        if (!$donor_id && post_type_exists('leyka_donor')) {
            $donors = get_posts([
                'post_type'      => 'leyka_donor',
                'post_status'    => 'any',
                'numberposts'    => 1,
                'meta_query'     => [
                    [
                        'key'     => 'donor_email',
                        'value'   => $donor_email,
                        'compare' => '=',
                    ],
                ],
            ]);
            if (!empty($donors)) {
                $donor_id = (int) $donors[0]->ID;
                self::log('assign_donor_tag: found leyka_donor post #' . $donor_id);
            }
        }

        if (!$donor_id) {
            self::log('assign_donor_tag: exit — donor not found (no WP user, no leyka_donor post)');
            return;
        }
        self::log('assign_donor_tag: donor found, id=' . $donor_id);

        // Check taxonomy.
        $taxonomy = 'donors_tag';

        if (!taxonomy_exists($taxonomy)) {
            self::log('assign_donor_tag: exit — taxonomy does not exist');
            return;
        }

        // Create term if needed.
        if (!term_exists($tag, $taxonomy)) {
            self::log('assign_donor_tag: creating term');
            $term = wp_insert_term($tag, $taxonomy);
            if (is_wp_error($term)) {
                self::log('assign_donor_tag: wp_insert_term error: ' . $term->get_error_message());
                return;
            }
        }

        // Assign tag to donor.
        $existing = wp_get_object_terms($donor_id, $taxonomy, ['fields' => 'names']);

        if (is_wp_error($existing)) {
            self::log('assign_donor_tag: wp_get_object_terms error: ' . $existing->get_error_message());
            return;
        }

        if (!in_array($tag, (array) $existing, true)) {
            $result = wp_set_object_terms($donor_id, [$tag], $taxonomy, true);
            if (is_wp_error($result)) {
                self::log('assign_donor_tag: wp_set_object_terms error: ' . $result->get_error_message());
            } else {
                self::log('assign_donor_tag: tag assigned, term_ids=' . implode(',', (array) $result));
            }
        } else {
            self::log('assign_donor_tag: tag already assigned');
        }
    }
}
