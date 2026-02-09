<?php
/**
 * 表单扫描器
 * 
 * 扫描站点页面，自动识别表单
 *
 * @package WP_to_CF
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Form_Scanner
{
    /**
     * 扫描所有页面的表单
     * 
     * @return array 发现的表单列表
     */
    public function scan_all_forms(): array
    {
        $forms = [];
        
        // 获取所有公开页面
        $pages = get_posts([
            'post_type' => ['page', 'post'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);
        
        foreach ($pages as $page) {
            $page_forms = $this->scan_page($page->ID);
            foreach ($page_forms as $form) {
                $form['page_id'] = $page->ID;
                $form['page_title'] = $page->post_title;
                $form['page_url'] = get_permalink($page->ID);
                
                // 用 form_id 作为 key 去重
                $key = $form['form_id'];
                if (!isset($forms[$key])) {
                    $forms[$key] = $form;
                } else {
                    // 同一表单出现在多个页面，记录页面列表
                    if (!isset($forms[$key]['pages'])) {
                        $forms[$key]['pages'] = [
                            ['id' => $forms[$key]['page_id'], 'title' => $forms[$key]['page_title']]
                        ];
                    }
                    $forms[$key]['pages'][] = ['id' => $page->ID, 'title' => $page->post_title];
                }
            }
        }
        
        return array_values($forms);
    }
    
    /**
     * 扫描单个页面的表单
     * 
     * @param int $page_id 页面 ID
     * @return array 表单列表
     */
    public function scan_page(int $page_id): array
    {
        $forms = [];
        
        // 获取页面内容
        $post = get_post($page_id);
        if (!$post) return $forms;
        
        // 渲染页面内容（处理短代码等）
        $content = apply_filters('the_content', $post->post_content);
        
        // 也检查页面模板渲染的完整 HTML
        $html = $this->get_page_html($page_id);
        if ($html) {
            $content = $html;
        }
        
        // 扫描 Elementor 表单
        $forms = array_merge($forms, $this->scan_elementor_forms($content, $page_id));
        
        // 扫描 Contact Form 7
        $forms = array_merge($forms, $this->scan_cf7_forms($content, $page_id));
        
        // 扫描 WPForms
        $forms = array_merge($forms, $this->scan_wpforms($content, $page_id));
        
        // 扫描 Gravity Forms
        $forms = array_merge($forms, $this->scan_gravity_forms($content, $page_id));
        
        // 扫描通用 HTML 表单
        $forms = array_merge($forms, $this->scan_html_forms($content, $page_id));
        
        return $forms;
    }
    
    /**
     * 获取页面完整 HTML
     */
    private function get_page_html(int $page_id): ?string
    {
        $url = get_permalink($page_id);
        if (!$url) return null;
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * 扫描 Elementor 表单
     */
    private function scan_elementor_forms(string $html, int $page_id): array
    {
        $forms = [];
        
        // 匹配 Elementor 表单 widget
        // <div class="elementor-widget-form" data-id="xxx">
        if (preg_match_all('/<div[^>]*class="[^"]*elementor-widget-form[^"]*"[^>]*data-id="([^"]+)"[^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $widget_id) {
                $form_id = 'elementor-' . $widget_id;
                
                // 尝试获取表单名称
                $name = $this->extract_elementor_form_name($html, $widget_id);
                
                $forms[] = [
                    'form_id' => $form_id,
                    'form_name' => $name ?: 'Elementor Form',
                    'type' => 'elementor',
                    'widget_id' => $widget_id,
                ];
            }
        }
        
        // 备用匹配：elementor-element-xxx class
        if (preg_match_all('/<form[^>]*class="[^"]*elementor-form[^"]*"[^>]*>.*?<\/form>/is', $html, $form_matches)) {
            foreach ($form_matches[0] as $form_html) {
                if (preg_match('/elementor-element-([a-z0-9]+)/i', $form_html, $id_match)) {
                    $form_id = 'elementor-' . $id_match[1];
                    
                    // 检查是否已添加
                    $exists = false;
                    foreach ($forms as $f) {
                        if ($f['form_id'] === $form_id) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $forms[] = [
                            'form_id' => $form_id,
                            'form_name' => 'Elementor Form',
                            'type' => 'elementor',
                        ];
                    }
                }
            }
        }
        
        return $forms;
    }
    
    /**
     * 提取 Elementor 表单名称
     */
    private function extract_elementor_form_name(string $html, string $widget_id): ?string
    {
        // 尝试从表单标题提取
        $pattern = '/data-id="' . preg_quote($widget_id, '/') . '"[^>]*>.*?<h\d[^>]*class="[^"]*elementor-heading-title[^"]*"[^>]*>([^<]+)</is';
        if (preg_match($pattern, $html, $match)) {
            return trim($match[1]);
        }
        return null;
    }
    
    /**
     * 扫描 Contact Form 7
     */
    private function scan_cf7_forms(string $html, int $page_id): array
    {
        $forms = [];
        
        // 匹配 CF7 表单容器
        // <div class="wpcf7" data-wpcf7-id="123">
        if (preg_match_all('/<div[^>]*class="[^"]*wpcf7[^"]*"[^>]*data-wpcf7-id="(\d+)"[^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $cf7_id) {
                $form_id = 'cf7-' . $cf7_id;
                
                // 获取 CF7 表单标题
                $cf7_post = get_post($cf7_id);
                $name = $cf7_post ? $cf7_post->post_title : 'Contact Form 7';
                
                $forms[] = [
                    'form_id' => $form_id,
                    'form_name' => $name,
                    'type' => 'cf7',
                    'cf7_id' => $cf7_id,
                ];
            }
        }
        
        // 备用：从 hidden input 获取
        if (preg_match_all('/<input[^>]*name="_wpcf7"[^>]*value="(\d+)"[^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $cf7_id) {
                $form_id = 'cf7-' . $cf7_id;
                
                // 检查是否已添加
                $exists = false;
                foreach ($forms as $f) {
                    if ($f['form_id'] === $form_id) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $cf7_post = get_post($cf7_id);
                    $forms[] = [
                        'form_id' => $form_id,
                        'form_name' => $cf7_post ? $cf7_post->post_title : 'Contact Form 7',
                        'type' => 'cf7',
                        'cf7_id' => $cf7_id,
                    ];
                }
            }
        }
        
        return $forms;
    }
    
    /**
     * 扫描 WPForms
     */
    private function scan_wpforms(string $html, int $page_id): array
    {
        $forms = [];
        
        // 匹配 WPForms
        // <form id="wpforms-form-123" data-formid="123">
        if (preg_match_all('/<form[^>]*(?:id="wpforms-form-(\d+)"|data-formid="(\d+)")[^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $wpforms_id = !empty($match[1]) ? $match[1] : $match[2];
                $form_id = 'wpforms-' . $wpforms_id;
                
                // 检查是否已添加
                $exists = false;
                foreach ($forms as $f) {
                    if ($f['form_id'] === $form_id) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    // 尝试获取表单名称
                    $name = $this->get_wpforms_name($wpforms_id);
                    
                    $forms[] = [
                        'form_id' => $form_id,
                        'form_name' => $name ?: 'WPForms',
                        'type' => 'wpforms',
                        'wpforms_id' => $wpforms_id,
                    ];
                }
            }
        }
        
        return $forms;
    }
    
    /**
     * 获取 WPForms 表单名称
     */
    private function get_wpforms_name(string $form_id): ?string
    {
        $post = get_post($form_id);
        return $post ? $post->post_title : null;
    }
    
    /**
     * 扫描 Gravity Forms
     */
    private function scan_gravity_forms(string $html, int $page_id): array
    {
        $forms = [];
        
        // 匹配 Gravity Forms
        // <form id="gform_123">
        if (preg_match_all('/<form[^>]*id="gform_(\d+)"[^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $gf_id) {
                $form_id = 'gf-' . $gf_id;
                
                $forms[] = [
                    'form_id' => $form_id,
                    'form_name' => 'Gravity Form #' . $gf_id,
                    'type' => 'gravity',
                    'gf_id' => $gf_id,
                ];
            }
        }
        
        return $forms;
    }
    
    /**
     * 扫描通用 HTML 表单
     */
    private function scan_html_forms(string $html, int $page_id): array
    {
        $forms = [];
        
        // 匹配有 id 属性的表单
        if (preg_match_all('/<form[^>]*id="([^"]+)"[^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $form_id) {
                // 跳过已知插件的表单
                if (strpos($form_id, 'wpforms') !== false ||
                    strpos($form_id, 'gform') !== false ||
                    strpos($form_id, 'wpcf7') !== false ||
                    strpos($form_id, 'elementor') !== false) {
                    continue;
                }
                
                // 跳过 WordPress 内置表单（除了评论表单）
                if (in_array($form_id, ['loginform', 'registerform', 'search-form'])) {
                    continue;
                }
                
                // 评论表单特殊处理
                if ($form_id === 'commentform') {
                    $forms[] = [
                        'form_id' => 'commentform',
                        'form_name' => '评论表单',
                        'type' => 'comment',
                    ];
                    continue;
                }
                
                $forms[] = [
                    'form_id' => $form_id,
                    'form_name' => ucwords(str_replace(['-', '_'], ' ', $form_id)),
                    'type' => 'html',
                ];
            }
        }
        
        return $forms;
    }
    
    /**
     * 快速扫描（只扫描有表单的页面）
     */
    public function quick_scan(): array
    {
        $forms = [];
        
        // 1. 扫描 Elementor 表单（从数据库）
        if (class_exists('Elementor\Plugin')) {
            $elementor_forms = $this->scan_elementor_from_db();
            $forms = array_merge($forms, $elementor_forms);
        }
        
        // 2. 扫描 Contact Form 7
        if (class_exists('WPCF7_ContactForm')) {
            $cf7_forms = $this->scan_cf7_from_db();
            $forms = array_merge($forms, $cf7_forms);
        }
        
        // 3. 扫描 WPForms
        if (function_exists('wpforms')) {
            $wpforms = $this->scan_wpforms_from_db();
            $forms = array_merge($forms, $wpforms);
        }
        
        return $forms;
    }
    
    /**
     * 从数据库扫描 Elementor 表单
     */
    private function scan_elementor_from_db(): array
    {
        global $wpdb;
        $forms = [];
        
        // 查找包含 form widget 的页面
        $results = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_key = '_elementor_data' 
             AND meta_value LIKE '%\"widgetType\":\"form\"%'"
        );
        
        foreach ($results as $row) {
            $data = json_decode($row->meta_value, true);
            if (!$data) continue;
            
            $page_forms = $this->extract_elementor_forms_from_data($data);
            foreach ($page_forms as $form) {
                $post = get_post($row->post_id);
                $form['page_id'] = $row->post_id;
                $form['page_title'] = $post ? $post->post_title : '';
                $form['page_url'] = get_permalink($row->post_id);
                $forms[] = $form;
            }
        }
        
        return $forms;
    }
    
    /**
     * 从 Elementor 数据中提取表单
     */
    private function extract_elementor_forms_from_data(array $elements): array
    {
        $forms = [];
        
        foreach ($elements as $element) {
            if (isset($element['widgetType']) && $element['widgetType'] === 'form') {
                $widget_id = $element['id'] ?? '';
                $settings = $element['settings'] ?? [];
                $form_name = $settings['form_name'] ?? 'Elementor Form';
                
                $forms[] = [
                    'form_id' => 'elementor-' . $widget_id,
                    'form_name' => $form_name,
                    'type' => 'elementor',
                    'widget_id' => $widget_id,
                ];
            }
            
            // 递归检查子元素
            if (!empty($element['elements'])) {
                $forms = array_merge($forms, $this->extract_elementor_forms_from_data($element['elements']));
            }
        }
        
        return $forms;
    }
    
    /**
     * 从数据库扫描 CF7 表单
     */
    private function scan_cf7_from_db(): array
    {
        $forms = [];
        
        $cf7_posts = get_posts([
            'post_type' => 'wpcf7_contact_form',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        
        foreach ($cf7_posts as $post) {
            $forms[] = [
                'form_id' => 'cf7-' . $post->ID,
                'form_name' => $post->post_title,
                'type' => 'cf7',
                'cf7_id' => $post->ID,
            ];
        }
        
        return $forms;
    }
    
    /**
     * 从数据库扫描 WPForms
     */
    private function scan_wpforms_from_db(): array
    {
        $forms = [];
        
        $wpforms_posts = get_posts([
            'post_type' => 'wpforms',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        
        foreach ($wpforms_posts as $post) {
            $forms[] = [
                'form_id' => 'wpforms-' . $post->ID,
                'form_name' => $post->post_title,
                'type' => 'wpforms',
                'wpforms_id' => $post->ID,
            ];
        }
        
        return $forms;
    }
}
