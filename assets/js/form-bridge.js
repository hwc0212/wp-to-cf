/**
 * WP to CF Form Bridge
 * 将表单提交转发到第三方表单服务
 */
(function() {
    'use strict';

    var CONFIG = window.__WPTOCF_FORM_CONFIG__ || { forms: {} };
    var formLoadTimes = {}; // 记录表单加载时间

    function init() {
        document.addEventListener('submit', handleSubmit, true);
        // 记录所有表单的加载时间
        recordFormLoadTimes();
    }

    // 记录表单加载时间（用于防机器人）
    function recordFormLoadTimes() {
        var forms = document.querySelectorAll('form');
        var now = Date.now();
        forms.forEach(function(form) {
            var formId = getFormId(form);
            if (formId) {
                formLoadTimes[formId] = now;
            }
        });
    }

    function handleSubmit(event) {
        var form = event.target;
        if (form.tagName !== 'FORM') return;

        var formId = getFormId(form);
        if (!formId) return;

        var formConfig = CONFIG.forms[formId];
        if (!formConfig || !formConfig.endpoint) return;

        event.preventDefault();
        event.stopPropagation();

        if (form.classList.contains('form-bridge-loading')) return;

        submitForm(form, formConfig);
    }

    function getFormId(form) {
        if (form.dataset.formId) return form.dataset.formId;
        if (form.id) return form.id;
        
        var elementorWidget = form.closest('.elementor-widget-form');
        if (elementorWidget) {
            var widgetId = elementorWidget.dataset.id;
            if (widgetId) return 'elementor-' + widgetId;
            var match = elementorWidget.className.match(/elementor-element-([a-z0-9]+)/);
            if (match) return 'elementor-' + match[1];
        }
        
        if (form.classList.contains('wpcf7-form')) {
            var wpcf7 = form.closest('.wpcf7');
            if (wpcf7 && wpcf7.dataset.wpcf7Id) return 'cf7-' + wpcf7.dataset.wpcf7Id;
            var cf7Input = form.querySelector('input[name="_wpcf7"]');
            if (cf7Input) return 'cf7-' + cf7Input.value;
        }
        
        if (form.classList.contains('wpforms-form')) {
            if (form.dataset.formid) return 'wpforms-' + form.dataset.formid;
            var wpMatch = form.id.match(/wpforms-form-(\d+)/);
            if (wpMatch) return 'wpforms-' + wpMatch[1];
        }
        
        if (form.id && form.id.match(/^gform_\d+$/)) {
            var gfMatch = form.id.match(/gform_(\d+)/);
            if (gfMatch) return 'gf-' + gfMatch[1];
        }
        
        var hidden = form.querySelector('input[name="form_id"], input[name="_form_id"]');
        if (hidden) return hidden.value;
        
        if (form.name) return form.name;
        
        return null;
    }

    function submitForm(form, config) {
        setLoadingState(form, true);
        clearMessages(form);

        var formData = new FormData(form);
        var serviceType = config.service_type || 'formspree';
        var isCommentForm = form.id === 'commentform' || form.classList.contains('comment-form');
        var formId = getFormId(form);
        
        // ========== 安全字段 (CF Form Service) ==========
        if (serviceType === 'cfform') {
            // 添加表单加载时间戳（防机器人）
            if (formId && formLoadTimes[formId]) {
                formData.append('_form_load_time', formLoadTimes[formId]);
            }
            // 蜜罐字段保持为空（如果表单中有的话）
        }
        
        // 评论表单字段映射
        if (isCommentForm) {
            // 将 WordPress 评论字段映射为通用字段名
            var author = formData.get('author');
            var email = formData.get('email');
            var url = formData.get('url');
            var comment = formData.get('comment');
            
            if (author) formData.set('name', author);
            if (email) formData.set('author_email', email);
            if (url) formData.set('website', url);
            if (comment) formData.set('comment_content', comment);
            
            // 添加页面 URL 用于调试
            formData.set('_page_url', window.location.href);
        }
        
        if (serviceType === 'web3forms') {
            var accessKey = extractWeb3FormsKey(config.endpoint);
            if (accessKey && !formData.has('access_key')) {
                formData.append('access_key', accessKey);
            }
        }

        fetch(config.endpoint, {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        })
        .then(function(response) {
            return response.json().then(function(data) {
                return { ok: response.ok, status: response.status, data: data };
            }).catch(function() {
                return { ok: response.ok, status: response.status, data: {} };
            });
        })
        .then(function(result) {
            setLoadingState(form, false);

            if (result.ok || result.status === 200 || result.status === 201) {
                form.classList.add('form-bridge-success');
                form.classList.remove('form-bridge-error');

                if (config.redirect_url) {
                    window.location.href = config.redirect_url;
                    return;
                }

                var successMsg = isCommentForm 
                    ? (config.success_message || '评论已提交，审核通过后将显示。')
                    : (config.success_message || '提交成功！');
                showMessage(form, successMsg, 'success');
                form.reset();
            } else {
                form.classList.add('form-bridge-error');
                form.classList.remove('form-bridge-success');
                var errorMsg = result.data.error || result.data.message || '提交失败，请稍后重试';
                showMessage(form, errorMsg, 'error');
            }
        })
        .catch(function() {
            setLoadingState(form, false);
            form.classList.add('form-bridge-error');
            form.classList.remove('form-bridge-success');
            showMessage(form, '网络错误，请稍后重试', 'error');
        });
    }

    function extractWeb3FormsKey(endpoint) {
        try {
            var url = new URL(endpoint);
            return url.searchParams.get('access_key');
        } catch (e) {
            return null;
        }
    }

    function setLoadingState(form, loading) {
        var submitBtn = form.querySelector(
            'button[type="submit"], input[type="submit"], ' +
            '.elementor-button[type="submit"], .wpcf7-submit, .wpforms-submit'
        );

        if (loading) {
            form.classList.add('form-bridge-loading');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.textContent || submitBtn.value;
                if (submitBtn.tagName === 'INPUT') {
                    submitBtn.value = '提交中...';
                } else {
                    submitBtn.textContent = '提交中...';
                }
            }
        } else {
            form.classList.remove('form-bridge-loading');
            if (submitBtn) {
                submitBtn.disabled = false;
                var original = submitBtn.dataset.originalText;
                if (original) {
                    if (submitBtn.tagName === 'INPUT') {
                        submitBtn.value = original;
                    } else {
                        submitBtn.textContent = original;
                    }
                }
            }
        }
    }

    function showMessage(form, message, type) {
        var container = form.querySelector('.form-bridge-message');
        if (!container) {
            container = document.createElement('div');
            container.className = 'form-bridge-message';
            var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitBtn && submitBtn.parentNode) {
                submitBtn.parentNode.insertBefore(container, submitBtn.nextSibling);
            } else {
                form.appendChild(container);
            }
        }

        container.textContent = message;
        container.className = 'form-bridge-message form-bridge-message-' + type;
        container.style.cssText = 'display:block;padding:10px;margin:10px 0;border-radius:4px;' +
            (type === 'success' 
                ? 'background:#d4edda;color:#155724;border:1px solid #c3e6cb;' 
                : 'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;');
    }

    function clearMessages(form) {
        var container = form.querySelector('.form-bridge-message');
        if (container) {
            container.style.display = 'none';
            container.textContent = '';
        }
        form.classList.remove('form-bridge-success', 'form-bridge-error');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
