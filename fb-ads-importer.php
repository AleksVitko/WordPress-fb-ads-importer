<?php
/*
Plugin Name: FB Ads Importer
Description: Импортирует объявления из Facebook-группы на сайт.
Version: 1.0
Author: Alexandr Vitko
*/

// Запрет прямого доступа к файлу
if (!defined('ABSPATH')) {
    exit;
}

// Подключение дополнительных файлов
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';

// Регистрация REST API
add_action('rest_api_init', function () {
    register_rest_route('fb-import/v1', '/create/', array(
        'methods'             => 'POST',
        'callback'            => 'fb_ads_importer_create_post',
        'permission_callback' => 'fb_ads_importer_permission_callback',
    ));
});

// Активация плагина
register_activation_hook(__FILE__, 'fb_ads_importer_activate');
function fb_ads_importer_activate() {
    if ('1' === get_option('fb_ads_importer_enable_import')) {
        if (!wp_next_scheduled('fb_ads_importer_fetch_posts')) {
            wp_schedule_event(time(), 'hourly', 'fb_ads_importer_fetch_posts');
        }
    }
}

// Деактивация плагина
register_deactivation_hook(__FILE__, 'fb_ads_importer_deactivate');
function fb_ads_importer_deactivate() {
    $timestamp = wp_next_scheduled('fb_ads_importer_fetch_posts');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fb_ads_importer_fetch_posts');
    }
}

// Добавление ссылок управления на странице плагинов
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'fb_ads_importer_add_action_links');
function fb_ads_importer_add_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=fb-ads-importer-settings') . '">Настройки</a>';
    $toggle_import_link = '';

    if ('1' === get_option('fb_ads_importer_enable_import')) {
        $toggle_import_link = '<a href="' . wp_nonce_url(add_query_arg('action', 'disable_import'), 'fb_ads_importer_toggle_import') . '" style="color:red;">Отключить импорт</a>';
    } else {
        $toggle_import_link = '<a href="' . wp_nonce_url(add_query_arg('action', 'enable_import'), 'fb_ads_importer_toggle_import') . '" style="color:green;">Включить импорт</a>';
    }

    return array_merge($links, [$settings_link, $toggle_import_link]);
}

// Обработка действия включения/выключения импорта
add_action('admin_init', 'fb_ads_importer_handle_toggle_import');
function fb_ads_importer_handle_toggle_import() {
    if (!isset($_GET['action']) || !wp_verify_nonce($_GET['_wpnonce'], 'fb_ads_importer_toggle_import')) {
        return;
    }

    $action = sanitize_text_field($_GET['action']);

    if ($action === 'enable_import') {
        update_option('fb_ads_importer_enable_import', '1');
        if (!wp_next_scheduled('fb_ads_importer_fetch_posts')) {
            wp_schedule_event(time(), 'hourly', 'fb_ads_importer_fetch_posts');
        }
        add_settings_error('fb_ads_importer_settings_group', 'import_enabled', 'Импорт успешно включён.', 'updated');
    } elseif ($action === 'disable_import') {
        update_option('fb_ads_importer_enable_import', '0');
        $timestamp = wp_next_scheduled('fb_ads_importer_fetch_posts');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'fb_ads_importer_fetch_posts');
        }
        add_settings_error('fb_ads_importer_settings_group', 'import_disabled', 'Импорт успешно отключён.', 'error');
    }

    wp_safe_redirect(admin_url('plugins.php'));
    exit;
}

// Добавление страницы настроек
add_action('admin_menu', 'fb_ads_importer_add_settings_page');
function fb_ads_importer_add_settings_page() {
    add_options_page(
        'FB Ads Importer Settings', // Название страницы
        'FB Ads Importer',          // Название в меню
        'manage_options',           // Capability (только для администраторов)
        'fb-ads-importer-settings', // Slug страницы
        'fb_ads_importer_settings_page' // Callback функция для отображения содержимого
    );
}

// Проверка токена авторизации
function fb_ads_importer_permission_callback($request) {
    $auth_token = get_option('fb_ads_importer_auth_token');
    $request_token = $request->get_header('Authorization');
    return $auth_token === $request_token;
}