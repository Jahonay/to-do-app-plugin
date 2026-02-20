<?php
/**
 *
 * @wordpress-plugin
 * Plugin Name:       To do app
 * Description:       To do app
 * Version:           1.0.0
 * Author:            John Mackey
 * Author URI:        https://johnmackeydesigns.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */



// Register custom REST API routes
add_action('rest_api_init', function () {
    // Get all tasks
    register_rest_route('tasks/v1', '/tasks', array(
        'methods' => 'GET',
        'callback' => 'get_tasks',
        'permission_callback' => 'tasks_permission_check',
    ));

    // Create a new task
    register_rest_route('tasks/v1', '/tasks', array(
        'methods' => 'POST',
        'callback' => 'create_task',
        'permission_callback' => 'tasks_permission_check',
    ));

    // Update a task
    register_rest_route('tasks/v1', '/tasks/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'update_task',
        'permission_callback' => 'tasks_permission_check',
    ));

    // Delete a task
    register_rest_route('tasks/v1', '/tasks/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'delete_task',
        'permission_callback' => 'tasks_permission_check',
    ));
    
    // Debug endpoint to test authentication
    register_rest_route('tasks/v1', '/auth-test', array(
        'methods' => 'GET',
        'callback' => 'tasks_auth_test',
        'permission_callback' => '__return_true', // Public for testing
    ));
});

/**
 * Permission check - user must be logged in
 * This function checks both cookie-based and Basic Auth authentication
 */
function tasks_permission_check($request) {
    // First check if user is logged in via WordPress cookies
    if (is_user_logged_in()) {
        return true;
    }
    
    // If not logged in via cookies, check for Basic Auth (Application Password)
    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        // Application Password authentication
        $user = wp_authenticate($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        
        if (!is_wp_error($user)) {
            wp_set_current_user($user->ID);
            return true;
        }
    }
    
    // Check Authorization header as fallback
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (empty($auth_header) && function_exists('getallheaders')) {
        $headers = getallheaders();
        $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    }
    
    if (!empty($auth_header) && strpos($auth_header, 'Basic ') === 0) {
        $credentials = base64_decode(substr($auth_header, 6));
        if (strpos($credentials, ':') !== false) {
            list($username, $password) = explode(':', $credentials, 2);
            $user = wp_authenticate($username, $password);
            
            if (!is_wp_error($user)) {
                wp_set_current_user($user->ID);
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Debug endpoint to test authentication setup
 * Access: /wp-json/tasks/v1/auth-test
 */
function tasks_auth_test($request) {
    $debug_info = array(
        'wordpress_version' => get_bloginfo('version'),
        'php_version' => phpversion(),
        'is_https' => is_ssl(),
        'is_user_logged_in' => is_user_logged_in(),
        'current_user_id' => get_current_user_id(),
        'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
        'auth_header_present' => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'Yes' : 'No',
        'php_auth_user_present' => isset($_SERVER['PHP_AUTH_USER']) ? 'Yes' : 'No',
        'application_passwords_available' => class_exists('WP_Application_Passwords'),
        'rest_url' => rest_url('tasks/v1/tasks'),
    );
    
    // Try to get Authorization header
    $auth_header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = 'HTTP_AUTHORIZATION found';
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $auth_header = 'Authorization header found in getallheaders()';
        } else {
            $auth_header = 'No Authorization header found';
        }
    }
    $debug_info['auth_header_status'] = $auth_header;
    
    // Test if we can authenticate with provided credentials
    if (isset($_SERVER['HTTP_AUTHORIZATION']) || (function_exists('getallheaders') && isset(getallheaders()['Authorization']))) {
        $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : getallheaders()['Authorization'];
        if (strpos($header, 'Basic ') === 0) {
            $credentials = base64_decode(substr($header, 6));
            if (strpos($credentials, ':') !== false) {
                list($username, $password) = explode(':', $credentials, 2);
                $user = wp_authenticate($username, $password);
                
                if (!is_wp_error($user)) {
                    $debug_info['authentication_test'] = 'SUCCESS - Valid credentials';
                    $debug_info['authenticated_user'] = $user->user_login;
                } else {
                    $debug_info['authentication_test'] = 'FAILED - ' . $user->get_error_message();
                }
            }
        }
    } else {
        $debug_info['authentication_test'] = 'No credentials provided';
    }
    
    return new WP_REST_Response($debug_info, 200);
}

/**
 * Get all tasks for the current user
 */
function get_tasks($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', array('status' => 401));
    }
    
    $tasks = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE user_id = %d 
            ORDER BY 
            CASE 
                WHEN completed = 0 AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1
                WHEN completed = 0 AND due_date = CURDATE() THEN 2
                WHEN completed = 0 AND due_date IS NOT NULL THEN 3
                WHEN completed = 0 THEN 4
                ELSE 5
            END,
            due_date ASC,
            created_at DESC",
            $user_id
        ),
        ARRAY_A
    );
    
    // Convert database results to proper format
    $formatted_tasks = array_map(function($task) {
        return array(
            'id' => (int)$task['id'],
            'user_id' => (int)$task['user_id'],
            'text' => $task['text'],
            'description' => $task['description'],
            'due_date' => $task['due_date'],
            'category' => $task['category'] ?: 'general',
            'completed' => (bool)$task['completed'],
            'created_at' => $task['created_at'],
        );
    }, $tasks);
    
    return new WP_REST_Response($formatted_tasks, 200);
}

/**
 * Create a new task for the current user
 */
function create_task($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', array('status' => 401));
    }
    
    $body = json_decode($request->get_body(), true);
    
    $text = sanitize_text_field($body['text']);
    $description = isset($body['description']) ? sanitize_textarea_field($body['description']) : '';
    $due_date = isset($body['due_date']) && !empty($body['due_date']) ? sanitize_text_field($body['due_date']) : null;
    $category = isset($body['category']) ? sanitize_text_field($body['category']) : 'general';
    $completed = isset($body['completed']) ? (bool)$body['completed'] : false;
    $created_at = current_time('mysql');
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'text' => $text,
            'description' => $description,
            'due_date' => $due_date,
            'category' => $category,
            'completed' => $completed,
            'created_at' => $created_at,
        ),
        array('%d', '%s', '%s', '%s', '%s', '%d', '%s')
    );
    
    if ($result === false) {
        return new WP_Error('db_insert_error', 'Could not create task', array('status' => 500));
    }
    
    $task = array(
        'id' => $wpdb->insert_id,
        'user_id' => $user_id,
        'text' => $text,
        'description' => $description,
        'due_date' => $due_date,
        'category' => $category,
        'completed' => $completed,
        'created_at' => $created_at,
    );
    
    return new WP_REST_Response($task, 201);
}

/**
 * Update a task (only if it belongs to the current user)
 */
function update_task($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', array('status' => 401));
    }
    
    $id = (int)$request['id'];
    $body = json_decode($request->get_body(), true);
    
    // Check if task exists and belongs to current user
    $task = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d", 
            $id, 
            $user_id
        ),
        ARRAY_A
    );
    
    if (!$task) {
        return new WP_Error('task_not_found', 'Task not found or access denied', array('status' => 404));
    }
    
    $update_data = array();
    $update_format = array();
    
    if (isset($body['text'])) {
        $update_data['text'] = sanitize_text_field($body['text']);
        $update_format[] = '%s';
    }
    
    if (isset($body['description'])) {
        $update_data['description'] = sanitize_textarea_field($body['description']);
        $update_format[] = '%s';
    }
    
    if (isset($body['due_date'])) {
        $update_data['due_date'] = !empty($body['due_date']) ? sanitize_text_field($body['due_date']) : null;
        $update_format[] = '%s';
    }
    
    if (isset($body['category'])) {
        $update_data['category'] = sanitize_text_field($body['category']);
        $update_format[] = '%s';
    }
    
    if (isset($body['completed'])) {
        $update_data['completed'] = (bool)$body['completed'];
        $update_format[] = '%d';
    }
    
    if (empty($update_data)) {
        return new WP_Error('no_data', 'No data to update', array('status' => 400));
    }
    
    $result = $wpdb->update(
        $table_name,
        $update_data,
        array('id' => $id, 'user_id' => $user_id),
        $update_format,
        array('%d', '%d')
    );
    
    if ($result === false) {
        return new WP_Error('db_update_error', 'Could not update task', array('status' => 500));
    }
    
    // Get updated task
    $updated_task = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d", 
            $id, 
            $user_id
        ),
        ARRAY_A
    );
    
    $formatted_task = array(
        'id' => (int)$updated_task['id'],
        'user_id' => (int)$updated_task['user_id'],
        'text' => $updated_task['text'],
        'description' => $updated_task['description'],
        'due_date' => $updated_task['due_date'],
        'category' => $updated_task['category'] ?: 'general',
        'completed' => (bool)$updated_task['completed'],
        'created_at' => $updated_task['created_at'],
    );
    
    return new WP_REST_Response($formatted_task, 200);
}

/**
 * Delete a task (only if it belongs to the current user)
 */
function delete_task($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', array('status' => 401));
    }
    
    $id = (int)$request['id'];
    
    // Check if task exists and belongs to current user
    $task = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d", 
            $id, 
            $user_id
        ),
        ARRAY_A
    );
    
    if (!$task) {
        return new WP_Error('task_not_found', 'Task not found or access denied', array('status' => 404));
    }
    
    $result = $wpdb->delete(
        $table_name,
        array('id' => $id, 'user_id' => $user_id),
        array('%d', '%d')
    );
    
    if ($result === false) {
        return new WP_Error('db_delete_error', 'Could not delete task', array('status' => 500));
    }
    
    return new WP_REST_Response(
        array('deleted' => true, 'id' => $id),
        200
    );
}

/**
 * Create tasks table on plugin/theme activation
 */
function create_tasks_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        text text NOT NULL,
        description text,
        due_date date,
        category varchar(50) DEFAULT 'general',
        completed tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY category (category),
        KEY due_date (due_date),
        KEY completed (completed)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Run table creation on theme/plugin activation
// If adding to functions.php, uncomment the line below:
// add_action('after_setup_theme', 'create_tasks_table');

// If creating a plugin, use register_activation_hook instead:
// register_activation_hook(__FILE__, 'create_tasks_table');

/**
 * Enable CORS for authenticated requests
 * 
 * IMPORTANT: For authentication to work with CORS, you need to:
 * 1. Use WordPress Application Passwords (WordPress 5.6+)
 * 2. Send credentials with fetch requests from React
 * 3. Configure CORS to allow credentials
 * 
 * APACHE SERVERS: If using Apache, you may need to add this to your .htaccess file
 * to enable the Authorization header:
 * 
 * RewriteEngine On
 * RewriteCond %{HTTP:Authorization} ^(.*)
 * RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]
 * 
 * Or add this to your wp-config.php:
 * if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
 *     list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = 
 *         explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
 * }
 */
function add_cors_http_header() {
    // IMPORTANT: Replace with your actual React app URL in production
    $allowed_origin = 'http://localhost:5173'; // Vite default port
    
    // For production, use your actual domain:
    // $allowed_origin = 'https://your-react-app.com';
    
    header("Access-Control-Allow-Origin: $allowed_origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Credentials: true");
}
add_action('init', 'add_cors_http_header');

// Handle OPTIONS requests for CORS preflight
add_action('rest_api_init', function() {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($value) {
        $allowed_origin = 'http://localhost:5173'; // Match the origin above
        
        header("Access-Control-Allow-Origin: $allowed_origin");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        return $value;
    });
}, 15);