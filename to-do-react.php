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


function my_custom_plugin_scripts() {
    // Enqueue the script
    wp_enqueue_script(
        'to-do-app',                                    // Handle (unique name)
        plugin_dir_url( __FILE__ ) . '/js/to-do-app.js',   // Full URL of the script
        array('jquery'),                                       // Dependencies (e.g., jQuery)
        '1.0.0',                                               // Version number
        true                                                   // Load in footer (true) or header (false)
    );
}

add_action( 'wp_enqueue_scripts', 'my_custom_plugin_scripts' );

// Register custom REST API routes
add_action('rest_api_init', function () {
    // Get all tasks
    register_rest_route('tasks/v1', '/tasks', array(
        'methods' => 'GET',
        'callback' => 'get_tasks',
        'permission_callback' => '__return_true', // For development - restrict in production
    ));

    // Create a new task
    register_rest_route('tasks/v1', '/tasks', array(
        'methods' => 'POST',
        'callback' => 'create_task',
        'permission_callback' => '__return_true', // For development - restrict in production
    ));

    // Update a task
    register_rest_route('tasks/v1', '/tasks/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'update_task',
        'permission_callback' => '__return_true', // For development - restrict in production
    ));

    // Delete a task
    register_rest_route('tasks/v1', '/tasks/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'delete_task',
        'permission_callback' => '__return_true', // For development - restrict in production
    ));
    
    register_rest_route( 'custom/v1', '/current-user', array(
        'methods'  => 'GET',
        'callback' => 'get_current_user_data',
        'permission_callback' => '__return_true', // Permissions handled within the function
    ) );
});

function get_current_user_data() {
    if ( is_user_logged_in() ) {
        $current_user = wp_get_current_user();
        // Return only necessary, non-sensitive data
        return rest_ensure_response( array(
            'id'           => $current_user->ID,
            'username'     => $current_user->user_login,
            'email'        => $current_user->user_email,
            'display_name' => $current_user->display_name,
        ) );
    } else {
        return new WP_Error( 'not_logged_in', 'User not logged in', array( 'status' => 401 ) );
    }
}


/**
 * Get all tasks
 */
function get_tasks($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    
    $tasks = $wpdb->get_results(
        "SELECT * FROM $table_name ORDER BY 
        CASE 
            WHEN completed = 0 AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1
            WHEN completed = 0 AND due_date = CURDATE() THEN 2
            WHEN completed = 0 AND due_date IS NOT NULL THEN 3
            WHEN completed = 0 THEN 4
            ELSE 5
        END,
        due_date ASC,
        created_at DESC",
        ARRAY_A
    );
    
    // Convert database results to proper format
    $formatted_tasks = array_map(function($task) {
        return array(
            'id' => (int)$task['id'],
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
 * Create a new task
 */
function create_task($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    
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
            'text' => $text,
            'description' => $description,
            'due_date' => $due_date,
            'category' => $category,
            'completed' => $completed,
            'created_at' => $created_at,
            'user_id' => get_current_user_id(),
        ),
        array('%s', '%s', '%s', '%s', '%d', '%s')
    );
    
    if ($result === false) {
        return new WP_Error('db_insert_error', 'Could not create task', array('status' => 500));
    }
    
    $task = array(
        'id' => $wpdb->insert_id,
        'text' => $text,
        'description' => $description,
        'due_date' => $due_date,
        'category' => $category,
        'completed' => $completed,
        'created_at' => $created_at,
        'user_id' => get_current_user_id(),
    );
    
    return new WP_REST_Response($task, 201);
}

/**
 * Update a task
 */
function update_task($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    
    $id = (int)$request['id'];
    $body = json_decode($request->get_body(), true);
    
    // Check if task exists
    $task = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
        ARRAY_A
    );
    
    if (!$task) {
        return new WP_Error('task_not_found', 'Task not found', array('status' => 404));
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
        array('id' => $id),
        $update_format,
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('db_update_error', 'Could not update task', array('status' => 500));
    }
    
    // Get updated task
    $updated_task = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
        ARRAY_A
    );
    
    $formatted_task = array(
        'id' => (int)$updated_task['id'],
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
 * Delete a task
 */
function delete_task($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tasks';
    
    $id = (int)$request['id'];
    
    // Check if task exists
    $task = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
        ARRAY_A
    );
    
    if (!$task) {
        return new WP_Error('task_not_found', 'Task not found', array('status' => 404));
    }
    
    $result = $wpdb->delete(
        $table_name,
        array('id' => $id),
        array('%d')
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
        text text NOT NULL,
        description text,
        due_date date,
        category varchar(50) DEFAULT 'general',
        completed tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
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
register_activation_hook(__FILE__, 'create_tasks_table');

/**
 * Enable CORS for development (remove in production or configure properly)
 */
function add_cors_http_header() {
    // Only enable in development - configure properly for production
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Authorization");
}
add_action('init', 'add_cors_http_header');

// Handle OPTIONS requests for CORS preflight
add_action('rest_api_init', function() {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($value) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Authorization');
        return $value;
    });
}, 15);
