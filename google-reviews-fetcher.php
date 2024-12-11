<?php
/**
 * Plugin Name: Google Reviews Fetcher
 * Description: Fetches Google Reviews and stores them as a Custom Post Type called Reviews
 * Version: 1.0.0
 * Author: Dave Pratt
 * Author URI: https://strength9.co.uk
 * Copyright: © 2024 Dave Pratt. All rights reserved.
 * License: GPL v2 or later
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Add these constants for better security
define('GRF_VERSION', '1.0.0');
define('GRF_MINIMUM_WP_VERSION', '5.0');
define('GRF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GRF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GRF_MINIMUM_FETCH_INTERVAL', 5 * MINUTE_IN_SECONDS);

class GoogleReviewsFetcher {
    private $api_key;
    private $place_id;
    private $post_type = 'google_review';
    private $capability = 'manage_options';
    private $allowed_mime_types = array('image/jpeg', 'image/png', 'image/webp');

    public function initialize() {
        // Initialize hooks
        add_action('init', array($this, 'register_post_type'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('grf_cron_fetch_reviews', array($this, 'cron_fetch_reviews'));
        
        // Add AJAX actions for both logged-in and non-logged-in users (if needed)
        add_action('wp_ajax_fetch_google_reviews', array($this, 'fetch_reviews'));

        // Get settings
        $this->api_key = get_option('grf_google_api_key');
        $this->place_id = get_option('grf_place_id');

        // Add custom meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_' . $this->post_type, array($this, 'save_review_meta'));
        add_action('admin_head', array($this, 'admin_styles'));

        // Add AJAX actions for Place ID lookup
        add_action('wp_ajax_grf_lookup_place', array($this, 'lookup_place'));
    }

    public function register_post_type() {
        $labels = array(
            'name'               => 'Google Reviews',
            'singular_name'      => 'Google Review',
            'menu_name'          => 'Google Reviews',
            'add_new'           => 'Add New',
            'add_new_item'      => 'Add New Review',
            'edit_item'         => 'Edit Review',
            'new_item'          => 'New Review',
            'view_item'         => 'View Review',
            'search_items'      => 'Search Reviews',
            'not_found'         => 'No reviews found',
            'not_found_in_trash'=> 'No reviews found in Trash'
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'query_var'           => false,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'has_archive'         => false,
            'hierarchical'        => false,
            'menu_position'       => 25,
            'supports'            => array('title', 'editor', 'thumbnail'),
            'menu_icon'           => 'dashicons-star-filled'
        );

        register_post_type($this->post_type, $args);
    }

    public function add_admin_menu() {
        // Add submenu page for settings
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,  // Parent slug
            'Google Reviews Settings',                  // Page title
            'Settings',                                // Menu title
            $this->capability,                         // Capability
            'google-reviews-settings',                 // Menu slug
            array($this, 'settings_page')              // Callback function
        );
    }

    public function register_settings() {
        // Secure API key storage
        register_setting('grf_settings', 'grf_google_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'show_in_rest' => false, // Prevent REST API exposure
        ));
        
        register_setting('grf_settings', 'grf_place_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'show_in_rest' => false,
        ));

        register_setting('grf_settings', 'grf_update_frequency', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_update_frequency'),
            'default' => 7,
            'show_in_rest' => false,
        ));
    }

    // Add sanitization method for update frequency
    public function sanitize_update_frequency($value) {
        $value = absint($value);
        return max(1, min(30, $value)); // Limit between 1 and 30 days
    }

    public function settings_page() {
        // Add JavaScript to handle Place ID lookup
        wp_enqueue_script('grf-admin-script', GRF_PLUGIN_URL . 'js/admin.js', array('jquery'), GRF_VERSION, true);
        wp_localize_script('grf-admin-script', 'grfAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fetch_google_reviews_nonce')
        ));
        ?>
        <div class="wrap">
            <h1>Google Reviews Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('grf_settings');
                do_settings_sections('grf_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Google API Key</th>
                        <td>
                            <input type="password" name="grf_google_api_key" 
                                value="<?php echo esc_attr(get_option('grf_google_api_key')); ?>" 
                                class="regular-text"
                                autocomplete="off">
                            <button type="button" class="button button-secondary toggle-password">Show</button>
                            <p class="description">Enter your Google Places API key</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Place ID</th>
                        <td>
                            <input type="text" name="grf_place_id" id="grf_place_id"
                                value="<?php echo esc_attr(get_option('grf_place_id')); ?>" 
                                class="regular-text">
                            <button type="button" class="button button-secondary lookup-place">Lookup Place</button>
                            <p class="description" id="place_name_display">
                                <?php 
                                $place_name = get_option('grf_place_name');
                                if ($place_name) {
                                    echo 'Current Place: <strong style="color: #000;">' . esc_html($place_name).'</strong>';
                                }
                                ?><br>Use <a href="https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder" target="_blank">this tool</a> to find your Place ID
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Update Frequency (days)</th>
                        <td>
                            <input type="number" name="grf_update_frequency" min="1" max="30" 
                                value="<?php echo esc_attr(get_option('grf_update_frequency', 7)); ?>" class="small-text">
                            <p class="description">Reviews will be automatically updated every specified number of days</p>
                            <?php 
                            if ($next_run = wp_next_scheduled('grf_cron_fetch_reviews')) {
                                echo '<p class="description">Next automatic update: ' . date_i18n('F j, Y @ g:i a', $next_run) . '</p>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <div style="margin-top: 20px;">
                <button type="button" class="button button-secondary button-fetch-reviews">Fetch Reviews Now</button>
                <p class="description">Click to manually fetch new reviews</p>
                <div id="fetch-status" style="margin-top: 10px;"></div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Toggle password visibility
            $('.toggle-password').on('click', function(e) {
                e.preventDefault();
                var input = $('input[name="grf_google_api_key"]');
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    $(this).text('Hide');
                } else {
                    input.attr('type', 'password');
                    $(this).text('Show');
                }
            });
        });
        </script>
        <?php
    }

    // Add this method to handle Place ID lookup
    public function lookup_place() {
        check_ajax_referer('grf_place_lookup_nonce', 'nonce');
        
        if (!current_user_can($this->capability)) {
            wp_send_json_error('Unauthorized access');
            return;
        }

        $place_id = isset($_POST['place_id']) ? sanitize_text_field($_POST['place_id']) : '';
        
        if (empty($place_id)) {
            wp_send_json_error('Place ID is required');
            return;
        }

        // Build the URL for Google Places API
        $url = sprintf(
            'https://maps.googleapis.com/maps/api/place/details/json?place_id=%s&fields=name&key=%s',
            urlencode($place_id),
            urlencode($this->api_key)
        );

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to lookup place: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'OK') {
            wp_send_json_error('Invalid place ID or API response');
            return;
        }

        $place_name = $data['result']['name'];
        update_option('grf_place_name', $place_name);
        
        wp_send_json_success(array(
            'name' => $place_name
        ));
    }

    public function fetch_reviews() {
        // Enhanced security checks
        if (!check_ajax_referer('fetch_google_reviews_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can($this->capability)) {
            wp_send_json_error('Unauthorized access');
            return;
        }

        // Rate limiting
        $last_fetch = get_option('grf_last_fetch', 0);
        if (time() - $last_fetch < GRF_MINIMUM_FETCH_INTERVAL) {
            wp_send_json_error('Please wait before fetching reviews again');
            return;
        }

        // Validate API credentials
        if (empty($this->api_key) || empty($this->place_id)) {
            wp_send_json_error('API credentials not configured');
            return;
        }

        // Build the URL for Google Places API
        $url = sprintf(
            'https://maps.googleapis.com/maps/api/place/details/json?place_id=%s&fields=reviews&key=%s',
            urlencode($this->place_id),
            urlencode($this->api_key)
        );

        // Make the API request
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch reviews: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'OK') {
            wp_send_json_error('Invalid API response');
            return;
        }

        if (!isset($data['result']['reviews'])) {
            wp_send_json_error('No reviews found in the API response');
            return;
        }

        // Include required files for media handling
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $reviews_added = 0;
        foreach ($data['result']['reviews'] as $review) {
            // Create unique ID for review
            $review_id = md5($review['author_name'] . $review['time']);
            
            // Check if review already exists
            $existing_reviews = get_posts(array(
                'post_type' => $this->post_type,
                'meta_key' => 'review_id',
                'meta_value' => $review_id,
                'posts_per_page' => 1
            ));

            if (!empty($existing_reviews)) {
                continue; // Skip if review already exists
            }

            // Format the review content with rating stars
            $rating_stars = str_repeat('★', intval($review['rating'])) . str_repeat('☆', 5 - intval($review['rating']));
            $formatted_content = sprintf(
                '%s',
                wp_kses_post($review['text'])
            );

            // Create post with review content
            $post_data = array(
                'post_title'   => sanitize_text_field($review['author_name']),
                'post_content' => $formatted_content,
                'post_status'  => 'publish',
                'post_type'    => $this->post_type,
                'post_date'    => date('Y-m-d H:i:s', intval($review['time'])),
                'post_date_gmt'=> gmdate('Y-m-d H:i:s', intval($review['time']))
            );

            $post_id = wp_insert_post($post_data);

            if ($post_id && !is_wp_error($post_id)) {
                // Store all review data in separate meta fields
                $meta_fields = array(
                    'review_id' => $review_id,
                    'author_name' => sanitize_text_field($review['author_name']),
                    'author_url' => esc_url_raw($review['author_url']),
                    'rating' => intval($review['rating']),
                    'rating_stars' => $rating_stars,
                    'review_time' => intval($review['time']),
                    'review_date' => date('Y-m-d H:i:s', intval($review['time'])),
                    'relative_time' => sanitize_text_field($review['relative_time_description']),
                    'language' => isset($review['language']) ? sanitize_text_field($review['language']) : '',
                    'profile_photo_url' => isset($review['profile_photo_url']) ? esc_url_raw($review['profile_photo_url']) : ''
                );

                // Store meta fields
                foreach ($meta_fields as $key => $value) {
                    update_post_meta($post_id, $key, $value);
                }

                // Handle profile photo as featured image
                if (!empty($review['profile_photo_url'])) {
                    error_log('Processing profile photo for review ' . $post_id);
                    error_log('Profile photo URL: ' . $review['profile_photo_url']);
                    
                    // Add a small delay to ensure server has processed previous requests
                    usleep(500000); // 0.5 second delay
                    
                    $image_result = $this->set_featured_image($post_id, $review['profile_photo_url']);
                    
                    if ($image_result) {
                        error_log('Successfully set featured image for review ' . $post_id);
                    } else {
                        error_log('Failed to set featured image for review ' . $post_id);
                    }
                }

                $reviews_added++;
            } else {
                error_log('Failed to create post for review: ' . ($post_id instanceof WP_Error ? $post_id->get_error_message() : 'Unknown error'));
            }
        }

        // Update last fetch time
        update_option('grf_last_fetch', time());

        wp_send_json_success(sprintf('Successfully added %d new reviews', $reviews_added));
    }

    private function set_featured_image($post_id, $image_url) {
        // Debug log
        error_log('Starting set_featured_image process for post ' . $post_id);
        error_log('Image URL: ' . $image_url);

        // Validate post ID
        if (empty($post_id)) {
            error_log('Empty post ID provided for featured image');
            return false;
        }

        // Validate image URL
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            error_log('Invalid image URL: ' . $image_url);
            return false;
        }

        // Include required files for media handling
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        // Download URL to temporary file
        $tmp = download_url($image_url);
        error_log('Temporary file created: ' . ($tmp instanceof WP_Error ? 'Error: ' . $tmp->get_error_message() : 'Success'));
        
        if (is_wp_error($tmp)) {
            error_log('Error downloading image: ' . $tmp->get_error_message());
            return false;
        }

        // Get file extension from URL
        $file_array = array(
            'name' => 'review-profile-' . uniqid() . '.jpg', // Force jpg extension
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp)
        );

        error_log('File array created: ' . print_r($file_array, true));

        // Do the validation and storage stuff
        $attachment_id = media_handle_sideload($file_array, $post_id);

        // Clean up temporary file
        @unlink($tmp);

        if (is_wp_error($attachment_id)) {
            error_log('Error creating attachment: ' . $attachment_id->get_error_message());
            return false;
        }

        error_log('Attachment created successfully with ID: ' . $attachment_id);

        // Set as featured image
        $result = set_post_thumbnail($post_id, $attachment_id);
        error_log('Set post thumbnail result: ' . ($result ? 'Success' : 'Failed'));

        if (!$result) {
            error_log('Failed to set featured image for post ' . $post_id);
            return false;
        }

        return true;
    }

    // Add method to securely handle errors
    private function handle_error($message, $log_message = '') {
        if (!empty($log_message)) {
            error_log('Google Reviews Fetcher Error: ' . $log_message);
        }
        return new WP_Error('grf_error', $message);
    }

    public function add_meta_boxes() {
        add_meta_box(
            'google_review_details',
            'Review Details',
            array($this, 'render_review_details_meta_box'),
            $this->post_type,
            'normal',
            'high'
        );
    }

    public function render_review_details_meta_box($post) {
        // Get all the review meta data
        $rating = get_post_meta($post->ID, 'rating', true);
        $rating_stars = get_post_meta($post->ID, 'rating_stars', true);
        $author_name = get_post_meta($post->ID, 'author_name', true);
        $review_date = get_post_meta($post->ID, 'review_date', true);
        $relative_time = get_post_meta($post->ID, 'relative_time', true);

        // Add nonce for security
        wp_nonce_field('google_review_meta_box', 'google_review_meta_box_nonce');
        ?>
        <style>
            .review-meta-box {
                padding: 10px;
            }
            .review-meta-box .review-field {
                margin-bottom: 15px;
            }
            .review-meta-box label {
                display: block;
                font-weight: bold;
                margin-bottom: 5px;
            }
            .review-meta-box .rating-stars {
                color: #e7711b;
                font-size: 20px;
            }
            .review-meta-box input[type="text"],
            .review-meta-box textarea {
                width: 100%;
            }
            .review-meta-box .review-text {
                min-height: 100px;
            }
        </style>

        <div class="review-meta-box">
            <div class="review-field">
                <label>Author Name:</label>
                <input type="text" name="author_name" value="<?php echo esc_attr($author_name); ?>" readonly />
            </div>

            <div class="review-field">
                <label>Rating:</label>
                <div class="rating-stars"><?php echo esc_html($rating_stars); ?></div>
                <input type="hidden" name="rating" value="<?php echo esc_attr($rating); ?>" />
            </div>



            <div class="review-field">
                <label>Review Date:</label>
                <input type="text" value="<?php echo esc_attr($review_date); ?>" readonly />
                <p class="description"><?php echo esc_html($relative_time); ?></p>
            </div>

          

            
        </div>
        <?php
    }

    public function save_review_meta($post_id) {
        // Security checks
        if (!isset($_POST['google_review_meta_box_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['google_review_meta_box_nonce'], 'google_review_meta_box')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Fields are read-only, so no need to save them
        // This is just for security in case we want to make them editable later
    }

    public function admin_styles() {
        global $post_type;
        if ($post_type == $this->post_type) {
            ?>
            <style>
                /* Existing styles... */
                
                /* Edit screen styles */
                #post-body-content {
                    margin-bottom: 20px;
                }
                #titlediv {
                    margin-bottom: 20px;
                }
                .review-meta-box {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
         
            </style>
            <?php
        }
    }
}

function global_review_admin_styles() {
    ?>
    <style>
        /* Default state (not selected) */
        #adminmenu .toplevel_page_edit-post_type-google_review .wp-menu-image::before,
                #adminmenu .menu-icon-google_review .wp-menu-image::before {
                    color: #fbbc04 !important; /* Google Reviews star yellow color */
                    opacity: 1; /* Slightly dimmed when not active */
                }
                
                /* Hover state */
                #adminmenu .toplevel_page_edit-post_type-google_review:hover .wp-menu-image::before,
                #adminmenu .menu-icon-google_review:hover .wp-menu-image::before {
                    color: #fbbc04 !important; /* Slightly lighter orange for hover */
                    opacity: 1;
                }

                /* Active/current state */
                #adminmenu .toplevel_page_edit-post_type-google_review.current .wp-menu-image::before,
                #adminmenu .menu-icon-google_review.current .wp-menu-image::before,
                #adminmenu .toplevel_page_edit-post_type-google_review.wp-has-current-submenu .wp-menu-image::before {
                    color: #fbbc04 !important; 
                    opacity: 1;
                }
            </style>
            <?php
        }

add_action('admin_head', 'global_review_admin_styles');


// Initialize the plugin
function initialize_google_reviews_fetcher() {
    global $google_reviews_fetcher;
    
    if (!isset($google_reviews_fetcher)) {
        $google_reviews_fetcher = new GoogleReviewsFetcher();
        $google_reviews_fetcher->initialize();
    }
    
    return $google_reviews_fetcher;
}

add_action('plugins_loaded', 'initialize_google_reviews_fetcher');