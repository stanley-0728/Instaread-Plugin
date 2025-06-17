<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Auto-injecting audio player with centralized updates and multi-location support.
 * Version: 2.2.0
 * Author: Instaread Team
 * Update URI: https://stanley-0728.github.io/Instaread-Plugin/plugin.json
 */

defined('ABSPATH') || exit;

// ======================
// Auto-Update Integration
// ======================
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://stanley-0728.github.io/Instaread-Plugin/plugin.json',
    __FILE__,
    'instaread-audio-player'
);

// ======================
// Plugin Configuration
// ======================
class InstareadPlayer {
    private static $instance;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register settings and hooks
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('wp_head', [$this, 'inject_player_script'], 5);
        add_filter('wp_resource_hints', [$this, 'add_resource_hints'], 10, 2);
    }

    // ======================
    // Settings Management
    // ======================
    public function register_settings() {
        register_setting('instaread_settings', 'instaread_injection_rules', [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => [$this, 'sanitize_injection_rules']
        ]);
    }

    // ======================
    // Admin Interface
    // ======================
    public function add_settings_page() {
        add_options_page(
            'Instaread Settings',
            'Instaread Player',
            'manage_options',
            'instaread-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        $rules = get_option('instaread_injection_rules', []);
        if (empty($rules)) {
            // Backward compatibility: migrate old options if present
            $old_rule = [
                'publication' => get_option('instaread_publication', 'halfbakedharvest'),
                'target_selector' => get_option('instaread_target_selector', '.entry-content'),
                'insert_position' => get_option('instaread_insert_position', 'append'),
                'exclude_slugs' => get_option('instaread_exclude_slugs', 'about,home')
            ];
            $rules = [$old_rule];
            update_option('instaread_injection_rules', $rules);
        }
        ?>
        <div class="wrap">
            <h1>Instaread Audio Player Configuration</h1>
            <form method="post" action="options.php">
                <?php settings_fields('instaread_settings'); ?>
                <table class="form-table" id="instaread-injection-table">
                    <thead>
                        <tr>
                            <th>Publication</th>
                            <th>Target Selector</th>
                            <th>Insert Position</th>
                            <th>Exclude Slugs</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rules as $i => $rule): ?>
                        <tr>
                            <td>
                                <input type="text" name="instaread_injection_rules[<?php echo $i; ?>][publication]" value="<?php echo esc_attr($rule['publication']); ?>" />
                            </td>
                            <td>
                                <input type="text" name="instaread_injection_rules[<?php echo $i; ?>][target_selector]" value="<?php echo esc_attr($rule['target_selector']); ?>" />
                            </td>
                            <td>
                                <select name="instaread_injection_rules[<?php echo $i; ?>][insert_position]">
                                    <?php foreach(['prepend', 'append', 'inside'] as $pos): ?>
                                        <option value="<?php echo $pos; ?>" <?php selected($rule['insert_position'], $pos); ?>>
                                            <?php echo ucfirst($pos); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="instaread_injection_rules[<?php echo $i; ?>][exclude_slugs]" value="<?php echo esc_attr($rule['exclude_slugs']); ?>" />
                            </td>
                            <td>
                                <button type="button" class="button remove-row">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="button" id="add-row">Add Location</button>
                <br><br>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        (function($){
            $('#add-row').on('click', function(){
                var rowCount = $('#instaread-injection-table tbody tr').length;
                var newRow = `<tr>
                    <td><input type="text" name="instaread_injection_rules[${rowCount}][publication]" value="" /></td>
                    <td><input type="text" name="instaread_injection_rules[${rowCount}][target_selector]" value="" /></td>
                    <td>
                        <select name="instaread_injection_rules[${rowCount}][insert_position]">
                            <option value="prepend">Prepend</option>
                            <option value="append" selected>Append</option>
                            <option value="inside">Inside</option>
                        </select>
                    </td>
                    <td><input type="text" name="instaread_injection_rules[${rowCount}][exclude_slugs]" value="" /></td>
                    <td><button type="button" class="button remove-row">Remove</button></td>
                </tr>`;
                $('#instaread-injection-table tbody').append(newRow);
            });
            $(document).on('click', '.remove-row', function(){
                $(this).closest('tr').remove();
            });
        })(jQuery);
        </script>
        <?php
    }

    public function sanitize_injection_rules($rules) {
        $sanitized = [];
        if (!is_array($rules)) return $sanitized;
        foreach ($rules as $rule) {
            $sanitized[] = [
                'publication'     => sanitize_text_field($rule['publication'] ?? ''),
                'target_selector' => $this->sanitize_css_selector($rule['target_selector'] ?? ''),
                'insert_position' => $this->sanitize_insert_position($rule['insert_position'] ?? 'append'),
                'exclude_slugs'   => sanitize_text_field($rule['exclude_slugs'] ?? '')
            ];
        }
        return $sanitized;
    }

    // ======================
    // Frontend Integration
    // ======================
    public function inject_player_script() {
        $rules = get_option('instaread_injection_rules', []);
        if (!is_singular() || !is_main_query() || empty($rules)) return;

        global $post;
        $slug = $post->post_name;

        echo '<script type="module">';
        echo '(function(){';
        echo 'const rules = ' . json_encode($rules) . ';';
        echo 'const postSlug = ' . json_encode($slug) . ';';
        ?>
        rules.forEach(function(rule){
            if (!rule.target_selector) return;
            var excluded = (rule.exclude_slugs || '').split(',').map(function(s){return s.trim();});
            if (excluded.includes(postSlug)) return;
            var config = {
                publication: rule.publication,
                targetSelector: rule.target_selector,
                insertPosition: rule.insert_position
            };
            var loadPlayer = function() {
                var target = document.querySelector(config.targetSelector);
                if (!target) return false;
                var script = document.createElement('script');
                script.src = 'https://instaread.co/js/player.v2.js';
                script.onload = function() {
                    if (typeof InstareadPlayer === 'function') {
                        new InstareadPlayer(config);
                    }
                };
                document.body.appendChild(script);
                return true;
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function(){
                    if (!loadPlayer()) {
                        var observer = new MutationObserver(function(){
                            if (loadPlayer()) observer.disconnect();
                        });
                        observer.observe(document.body, {childList:true, subtree:true});
                    }
                });
            } else {
                loadPlayer();
            }
        });
        <?php
        echo '})();';
        echo '</script>';
    }

    // ======================
    // Security & Validation
    // ======================
    public function sanitize_css_selector($input) {
        return preg_replace('/[^a-zA-Z0-9\s\.#\->+~=^$|*,:]/', '', $input);
    }

    public function sanitize_insert_position($input) {
        return in_array($input, ['prepend', 'append', 'inside']) ? $input : 'append';
    }

    public function add_resource_hints($urls, $relation_type) {
        if (in_array($relation_type, ['dns-prefetch', 'preconnect']) && is_singular()) {
            $urls[] = 'https://instaread.co';
        }
        return array_unique($urls);
    }
}

InstareadPlayer::init();
