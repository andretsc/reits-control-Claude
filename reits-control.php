<?php
/**
 * Plugin Name: REITs Control
 * Plugin URI: http://bitelecom.jp/plugins/
 * Description: Plugin para controle de carteiras de FIIs e ações da B3, com suporte multilíngue (PT/JP).
 * Version: 1.0.5
 * Author: Bitelecom
 * Author URI: http://bitelecom.jp/
 * License: GPL-2.0+
 * Text Domain: reits-control
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

// Constants
define('REITS_CONTROL_VERSION', '1.0.5');
define('REITS_CONTROL_PATH', plugin_dir_path(__FILE__));
define('REITS_CONTROL_URL', plugin_dir_url(__FILE__));

// Activation: Create tables
register_activation_hook(__FILE__, 'reits_control_activate');
function reits_control_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_users = $wpdb->prefix . 'reits_users';
    $sql_users = "CREATE TABLE $table_users (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        post_id bigint(20) UNSIGNED DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_users);

    $table_registros = $wpdb->prefix . 'reits_registros';
    $sql_registros = "CREATE TABLE $table_registros (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        fii_nome varchar(255) NOT NULL,
        ticker varchar(20) NOT NULL,
        volume int(11) NOT NULL,
        valor_entrada decimal(10,2) NOT NULL,
        data_compra date NOT NULL,
        preco_atual decimal(10,2) DEFAULT 0.00,
        nome_fii varchar(255) DEFAULT '',
        dividendos decimal(10,2) DEFAULT 0.00,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql_registros);

    $table_logs = $wpdb->prefix . 'reits_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        action varchar(255) NOT NULL,
        details text,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql_logs);

    add_option('reits_control_settings', array(
        'provider' => 'yahoo',
        'suffix' => '',
        'cache_duration' => 300,
        'per_page' => 20,
        'enable_dividends' => true,
        'safe_mode' => false,
        'enable_logs' => true,
        'alphavantage_key' => '',
    ));
}

// Deactivation
register_deactivation_hook(__FILE__, 'reits_control_deactivate');
function reits_control_deactivate() {}

// Uninstall
register_uninstall_hook(__FILE__, 'reits_control_uninstall');
function reits_control_uninstall() {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}reits_users");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}reits_registros");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}reits_logs");
    delete_option('reits_control_settings');
}

// Textdomain
add_action('plugins_loaded', 'reits_control_load_textdomain');
function reits_control_load_textdomain() {
    load_plugin_textdomain('reits-control', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

// Enqueue assets
add_action('wp_enqueue_scripts', 'reits_control_enqueue_frontend');
function reits_control_enqueue_frontend() {
    wp_enqueue_style('bootstrap-cdn', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css', [], '5.3.0');
    wp_enqueue_script('bootstrap-cdn', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', [], '5.3.0', true);
    wp_enqueue_style('reits-control-css', REITS_CONTROL_URL . 'assets/css/style.css', [], REITS_CONTROL_VERSION);
    wp_enqueue_script('reits-control-js', REITS_CONTROL_URL . 'assets/js/script.js', ['jquery'], REITS_CONTROL_VERSION, true);
    wp_localize_script('reits-control-js', 'reits_control_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('reits_control_nonce'),
        'suffix' => '',
        'translations' => [
            'empty_ticker' => __('Por favor, insira um ticker.', 'reits-control'),
            'searching' => __('Buscando...', 'reits-control'),
            'fetch_success' => __('Dados buscados com sucesso!', 'reits-control'),
            'fetch_failed' => __('Falha ao buscar dados.', 'reits-control'),
            'testing' => __('Testando...', 'reits-control'),
            'test_failed' => __('Falha no teste da API.', 'reits-control'),
            'updating' => __('Atualizando...', 'reits-control'),
            'update_failed' => __('Erro ao atualizar preços.', 'reits-control'),
            'api_error' => __('Erro de conexão com a API: ', 'reits-control'),
            'view_yahoo_finance' => __('Ver no Yahoo Finance', 'reits-control'),
            'provider_yahoo' => __('Provedor: Yahoo Finance', 'reits-control'),
            'provider_alphavantage' => __('Provedor: AlphaVantage', 'reits-control'),
            'provider_investing' => __('Provedor: Investing.com', 'reits-control'),
            'provider_none' => __('Provedor: Desconhecido', 'reits-control')
        ]
    ]);
}

add_action('admin_enqueue_scripts', 'reits_control_enqueue_admin');
function reits_control_enqueue_admin() {
    reits_control_enqueue_frontend();
}

// Check and create private post for user
function reits_control_ensure_user_post($user_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'reits_users';
    
    $user_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $user_id));
    
    if (!$user_entry) {
        // Create private post for user
        $user_info = get_userdata($user_id);
        $post_id = wp_insert_post([
            'post_title' => sprintf(__('Carteira de %s', 'reits-control'), $user_info->display_name),
            'post_content' => '[reits_control_lista]',
            'post_status' => 'private',
            'post_type' => 'page',
            'post_author' => $user_id
        ]);
        
        $wpdb->insert($table, [
            'user_id' => $user_id,
            'post_id' => $post_id,
            'created_at' => current_time('mysql')
        ], ['%d', '%d', '%s']);
        
        reits_control_log_action($user_id, 'user_registered', 'Usuário registrado com post privado ID: ' . $post_id);
        
        return $post_id;
    }
    
    return $user_entry->post_id;
}

// Normalize ticker format
function reits_control_normalize_ticker($ticker) {
    $ticker = strtoupper(trim($ticker));
    
    // Convert BVMF:TICKER to TICKER.SA
    if (strpos($ticker, 'BVMF:') === 0) {
        $ticker = substr($ticker, 5) . '.SA';
        reits_control_log_action(get_current_user_id(), 'ticker_normalized', 'Normalizou ticker de BVMF: para ' . $ticker);
    }
    
    return $ticker;
}

// Shortcode for frontend
add_shortcode('reits_control_lista', 'reits_control_shortcode');
function reits_control_shortcode($atts) {
    if (!is_user_logged_in()) return __('Você precisa estar logado.', 'reits-control');
    
    $user_id = get_current_user_id();
    reits_control_ensure_user_post($user_id);
    
    $atts = shortcode_atts(['per_page' => 20], $atts);
    return reits_control_frontend_table($user_id, $atts['per_page']);
}

// AJAX handlers
add_action('wp_ajax_reits_update_prices', 'reits_control_update_prices_ajax');
function reits_control_update_prices_ajax() {
    check_ajax_referer('reits_control_nonce', 'nonce');
    $user_id = get_current_user_id();
    reits_control_update_prices($user_id);
    reits_control_log_action($user_id, 'update_prices', 'Atualizou preços via AJAX');
    wp_send_json_success(['message' => __('Preços atualizados com sucesso!', 'reits-control')]);
}

add_action('wp_ajax_reits_fetch_info', 'reits_control_fetch_info_ajax');
function reits_control_fetch_info_ajax() {
    check_ajax_referer('reits_control_nonce', 'nonce');
    $ticker = reits_control_normalize_ticker($_POST['ticker']);
    
    if (empty($ticker)) {
        wp_send_json_error(['message' => __('Por favor, insira um ticker.', 'reits-control')]);
    }
    
    $data = reits_control_get_quote($ticker);
    $data['dividend'] = reits_control_get_dividend($ticker);
    
    reits_control_log_action(get_current_user_id(), 'fetch_info', 'Buscou info para ticker: ' . $ticker);
    
    if ($data['price'] > 0) {
        wp_send_json_success($data);
    } else {
        $error_msg = __('Falha ao buscar dados da API para ', 'reits-control') . $ticker;
        reits_control_log_action(get_current_user_id(), 'fetch_info_fail', $error_msg);
        wp_send_json_error(['message' => $error_msg]);
    }
}

add_action('wp_ajax_reits_test_api', 'reits_control_test_api');
function reits_control_test_api() {
    check_ajax_referer('reits_control_nonce', 'nonce');
    $test_ticker = 'PETR4.SA';
    $data = reits_control_get_quote($test_ticker);
    $data['dividend'] = reits_control_get_dividend($test_ticker);
    reits_control_log_action(get_current_user_id(), 'test_api', 'Testou API para ' . $test_ticker);
    if ($data['price'] > 0) {
        wp_send_json_success(['message' => __('Teste bem-sucedido! Nome: ', 'reits-control') . $data['name'] . ', Preço: ' . $data['price'] . ', Dividendo: ' . $data['dividend'], 'data' => $data]);
    } else {
        $error_msg = __('Falha no teste da API para ', 'reits-control') . $test_ticker;
        reits_control_log_action(get_current_user_id(), 'test_api_fail', $error_msg);
        wp_send_json_error(['message' => $error_msg]);
    }
}

// Log function
function reits_control_log_action($user_id, $action, $details = '') {
    $settings = get_option('reits_control_settings');
    if ($settings['enable_logs']) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'reits_logs', [
            'user_id' => $user_id,
            'action' => $action,
            'details' => $details,
            'timestamp' => current_time('mysql')
        ], ['%d', '%s', '%s', '%s']);
    }
}

// Admin menu
add_action('admin_menu', 'reits_control_admin_menu');
function reits_control_admin_menu() {
    add_menu_page(__('REITs – Controle', 'reits-control'), __('REITs – Controle', 'reits-control'), 'read', 'reits-control', 'reits_control_admin_page', 'dashicons-chart-pie');
    add_submenu_page('reits-control', __('Usuários', 'reits-control'), __('Usuários', 'reits-control'), 'manage_options', 'reits-control-users', 'reits_control_users_page');
    add_submenu_page('reits-control', __('Configurações', 'reits-control'), __('Configurações', 'reits-control'), 'manage_options', 'reits-control-settings', 'reits_control_settings_page');
}

// Users list page (Admin only)
function reits_control_users_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Acesso negado.', 'reits-control'));
    }
    
    $view_user_id = isset($_GET['view_user']) ? intval($_GET['view_user']) : 0;
    
    if ($view_user_id) {
        // Show specific user's portfolio
        reits_control_admin_view_user_portfolio($view_user_id);
        return;
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'reits_users';
    $users = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
    
    ?>
    <div class="wrap">
        <h1><?php _e('Usuários Registrados no REITs Control', 'reits-control'); ?></h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('ID', 'reits-control'); ?></th>
                    <th><?php _e('Nome', 'reits-control'); ?></th>
                    <th><?php _e('Email', 'reits-control'); ?></th>
                    <th><?php _e('Data de Registro', 'reits-control'); ?></th>
                    <th><?php _e('Post ID', 'reits-control'); ?></th>
                    <th><?php _e('Ações', 'reits-control'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): 
                    $user_info = get_userdata($user->user_id);
                    if (!$user_info) continue;
                ?>
                <tr>
                    <td><?php echo esc_html($user->user_id); ?></td>
                    <td><?php echo esc_html($user_info->display_name); ?></td>
                    <td><?php echo esc_html($user_info->user_email); ?></td>
                    <td><?php echo esc_html($user->created_at); ?></td>
                    <td><?php echo esc_html($user->post_id); ?></td>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'reits-control-users', 'view_user' => $user->user_id])); ?>" class="button button-primary">
                            <?php _e('Ver Carteira', 'reits-control'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Admin view user portfolio
function reits_control_admin_view_user_portfolio($user_id) {
    global $wpdb;
    
    $user_info = get_userdata($user_id);
    if (!$user_info) {
        wp_die(__('Usuário não encontrado.', 'reits-control'));
    }
    
    // Handle delete
    if (isset($_GET['delete'])) {
        check_admin_referer('reits_delete_asset');
        $id = intval($_GET['delete']);
        $wpdb->delete($wpdb->prefix . 'reits_registros', ['id' => $id, 'user_id' => $user_id]);
        reits_control_log_action(get_current_user_id(), 'admin_delete_asset', 'Admin excluiu ativo ID ' . $id . ' do usuário ' . $user_id);
        echo '<div class="notice notice-success"><p>' . __('Ativo excluído com sucesso!', 'reits-control') . '</p></div>';
    }
    
    ?>
    <div class="wrap">
        <h1><?php printf(__('Carteira de %s', 'reits-control'), esc_html($user_info->display_name)); ?></h1>
        <a href="<?php echo esc_url(admin_url('admin.php?page=reits-control-users')); ?>" class="button">
            <?php _e('← Voltar para Lista de Usuários', 'reits-control'); ?>
        </a>
        
        <?php 
        $settings = get_option('reits_control_settings');
        reits_control_admin_template($user_id, $settings['per_page'], true); 
        ?>
    </div>
    <?php
}

// Admin page
function reits_control_admin_page() {
    if (!is_user_logged_in()) {
        wp_die(__('Você precisa estar logado para acessar o painel.', 'reits-control'));
    }

    $user_id = get_current_user_id();
    reits_control_ensure_user_post($user_id);
    reits_control_log_action($user_id, 'access_admin', 'Acessou painel administrativo');
    
    $settings = get_option('reits_control_settings');
    $per_page = $settings['per_page'];

    global $wpdb;
    
    // Handle form submission
    if (isset($_POST['save_asset'])) {
        check_admin_referer('reits_save_asset');
        
        $ticker = reits_control_normalize_ticker($_POST['ticker']);
        $volume = max(1, intval($_POST['volume']));
        $valor_entrada = (float) str_replace(',', '.', sanitize_text_field($_POST['valor_entrada']));
        $preco_atual = (float) str_replace(',', '.', sanitize_text_field($_POST['preco_atual']));
        $dividendos = (float) str_replace(',', '.', sanitize_text_field($_POST['dividendos']));
        
        if ($volume < 1) {
            echo '<div class="notice notice-error"><p>' . __('O volume deve ser maior que 0.', 'reits-control') . '</p></div>';
        } else {
            $data = [
                'user_id' => $user_id,
                'fii_nome' => sanitize_text_field($_POST['fii_nome']),
                'ticker' => $ticker,
                'volume' => $volume,
                'valor_entrada' => $valor_entrada,
                'data_compra' => !empty($_POST['data_compra']) ? sanitize_text_field($_POST['data_compra']) : current_time('Y-m-d'),
                'preco_atual' => $preco_atual,
                'dividendos' => $dividendos,
                'nome_fii' => sanitize_text_field($_POST['fii_nome']),
                'updated_at' => current_time('mysql')
            ];

            if (isset($_POST['id']) && !empty($_POST['id'])) {
                $id = intval($_POST['id']);
                $wpdb->update($wpdb->prefix . 'reits_registros', $data, ['id' => $id, 'user_id' => $user_id]);
                reits_control_log_action($user_id, 'update_asset', 'Atualizou ativo ID ' . $id);
                echo '<div class="notice notice-success"><p>' . __('Ativo atualizado com sucesso!', 'reits-control') . '</p></div>';
            } else {
                $wpdb->insert($wpdb->prefix . 'reits_registros', $data);
                reits_control_log_action($user_id, 'add_asset', 'Adicionou ativo: ' . $data['ticker']);
                echo '<div class="notice notice-success"><p>' . __('Ativo salvo com sucesso!', 'reits-control') . '</p></div>';
            }
        }
    }

    // Handle delete
    if (isset($_GET['delete'])) {
        check_admin_referer('reits_delete_asset');
        $id = intval($_GET['delete']);
        $wpdb->delete($wpdb->prefix . 'reits_registros', ['id' => $id, 'user_id' => $user_id]);
        reits_control_log_action($user_id, 'delete_asset', 'Excluiu ativo ID ' . $id);
        echo '<div class="notice notice-success"><p>' . __('Ativo excluído com sucesso!', 'reits-control') . '</p></div>';
    }

    $edit = isset($_GET['edit']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}reits_registros WHERE id = %d AND user_id = %d", intval($_GET['edit']), $user_id)) : null;

    ?>
    <div class="wrap">
        <h1><?php _e('REITs Controle - Painel Administrativo', 'reits-control'); ?></h1>
        <h2><?php _e('Adicionar/Editar Ativo', 'reits-control'); ?></h2>
        <form method="post" class="form-group">
            <?php wp_nonce_field('reits_save_asset'); ?>
            <input type="hidden" name="id" value="<?php echo $edit ? esc_attr($edit->id) : ''; ?>">
            <div class="form-group">
                <label for="fii_nome"><?php _e('Nome do Ativo/FII', 'reits-control'); ?></label>
                <input type="text" name="fii_nome" id="fii_nome" class="form-control" value="<?php echo $edit ? esc_attr($edit->fii_nome) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="ticker"><?php _e('Ticker (ex: ITUB4.SA, BVMF:ITUB4)', 'reits-control'); ?></label>
                <div class="input-group">
                    <input type="text" name="ticker" id="ticker" class="form-control" value="<?php echo $edit ? esc_attr($edit->ticker) : ''; ?>" required>
                    <button type="button" id="buscar-info" class="btn btn-outline-secondary"><?php _e('Buscar Info', 'reits-control'); ?></button>
                </div>
                <div id="manual-link" style="margin-top: 5px;"></div>
                <div id="provider-info" style="margin-top: 5px; font-style: italic; color: #666;"></div>
            </div>
            <div class="form-group">
                <label for="volume"><?php _e('Volume (>0)', 'reits-control'); ?></label>
                <input type="number" name="volume" id="volume" min="1" step="1" class="form-control" value="<?php echo $edit ? esc_attr($edit->volume) : '1'; ?>" required>
            </div>
            <div class="form-group">
                <label for="valor_entrada"><?php _e('Valor de Entrada (R$)', 'reits-control'); ?></label>
                <input type="text" name="valor_entrada" id="valor_entrada" class="form-control" value="<?php echo $edit ? number_format($edit->valor_entrada, 2, ',', '.') : '0,00'; ?>" required>
            </div>
            <div class="form-group">
                <label for="data_compra"><?php _e('Data de Compra', 'reits-control'); ?></label>
                <input type="date" name="data_compra" id="data_compra" class="form-control" value="<?php echo $edit ? esc_attr($edit->data_compra) : current_time('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label for="preco_atual"><?php _e('Preço Atual (R$)', 'reits-control'); ?></label>
                <input type="text" name="preco_atual" id="preco_atual" class="form-control" value="<?php echo $edit ? number_format($edit->preco_atual, 2, ',', '.') : '0,00'; ?>">
            </div>
            <div class="form-group">
                <label for="dividendos"><?php _e('Dividendos por Ação (R$)', 'reits-control'); ?></label>
                <input type="text" name="dividendos" id="dividendos" class="form-control" value="<?php echo $edit ? number_format($edit->dividendos, 2, ',', '.') : '0,00'; ?>">
            </div>
            <button type="submit" name="save_asset" class="btn btn-primary"><?php _e('Salvar', 'reits-control'); ?></button>
        </form>

        <?php reits_control_admin_template($user_id, $per_page, false); ?>
        <div id="api-message" class="alert alert-info" style="margin-top: 20px; display: none;"></div>
    </div>
    <?php
}

// Settings page
function reits_control_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Acesso negado.', 'reits-control'));
    }

    if (isset($_POST['save_settings'])) {
        check_admin_referer('reits_save_settings');
        $settings = [
            'provider' => sanitize_text_field($_POST['provider']),
            'suffix' => '',
            'cache_duration' => max(300, intval($_POST['cache_duration'])),
            'per_page' => max(1, intval($_POST['per_page'])),
            'enable_dividends' => true,
            'safe_mode' => isset($_POST['safe_mode']) ? true : false,
            'enable_logs' => isset($_POST['enable_logs']) ? true : false,
            'alphavantage_key' => sanitize_text_field($_POST['alphavantage_key']),
        ];
        update_option('reits_control_settings', $settings);
        reits_control_log_action(get_current_user_id(), 'update_settings', 'Atualizou configurações');
        echo '<div class="notice notice-success"><p>' . __('Configurações salvas com sucesso!', 'reits-control') . '</p></div>';
    }

    if (isset($_POST['clear_cache'])) {
        check_admin_referer('reits_clear_cache');
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_reits_%'");
        reits_control_log_action(get_current_user_id(), 'clear_cache', 'Limpou cache');
        echo '<div class="notice notice-success"><p>' . __('Cache limpo com sucesso!', 'reits-control') . '</p></div>';
    }

    if (isset($_POST['clear_logs'])) {
        check_admin_referer('reits_clear_logs');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}reits_logs");
        reits_control_log_action(get_current_user_id(), 'clear_logs', 'Limpou logs');
        echo '<div class="notice notice-success"><p>' . __('Logs limpos com sucesso!', 'reits-control') . '</p></div>';
    }

    $settings = get_option('reits_control_settings');
    ?>
    <div class="wrap">
        <h1><?php _e('Configurações REITs Control', 'reits-control'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('reits_save_settings'); ?>
            <div class="form-group">
                <label for="provider"><?php _e('Provedor de Cotação', 'reits-control'); ?></label>
                <select name="provider" id="provider" class="form-control">
                    <option value="yahoo" <?php selected($settings['provider'], 'yahoo'); ?>>Yahoo Finance</option>
                    <option value="alphavantage" <?php selected($settings['provider'], 'alphavantage'); ?>>AlphaVantage</option>
                    <option value="investing" <?php selected($settings['provider'], 'investing'); ?>>Investing.com</option>
                </select>
            </div>
            <div class="form-group">
                <label for="alphavantage_key"><?php _e('Chave AlphaVantage', 'reits-control'); ?></label>
                <input type="text" name="alphavantage_key" id="alphavantage_key" class="form-control" value="<?php echo esc_attr($settings['alphavantage_key']); ?>">
            </div>
            <div class="form-group">
                <label for="cache_duration"><?php _e('Duração do Cache (segundos)', 'reits-control'); ?></label>
                <input type="number" name="cache_duration" id="cache_duration" class="form-control" value="<?php echo esc_attr($settings['cache_duration']); ?>" min="300">
            </div>
            <div class="form-group">
                <label for="per_page"><?php _e('Itens por Página', 'reits-control'); ?></label>
                <input type="number" name="per_page" id="per_page" class="form-control" value="<?php echo esc_attr($settings['per_page']); ?>" min="1">
            </div>
            <div class="form-check">
                <input type="checkbox" name="safe_mode" id="safe_mode" class="form-check-input" <?php checked($settings['safe_mode']); ?>>
                <label for="safe_mode" class="form-check-label"><?php _e('Safe Mode (sem chamadas externas)', 'reits-control'); ?></label>
            </div>
            <div class="form-check">
                <input type="checkbox" name="enable_logs" id="enable_logs" class="form-check-input" <?php checked($settings['enable_logs']); ?>>
                <label for="enable_logs" class="form-check-label"><?php _e('Ativar Logs', 'reits-control'); ?></label>
            </div>
            <button type="submit" name="save_settings" class="btn btn-primary"><?php _e('Salvar', 'reits-control'); ?></button>
            <?php wp_nonce_field('reits_clear_cache', '_wpnonce_clear_cache'); ?>
            <button type="submit" name="clear_cache" class="btn btn-secondary"><?php _e('Limpar Cache', 'reits-control'); ?></button>
            <?php wp_nonce_field('reits_clear_logs', '_wpnonce_clear_logs'); ?>
            <button type="submit" name="clear_logs" class="btn btn-secondary"><?php _e('Limpar Log', 'reits-control'); ?></button>
            <button type="button" id="test-api" class="btn btn-info"><?php _e('Testar API', 'reits-control'); ?></button>
        </form>
        <div id="api-message" class="alert alert-info" style="margin-top: 20px; display: none;"></div>
    </div>
    <?php
}

// Admin template function
function reits_control_admin_template($user_id, $per_page, $is_admin_view = false) {
    global $wpdb;
    $table = $wpdb->prefix . 'reits_registros';
    $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ticker) FROM $table WHERE user_id = %d", $user_id));
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;

    $registros = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY ticker, data_compra LIMIT %d OFFSET %d", $user_id, $per_page, $offset));
    $grouped = [];
    foreach ($registros as $reg) {
        $ticker = $reg->ticker;
        if (!isset($grouped[$ticker])) {
            $grouped[$ticker] = ['entries' => [], 'total_volume' => 0, 'weighted_sum' => 0, 'preco_atual' => $reg->preco_atual, 'dividendos' => $reg->dividendos, 'fii_nome' => $reg->fii_nome];
        }
        $grouped[$ticker]['entries'][] = $reg;
        $grouped[$ticker]['total_volume'] += $reg->volume;
        $grouped[$ticker]['weighted_sum'] += $reg->volume * $reg->valor_entrada;
    }

    ob_start();
    ?>
    <div class="container mt-4">
        <h2><?php _e('Carteira', 'reits-control'); ?></h2>
        <button class="btn btn-primary btn-update mb-3"><?php _e('Atualizar Agora', 'reits-control'); ?></button>
        <table class="table table-striped reits-table">
            <thead>
                <tr>
                    <th><?php _e('FII', 'reits-control'); ?></th>
                    <th><?php _e('Ticker', 'reits-control'); ?></th>
                    <th><?php _e('Volume Total', 'reits-control'); ?></th>
                    <th><?php _e('Preço Médio Entrada', 'reits-control'); ?></th>
                    <th><?php _e('Valor Atual', 'reits-control'); ?></th>
                    <th><?php _e('P&L', 'reits-control'); ?></th>
                    <th><?php _e('Dividendos Total', 'reits-control'); ?></th>
                    <th><?php _e('Ações', 'reits-control'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_pl = 0;
                foreach ($grouped as $ticker => $data):
                    $average_entry = $data['total_volume'] ? $data['weighted_sum'] / $data['total_volume'] : 0;
                    $pl = ($data['preco_atual'] - $average_entry) * $data['total_volume'];
                    $total_div = $data['dividendos'] * $data['total_volume'];
                    $total_pl += $pl;
                ?>
                <tr>
                    <td><?php echo esc_html($data['fii_nome']); ?></td>
                    <td><?php echo esc_html($ticker); ?></td>
                    <td><?php echo esc_html($data['total_volume']); ?></td>
                    <td>R$ <?php echo number_format($average_entry, 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($data['preco_atual'], 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($pl, 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($total_div, 2, ',', '.'); ?></td>
                    <td>
                        <?php foreach ($data['entries'] as $entry): 
                            if ($is_admin_view) {
                                $edit_url = add_query_arg(['page' => 'reits-control-users', 'view_user' => $user_id, 'edit' => $entry->id]);
                                $delete_url = wp_nonce_url(add_query_arg(['page' => 'reits-control-users', 'view_user' => $user_id, 'delete' => $entry->id]), 'reits_delete_asset');
                            } else {
                                $edit_url = add_query_arg(['page' => 'reits-control', 'edit' => $entry->id]);
                                $delete_url = wp_nonce_url(add_query_arg(['page' => 'reits-control', 'delete' => $entry->id]), 'reits_delete_asset');
                            }
                        ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="btn btn-sm btn-outline-primary"><?php _e('Editar', 'reits-control'); ?></a>
                            <a href="<?php echo esc_url($delete_url); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('<?php _e('Certeza?', 'reits-control'); ?>');"><?php _e('Excluir', 'reits-control'); ?></a><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="reits-total-pl"><strong><?php _e('P&L Total:', 'reits-control'); ?> R$ <?php echo number_format($total_pl, 2, ',', '.'); ?></strong></p>
        <?php
        $base_url = $is_admin_view ? add_query_arg(['page' => 'reits-control-users', 'view_user' => $user_id, 'paged' => '%#%']) : add_query_arg('paged', '%#%');
        echo paginate_links([
            'base' => $base_url,
            'format' => '&paged=%#%',
            'current' => $page,
            'total' => ceil($total / $per_page),
            'prev_text' => __('&laquo; Anterior', 'reits-control'),
            'next_text' => __('Próximo &raquo;', 'reits-control')
        ]);
        ?>
    </div>
    <?php
    echo ob_get_clean();
}

// Frontend table
function reits_control_frontend_table($user_id, $per_page) {
    if (!is_user_logged_in()) {
        return '<p>' . __('Você precisa estar logado para visualizar a carteira.', 'reits-control') . '</p>';
    }

    reits_control_update_prices($user_id);
    reits_control_log_action($user_id, 'access_frontend', 'Acessou frontend via shortcode');

    global $wpdb;
    $table = $wpdb->prefix . 'reits_registros';
    $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ticker) FROM $table WHERE user_id = %d", $user_id));
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;

    $registros = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY ticker, data_compra LIMIT %d OFFSET %d", $user_id, $per_page, $offset));
    $grouped = [];
    foreach ($registros as $reg) {
        $ticker = $reg->ticker;
        if (!isset($grouped[$ticker])) {
            $grouped[$ticker] = ['entries' => [], 'total_volume' => 0, 'weighted_sum' => 0, 'preco_atual' => $reg->preco_atual, 'dividendos' => $reg->dividendos, 'fii_nome' => $reg->fii_nome];
        }
        $grouped[$ticker]['entries'][] = $reg;
        $grouped[$ticker]['total_volume'] += $reg->volume;
        $grouped[$ticker]['weighted_sum'] += $reg->volume * $reg->valor_entrada;
    }

    ob_start();
    ?>
    <div class="container mt-4">
        <h2><?php _e('Sua Carteira', 'reits-control'); ?></h2>
        <button class="btn btn-primary btn-update mb-3"><?php _e('Atualizar Agora', 'reits-control'); ?></button>
        <table class="table table-striped reits-table">
            <thead>
                <tr>
                    <th><?php _e('FII', 'reits-control'); ?></th>
                    <th><?php _e('Ticker', 'reits-control'); ?></th>
                    <th><?php _e('Volume Total', 'reits-control'); ?></th>
                    <th><?php _e('Preço Médio Entrada', 'reits-control'); ?></th>
                    <th><?php _e('Valor Atual', 'reits-control'); ?></th>
                    <th><?php _e('P&L', 'reits-control'); ?></th>
                    <th><?php _e('Dividendos Total', 'reits-control'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_pl = 0;
                foreach ($grouped as $ticker => $data):
                    $average_entry = $data['total_volume'] ? $data['weighted_sum'] / $data['total_volume'] : 0;
                    $pl = ($data['preco_atual'] - $average_entry) * $data['total_volume'];
                    $total_div = $data['dividendos'] * $data['total_volume'];
                    $total_pl += $pl;
                ?>
                <tr>
                    <td><?php echo esc_html($data['fii_nome']); ?></td>
                    <td><?php echo esc_html($ticker); ?></td>
                    <td><?php echo esc_html($data['total_volume']); ?></td>
                    <td>R$ <?php echo number_format($average_entry, 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($data['preco_atual'], 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($pl, 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($total_div, 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="reits-total-pl"><strong><?php _e('P&L Total:', 'reits-control'); ?> R$ <?php echo number_format($total_pl, 2, ',', '.'); ?></strong></p>
        <?php
        echo paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '&paged=%#%',
            'current' => $page,
            'total' => ceil($total / $per_page),
            'prev_text' => __('&laquo; Anterior', 'reits-control'),
            'next_text' => __('Próximo &raquo;', 'reits-control')
        ]);
        ?>
        <div id="api-message" class="alert alert-info" style="margin-top: 20px; display: none;"></div>
    </div>
    <?php
    return ob_get_clean();
}

// Update prices
function reits_control_update_prices($user_id) {
    global $wpdb;
    $settings = get_option('reits_control_settings');
    if ($settings['safe_mode']) {
        reits_control_log_action($user_id, 'update_prices_fail', 'Safe mode ativo, atualização bloqueada');
        return;
    }
    $registros = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT ticker FROM {$wpdb->prefix}reits_registros WHERE user_id = %d", $user_id));
    $tickers = array_map(function($reg) { return $reg->ticker; }, $registros);
    $quotes = reits_control_get_batch_quotes($tickers);
    $dividends = reits_control_get_batch_dividends($tickers);
    foreach ($registros as $reg) {
        $ticker = $reg->ticker;
        if (isset($quotes[$ticker]) && $quotes[$ticker]['price'] > 0) {
            $wpdb->update($wpdb->prefix . 'reits_registros', [
                'preco_atual' => $quotes[$ticker]['price'],
                'dividendos' => $dividends[$ticker] ?? 0,
                'nome_fii' => $quotes[$ticker]['name'],
                'updated_at' => current_time('mysql')
            ], ['ticker' => $ticker, 'user_id' => $user_id], ['%f', '%f', '%s', '%s'], ['%s', '%d']);
            reits_control_log_action($user_id, 'update_price_success', 'Atualizou preço para ' . $ticker . ': ' . $quotes[$ticker]['price']);
        } else {
            reits_control_log_action($user_id, 'update_price_fail', 'Falha ao atualizar ' . $ticker . ': Nenhum dado retornado');
        }
    }
}

// API Functions
function reits_control_get_quote($ticker) {
    $settings = get_option('reits_control_settings');
    $transient_key = 'reits_quote_' . md5($ticker);
    $data = get_transient($transient_key);
    if ($data !== false) {
        reits_control_log_action(get_current_user_id(), 'quote_cache_hit', 'Cache hit para ' . $ticker);
        return $data;
    }

    $providers = ['yahoo', 'alphavantage', 'investing'];
    foreach ($providers as $prov) {
        try {
            if ($prov === 'yahoo') {
                $result = reits_control_get_quote_yahoo($ticker);
            } elseif ($prov === 'alphavantage') {
                $result = reits_control_get_quote_alphavantage($ticker);
            } elseif ($prov === 'investing') {
                $result = reits_control_get_quote_investing($ticker);
            }
            if ($result['price'] > 0) {
                $result['provider'] = $prov;
                set_transient($transient_key, $result, $settings['cache_duration']);
                reits_control_log_action(get_current_user_id(), 'quote_success', 'Sucesso na API ' . $prov . ' para ' . $ticker . ': ' . $result['price']);
                return $result;
            }
        } catch (Exception $e) {
            $error_msg = 'Falha na API ' . $prov . ' para ' . $ticker . ': ' . $e->getMessage();
            reits_control_log_action(get_current_user_id(), 'api_fail', $error_msg);
        }
    }
    $error_msg = 'Falha em todas as APIs para ' . $ticker;
    reits_control_log_action(get_current_user_id(), 'api_fail_all', $error_msg);
    return ['name' => '', 'price' => 0, 'provider' => 'none'];
}

function reits_control_get_dividend($ticker) {
    $settings = get_option('reits_control_settings');
    $transient_key = 'reits_dividend_' . md5($ticker);
    $data = get_transient($transient_key);
    if ($data !== false) {
        reits_control_log_action(get_current_user_id(), 'dividend_cache_hit', 'Cache hit para dividendos de ' . $ticker);
        return $data;
    }

    $providers = ['yahoo', 'alphavantage', 'investing'];
    foreach ($providers as $prov) {
        try {
            if ($prov === 'yahoo') {
                $result = reits_control_get_dividend_yahoo($ticker);
            } elseif ($prov === 'alphavantage') {
                $result = reits_control_get_dividend_alphavantage($ticker);
            } elseif ($prov === 'investing') {
                $result = reits_control_get_dividend_investing($ticker);
            }
            if ($result > 0) {
                set_transient($transient_key, $result, $settings['cache_duration']);
                reits_control_log_action(get_current_user_id(), 'dividend_success', 'Sucesso na API de dividendos ' . $prov . ' para ' . $ticker . ': ' . $result);
                return $result;
            }
        } catch (Exception $e) {
            $error_msg = 'Falha na API de dividendos ' . $prov . ' para ' . $ticker . ': ' . $e->getMessage();
            reits_control_log_action(get_current_user_id(), 'api_dividend_fail', $error_msg);
        }
    }
    $error_msg = 'Falha em todas as APIs de dividendos para ' . $ticker;
    reits_control_log_action(get_current_user_id(), 'api_dividend_fail_all', $error_msg);
    return 0;
}

function reits_control_get_batch_quotes($tickers) {
    $quotes = [];
    foreach ($tickers as $ticker) {
        $quotes[$ticker] = reits_control_get_quote($ticker);
    }
    return $quotes;
}

function reits_control_get_batch_dividends($tickers) {
    $divs = [];
    foreach ($tickers as $ticker) {
        $divs[$ticker] = reits_control_get_dividend($ticker);
    }
    return $divs;
}

// Yahoo Finance functions
function reits_control_get_quote_yahoo($ticker) {
    $url = "https://query1.finance.yahoo.com/v7/finance/quote?symbols=" . urlencode($ticker);
    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        reits_control_log_action(get_current_user_id(), 'yahoo_quote_error', 'Erro wp_remote_get para ' . $ticker . ': ' . $response->get_error_message());
        throw new Exception($response->get_error_message());
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $quote = $body['quoteResponse']['result'][0] ?? [];
    if (empty($quote)) {
        reits_control_log_action(get_current_user_id(), 'yahoo_quote_no_data', 'Nenhum dado retornado para ' . $ticker);
        throw new Exception('No data returned for ' . $ticker);
    }
    return [
        'name' => $quote['longName'] ?? $quote['shortName'] ?? $ticker,
        'price' => floatval($quote['regularMarketPrice'] ?? 0)
    ];
}

function reits_control_get_dividend_yahoo($ticker) {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/$ticker?range=1mo&includeDividends=true";
    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        reits_control_log_action(get_current_user_id(), 'yahoo_dividend_error', 'Erro wp_remote_get para dividendos de ' . $ticker . ': ' . $response->get_error_message());
        throw new Exception($response->get_error_message());
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $dividends = $body['chart']['result'][0]['events']['dividends'] ?? [];
    $total = array_sum(array_column($dividends, 'amount')) ?? 0;
    if ($total == 0) {
        reits_control_log_action(get_current_user_id(), 'yahoo_dividend_no_data', 'Nenhum dividendo retornado para ' . $ticker);
    }
    return $total;
}

// AlphaVantage functions
function reits_control_get_quote_alphavantage($ticker) {
    $settings = get_option('reits_control_settings');
    $key = $settings['alphavantage_key'];
    if (!$key) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_quote_error', 'Chave AlphaVantage não configurada para ' . $ticker);
        throw new Exception('Chave AlphaVantage não configurada');
    }
    $url = "https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=$ticker&apikey=$key";
    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_quote_error', 'Erro wp_remote_get para ' . $ticker . ': ' . $response->get_error_message());
        throw new Exception($response->get_error_message());
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_quote_error', 'Resposta inválida da API para ' . $ticker . ': ' . wp_remote_retrieve_body($response));
        throw new Exception('Invalid API response for ' . $ticker);
    }
    $quote = $body['Global Quote'] ?? [];
    if (empty($quote)) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_quote_no_data', 'Nenhum dado retornado para ' . $ticker);
        throw new Exception('No data returned for ' . $ticker);
    }
    return [
        'name' => $quote['01. symbol'] ?? $ticker,
        'price' => floatval($quote['05. price'] ?? 0)
    ];
}

function reits_control_get_dividend_alphavantage($ticker) {
    $settings = get_option('reits_control_settings');
    $key = $settings['alphavantage_key'];
    if (!$key) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_dividend_error', 'Chave AlphaVantage não configurada para ' . $ticker);
        throw new Exception('Chave AlphaVantage não configurada');
    }
    $url = "https://www.alphavantage.co/query?function=TIME_SERIES_MONTHLY_ADJUSTED&symbol=$ticker&apikey=$key";
    $response = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_dividend_error', 'Erro wp_remote_get para dividendos de ' . $ticker . ': ' . $response->get_error_message());
        throw new Exception($response->get_error_message());
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_dividend_error', 'Resposta inválida da API para ' . $ticker . ': ' . wp_remote_retrieve_body($response));
        throw new Exception('Invalid API response for ' . $ticker);
    }
    $series = $body['Monthly Adjusted Time Series'] ?? [];
    if (empty($series)) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_dividend_no_data', 'Nenhum dado de série mensal retornado para ' . $ticker);
        return 0;
    }
    $latest = reset($series) ?? [];
    $dividend = floatval($latest['7. dividend amount'] ?? 0);
    if ($dividend == 0) {
        reits_control_log_action(get_current_user_id(), 'alphavantage_dividend_no_data', 'Nenhum dividendo retornado para ' . $ticker);
    }
    return $dividend;
}

// Investing.com functions
function reits_control_get_quote_investing($ticker) {
    $ticker_slug = str_replace('.', '-', strtolower($ticker));
    $url = "https://www.investing.com/equities/$ticker_slug-quote";
    $response = wp_remote_get($url, ['headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'], 'timeout' => 15]);
    if (is_wp_error($response)) {
        reits_control_log_action(get_current_user_id(), 'investing_quote_error', 'Erro wp_remote_get para ' . $ticker . ': ' . $response->get_error_message());
        throw new Exception($response->get_error_message());
    }
    $body = wp_remote_retrieve_body($response);
    preg_match('/<span class="text-2xl">([\d.,]+)\s*<\/span>/', $body, $matches);
    $price = (float) str_replace(',', '.', $matches[1] ?? '0');
    if ($price == 0) {
        reits_control_log_action(get_current_user_id(), 'investing_quote_no_price', 'Nenhum preço encontrado para ' . $ticker);
    }
    return ['name' => $ticker, 'price' => $price];
}

function reits_control_get_dividend_investing($ticker) {
    $ticker_slug = str_replace('.', '-', strtolower($ticker));
    $url = "https://www.investing.com/equities/$ticker_slug-dividends";
    $response = wp_remote_get($url, ['headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'], 'timeout' => 15]);
    if (is_wp_error($response)) {
        reits_control_log_action(get_current_user_id(), 'investing_dividend_error', 'Erro wp_remote_get para dividendos de ' . $ticker . ': ' . $response->get_error_message());
        throw new Exception($response->get_error_message());
    }
    $body = wp_remote_retrieve_body($response);
    preg_match('/Dividend \(Yield\):\s*([\d.,]+)\s*\(/', $body, $matches);
    $dividend = (float) str_replace(',', '.', $matches[1] ?? '0');
    if ($dividend == 0) {
        reits_control_log_action(get_current_user_id(), 'investing_dividend_no_data', 'Nenhum dividendo retornado para ' . $ticker);
    }
    return $dividend;
}
?>