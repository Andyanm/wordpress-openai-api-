<?php
/*
Plugin Name: ChatGPT Interaction Enhanced
Description: 插入一个美观的对话界面，通过后台设置API请求URL、API密钥和模型参数，支持按对话ID管理对话历史。支持基于用户的对话历史、对话次数限制，支持Markdown渲染。
Version: 5.0
Author: 您的姓名
*/

if (!defined('ABSPATH')) exit;

// 激活插件时创建自定义数据库表和默认设置
register_activation_hook(__FILE__, 'chatgpt_interaction_activate');
function chatgpt_interaction_activate() {
    global $wpdb;
    $table_name_conversations = $wpdb->prefix . 'chatgpt_conversations';
    $table_name_message_logs = $wpdb->prefix . 'chatgpt_message_logs';
    $table_name_api_keys = $wpdb->prefix . 'chatgpt_api_keys';
    $charset_collate = $wpdb->get_charset_collate();

    // 创建对话表
    $sql_conversations = "CREATE TABLE $table_name_conversations (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) NOT NULL,
        conversation_id VARCHAR(50) NOT NULL,
        messages LONGTEXT NOT NULL,
        message_count INT(11) NOT NULL DEFAULT 0,
        title VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX (user_id),
        INDEX (conversation_id),
        INDEX (created_at)
    ) $charset_collate;";

    // 创建消息日志表
    $sql_message_logs = "CREATE TABLE $table_name_message_logs (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX (user_id),
        INDEX (created_at)
    ) $charset_collate;";

    // 创建API密钥表，增加user_id字段
    $sql_api_keys = "CREATE TABLE $table_name_api_keys (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) NOT NULL,
        api_key VARCHAR(100) NOT NULL,
        usage_limit INT(11) DEFAULT NULL,
        usage_count INT(11) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY (api_key),
        INDEX (user_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_conversations);
    dbDelta($sql_message_logs);
    dbDelta($sql_api_keys);

    // 初始化设置
    if (!get_option('chatgpt_models')) {
        add_option('chatgpt_models', 'gpt-3.5-turbo, gpt-4');
    }

    if (!get_option('chatgpt_api_url')) {
        add_option('chatgpt_api_url', 'https://api.openai.com/v1/chat/completions');
    }

    if (!get_option('chatgpt_api_key')) {
        add_option('chatgpt_api_key', '');
    }

    if (!get_option('chatgpt_default_model')) {
        add_option('chatgpt_default_model', 'gpt-3.5-turbo');
    }

    if (!get_option('chatgpt_save_conversations')) {
        add_option('chatgpt_save_conversations', true);
    }

    if (!get_option('chatgpt_conversation_limit')) {
        add_option('chatgpt_conversation_limit', 100); // 默认消息限制
    }

    if (!get_option('chatgpt_disable_users')) {
        add_option('chatgpt_disable_users', []);
    }

    if (!get_option('chatgpt_system_prompt')) {
        add_option('chatgpt_system_prompt', '');
    }

    if (!get_option('chatgpt_enable_system_prompt')) {
        add_option('chatgpt_enable_system_prompt', false);
    }

    if (!get_option('chatgpt_chatgpt_avatar')) {
        add_option('chatgpt_chatgpt_avatar', plugin_dir_url(__FILE__) . 'assets/chatgpt-avatar.png');
    }

    if (!get_option('chatgpt_title_generation_model')) {
        add_option('chatgpt_title_generation_model', 'gpt-3.5-turbo');
    }

    if (!get_option('chatgpt_title_generation_prompt')) {
        add_option('chatgpt_title_generation_prompt', '请为以下对话生成一个简短的标题，不超过10个字：');
    }
}

// 删除插件时删除自定义数据库表和设置
register_deactivation_hook(__FILE__, 'chatgpt_interaction_deactivate');
function chatgpt_interaction_deactivate() {
    global $wpdb;
    $table_name_conversations = $wpdb->prefix . 'chatgpt_conversations';
    $table_name_message_logs = $wpdb->prefix . 'chatgpt_message_logs';
    $table_name_api_keys = $wpdb->prefix . 'chatgpt_api_keys';
    $wpdb->query("DROP TABLE IF EXISTS $table_name_conversations");
    $wpdb->query("DROP TABLE IF EXISTS $table_name_message_logs");
    $wpdb->query("DROP TABLE IF EXISTS $table_name_api_keys");

    delete_option('chatgpt_api_url');
    delete_option('chatgpt_api_key');
    delete_option('chatgpt_models');
    delete_option('chatgpt_default_model');
    delete_option('chatgpt_save_conversations');
    delete_option('chatgpt_conversation_limit');
    delete_option('chatgpt_disable_users');
    delete_option('chatgpt_system_prompt');
    delete_option('chatgpt_enable_system_prompt');
    delete_option('chatgpt_chatgpt_avatar');
    delete_option('chatgpt_title_generation_model');
    delete_option('chatgpt_title_generation_prompt');
}

// 添加主菜单和子菜单
add_action('admin_menu', 'chatgpt_interaction_menu');
function chatgpt_interaction_menu() {
    // 添加ChatGPT 主菜单
    add_menu_page(
        'ChatGPT 设置',
        'ChatGPT',
        'manage_options',
        'chatgpt-main-menu',
        'chatgpt_interaction_settings_page',
        'dashicons-admin-generic',
        25
    );

    // 添加子菜单项
    add_submenu_page(
        'chatgpt-main-menu',
        'ChatGPT 设置',
        '设置',
        'manage_options',
        'chatgpt-settings',
        'chatgpt_interaction_settings_page'
    );

    add_submenu_page(
        'chatgpt-main-menu',
        '用户对话历史',
        '用户对话历史',
        'manage_options',
        'chatgpt-user-history',
        'chatgpt_interaction_user_history_page'
    );

    add_submenu_page(
        'chatgpt-main-menu',
        'API 密钥管理',
        'API 密钥管理',
        'manage_options',
        'chatgpt-api-keys',
        'chatgpt_interaction_api_keys_page'
    );
}

// 注册设置
add_action('admin_init', 'chatgpt_interaction_settings');
function chatgpt_interaction_settings() {
    register_setting('chatgpt_interaction_options', 'chatgpt_api_url', 'esc_url_raw');
    register_setting('chatgpt_interaction_options', 'chatgpt_api_key', 'sanitize_text_field');
    register_setting('chatgpt_interaction_options', 'chatgpt_models', 'sanitize_text_field');
    register_setting('chatgpt_interaction_options', 'chatgpt_default_model', 'sanitize_text_field');
    register_setting('chatgpt_interaction_options', 'chatgpt_save_conversations', 'intval');
    register_setting('chatgpt_interaction_options', 'chatgpt_conversation_limit', 'intval');
    register_setting('chatgpt_interaction_options', 'chatgpt_disable_users', 'chatgpt_sanitize_array');
    register_setting('chatgpt_interaction_options', 'chatgpt_system_prompt', 'sanitize_textarea_field');
    register_setting('chatgpt_interaction_options', 'chatgpt_enable_system_prompt', 'intval');
    register_setting('chatgpt_interaction_options', 'chatgpt_chatgpt_avatar', 'esc_url_raw');
    register_setting('chatgpt_interaction_options', 'chatgpt_title_generation_model', 'sanitize_text_field');
    register_setting('chatgpt_interaction_options', 'chatgpt_title_generation_prompt', 'sanitize_textarea_field');

    add_settings_section('chatgpt_interaction_main', '主要设置', null, 'chatgpt-settings');

    add_settings_field('chatgpt_api_url', 'API 请求 URL', 'chatgpt_interaction_api_url_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_api_key', 'API 密钥', 'chatgpt_interaction_api_key_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_models', '可用模型（用逗号分隔）', 'chatgpt_interaction_models_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_default_model', '默认模型', 'chatgpt_interaction_default_model_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_save_conversations', '保存对话记录', 'chatgpt_interaction_save_conversations_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_conversation_limit', '每日消息限制', 'chatgpt_interaction_conversation_limit_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_disable_users', '禁用用户', 'chatgpt_interaction_disable_users_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_system_prompt', '系统提示词', 'chatgpt_interaction_system_prompt_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_enable_system_prompt', '启用系统提示词', 'chatgpt_interaction_enable_system_prompt_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_chatgpt_avatar', 'ChatGPT 头像 URL', 'chatgpt_interaction_chatgpt_avatar_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_title_generation_model', '对话标题生成模型', 'chatgpt_interaction_title_generation_model_render', 'chatgpt-settings', 'chatgpt_interaction_main');
    add_settings_field('chatgpt_title_generation_prompt', '对话标题生成提示词', 'chatgpt_interaction_title_generation_prompt_render', 'chatgpt-settings', 'chatgpt_interaction_main');
}

function chatgpt_sanitize_array($input) {
    if (is_array($input)) {
        return array_map('sanitize_text_field', $input);
    }
    return [];
}

function chatgpt_interaction_api_url_render() {
    $value = get_option('chatgpt_api_url');
    echo '<input type="url" name="chatgpt_api_url" value="' . esc_attr($value) . '" size="50" required>';
}

function chatgpt_interaction_api_key_render() {
    $value = get_option('chatgpt_api_key');
    echo '<input type="password" name="chatgpt_api_key" value="' . esc_attr($value) . '" size="50" required>';
}

function chatgpt_interaction_models_render() {
    $value = get_option('chatgpt_models');
    echo '<input type="text" name="chatgpt_models" value="' . esc_attr($value) . '" size="50" required>';
    echo '<p class="description">请输入模型名称，使用逗号分隔，例如：gpt-3.5-turbo, gpt-4</p>';
}

function chatgpt_interaction_default_model_render() {
    $value = get_option('chatgpt_default_model');
    $models_option = get_option('chatgpt_models', 'gpt-3.5-turbo');

    if (is_array($models_option)) {
        $models_option = implode(', ', $models_option);
    }

    $models = array_map('trim', explode(',', $models_option));
    echo '<select name="chatgpt_default_model">';
    foreach ($models as $model) {
        echo '<option value="' . esc_attr($model) . '" ' . selected($value, $model, false) . '>' . esc_html($model) . '</option>';
    }
    echo '</select>';
}

function chatgpt_interaction_save_conversations_render() {
    $value = get_option('chatgpt_save_conversations');
    echo '<input type="checkbox" name="chatgpt_save_conversations" value="1" ' . checked(1, $value, false) . '>';
    echo '<p class="description">启用以保存对话记录。</p>';
}

function chatgpt_interaction_conversation_limit_render() {
    $value = get_option('chatgpt_conversation_limit', 100);
    echo '<input type="number" name="chatgpt_conversation_limit" value="' . esc_attr($value) . '" min="1" max="1000" />';
    echo '<p class="description">设置普通用户每日的消息发送次数限制。管理员不受此限制。</p>';
}

function chatgpt_interaction_disable_users_render() {
    $disabled_users = get_option('chatgpt_disable_users', []);
    $users = get_users();
    echo '<select name="chatgpt_disable_users[]" multiple style="height:200px;">';
    foreach ($users as $user) {
        echo '<option value="' . esc_attr($user->ID) . '" ' . (in_array($user->ID, $disabled_users) ? 'selected' : '') . '>' . esc_html($user->user_login) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">选择要禁用ChatGPT功能的用户。</p>';
}

function chatgpt_interaction_system_prompt_render() {
    $value = get_option('chatgpt_system_prompt', '');
    echo '<textarea name="chatgpt_system_prompt" rows="5" cols="50">' . esc_textarea($value) . '</textarea>';
    echo '<p class="description">设置系统提示词，将作为每次对话的起始消息，不会显示在前端。</p>';
}

function chatgpt_interaction_enable_system_prompt_render() {
    $value = get_option('chatgpt_enable_system_prompt');
    echo '<input type="checkbox" name="chatgpt_enable_system_prompt" value="1" ' . checked(1, $value, false) . '>';
    echo '<p class="description">启用系统提示词。</p>';
}

function chatgpt_interaction_chatgpt_avatar_render() {
    $value = get_option('chatgpt_chatgpt_avatar', plugin_dir_url(__FILE__) . 'assets/chatgpt-avatar.png');
    echo '<input type="url" name="chatgpt_chatgpt_avatar" value="' . esc_attr($value) . '" size="50" required>';
    echo '<p class="description">设置ChatGPT的头像URL。</p>';
}

function chatgpt_interaction_title_generation_model_render() {
    $value = get_option('chatgpt_title_generation_model', 'gpt-3.5-turbo');
    $models_option = get_option('chatgpt_models', 'gpt-3.5-turbo');

    if (is_array($models_option)) {
        $models_option = implode(', ', $models_option);
    }

    $models = array_map('trim', explode(',', $models_option));
    echo '<select name="chatgpt_title_generation_model">';
    foreach ($models as $model) {
        echo '<option value="' . esc_attr($model) . '" ' . selected($value, $model, false) . '>' . esc_html($model) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">选择用于生成对话标题的模型。</p>';
}

function chatgpt_interaction_title_generation_prompt_render() {
    $value = get_option('chatgpt_title_generation_prompt', '请为以下对话生成一个简短的标题，不超过10个字：');
    echo '<textarea name="chatgpt_title_generation_prompt" rows="3" cols="50">' . esc_textarea($value) . '</textarea>';
    echo '<p class="description">设置用于生成对话标题的提示词。</p>';
}

// 添加后台设置页面
function chatgpt_interaction_settings_page() {
    ?>
    <div class="wrap">
        <h1>ChatGPT 设置</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('chatgpt_interaction_options');
            do_settings_sections('chatgpt-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// 添加用户对话历史页面
function chatgpt_interaction_user_history_page() {
    if (!current_user_can('administrator')) {
        wp_die('您没有权限访问此页面。');
    }

    // 获取当前页数
    $paged = isset($_GET['paged']) && is_numeric($_GET['paged']) ? intval($_GET['paged']) : 1;
    $per_page = 20;

    // 获取搜索关键词
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';

    // 构建查询
    $where = 'WHERE 1=1';
    if (!empty($search)) {
        $where .= $wpdb->prepare(" AND user_id IN (SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s)", '%' . $wpdb->esc_like($search) . '%');
    }

    // 获取总记录数
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where");

    // 获取对话数据
    $offset = ($paged - 1) * $per_page;
    $conversations = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));

    // 计算总页数
    $total_pages = ceil($total_items / $per_page);

    ?>
    <div class="wrap">
        <h1>用户对话历史</h1>

        <form method="get">
            <input type="hidden" name="page" value="chatgpt-user-history">
            <p class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索用户名">
                <input type="submit" id="search-submit" class="button" value="搜索">
            </p>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>用户ID</th>
                    <th>用户名</th>
                    <th>对话标题</th>
                    <th>消息数量</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($conversations)): ?>
                    <?php foreach ($conversations as $conversation): ?>
                        <?php
                        $user = get_userdata($conversation->user_id);
                        $username = $user ? $user->user_login : '未知用户';
                        ?>
                        <tr>
                            <td><?php echo esc_html($conversation->user_id); ?></td>
                            <td><?php echo esc_html($username); ?></td>
                            <td><?php echo esc_html($conversation->title ? $conversation->title : '未命名对话'); ?></td>
                            <td><?php echo esc_html($conversation->message_count); ?></td>
                            <td><?php echo esc_html($conversation->created_at); ?></td>
                            <td>
                                <a href="#" class="button view-conversation" data-conversation-id="<?php echo esc_attr($conversation->conversation_id); ?>">查看</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">暂无对话历史。</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $paged
            ]);
            echo '</div></div>';
        }
        ?>
    </div>

    <!-- 对话内容弹窗 -->
    <div id="conversation-modal" style="display:none;">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>对话内容</h2>
            <div id="conversation-messages" style="max-height:500px; overflow:auto;"></div>
        </div>
    </div>

    <style>
        /* 模态框样式 */
        #conversation-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
        }

        #conversation-modal .modal-content {
            background: #fff;
            margin: 5% auto;
            padding: 20px;
            width: 80%;
            position: relative;
        }

        #conversation-modal .close-button {
            position: absolute;
            right: 10px;
            top: 10px;
            font-size: 24px;
            cursor: pointer;
        }
    </style>

    <script>
        jQuery(document).ready(function($) {
            $('.view-conversation').on('click', function(e) {
                e.preventDefault();
                var conversationId = $(this).data('conversation-id');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'chatgpt_get_conversation_messages',
                        conversation_id: conversationId
                    },
                    success: function(response) {
                        if (response.success) {
                            var messages = response.data.messages;
                            var html = '';
                            messages.forEach(function(msg) {
                                html += '<p><strong>' + msg.role + ':</strong> ' + msg.content + '</p>';
                            });
                            $('#conversation-messages').html(html);
                            $('#conversation-modal').show();
                        } else {
                            alert('无法获取对话内容。');
                        }
                    }
                });
            });

            $('.close-button').on('click', function() {
                $('#conversation-modal').hide();
            });
        });
    </script>
    <?php
}

// AJAX 获取对话内容（管理员）
add_action('wp_ajax_chatgpt_get_conversation_messages', 'chatgpt_get_conversation_messages');
function chatgpt_get_conversation_messages() {
    if (!current_user_can('administrator')) {
        wp_send_json_error('您没有权限执行此操作。');
    }

    $conversation_id = isset($_POST['conversation_id']) ? sanitize_text_field($_POST['conversation_id']) : '';

    if (empty($conversation_id)) {
        wp_send_json_error('无效的对话ID。');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';

    $conversation = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE conversation_id = %s", $conversation_id));

    if ($conversation) {
        $messages = json_decode($conversation->messages, true);
        wp_send_json_success(['messages' => $messages]);
    } else {
        wp_send_json_error('未找到该对话。');
    }
}

// 添加API密钥管理页面
function chatgpt_interaction_api_keys_page() {
    if (!current_user_can('administrator')) {
        wp_die('您没有权限访问此页面。');
    }

    global $wpdb;
    $table_name_api_keys = $wpdb->prefix . 'chatgpt_api_keys';

    // 处理添加新API密钥
    if (isset($_POST['action']) && $_POST['action'] == 'add_api_key') {
        check_admin_referer('add_api_key');

        $usage_limit = isset($_POST['usage_limit']) ? intval($_POST['usage_limit']) : null;
        $api_key = wp_generate_password(32, false);

        $wpdb->insert(
            $table_name_api_keys,
            [
                'user_id' => 0, // 0表示管理员生成的API密钥
                'api_key' => $api_key,
                'usage_limit' => $usage_limit,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s']
        );

        echo '<div class="notice notice-success is-dismissible"><p>API密钥已生成。</p></div>';
    }

    // 处理删除API密钥
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['key_id'])) {
        check_admin_referer('delete_api_key_' . $_GET['key_id']);

        $wpdb->delete(
            $table_name_api_keys,
            ['id' => intval($_GET['key_id'])],
            ['%d']
        );

        echo '<div class="notice notice-success is-dismissible"><p>API密钥已删除。</p></div>';
    }

    // 获取API密钥列表
    $api_keys = $wpdb->get_results("SELECT * FROM $table_name_api_keys ORDER BY created_at DESC");

    ?>
    <div class="wrap">
        <h1>API 密钥管理</h1>

        <h2>生成新API密钥</h2>
        <form method="post">
            <?php wp_nonce_field('add_api_key'); ?>
            <input type="hidden" name="action" value="add_api_key">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="usage_limit">使用次数限制</label></th>
                    <td>
                        <input name="usage_limit" type="number" id="usage_limit" value="" class="small-text">
                        <p class="description">留空表示不限次数。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('生成API密钥'); ?>
        </form>

        <h2>已有API密钥</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>用户</th>
                    <th>API密钥</th>
                    <th>使用次数限制</th>
                    <th>已使用次数</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($api_keys)): ?>
                    <?php foreach ($api_keys as $key): ?>
                        <?php
                        $user = get_userdata($key->user_id);
                        $username = $user ? $user->user_login : '管理员';
                        ?>
                        <tr>
                            <td><?php echo esc_html($key->id); ?></td>
                            <td><?php echo esc_html($username); ?></td>
                            <td><?php echo esc_html($key->api_key); ?></td>
                            <td><?php echo is_null($key->usage_limit) ? '不限' : esc_html($key->usage_limit); ?></td>
                            <td><?php echo esc_html($key->usage_count); ?></td>
                            <td><?php echo $key->is_active ? '激活' : '已禁用'; ?></td>
                            <td><?php echo esc_html($key->created_at); ?></td>
                            <td>
                                <a href="<?php echo wp_nonce_url(add_query_arg(['action' => 'delete', 'key_id' => $key->id]), 'delete_api_key_' . $key->id); ?>" class="button">删除</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">暂无API密钥。</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// 注册短代码
add_shortcode('chatgpt_interaction', 'chatgpt_interaction_shortcode');
function chatgpt_interaction_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>您必须登录才能使用此功能。</p>';
    }

    // 检查用户是否被禁用
    $disabled_users = get_option('chatgpt_disable_users', []);
    if (in_array(get_current_user_id(), $disabled_users)) {
        return '<p>您的ChatGPT使用权限已被禁用。</p>';
    }

    // 引入必要的样式和脚本
    wp_enqueue_style('chatgpt-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css');
    wp_enqueue_style('chatgpt-fontawesome', 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css');
    wp_enqueue_script('marked', 'https://cdn.jsdelivr.net/npm/marked/marked.min.js', [], null, true);
    wp_enqueue_script('highlightjs', 'https://cdn.jsdelivr.net/npm/highlightjs@11.7.0/highlight.min.js', [], null, true);
    wp_enqueue_style('highlightjs-style', 'https://cdn.jsdelivr.net/npm/highlightjs@11.7.0/styles/default.min.css');
    wp_enqueue_script('chatgpt-frontend', plugin_dir_url(__FILE__) . 'chatgpt-frontend.js', ['jquery', 'marked', 'highlightjs'], null, true);

    // 传递Ajax URL到前端脚本
    wp_localize_script('chatgpt-frontend', 'chatgpt_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
    ]);

    // 获取可用模型
    $models_option = get_option('chatgpt_models', 'gpt-3.5-turbo');
    if (is_array($models_option)) {
        $models_option = implode(', ', $models_option);
    }
    $models = array_map('trim', explode(',', $models_option));
    $default_model = get_option('chatgpt_default_model', 'gpt-3.5-turbo');

    // 获取当前用户ID
    $user_id = get_current_user_id();

    // 获取ChatGPT头像
    $chatgpt_avatar_url = get_option('chatgpt_chatgpt_avatar', plugin_dir_url(__FILE__) . 'assets/chatgpt-avatar.png');

    ob_start();
    ?>
    <div id="chatgpt-container" class="container mt-4 mb-4">
        <div id="chatgpt-header" class="d-flex justify-content-between align-items-center mb-3">
            <h2><i class="fas fa-comments"></i> ChatGPT 对话</h2>
            <div class="chatgpt-controls d-flex align-items-center">
                <label for="chatgpt-model-select" class="me-2">模型选择：</label>
                <select id="chatgpt-model-select" class="form-select me-2">
                    <?php foreach ($models as $model): ?>
                        <option value="<?php echo esc_attr($model); ?>" <?php selected($default_model, $model); ?>><?php echo esc_html($model); ?></option>
                    <?php endforeach; ?>
                </select>
                <button id="chatgpt-new-conversation" class="btn btn-success"><i class="fas fa-plus"></i> 新对话</button>
            </div>
        </div>
        <div id="chatgpt-messages" class="border rounded p-3 mb-3" style="height: 500px; overflow-y: auto; background-color: #fff;">
            <!-- 消息内容 -->
        </div>
        <div id="chatgpt-input-area" class="input-group">
            <textarea id="chatgpt-input" class="form-control" placeholder="请输入您的问题..." rows="2"></textarea>
            <button id="chatgpt-send" class="btn btn-primary"><i class="fas fa-paper-plane"></i> 发送</button>
        </div>
        <div id="chatgpt-history" class="mt-4">
            <h3><i class="fas fa-history"></i> 您的对话历史</h3>
            <div id="chatgpt-history-list"></div>
        </div>
    </div>

    <style>
        body {
            background-color: #f8f9fa;
        }
        #chatgpt-container {
            max-width: 800px;
            margin: auto;
        }
        #chatgpt-messages {
            background-color: #ffffff;
        }
        .chatgpt-message {
            display: flex;
            margin-bottom: 15px;
        }
        .chatgpt-message .avatar {
            width: 50px;
            height: 50px;
            margin-right: 10px;
            border-radius: 50%;
            overflow: hidden;
        }
        .chatgpt-message .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .chatgpt-message .message-content {
            max-width: 100%;
        }
        .chatgpt-message.user {
            flex-direction: row-reverse;
        }
        .chatgpt-message.user .message-content {
            background-color: #d1e7dd;
            border-radius: 15px 15px 0 15px;
            padding: 10px;
            margin-right: 10px;
        }
        .chatgpt-message.assistant .message-content {
            background-color: #e9ecef;
            border-radius: 15px 15px 15px 0;
            padding: 10px;
            margin-left: 10px;
        }
        pre {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>

    <script>
        jQuery(document).ready(function($) {
            const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

            let conversationId = localStorage.getItem('chatgpt_conversationId') || null;

            // 初始化对话历史
            loadConversationList();

            // 如果有未完成的对话，加载对话内容
            if (conversationId) {
                loadConversation(conversationId);
            }

            // 发送消息
            $('#chatgpt-send').on('click', function() {
                sendMessage();
            });

            $('#chatgpt-input').on('keypress', function(e) {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            // 开始新对话
            $('#chatgpt-new-conversation').on('click', function() {
                conversationId = null;
                localStorage.removeItem('chatgpt_conversationId');
                $('#chatgpt-messages').html('');
            });

            function sendMessage() {
                const message = $('#chatgpt-input').val().trim();
                const selectedModel = $('#chatgpt-model-select').val();

                if (!message) return;

                appendMessage('您', message);
                $('#chatgpt-input').val('');
                showLoading();

                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'chatgpt_interaction_respond',
                        message: message,
                        model: selectedModel,
                        conversation_id: conversationId
                    },
                    success: function(response) {
                        hideLoading();
                        if (response.success) {
                            if (!conversationId) {
                                conversationId = response.data.conversation_id;
                                localStorage.setItem('chatgpt_conversationId', conversationId);
                                loadConversationList();
                            }
                            appendMessage('ChatGPT', response.data.reply, true);
                            // 更新对话标题
                            updateConversationTitle(conversationId, response.data.title);
                        } else {
                            showError(response.data);
                        }
                    },
                    error: function() {
                        hideLoading();
                        showError('无法连接到服务器，请稍后再试。');
                    }
                });
            }

            function loadConversationList() {
                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'chatgpt_interaction_get_conversations'
                    },
                    success: function(response) {
                        if (response.success) {
                            const conversations = response.data.conversations;
                            let html = '<ul class="list-group">';
                            conversations.forEach(function(conv) {
                                html += `<li class="list-group-item d-flex justify-content-between align-items-center">
                                            <button class="chatgpt-select-conversation btn btn-link" data-conversation-id="${conv.conversation_id}">
                                                ${conv.title ? conv.title : '未命名对话'}
                                            </button>
                                            <div>
                                                <span class="badge bg-secondary">${conv.message_count} 条消息</span>
                                                <button class="chatgpt-delete-conversation btn btn-danger btn-sm ms-2" data-conversation-id="${conv.conversation_id}">删除</button>
                                            </div>
                                        </li>`;
                            });
                            html += '</ul>';
                            $('#chatgpt-history-list').html(html);

                            // 绑定事件
                            $('.chatgpt-select-conversation').on('click', function() {
                                const selectedId = $(this).data('conversation-id');
                                loadConversation(selectedId);
                            });

                            $('.chatgpt-delete-conversation').on('click', function() {
                                const convId = $(this).data('conversation-id');
                                if (confirm('确定要删除此对话吗？')) {
                                    deleteConversation(convId, $(this));
                                }
                            });
                        } else {
                            $('#chatgpt-history-list').html('<p>您还没有任何对话记录。</p>');
                        }
                    }
                });
            }

            function loadConversation(selectedId) {
                conversationId = selectedId;
                localStorage.setItem('chatgpt_conversationId', conversationId);
                $('#chatgpt-messages').html('');

                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'chatgpt_interaction_get_conversation',
                        conversation_id: conversationId
                    },
                    success: function(response) {
                        if (response.success) {
                            const messages = response.data.messages;
                            messages.forEach(msg => {
                                const sender = msg.role === 'user' ? '您' : 'ChatGPT';
                                appendMessage(sender, msg.content, sender !== '您');
                            });
                        } else {
                            showError(response.data);
                        }
                    },
                    error: function() {
                        showError('无法加载对话，请稍后再试。');
                    }
                });
            }

            function deleteConversation(convId, button) {
                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'chatgpt_interaction_delete_conversation',
                        conversation_id: convId
                    },
                    success: function(response) {
                        if (response.success) {
                            button.closest('li').remove();
                            if (convId === conversationId) {
                                conversationId = null;
                                localStorage.removeItem('chatgpt_conversationId');
                                $('#chatgpt-messages').html('');
                            }
                        } else {
                            showError(response.data);
                        }
                    },
                    error: function() {
                        showError('无法删除对话，请稍后再试。');
                    }
                });
            }

            function appendMessage(sender, message, isMarkdown = false) {
                const senderClass = sender === '您' ? 'user' : 'assistant';
                const avatarUrl = sender === '您' ? '<?php echo get_avatar_url($user_id); ?>' : '<?php echo $chatgpt_avatar_url; ?>';
                const messageElement = $(`
                    <div class="chatgpt-message ${senderClass}">
                        <div class="avatar">
                            <img src="${avatarUrl}" alt="${sender}">
                        </div>
                        <div class="message-content">
                            <div>${isMarkdown && sender !== '您' ? marked.parse(message) : escapeHtml(message)}</div>
                        </div>
                    </div>
                `);

                $('#chatgpt-messages').append(messageElement);
                $('#chatgpt-messages').scrollTop($('#chatgpt-messages')[0].scrollHeight);

                // 语法高亮
                $('pre code').each(function(i, block) {
                    hljs.highlightBlock(block);
                });
            }

            function showLoading() {
                const loadingElement = $(`
                    <div id="chatgpt-loading" class="chatgpt-message mb-3">
                        <div class="message-content">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                `);
                $('#chatgpt-messages').append(loadingElement);
                $('#chatgpt-messages').scrollTop($('#chatgpt-messages')[0].scrollHeight);
            }

            function hideLoading() {
                $('#chatgpt-loading').remove();
            }

            function showError(message) {
                const errorElement = $(`<div class="alert alert-danger mt-3"><strong>错误:</strong> ${escapeHtml(message)}</div>`);
                $('#chatgpt-messages').append(errorElement);
                $('#chatgpt-messages').scrollTop($('#chatgpt-messages')[0].scrollHeight);

                setTimeout(() => {
                    errorElement.remove();
                }, 5000);
            }

            function escapeHtml(text) {
                var map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
            }

            function updateConversationTitle(convId, title) {
                // 更新对话历史中的标题
                $('#chatgpt-history-list button.chatgpt-select-conversation').each(function() {
                    if ($(this).data('conversation-id') === convId) {
                        $(this).text(title);
                    }
                });
            }
        });
    </script>
    <?php
    return ob_get_clean();
}

// AJAX 获取对话列表
add_action('wp_ajax_chatgpt_interaction_get_conversations', 'chatgpt_interaction_get_conversations');
function chatgpt_interaction_get_conversations() {
    if (!is_user_logged_in()) {
        wp_send_json_error('您必须登录才能使用此功能。');
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';
    $conversations = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_at DESC", $user_id));

    $conversation_history = [];
    foreach ($conversations as $conversation) {
        $conversation_history[] = [
            'conversation_id' => $conversation->conversation_id,
            'created_at' => $conversation->created_at,
            'message_count' => $conversation->message_count,
            'title' => $conversation->title,
        ];
    }

    wp_send_json_success(['conversations' => $conversation_history]);
}

// AJAX 获取对话内容
add_action('wp_ajax_chatgpt_interaction_get_conversation', 'chatgpt_interaction_get_conversation');
function chatgpt_interaction_get_conversation() {
    if (!is_user_logged_in()) {
        wp_send_json_error('您必须登录才能使用此功能。');
    }

    $user_id = get_current_user_id();
    $conversation_id = isset($_POST['conversation_id']) ? sanitize_text_field($_POST['conversation_id']) : null;

    if (!$conversation_id) {
        wp_send_json_error('无效的对话ID');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';

    $conversation_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE conversation_id = %s AND user_id = %d ORDER BY created_at DESC LIMIT 1", $conversation_id, $user_id));

    if ($conversation_row) {
        $messages = json_decode($conversation_row->messages, true);
        wp_send_json_success(['messages' => $messages]);
    } else {
        wp_send_json_error('未找到该对话');
    }
}

// AJAX 删除对话
add_action('wp_ajax_chatgpt_interaction_delete_conversation', 'chatgpt_interaction_delete_conversation');
function chatgpt_interaction_delete_conversation() {
    if (!is_user_logged_in()) {
        wp_send_json_error('您必须登录才能使用此功能。');
    }

    $user_id = get_current_user_id();
    $conversation_id = isset($_POST['conversation_id']) ? sanitize_text_field($_POST['conversation_id']) : null;

    if (!$conversation_id) {
        wp_send_json_error('无效的对话ID');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';

    $deleted = $wpdb->delete(
        $table_name,
        ['conversation_id' => $conversation_id, 'user_id' => $user_id],
        ['%s', '%d']
    );

    if ($deleted) {
        wp_send_json_success('对话已删除');
    } else {
        wp_send_json_error('无法删除对话');
    }
}

// AJAX 发送消息并获取回复
add_action('wp_ajax_chatgpt_interaction_respond', 'chatgpt_interaction_respond');
function chatgpt_interaction_respond() {
    if (!is_user_logged_in()) {
        wp_send_json_error('您必须登录才能使用此功能。');
    }

    $user_id = get_current_user_id();
    $user = wp_get_current_user();

    // 检查用户是否被禁用
    $disabled_users = get_option('chatgpt_disable_users', []);
    if (in_array($user_id, $disabled_users)) {
        wp_send_json_error('您的ChatGPT使用权限已被禁用。');
    }

    // 获取请求中的消息和模型
    $message_content = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
    $selected_model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : get_option('chatgpt_default_model', 'gpt-3.5-turbo');
    $conversation_id = isset($_POST['conversation_id']) ? sanitize_text_field($_POST['conversation_id']) : null;

    $api_url = get_option('chatgpt_api_url');
    $api_key = get_option('chatgpt_api_key');
    $save_conversations = get_option('chatgpt_save_conversations');

    if (empty($api_key) || empty($message_content)) {
        wp_send_json_error('API密钥或消息内容为空');
    }

    // 检查每日消息发送次数限制
    if (!user_can($user, 'administrator')) {
        $limit = get_option('chatgpt_conversation_limit', 100);
        $today = current_time('Y-m-d');
        $messages_today = get_user_meta($user_id, 'chatgpt_messages_today', true);
        if (!$messages_today || !is_array($messages_today) || $messages_today['date'] !== $today) {
            // 新的一天，重置计数
            $messages_today = ['date' => $today, 'count' => 0];
        }

        if ($messages_today['count'] >= $limit) {
            wp_send_json_error('您今天的消息发送次数已达上限。请明天再试。');
        }

        // 增加消息计数
        $messages_today['count'] += 1;
        update_user_meta($user_id, 'chatgpt_messages_today', $messages_today);
    }

    // 获取用户的对话历史
    global $wpdb;
    $table_name = $wpdb->prefix . 'chatgpt_conversations';
    $table_name_messages = $wpdb->prefix . 'chatgpt_message_logs';

    if ($conversation_id) {
        // 获取指定对话
        $conversation_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE conversation_id = %s AND user_id = %d ORDER BY created_at DESC LIMIT 1", $conversation_id, $user_id));
    } else {
        $conversation_row = null;
    }

    if ($conversation_row) {
        $conversation = json_decode($conversation_row->messages, true);
        $current_conversation_id = $conversation_row->conversation_id;
    } else {
        $conversation = [];
        $current_conversation_id = null;
    }

    // 处理模型
    $models_option = get_option('chatgpt_models', 'gpt-3.5-turbo');
    $models = array_map('trim', explode(',', $models_option));
    if (!in_array($selected_model, $models)) {
        $selected_model = get_option('chatgpt_default_model', 'gpt-3.5-turbo');
    }

    // 系统提示词
    $enable_system_prompt = get_option('chatgpt_enable_system_prompt', false);
    if ($enable_system_prompt && empty($conversation)) {
        $system_prompt = get_option('chatgpt_system_prompt', '');
        if (!empty($system_prompt)) {
            $conversation[] = ['role' => 'system', 'content' => $system_prompt];
        }
    }

    // 更新对话
    $conversation[] = ['role' => 'user', 'content' => $message_content];

    $data = [
        'model' => $selected_model,
        'messages' => $conversation,
        'temperature' => 0.7,
        'stream' => false,
    ];

    $args = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode($data),
        'timeout' => 60,
        'httpversion' => '1.1',
    ];

    $response = wp_remote_post($api_url, $args);

    if (is_wp_error($response)) {
        wp_send_json_error('请求失败：' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (isset($result['choices'][0]['message']['content'])) {
        $reply = $result['choices'][0]['message']['content'];
        $conversation[] = ['role' => 'assistant', 'content' => $reply];

        // 保存对话历史
        $conversation_json = json_encode($conversation);
        if ($save_conversations) {
            if ($conversation_row) {
                // 更新现有对话
                $wpdb->update(
                    $table_name,
                    [
                        'messages' => $conversation_json,
                        'message_count' => count($conversation),
                        'created_at' => current_time('mysql'),
                    ],
                    [
                        'conversation_id' => $current_conversation_id,
                        'user_id' => $user_id
                    ],
                    ['%s', '%d', '%s'],
                    ['%s', '%d']
                );
                $new_conversation_id = $current_conversation_id;
                $title = $conversation_row->title;
            } else {
                // 插入新对话
                $new_conversation_id = wp_generate_uuid4();
                $title = chatgpt_generate_conversation_title($conversation);
                $wpdb->insert(
                    $table_name,
                    [
                        'user_id' => $user_id,
                        'conversation_id' => $new_conversation_id,
                        'messages' => $conversation_json,
                        'message_count' => count($conversation),
                        'title' => $title,
                        'created_at' => current_time('mysql'),
                    ],
                    ['%d', '%s', '%s', '%d', '%s', '%s']
                );
            }
        } else {
            // 即使不保存对话，也需要生成对话ID
            if ($conversation_id) {
                $new_conversation_id = $conversation_id;
            } else {
                $new_conversation_id = wp_generate_uuid4();
            }
            $title = '未命名对话';
        }

        // 无论是否保存对话，都记录消息发送日志
        $wpdb->insert(
            $table_name_messages,
            [
                'user_id' => $user_id,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s']
        );

        wp_send_json_success(['reply' => $reply, 'conversation_id' => $new_conversation_id, 'title' => $title]);
    } else {
        wp_send_json_error('未能获取有效回复');
    }
}

// 生成对话标题
function chatgpt_generate_conversation_title($conversation) {
    // 获取标题生成模型和提示词
    $api_url = get_option('chatgpt_api_url');
    $api_key = get_option('chatgpt_api_key');
    $selected_model = get_option('chatgpt_title_generation_model', 'gpt-3.5-turbo');
    $title_prompt = get_option('chatgpt_title_generation_prompt', '请为以下对话生成一个简短的标题，不超过10个字：');

    $conversation_text = '';
    foreach ($conversation as $msg) {
        if ($msg['role'] != 'system') {
            $conversation_text .= $msg['content'] . "\n";
        }
    }

    $prompt = $title_prompt . "\n\n" . $conversation_text;

    $data = [
        'model' => $selected_model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 50,
        'temperature' => 0.5,
    ];

    $args = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode($data),
        'timeout' => 60,
        'httpversion' => '1.1',
    ];

    $response = wp_remote_post($api_url, $args);

    if (is_wp_error($response)) {
        return '未命名对话';
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (isset($result['choices'][0]['message']['content'])) {
        return trim($result['choices'][0]['message']['content']);
    } else {
        return '未命名对话';
    }
}

// 前端API密钥管理短代码
add_shortcode('chatgpt_api_key_management', 'chatgpt_api_key_management_shortcode');
function chatgpt_api_key_management_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>您必须登录才能使用此功能。</p>';
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $table_name_api_keys = $wpdb->prefix . 'chatgpt_api_keys';

    // 处理API密钥生成
    if (isset($_POST['chatgpt_generate_api_key'])) {
        check_admin_referer('chatgpt_generate_api_key');

        // 生成新API密钥
        $api_key = wp_generate_password(32, false);

        $wpdb->insert(
            $table_name_api_keys,
            [
                'user_id' => $user_id,
                'api_key' => $api_key,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s']
        );

        echo '<div class="notice notice-success is-dismissible"><p>API密钥已生成。</p></div>';
    }

    // 处理API密钥撤销
    if (isset($_POST['chatgpt_revoke_api_key']) && isset($_POST['key_id'])) {
        check_admin_referer('chatgpt_revoke_api_key_' . $_POST['key_id']);

        $key_id = intval($_POST['key_id']);

        // 删除API密钥
        $wpdb->delete(
            $table_name_api_keys,
            ['id' => $key_id, 'user_id' => $user_id],
            ['%d', '%d']
        );

        echo '<div class="notice notice-success is-dismissible"><p>API密钥已撤销。</p></div>';
    }

    // 获取用户的API密钥
    $api_keys = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name_api_keys WHERE user_id = %d ORDER BY created_at DESC", $user_id));

    ob_start();
    ?>
    <div class="chatgpt-api-key-management">
        <h2>我的 API 密钥</h2>

        <form method="post">
            <?php wp_nonce_field('chatgpt_generate_api_key'); ?>
            <input type="hidden" name="chatgpt_generate_api_key" value="1">
            <?php submit_button('生成新的 API 密钥'); ?>
        </form>

        <?php if (!empty($api_keys)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>API密钥</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($api_keys as $key): ?>
                        <tr>
                            <td><?php echo esc_html($key->id); ?></td>
                            <td><?php echo esc_html($key->api_key); ?></td>
                            <td><?php echo esc_html($key->created_at); ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('chatgpt_revoke_api_key_' . $key->id); ?>
                                    <input type="hidden" name="chatgpt_revoke_api_key" value="1">
                                    <input type="hidden" name="key_id" value="<?php echo esc_attr($key->id); ?>">
                                    <?php submit_button('撤销', 'delete', '', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>您还没有生成任何 API 密钥。</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
