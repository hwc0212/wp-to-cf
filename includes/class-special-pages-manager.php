<?php
/**
 * 特殊页面管理器类
 * 
 * 负责处理非文章类页面的静态化（首页、分类页、标签页等）
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Special_Pages_Manager
 * 
 * 管理特殊页面的静态化
 */
class WP_to_CF_Special_Pages_Manager
{
    /**
     * HTML 生成器实例
     */
    private WP_to_CF_HTML_Generator $html_generator;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->html_generator = new WP_to_CF_HTML_Generator();
    }

    /**
     * 获取首页 URL
     *
     * @return string 首页 URL
     */
    public function get_home_url(): string
    {
        return home_url('/');
    }

    /**
     * 获取首页的文件路径
     *
     * @return string 文件路径（index.html）
     */
    public function get_home_file_path(): string
    {
        return 'index.html';
    }

    /**
     * 静态化首页
     *
     * @return array 结果数组，包含 success, file_path, content
     */
    public function staticize_home(): array
    {
        WP_to_CF_Logger::info('Staticizing home page');

        $home_url = $this->get_home_url();
        $file_path = $this->get_home_file_path();

        // 生成 HTML
        $html = $this->html_generator->generate_html($home_url);

        if ($html === false) {
            WP_to_CF_Logger::error('Failed to generate HTML for home page', [
                'url' => $home_url,
            ]);

            return [
                'success' => false,
                'file_path' => $file_path,
                'content' => null,
                'error' => 'Failed to generate HTML',
            ];
        }

        WP_to_CF_Logger::info('Home page staticized successfully', [
            'file_path' => $file_path,
            'content_size' => strlen($html),
        ]);

        return [
            'success' => true,
            'file_path' => $file_path,
            'content' => $html,
        ];
    }

    /**
     * 获取文章关联的分类
     *
     * @param int $post_id 文章 ID
     * @return array 分类数组
     */
    public function get_post_categories(int $post_id): array
    {
        $categories = get_the_category($post_id);

        if (empty($categories) || is_wp_error($categories)) {
            return [];
        }

        return $categories;
    }

    /**
     * 获取文章关联的标签
     *
     * @param int $post_id 文章 ID
     * @return array 标签数组
     */
    public function get_post_tags(int $post_id): array
    {
        $tags = get_the_tags($post_id);

        if (empty($tags) || is_wp_error($tags)) {
            return [];
        }

        return $tags;
    }

    /**
     * 获取产品关联的产品分类（WooCommerce）
     *
     * @param int $product_id 产品 ID
     * @return array 产品分类数组
     */
    public function get_product_categories(int $product_id): array
    {
        // 检查 WooCommerce 是否激活
        if (!class_exists('WooCommerce')) {
            WP_to_CF_Logger::warning('WooCommerce not active, skipping product categories', [
                'product_id' => $product_id,
            ]);
            return [];
        }

        $categories = wp_get_post_terms($product_id, 'product_cat');

        if (empty($categories) || is_wp_error($categories)) {
            return [];
        }

        WP_to_CF_Logger::info('Product categories retrieved', [
            'product_id' => $product_id,
            'categories_count' => count($categories),
        ]);

        return $categories;
    }

    /**
     * 获取分类页的文件路径
     *
     * @param WP_Term $category 分类对象
     * @return string 文件路径
     */
    public function get_category_file_path(WP_Term $category): string
    {
        // 获取分类的 URL 路径
        $category_link = get_category_link($category->term_id);
        $category_path = parse_url($category_link, PHP_URL_PATH);

        // 移除开头和结尾的斜杠
        $category_path = trim($category_path, '/');

        // 如果路径为空，使用分类 slug
        if (empty($category_path)) {
            $category_path = 'category/' . $category->slug;
        }

        // 添加 index.html
        return $category_path . '/index.html';
    }

    /**
     * 获取标签页的文件路径
     *
     * @param WP_Term $tag 标签对象
     * @return string 文件路径
     */
    public function get_tag_file_path(WP_Term $tag): string
    {
        // 获取标签的 URL 路径
        $tag_link = get_tag_link($tag->term_id);
        $tag_path = parse_url($tag_link, PHP_URL_PATH);

        // 移除开头和结尾的斜杠
        $tag_path = trim($tag_path, '/');

        // 如果路径为空，使用标签 slug
        if (empty($tag_path)) {
            $tag_path = 'tag/' . $tag->slug;
        }

        // 添加 index.html
        return $tag_path . '/index.html';
    }

    /**
     * 获取产品分类页的文件路径（WooCommerce）
     *
     * @param WP_Term $category 产品分类对象
     * @return string 文件路径
     */
    public function get_product_category_file_path(WP_Term $category): string
    {
        // 获取产品分类的 URL 路径
        $category_link = get_term_link($category->term_id, 'product_cat');
        
        if (is_wp_error($category_link)) {
            WP_to_CF_Logger::error('Failed to get product category link', [
                'category_id' => $category->term_id,
                'error' => $category_link->get_error_message(),
            ]);
            // 回退到默认路径
            return 'product-category/' . $category->slug . '/index.html';
        }
        
        $category_path = parse_url($category_link, PHP_URL_PATH);

        // 移除开头和结尾的斜杠
        $category_path = trim($category_path, '/');

        // 如果路径为空，使用分类 slug
        if (empty($category_path)) {
            $category_path = 'product-category/' . $category->slug;
        }

        // 添加 index.html
        return $category_path . '/index.html';
    }

    /**
     * 静态化分类页
     *
     * @param WP_Term $category 分类对象
     * @return array 结果数组
     */
    public function staticize_category(WP_Term $category): array
    {
        $category_link = get_category_link($category->term_id);
        $file_path = $this->get_category_file_path($category);

        WP_to_CF_Logger::info('Staticizing category page', [
            'category_id' => $category->term_id,
            'category_name' => $category->name,
            'category_slug' => $category->slug,
            'file_path' => $file_path,
        ]);

        // 生成 HTML
        $html = $this->html_generator->generate_html($category_link);

        if ($html === false) {
            WP_to_CF_Logger::error('Failed to generate HTML for category page', [
                'category_id' => $category->term_id,
                'url' => $category_link,
            ]);

            return [
                'success' => false,
                'file_path' => $file_path,
                'content' => null,
                'error' => 'Failed to generate HTML',
            ];
        }

        WP_to_CF_Logger::info('Category page staticized successfully', [
            'category_id' => $category->term_id,
            'file_path' => $file_path,
            'content_size' => strlen($html),
        ]);

        return [
            'success' => true,
            'file_path' => $file_path,
            'content' => $html,
            'category_id' => $category->term_id,
            'category_name' => $category->name,
        ];
    }

    /**
     * 静态化标签页
     *
     * @param WP_Term $tag 标签对象
     * @return array 结果数组
     */
    public function staticize_tag(WP_Term $tag): array
    {
        $tag_link = get_tag_link($tag->term_id);
        $file_path = $this->get_tag_file_path($tag);

        WP_to_CF_Logger::info('Staticizing tag page', [
            'tag_id' => $tag->term_id,
            'tag_name' => $tag->name,
            'tag_slug' => $tag->slug,
            'file_path' => $file_path,
        ]);

        // 生成 HTML
        $html = $this->html_generator->generate_html($tag_link);

        if ($html === false) {
            WP_to_CF_Logger::error('Failed to generate HTML for tag page', [
                'tag_id' => $tag->term_id,
                'url' => $tag_link,
            ]);

            return [
                'success' => false,
                'file_path' => $file_path,
                'content' => null,
                'error' => 'Failed to generate HTML',
            ];
        }

        WP_to_CF_Logger::info('Tag page staticized successfully', [
            'tag_id' => $tag->term_id,
            'file_path' => $file_path,
            'content_size' => strlen($html),
        ]);

        return [
            'success' => true,
            'file_path' => $file_path,
            'content' => $html,
            'tag_id' => $tag->term_id,
            'tag_name' => $tag->name,
        ];
    }

    /**
     * 静态化产品分类页（WooCommerce）
     *
     * @param WP_Term $category 产品分类对象
     * @return array 结果数组
     */
    public function staticize_product_category(WP_Term $category): array
    {
        $category_link = get_term_link($category->term_id, 'product_cat');
        
        if (is_wp_error($category_link)) {
            WP_to_CF_Logger::error('Failed to get product category link', [
                'category_id' => $category->term_id,
                'error' => $category_link->get_error_message(),
            ]);
            
            return [
                'success' => false,
                'file_path' => '',
                'content' => null,
                'error' => 'Failed to get category link',
            ];
        }
        
        $file_path = $this->get_product_category_file_path($category);

        WP_to_CF_Logger::info('Staticizing product category page', [
            'category_id' => $category->term_id,
            'category_name' => $category->name,
            'category_slug' => $category->slug,
            'file_path' => $file_path,
        ]);

        // 生成 HTML
        $html = $this->html_generator->generate_html($category_link);

        if ($html === false) {
            WP_to_CF_Logger::error('Failed to generate HTML for product category page', [
                'category_id' => $category->term_id,
                'url' => $category_link,
            ]);

            return [
                'success' => false,
                'file_path' => $file_path,
                'content' => null,
                'error' => 'Failed to generate HTML',
            ];
        }

        WP_to_CF_Logger::info('Product category page staticized successfully', [
            'category_id' => $category->term_id,
            'file_path' => $file_path,
            'content_size' => strlen($html),
        ]);

        return [
            'success' => true,
            'file_path' => $file_path,
            'content' => $html,
            'category_id' => $category->term_id,
            'category_name' => $category->name,
        ];
    }

    /**
     * 批量静态化文章关联的分类页
     *
     * @param int $post_id 文章 ID
     * @return array 结果数组
     */
    public function staticize_post_categories(int $post_id): array
    {
        $categories = $this->get_post_categories($post_id);

        if (empty($categories)) {
            WP_to_CF_Logger::info('No categories found for post', [
                'post_id' => $post_id,
            ]);

            return [
                'success' => true,
                'staticized' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $results = [];
        $staticized = 0;
        $failed = 0;

        foreach ($categories as $category) {
            $result = $this->staticize_category($category);

            if ($result['success']) {
                $staticized++;
            } else {
                $failed++;
            }

            $results[] = $result;
        }

        WP_to_CF_Logger::info('Post categories staticized', [
            'post_id' => $post_id,
            'total_categories' => count($categories),
            'staticized' => $staticized,
            'failed' => $failed,
        ]);

        return [
            'success' => $failed === 0,
            'staticized' => $staticized,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * 批量静态化文章关联的标签页
     *
     * @param int $post_id 文章 ID
     * @return array 结果数组
     */
    public function staticize_post_tags(int $post_id): array
    {
        $tags = $this->get_post_tags($post_id);

        if (empty($tags)) {
            WP_to_CF_Logger::info('No tags found for post', [
                'post_id' => $post_id,
            ]);

            return [
                'success' => true,
                'staticized' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $results = [];
        $staticized = 0;
        $failed = 0;

        foreach ($tags as $tag) {
            $result = $this->staticize_tag($tag);

            if ($result['success']) {
                $staticized++;
            } else {
                $failed++;
            }

            $results[] = $result;
        }

        WP_to_CF_Logger::info('Post tags staticized', [
            'post_id' => $post_id,
            'total_tags' => count($tags),
            'staticized' => $staticized,
            'failed' => $failed,
        ]);

        return [
            'success' => $failed === 0,
            'staticized' => $staticized,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * 静态化文章相关的所有特殊页面
     *
     * @param int $post_id 文章 ID
     * @return array 结果数组
     */
    public function staticize_post_related_pages(int $post_id): array
    {
        WP_to_CF_Logger::info('Staticizing post related pages', [
            'post_id' => $post_id,
        ]);

        $results = [
            'home' => null,
            'categories' => null,
            'tags' => null,
        ];

        // 1. 静态化首页
        $results['home'] = $this->staticize_home();

        // 2. 静态化关联的分类页
        $results['categories'] = $this->staticize_post_categories($post_id);

        // 3. 静态化关联的标签页
        $results['tags'] = $this->staticize_post_tags($post_id);

        $total_success = 
            ($results['home']['success'] ? 1 : 0) +
            $results['categories']['staticized'] +
            $results['tags']['staticized'];

        $total_failed = 
            ($results['home']['success'] ? 0 : 1) +
            $results['categories']['failed'] +
            $results['tags']['failed'];

        WP_to_CF_Logger::info('Post related pages staticized', [
            'post_id' => $post_id,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
        ]);

        return [
            'success' => $total_failed === 0,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
            'results' => $results,
        ];
    }
}
