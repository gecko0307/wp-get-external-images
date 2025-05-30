<?php
/*
Plugin Name: Get External Images
Description: Imports and uploads external images in posts.
Version: 1.0
Author: Gecko
*/

add_action('admin_menu', function() {
    add_menu_page('Get External Images', 'Get External Images', 'manage_options', 'image-import-progress', 'iip_render_page');
});

function iip_render_page() {
    $nonce = wp_create_nonce('iip_image_import');
    ?>
    <div class="wrap">
        <h1>Get External Images</h1>
        <button id="iip-start-btn" class="button button-primary">Start import</button>
        <progress id="iip-progress-bar" max="100" value="0" style="width: 100%; height: 20px; margin-top: 10px;"></progress>
        <div id="iip-progress-text" style="margin-top: 10px;">Waiting...</div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var total = 0;

        function processBatch(offset) {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'iip_process_batch',
                    offset: offset,
                    nonce: "<?php echo esc_js($nonce); ?>"
                },
                success: function(response) {
                    console.log('Server responce:', response);

                    if (response.success) {
                        var processed = parseInt(response.data.processed);
                        total = parseInt(response.data.total);

                        if (isNaN(processed) || isNaN(total) || total === 0) {
                            console.warn('Invalid data:', processed, total);
                            $('#iip-progress-text').text('Error!');
                            return;
                        }

                        var progress = (processed / total) * 100;
                        $('#iip-progress-bar').val(progress);
                        $('#iip-progress-text').text(processed + ' from ' + total + ' posts processed');

                        if (processed < total) {
                            processBatch(processed);
                        } else {
                            $('#iip-progress-text').text('Import complete!');
                        }
                    } else {
                        $('#iip-progress-text').text('Error: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    $('#iip-progress-text').text('AJAX request error');
                }
            });
        }

        $('#iip-start-btn').on('click', function() {
            $('#iip-progress-bar').val(0);
            $('#iip-progress-text').text('Importing images...');
            processBatch(0);
        });
    });
    </script>
    <?php
}

function iip_is_private_url($url) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return true;

    $ip = gethostbyname($host);
    return
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

add_action('wp_ajax_iip_process_batch', function() {
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized user', 403);
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'iip_image_import')) {
        wp_send_json_error('Invalid nonce');
    }
    
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $batch_size = 5;

    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => $batch_size,
        'offset' => $offset,
    ];

    $posts = get_posts($args);
    $total = wp_count_posts('post')->publish;
    $processed = $offset;

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    foreach ($posts as $post) {
        $content = $post->post_content;

        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);

        if (empty($matches[1])) {
            $processed++;
            continue;
        }

        $has_changes = false;

        foreach ($matches[1] as $img_url) {
            if (strpos($img_url, home_url()) === 0) {
                continue;
            }

            if (!filter_var($img_url, FILTER_VALIDATE_URL)) {
                continue;
            }

            // SSRF mitigation: only allow http(s), no localhost/private IPs
            if (!preg_match('/^https?:\/\//', $img_url) || iip_is_private_url($img_url)) {
                continue;
            }

            $tmp = download_url($img_url);

            if (is_wp_error($tmp)) {
                continue;
            }

            // Check MIME type
            $mime = mime_content_type($tmp);
            if (strpos($mime, 'image/') !== 0) {
                @unlink($tmp);
                continue;
            }

            $file_array = [
                'name' => basename($img_url),
                'tmp_name' => $tmp,
            ];

            $id = media_handle_sideload($file_array, $post->ID);

            if (is_wp_error($id)) {
                @unlink($tmp);
                continue;
            }

            $local_url = wp_get_attachment_url($id);

            $content = str_replace($img_url, $local_url, $content);
            $has_changes = true;
        }

        if ($has_changes) {
            wp_update_post([
                'ID' => $post->ID,
                'post_content' => $content,
            ]);
        }
        $processed++;
    }

    wp_send_json_success([
        'processed' => $processed,
        'total' => $total,
    ]);
});
