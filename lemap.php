<?php
/**
 * Plugin Name: LeMap
 * Plugin URI: https://www.laojiang.me/7223.html
 * Description: 一个简单而优雅的文章地图/存档插件，显示按年和月组织的所有帖子。公众号：<span style="color: red;">老蒋朋友圈</span>
 * Version: 1.0.0
 * Author: 老蒋和他的小伙伴
 * Author URI: https://www.laojiang.me
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lemap
 */

if (!defined('ABSPATH')) {
    exit;
}

class LeMap {
    private static $instance = null;
    private $options;
    private $is_generating = false;
    private $generation_queue = false;
    private $default_title;
    
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // 延迟初始化选项，等待翻译加载
        add_action('init', [$this, 'initPlugin']);
        
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_notices', [$this, 'adminNotices']);
        
        // 优化的文章更新钩子
        add_action('save_post', [$this, 'queueGeneration'], 10, 2);
        add_action('delete_post', [$this, 'queueGeneration'], 10, 2);
        add_action('wp_trash_post', [$this, 'queueGeneration'], 10, 2);
        add_action('untrash_post', [$this, 'queueGeneration'], 10, 2);
        add_action('shutdown', [$this, 'processGenerationQueue']);
        
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        register_uninstall_hook(__FILE__, ['LeMap', 'uninstall']);

        // 加载翻译文件
        add_action('init', [$this, 'loadTextDomain']);
    }

    public function loadTextDomain() {
        load_plugin_textdomain('lemap', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function initPlugin() {
        $this->default_title = __('Article Map', 'lemap');
        $this->options = get_option('lemap_options', [
            'enabled' => true,
            'page_slug' => 'lemap.html',
            'posts_per_page' => 1000,
            'page_title' => $this->default_title
        ]);
    }
    
    public function activate() {
        // 等待翻译加载后再设置默认值
        add_action('init', function() {
            if (!get_option('lemap_options')) {
                add_option('lemap_options', [
                    'enabled' => true,
                    'page_slug' => 'lemap.html',
                    'posts_per_page' => 1000,
                    'page_title' => $this->default_title
                ]);
            }
            
            // 检查文件写入权限
            $file_path = ABSPATH . 'lemap.html';
            if (!$this->checkWritePermissions($file_path)) {
                set_transient('lemap_activation_error', true, 5);
                return;
            }
            
            $this->generateStaticFile();
        });
    }
    
    public function adminNotices() {
        if (get_transient('lemap_activation_error')) {
            ?>
            <div class="error notice">
                <p><?php _e('LeMap: Unable to create the article map file. Please check directory permissions.', 'lemap'); ?></p>
            </div>
            <?php
            delete_transient('lemap_activation_error');
        }
        
        if (get_transient('lemap_generation_error')) {
            ?>
            <div class="error notice">
                <p><?php echo esc_html(get_transient('lemap_generation_error')); ?></p>
            </div>
            <?php
            delete_transient('lemap_generation_error');
        }
    }
    
    private function checkWritePermissions($file_path) {
        $dir = dirname($file_path);
        
        if (!file_exists($dir)) {
            return false;
        }
        
        if (file_exists($file_path)) {
            return is_writable($file_path);
        }
        
        return is_writable($dir);
    }
    
    public function queueGeneration($post_id, $post = null) {
        // 只处理文章类型
        if ($post && $post->post_type !== 'post') {
            return;
        }
        
        $this->generation_queue = true;
    }
    
    public function processGenerationQueue() {
        if ($this->generation_queue && !$this->is_generating) {
            $this->generateStaticFile();
        }
    }
    
    public function deactivate() {
        $this->deleteStaticFile();
    }
    
    public static function uninstall() {
        delete_option('lemap_options');
        $file_path = ABSPATH . 'lemap.html';
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    public function deleteStaticFile() {
        $file_path = ABSPATH . $this->options['page_slug'];
        if (file_exists($file_path)) {
            if (!unlink($file_path)) {
                set_transient('lemap_generation_error', 
                    __('Unable to delete the old article map file. Please check file permissions.', 'lemap'),
                    5
                );
            }
        }
    }
    
    public function generateStaticFile() {
        if (!$this->options['enabled']) {
            $this->deleteStaticFile();
            return;
        }

        // 防止重复生成
        if ($this->is_generating) {
            return;
        }
        
        $this->is_generating = true;
        $this->generation_queue = false;

        // 检查文件权限
        $file_path = ABSPATH . $this->options['page_slug'];
        if (!$this->checkWritePermissions($file_path)) {
            set_transient('lemap_generation_error', 
                __('Unable to create the article map file. Please check directory permissions.', 'lemap'),
                5
            );
            $this->is_generating = false;
            return;
        }

        // 使用 WP_Query 缓存功能
        $args = [
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_type' => 'post',
            'post_status' => 'publish',
            'cache_results' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false
        ];
        
        $posts = new WP_Query($args);
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="index, follow">
            <meta name="generator" content="LeMap <?php echo esc_attr(get_plugin_data(__FILE__)['Version']); ?>">
            <title><?php echo esc_html($this->options['page_title']); ?> - <?php echo esc_html(get_bloginfo('name')); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    line-height: 1.6;
                    margin: 0;
                    padding: 20px;
                    background: #f5f5f5;
                    color: #333;
                }
                .container {
                    max-width: 800px;
                    margin: 0 auto;
                    background: #fff;
                    padding: 40px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                h1 {
                    text-align: center;
                    color: #2c3e50;
                    margin-bottom: 40px;
                }
                .year {
                    margin-bottom: 30px;
                }
                .year-title {
                    font-size: 24px;
                    color: #2c3e50;
                    border-bottom: 2px solid #eee;
                    padding-bottom: 10px;
                    margin-bottom: 20px;
                }
                .month {
                    margin-bottom: 20px;
                }
                .month-title {
                    font-size: 18px;
                    color: #34495e;
                    margin-bottom: 10px;
                }
                .posts {
                    margin-left: 20px;
                }
                .post-link {
                    display: block;
                    text-decoration: none;
                    color: #3498db;
                    margin-bottom: 8px;
                    transition: color 0.2s;
                }
                .post-link:hover {
                    color: #2980b9;
                }
                .post-date {
                    color: #7f8c8d;
                    font-size: 0.9em;
                    margin-right: 10px;
                }
                .footer {
                    text-align: center;
                    margin-top: 20px;
                    color: #7f8c8d;
                    font-size: 0.9em;
                }
                @media (max-width: 768px) {
                    .container {
                        padding: 20px;
                    }
                    body {
                        padding: 10px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1><?php echo esc_html($this->options['page_title']); ?></h1>
                <?php
                if ($posts->have_posts()) {
                    $current_year = '';
                    $current_month = '';
                    $post_count = 0;
                    
                    while ($posts->have_posts()) {
                        $posts->the_post();
                        $year = get_the_date('Y');
                        $month = get_the_date('F');
                        $post_count++;
                        
                        if ($current_year !== $year) {
                            if ($current_year !== '') {
                                echo '</div></div>';
                            }
                            $current_year = $year;
                            echo '<div class="year">';
                            echo '<h2 class="year-title">' . esc_html($year) . '</h2>';
                        }
                        
                        if ($current_month !== $month) {
                            if ($current_month !== '') {
                                echo '</div></div>';
                            }
                            $current_month = $month;
                            echo '<div class="month">';
                            echo '<h3 class="month-title">' . esc_html($month) . '</h3>';
                            echo '<div class="posts">';
                        }
                        ?>
                        <a href="<?php the_permalink(); ?>" class="post-link">
                            <span class="post-date"><?php echo get_the_date('d M'); ?></span>
                            <?php the_title(); ?>
                        </a>
                        <?php
                    }
                    echo '</div></div></div>';
                }
                wp_reset_postdata();
                ?>
                <div class="footer">
                    <?php 
                    printf(
                        __('Total Posts: %d | Last updated: %s', 'lemap'),
                        $post_count,
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'))
                    ); 
                    ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        $content = ob_get_clean();
        
        // 写入文件
        if (file_put_contents($file_path, $content) === false) {
            set_transient('lemap_generation_error', 
                __('Failed to write the article map file. Please check file permissions.', 'lemap'),
                5
            );
        }
        
        $this->is_generating = false;
    }
    
    public function addAdminMenu() {
        add_options_page(
            __('LeMap Settings', 'lemap'),
            __('LeMap', 'lemap'),
            'manage_options',
            'lemap-settings',
            [$this, 'renderSettingsPage']
        );
    }
    
    public function registerSettings() {
        register_setting('lemap_options', 'lemap_options', [$this, 'validateSettings']);
        
        add_settings_section(
            'lemap_main_section',
            __('Main Settings', 'lemap'),
            null,
            'lemap-settings'
        );
        
        add_settings_field(
            'lemap_enabled',
            __('Enable Article Map', 'lemap'),
            [$this, 'renderEnabledField'],
            'lemap-settings',
            'lemap_main_section'
        );
        
        add_settings_field(
            'lemap_page_slug',
            __('Page Filename', 'lemap'),
            [$this, 'renderPageSlugField'],
            'lemap-settings',
            'lemap_main_section'
        );

        add_settings_field(
            'lemap_page_title',
            __('Page Title', 'lemap'),
            [$this, 'renderPageTitleField'],
            'lemap-settings',
            'lemap_main_section'
        );
    }
    
    public function validateSettings($input) {
        $old_options = $this->options;
        
        // 验证并保存设置
        $output = array();
        $output['enabled'] = isset($input['enabled']) ? (bool)$input['enabled'] : false;
        $output['page_slug'] = sanitize_file_name($input['page_slug']);
        $output['page_title'] = sanitize_text_field($input['page_title']);
        
        // 确保文件名以.html结尾
        if (!preg_match('/\.html$/', $output['page_slug'])) {
            $output['page_slug'] .= '.html';
        }
        
        // 如果文件名改变了，删除旧文件
        if ($old_options['page_slug'] !== $output['page_slug']) {
            $old_file = ABSPATH . $old_options['page_slug'];
            if (file_exists($old_file)) {
                if (!unlink($old_file)) {
                    set_transient('lemap_generation_error', 
                        __('Unable to delete the old article map file. Please check file permissions.', 'lemap'),
                        5
                    );
                }
            }
        }
        
        // 重新生成静态文件
        $this->options = $output;
        $this->generateStaticFile();
        
        return $output;
    }
    
    public function renderSettingsPage() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p>设置地图。<a href="https://www.laojiang.me/7223.html" target="_blank">插件介绍</a>（关注公众号：<span style="color: red;">老蒋朋友圈</span>）</p>
            <form action="options.php" method="post">
                <?php
                settings_fields('lemap_options');
                do_settings_sections('lemap-settings');
                submit_button();
                
                if ($this->options['enabled']) {
                    $file_url = home_url('/' . $this->options['page_slug']);
                    echo '<p>' . sprintf(__('Your article map is available at: <a href="%s" target="_blank">%s</a>', 'lemap'), 
                        esc_url($file_url), 
                        esc_html($file_url)
                    ) . '</p>';
                }
                ?>
            </form>
        </div>
        <p><img width="150" height="150" src="<?php echo plugins_url('/images/wechat.png', __FILE__); ?>" alt="扫码关注公众号" /></p>
        <?php
    }
    
    public function renderEnabledField() {
        $enabled = isset($this->options['enabled']) ? $this->options['enabled'] : true;
        ?>
        <input type="checkbox" name="lemap_options[enabled]" value="1" <?php checked($enabled); ?>>
        <p class="description"><?php _e('Enable or disable the article map generation', 'lemap'); ?></p>
        <?php
    }
    
    public function renderPageSlugField() {
        $slug = isset($this->options['page_slug']) ? $this->options['page_slug'] : 'lemap.html';
        ?>
        <input type="text" name="lemap_options[page_slug]" value="<?php echo esc_attr($slug); ?>" class="regular-text">
        <p class="description"><?php _e('The filename for your article map (e.g., lemap.html)', 'lemap'); ?></p>
        <?php
    }

    public function renderPageTitleField() {
        $title = isset($this->options['page_title']) ? $this->options['page_title'] : $this->default_title;
        ?>
        <input type="text" name="lemap_options[page_title]" value="<?php echo esc_attr($title); ?>" class="regular-text">
        <p class="description"><?php _e('The title that will be displayed at the top of your article map page', 'lemap'); ?></p>
        <?php
    }
}

// Initialize the plugin
LeMap::getInstance(); 