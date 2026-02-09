<?php
/**
 * Tab: Form Submissions
 */
if (!defined('ABSPATH')) exit;

// Get submission records
$sync = new WP_to_CF_Submission_Sync();
$submissions = $sync->get_recent_submissions(100);

// Group by form
$grouped = [];
foreach ($submissions as $submission) {
    if ($submission['submission_type'] === 'form') {
        $form_id = $submission['form_id'];
        if (!isset($grouped[$form_id])) {
            $grouped[$form_id] = [];
        }
        $grouped[$form_id][] = $submission;
    }
}
?>

<div class="wptocf-panel blue">
    <h2><span class="dashicons dashicons-feedback"></span> <?php esc_html_e('Form Submissions', 'wp-to-cf'); ?></h2>
    
    <div class="wptocf-warning-box">
        <p style="margin: 0;"><span class="dashicons dashicons-info" style="color: #2271b1;"></span> 
        <?php esc_html_e('This page displays form submission records synced from third-party form services (excluding comments)', 'wp-to-cf'); ?></p>
    </div>
    
    <?php if (empty($grouped)): ?>
        <p style="text-align: center; padding: 40px; color: #666;">
            <span class="dashicons dashicons-clipboard" style="font-size: 48px; opacity: 0.3;"></span><br>
            <?php esc_html_e('No form submissions yet', 'wp-to-cf'); ?>
        </p>
    <?php else: ?>
        <?php foreach ($grouped as $form_id => $items): ?>
            <div class="wptocf-submission-group" style="margin-top: 20px;">
                <h3 style="background: #f0f0f1; padding: 10px 15px; margin: 0;">
                    <?php echo esc_html($form_id); ?>
                    <span style="color: #666; font-size: 14px; font-weight: normal;">
                        (<?php echo count($items); ?> <?php esc_html_e('records', 'wp-to-cf'); ?>)
                    </span>
                </h3>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 0;">
                    <thead>
                        <tr>
                            <th style="width: 50px;"><?php esc_html_e('ID', 'wp-to-cf'); ?></th>
                            <th><?php esc_html_e('Submission Content', 'wp-to-cf'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Status', 'wp-to-cf'); ?></th>
                            <th style="width: 150px;"><?php esc_html_e('Submitted At', 'wp-to-cf'); ?></th>
                            <th style="width: 80px;"><?php esc_html_e('Actions', 'wp-to-cf'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $submission): ?>
                            <?php
                            $data = json_decode($submission['data'], true);
                            $status_label = $submission['status'] === 'processed' ? __('Processed', 'wp-to-cf') : __('Pending', 'wp-to-cf');
                            $status_color = $submission['status'] === 'processed' ? '#00a32a' : '#dba617';
                            ?>
                            <tr>
                                <td><?php echo esc_html($submission['id']); ?></td>
                                <td>
                                    <button type="button" class="button button-small wptocf-view-submission" 
                                            data-id="<?php echo esc_attr($submission['id']); ?>"
                                            data-content="<?php echo esc_attr(json_encode($data, JSON_UNESCAPED_UNICODE)); ?>">
                                        <span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
                                        <?php esc_html_e('View Details', 'wp-to-cf'); ?>
                                    </button>
                                </td>
                                <td>
                                    <span style="color: <?php echo esc_attr($status_color); ?>; font-weight: bold;">
                                        <?php echo esc_html($status_label); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($submission['created_at']); ?></td>
                                <td>
                                    <button type="button" class="button button-small button-link-delete wptocf-delete-submission" 
                                            data-id="<?php echo esc_attr($submission['id']); ?>">
                                        <?php esc_html_e('Delete', 'wp-to-cf'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Details Modal -->
<div id="wptocf-submission-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 20px; border-radius: 4px; max-width: 600px; max-height: 80vh; overflow-y: auto; position: relative;">
        <button type="button" id="wptocf-close-modal" style="position: absolute; top: 10px; right: 10px; border: none; background: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
        <h2><?php esc_html_e('Submission Details', 'wp-to-cf'); ?></h2>
        <div id="wptocf-submission-content"></div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var formNonce = '<?php echo wp_create_nonce('wptocf_ajax'); ?>';
    
    // View details
    $('.wptocf-view-submission').on('click', function() {
        var data = JSON.parse($(this).attr('data-content'));
        var html = '<table class="widefat" style="margin-top: 15px;">';
        
        $.each(data, function(key, value) {
            if (key.indexOf('_') === 0) return; // Skip internal fields
            var displayKey = key.replace(/_/g, ' ').replace(/\b\w/g, function(l){ return l.toUpperCase(); });
            html += '<tr>';
            html += '<td style="padding: 8px; background: #f9f9f9; font-weight: bold; width: 30%;">' + displayKey + '</td>';
            html += '<td style="padding: 8px;">' + (value || '-') + '</td>';
            html += '</tr>';
        });
        
        html += '</table>';
        $('#wptocf-submission-content').html(html);
        $('#wptocf-submission-modal').css('display', 'flex');
    });
    
    // Close modal
    $('#wptocf-close-modal, #wptocf-submission-modal').on('click', function(e) {
        if (e.target === this) {
            $('#wptocf-submission-modal').hide();
        }
    });
    
    // Delete submission
    $('.wptocf-delete-submission').on('click', function() {
        if (!confirm('<?php esc_html_e('Are you sure you want to delete this record?', 'wp-to-cf'); ?>')) {
            return;
        }
        
        var $btn = $(this);
        var id = $btn.data('id');
        
        $btn.prop('disabled', true).text('<?php esc_html_e('Deleting...', 'wp-to-cf'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wptocf_delete_submission',
                nonce: formNonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(function() {
                        $(this).remove();
                    });
                } else {
                    alert(response.data.message || '<?php esc_html_e('Delete failed', 'wp-to-cf'); ?>');
                    $btn.prop('disabled', false).text('<?php esc_html_e('Delete', 'wp-to-cf'); ?>');
                }
            },
            error: function() {
                alert('<?php esc_html_e('Network error', 'wp-to-cf'); ?>');
                $btn.prop('disabled', false).text('<?php esc_html_e('Delete', 'wp-to-cf'); ?>');
            }
        });
    });
});
</script>
