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
                    offset: offset
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

add_action('wp_ajax_iip_process_batch', function() {
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

            $tmp = download_url($img_url);

            if (is_wp_error($tmp)) {
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
