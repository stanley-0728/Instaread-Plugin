<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Auto-injecting audio player with centralized updates
 * Version: 2.1.3
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
        register_setting('instaread_settings', 'instaread_publication', [
            'type' => 'string',
            'default' => 'halfbakedharvest',
            'sanitize_callback' => 'sanitize_text_field'
        ]);

        register_setting('instaread_settings', 'instaread_target_selector', [
            'type' => 'string',
            'default' => '.entry-content',
            'sanitize_callback' => [$this, 'sanitize_css_selector']
        ]);

        register_setting('instaread_settings', 'instaread_insert_position', [
            'type' => 'string',
            'default' => 'append',
            'sanitize_callback' => [$this, 'sanitize_insert_position']
        ]);

        register_setting('instaread_settings', 'instaread_exclude_slugs', [
            'type' => 'string',
            'default' => 'about,home',
            'sanitize_callback' => 'sanitize_text_field'
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
        ?>
        <div class="wrap">
            <h1>Instaread Audio Player Configuration</h1>
            <form method="post" action="options.php">
                <?php 
                settings_fields('instaread_settings');
                do_settings_sections('instaread-settings');
                $this->settings_fields();
                submit_button(); 
                ?>
            </form>
        </div>
        <?php
    }

    private function settings_fields() {
        ?>
        <table class="form-table">
            <tr>
                <th><label for="instaread_publication">Publication ID</label></th>
                <td>
                    <input type="text" name="instaread_publication" 
                           value="<?php echo esc_attr(get_option('instaread_publication')); ?>"
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="instaread_target_selector">Target Element</label></th>
                <td>
                    <input type="text" name="instaread_target_selector" 
                           value="<?php echo esc_attr(get_option('instaread_target_selector')); ?>"
                           class="regular-text">
                    <p class="description">CSS selector for injection point (e.g., <code>.entry-content</code>)</p>
                </td>
            </tr>
            <tr>
                <th>Insert Position</th>
                <td>
                    <select name="instaread_insert_position">
                        <?php foreach(['prepend', 'append', 'inside'] as $position): ?>
                        <option value="<?php echo $position; ?>" 
                            <?php selected(get_option('instaread_insert_position'), $position); ?>>
                            <?php echo ucfirst($position); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="instaread_exclude_slugs">Excluded Slugs</label></th>
                <td>
                    <input type="text" name="instaread_exclude_slugs" 
                           value="<?php echo esc_attr(get_option('instaread_exclude_slugs')); ?>"
                           class="regular-text">
                    <p class="description">Comma-separated list of slugs to exclude</p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ======================
    // Frontend Integration
    // ======================
    public function inject_player_script() {
        if (!$this->should_inject_player()) return;

        $settings = [
            'publication' => esc_js(get_option('instaread_publication')),
            'target' => esc_js(get_option('instaread_target_selector')),
            'position' => esc_js(get_option('instaread_insert_position'))
        ];

        echo <<<HTML
        <script type="module">
        (function() {
            const config = {
                publication: "{$settings['publication']}",
                targetSelector: "{$settings['target']}",
                insertPosition: "{$settings['position']}"
            };

            const loadPlayer = () => {
                if(!document.querySelector(config.targetSelector)) return false;
                
                const script = document.createElement('script');
                script.src = 'https://instaread.co/js/player.v2.js';
                script.onload = () => {
                    new InstareadPlayer(config);
                };
                document.body.appendChild(script);
                return true;
            };

            if(document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    if(!loadPlayer()) {
                        const observer = new MutationObserver(() => {
                            if(loadPlayer()) observer.disconnect();
                        });
                        observer.observe(document.body, {childList: true, subtree: true});
                    }
                });
            } else {
                loadPlayer();
            }
        })();
        </script>
        HTML;
    }

    private function should_inject_player() {
        if(!is_singular() || !is_main_query()) return false;
        
        global $post;
        $excluded = array_map('trim', explode(',', get_option('instaread_exclude_slugs')));
        
        return !in_array($post->post_name, $excluded, true);
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
