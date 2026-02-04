<?php
/**
 * 手动缓存预热脚本
 * 
 * 一次性生成账本里所有页面的缓存，避免依赖文章保存触发
 * 
 * 使用方法：
 * 1. 在 WordPress 后台访问：工具 > WP-to-CF 缓存预热
 * 2. 或通过 WP-CLI: wp eval-file manual-cache-warmup.php
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Manual_Cache_Warmup
 * 
 * 手动缓存预热工具
 */
class WP_to_CF_Manual_Cache_Warmup
{
    /**
     * 执行缓存预热
     *
     * @return array 执行结果统计
     */
    public static function warmup_all_caches(): array
    {
        $start_time = microtime(true);
        
        WP_to_CF_Logger::info('Starting manual cache warmup');

        $stats = [
            'total_posts' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'special_pages' => 0,
            'errors' => [],
        ];

        // 1. 预热所有已发布的文章和页面
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);

        $stats['total_posts'] = count($posts);

        WP_to_CF_Logger::info('Found posts to warmup', [
            'count' => count($posts),
        ]);

        foreach ($posts as $post) {
            try {
                $result = self::warmup_post_cache($post->ID);
                
                if ($result['success']) {
                    $stats['success_count']++;
                } elseif ($result['skipped']) {
                    $stats['skipped_count']++;
                } else {
                    $stats['failed_count']++;
                    $stats['errors'][] = [
                        'post_id' => $post->ID,
                        'post_title' => $post->post_title,
                        'error' => $result['error'] ?? 'Unknown error',
                    ];
                }
            } catch (Exception $e) {
                $stats['failed_count']++;
                $stats['errors'][] = [
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'error' => $e->getMessage(),
                ];
                
                WP_to_CF_Logger::error('Failed to warmup post cache', [
                    'post_id' => $post->ID,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. 预热首页
        try {
            self::warmup_home_cache();
            $stats['special_pages']++;
        } catch (Exception $e) {
            WP_to_CF_Logger::error('Failed to warmup home page cache', [
                'error' => $e->getMessage(),
            ]);
        }

        // 3. 预热所有分类页
        $categories = get_categories(['hide_empty' => false]);
        foreach ($categories as $category) {
            try {
                self::warmup_category_cache($category->term_id);
                $stats['special_pages']++;
            } catch (Exception $e) {
                WP_to_CF_Logger::error('Failed to warmup category cache', [
                    'category_id' => $category->term_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. 预热所有标签页
        $tags = get_tags(['hide_empty' => false]);
        foreach ($tags as $tag) {
            try {
                self::warmup_tag_cache($tag->term_id);
                $stats['special_pages']++;
            } catch (Exception $e) {
                WP_to_CF_Logger::error('Failed to warmup tag cache', [
                    'tag_id' => $tag->term_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $end_time = microtime(true);
        $total_time = $end_time - $start_time;

        $stats['total_time'] = round($total_time, 2);

        WP_to_CF_Logger::info('Manual cache warmup completed', $stats);

        return $stats;
    }

    /**
     * 预热单个文章的缓存
     *
     * @param int $post_id 文章 ID
     * @return array 结果
     */
    private static function warmup_post_cache(int $post_id): array
    {
        $post = get_post($post_id);
        
        if (!$post) {
            return [
                'success' => false,
                'error' => 'Post not found',
            ];
        }

        // 生成文件路径
        $permalink = get_permalink($post_id);
        $parsed = parse_url($permalink);
        $path = $parsed['path'] ?? '/';
        $path = ltrim($path, '/');
        
        if (empty($path) || $path === '/') {
            $file_path = 'index.html';
        } else {
            $path = rtrim($path, '/');
            $file_path = $path . '/index.html';
        }

        // 检查缓存是否已存在
        $ledger = new WP_to_CF_Hash_Ledger();
        $ledger_entry = $ledger->get_by_post_id($post_id);
        
        if ($ledger_entry) {
            $cache = new WP_to_CF_HTML_Cache();
            $cached_html = $cache->get($file_path, $ledger_entry['content_hash']);
            
            if ($cached_html !== null) {
                // 缓存已存在，跳过
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'Cache already exists',
                ];
            }
        }

        // 生成 HTML
        $generator = new WP_to_CF_HTML_Generator();
        $html = $generator->generate_post_html($post_id);

        if ($html === false) {
            return [
                'success' => false,
                'error' => 'Failed to generate HTML',
            ];
        }

        // 应用所有转换
        $localizer = new WP_to_CF_Asset_Localizer();
        $html = $localizer->localize_assets($html);

        $debloat = new WP_to_CF_WordPress_Debloat();
        $html = $debloat->debloat_html($html);

        $injector = new WP_to_CF_Code_Injector();
        $html = $injector->inject_code($html);

        $transformer = new WP_to_CF_URL_Transformer();
        $html = $transformer->transform_html($html);

        // 计算 Hash
        $content_hash = $ledger->calculate_hash($html);

        // 保存到磁盘缓存
        $cache = new WP_to_CF_HTML_Cache();
        $cache->save($file_path, $html, $content_hash);

        // 更新账本
        $ledger->update_ledger($post_id, $file_path, $content_hash, strlen($html));

        WP_to_CF_Logger::info('Post cache warmed up', [
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'file_path' => $file_path,
            'size' => strlen($html),
        ]);

        return [
            'success' => true,
            'skipped' => false,
        ];
    }

    /**
     * 预热首页缓存
     *
     * @return void
     */
    private static function warmup_home_cache(): void
    {
        $special_pages_manager = new WP_to_CF_Special_Pages_Manager();
        $result = $special_pages_manager->staticize_home();

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to staticize home page');
        }

        // 保存到磁盘缓存
        $cache = new WP_to_CF_HTML_Cache();
        $ledger = new WP_to_CF_Hash_Ledger();
        $content_hash = $ledger->calculate_hash($result['content']);
        
        $cache->save($result['file_path'], $result['content'], $content_hash);

        WP_to_CF_Logger::info('Home page cache warmed up', [
            'file_path' => $result['file_path'],
            'size' => strlen($result['content']),
        ]);
    }

    /**
     * 预热分类页缓存
     *
     * @param int $term_id 分类 ID
     * @return void
     */
    private static function warmup_category_cache(int $term_id): void
    {
        $category = get_term($term_id, 'category');

        if (is_wp_error($category) || !$category) {
            throw new Exception("Category not found: {$term_id}");
        }

        $special_pages_manager = new WP_to_CF_Special_Pages_Manager();
        $result = $special_pages_manager->staticize_category($category);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to staticize category page');
        }

        // 保存到磁盘缓存
        $cache = new WP_to_CF_HTML_Cache();
        $ledger = new WP_to_CF_Hash_Ledger();
        $content_hash = $ledger->calculate_hash($result['content']);
        
        $cache->save($result['file_path'], $result['content'], $content_hash);

        WP_to_CF_Logger::info('Category page cache warmed up', [
            'category_id' => $term_id,
            'category_name' => $result['category_name'],
            'file_path' => $result['file_path'],
            'size' => strlen($result['content']),
        ]);
    }

    /**
     * 预热标签页缓存
     *
     * @param int $term_id 标签 ID
     * @return void
     */
    private static function warmup_tag_cache(int $term_id): void
    {
        $tag = get_term($term_id, 'post_tag');

        if (is_wp_error($tag) || !$tag) {
            throw new Exception("Tag not found: {$term_id}");
        }

        $special_pages_manager = new WP_to_CF_Special_Pages_Manager();
        $result = $special_pages_manager->staticize_tag($tag);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to staticize tag page');
        }

        // 保存到磁盘缓存
        $cache = new WP_to_CF_HTML_Cache();
        $ledger = new WP_to_CF_Hash_Ledger();
        $content_hash = $ledger->calculate_hash($result['content']);
        
        $cache->save($result['file_path'], $result['content'], $content_hash);

        WP_to_CF_Logger::info('Tag page cache warmed up', [
            'tag_id' => $term_id,
            'tag_name' => $result['tag_name'],
            'file_path' => $result['file_path'],
            'size' => strlen($result['content']),
        ]);
    }

    /**
     * 渲染管理页面
     *
     * @return void
     */
    public static function render_admin_page(): void
    {
        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // 处理表单提交
        if (isset($_POST['warmup_caches']) && check_admin_referer('wptocf_warmup_caches')) {
            $stats = self::warmup_all_caches();
            
            echo '<div class="notice notice-success"><p>';
            echo '<strong>缓存预热完成！</strong><br>';
            echo "总文章数: {$stats['total_posts']}<br>";
            echo "成功: {$stats['success_count']}<br>";
            echo "跳过（已存在）: {$stats['skipped_count']}<br>";
            echo "失败: {$stats['failed_count']}<br>";
            echo "特殊页面: {$stats['special_pages']}<br>";
            echo "总耗时: {$stats['total_time']} 秒";
            echo '</p></div>';
            
            if (!empty($stats['errors'])) {
                echo '<div class="notice notice-error"><p><strong>错误列表：</strong></p><ul>';
                foreach ($stats['errors'] as $error) {
                    echo '<li>' . esc_html($error['post_title']) . ' (ID: ' . $error['post_id'] . '): ' . esc_html($error['error']) . '</li>';
                }
                echo '</ul></div>';
            }
        }

        // 渲染页面
        ?>
        <div class="wrap">
            <h1>WP-to-CF 缓存预热</h1>
            <p>此工具会一次性生成账本里所有页面的缓存，避免依赖文章保存时触发的高压力全量同步。</p>
            
            <div class="card">
                <h2>执行缓存预热</h2>
                <p>点击下方按钮开始预热所有页面的缓存。这个过程可能需要几分钟时间，具体取决于您的站点大小。</p>
                
                <form method="post">
                    <?php wp_nonce_field('wptocf_warmup_caches'); ?>
                    <p>
                        <button type="submit" name="warmup_caches" class="button button-primary button-large">
                            开始缓存预热
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2>说明</h2>
                <ul>
                    <li>此工具会扫描所有已发布的文章、页面、分类和标签</li>
                    <li>对于每个页面，会生成完整的 HTML 并保存到磁盘缓存</li>
                    <li>如果缓存已存在且未过期，会自动跳过</li>
                    <li>预热后，后续的部署将直接使用缓存，大幅提升速度</li>
                    <li>建议在首次部署前或大量内容更新后执行此操作</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * 注册管理菜单
     *
     * @return void
     */
    public static function register_admin_menu(): void
    {
        add_management_page(
            'WP-to-CF 缓存预热',
            'WP-to-CF 缓存预热',
            'manage_options',
            'wptocf-cache-warmup',
            [self::class, 'render_admin_page']
        );
    }
}

// 注册管理菜单
add_action('admin_menu', ['WP_to_CF_Manual_Cache_Warmup', 'register_admin_menu']);
