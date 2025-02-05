<?php
/**
 * Подключение необходимых файлов
 */
require_once ABSPATH . 'wp-admin/includes/media.php';

/**
 * Создание нового поста на основе данных из запроса
 *
 * @param WP_REST_Request $request Объект запроса.
 * @return WP_REST_Response Ответ с результатом.
 */
function fb_ads_importer_create_post($request) {
    // Получение данных из запроса
    $data = $request->get_json_params();

    // Получение настроек плагина
    $default_category_slug = get_option('fb_ads_importer_default_category', '');
    $post_type = get_option('fb_ads_importer_post_type', 'post');

    // Проверка обязательных полей
    if (!isset($data['title']) || !isset($data['description'])) {
        return new WP_REST_Response(['message' => 'Отсутствуют обязательные параметры'], 400);
    }

    // Создание массива данных для нового поста
    $post_data = array(
        'post_title'   => sanitize_text_field($data['title']),
        'post_content' => sanitize_textarea_field($data['description']),
        'post_status'  => 'publish',
        'post_type'    => $post_type,
    );

    // Добавление категории
    if (isset($data['category']) && !empty($data['category'])) {
        $category_id = get_cat_ID($data['category']);
        if ($category_id === 0 && !empty($default_category_slug)) {
            $category_id = get_cat_ID($default_category_slug);
        }
        $post_data['post_category'] = array($category_id);
    } elseif (!empty($default_category_slug)) {
        $post_data['post_category'] = array(get_cat_ID($default_category_slug));
    }

    // Создание поста
    $post_id = wp_insert_post($post_data);

    if (!$post_id) {
        error_log('Ошибка при создании поста: ' . print_r($post_data, true));
        return new WP_REST_Response(['message' => 'Ошибка создания поста'], 500);
    }

    // Сохранение дополнительных параметров в метаполя
    if (isset($data['type_of_ad'])) {
        update_post_meta($post_id, '_type_of_ad', sanitize_text_field($data['type_of_ad']));
    }
    if (isset($data['keywords'])) {
        update_post_meta($post_id, '_keywords', sanitize_text_field($data['keywords']));
    }
    if (isset($data['price'])) {
        update_post_meta($post_id, '_price', sanitize_text_field($data['price']));
    }
    if (isset($data['bidding'])) {
        update_post_meta($post_id, '_bidding', sanitize_text_field($data['bidding']));
    }
    if (isset($data['phone'])) {
        update_post_meta($post_id, '_phone', sanitize_text_field($data['phone']));
    }
    if (isset($data['item_condition'])) {
        update_post_meta($post_id, '_item_condition', sanitize_text_field($data['item_condition']));
    }
    if (isset($data['country'])) {
        update_post_meta($post_id, '_country', sanitize_text_field($data['country']));
    }
    if (isset($data['state'])) {
        update_post_meta($post_id, '_state', sanitize_text_field($data['state']));
    }
    if (isset($data['city'])) {
        update_post_meta($post_id, '_city', sanitize_text_field($data['city']));
    }

    // Добавление изображений
    if (isset($data['images']) && is_array($data['images'])) {
        foreach ($data['images'] as $image_url) {
            fb_ads_importer_add_image_to_post($post_id, $image_url);
        }
    }

    // Возвращаем успешный ответ
    return new WP_REST_Response(['message' => 'Объявление успешно создано', 'id' => $post_id], 201);
}

/**
 * Добавление изображения к посту
 *
 * @param int    $post_id ID поста.
 * @param string $image_url URL изображения.
 */
function fb_ads_importer_add_image_to_post($post_id, $image_url) {
    $upload = media_sideload_image($image_url, $post_id);
    if (is_wp_error($upload)) {
        error_log('Ошибка при загрузке изображения: ' . $upload->get_error_message());
    }
}

/**
 * Получение постов из Facebook-группы
 */
function fb_ads_importer_fetch_facebook_posts() {
    // Проверяем, включен ли импорт
    if ('1' !== get_option('fb_ads_importer_enable_import')) {
        error_log('Автоматический импорт объявлений из Facebook отключён.');
        return;
    }

    // Получение настроек плагина
    $access_token = get_option('fb_ads_importer_fb_access_token');
    $group_id = get_option('fb_ads_importer_fb_group_id');
    $default_category = get_option('fb_ads_importer_default_category', '');

    if (empty($access_token) || empty($group_id)) {
        error_log('Не указан Facebook Access Token или Group ID.');
        return;
    }

    // URL для запроса к Facebook Graph API
    $url = "https://graph.facebook.com/v16.0/$group_id/feed";
    $params = [
        'access_token' => $access_token,
        'fields'       => 'message,created_time,from,picture,id'
    ];

    try {
        // Получение данных из Facebook
        $response = wp_remote_get(add_query_arg($params, $url));

        if (is_wp_error($response)) {
            error_log('Ошибка при получении данных из Facebook: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $posts = isset($body['data']) ? $body['data'] : [];

        // Обработка каждого поста
        foreach ($posts as $post) {
            $parsed_data = fb_ads_importer_parse_post($post, $default_category);
            if ($parsed_data) {
                fb_ads_importer_send_to_wordpress($parsed_data);
            }
        }
    } catch (Exception $e) {
        error_log('Ошибка при работе с Facebook API: ' . $e->getMessage());
    }
}

/**
 * Анализ поста из Facebook
 *
 * @param array $post Данные поста.
 * @param string $default_category Категория по умолчанию.
 * @return array|false Обработанные данные или false, если данные некорректны.
 */
function fb_ads_importer_parse_post($post, $default_category) {
    $message = $post['message'] ?? '';
    $data = [];

    // Простой парсер для извлечения параметров
    $lines = explode("\n", $message);
    foreach ($lines as $line) {
        if (strpos($line, '[Selected Category:') === 0) {
            $data['category'] = trim(str_replace('[Selected Category:', '', $line), ']');
        } elseif (strpos($line, '[Type of Ad:') === 0) {
            $data['type_of_ad'] = trim(str_replace('[Type of Ad:', '', $line), ']');
        } elseif (strpos($line, '[Ad title:') === 0) {
            $data['title'] = trim(str_replace('[Ad title:', '', $line), ']');
        } elseif (strpos($line, '[Ad description:') === 0) {
            $data['description'] = trim(str_replace('[Ad description:', '', $line), ']', "\n");
        } elseif (strpos($line, '[Keywords:') === 0) {
            $data['keywords'] = trim(str_replace('[Keywords:', '', $line), ']');
        } elseif (strpos($line, '[Ad price:') === 0) {
            $data['price'] = trim(str_replace('[Ad price:', '', $line), ']');
        } elseif (strpos($line, '[Bidding:') === 0) {
            $data['bidding'] = trim(str_replace('[Bidding:', '', $line), ']');
        } elseif (strpos($line, '[Your Phone/Mobile:') === 0) {
            $data['phone'] = trim(str_replace('[Your Phone/Mobile:', '', $line), ']');
        } elseif (strpos($line, '[Item Condition:') === 0) {
            $data['item_condition'] = trim(str_replace('[Item Condition:', '', $line), ']');
        } elseif (strpos($line, '[Country:') === 0) {
            $data['country'] = trim(str_replace('[Country:', '', $line), ']');
        } elseif (strpos($line, '[State:') === 0) {
            $data['state'] = trim(str_replace('[State:', '', $line), ']');
        } elseif (strpos($line, '[City:') === 0) {
            $data['city'] = trim(str_replace('[City:', '', $line), ']');
        }
    }

    // Добавляем изображения
    if (!empty($post['picture'])) {
        $data['images'] = [$post['picture']];
    }

    // Проверяем наличие обязательных полей
    if (empty($data['title']) || empty($data['description'])) {
        return false;
    }

    // Если категория не указана, используем категорию по умолчанию
    if (empty($data['category']) && !empty($default_category)) {
        $data['category'] = $default_category;
    }

    return $data;
}

/**
 * Отправка данных на сайт через REST API
 *
 * @param array $data Обработанные данные.
 */
function fb_ads_importer_send_to_wordpress($data) {
    // Получение URL REST API
    $api_url = rest_url('fb-import/v1/create');

    // Получение токена авторизации
    $auth_token = get_option('fb_ads_importer_auth_token');
    if (empty($auth_token)) {
        error_log('Не указан токен авторизации API.');
        return;
    }

    // Отправка данных на сайт
    $response = wp_remote_post($api_url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => $auth_token
        ],
        'body'    => json_encode($data)
    ]);

    if (is_wp_error($response)) {
        error_log('Ошибка при отправке данных на сайт: ' . $response->get_error_message());
    } else {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($body['message'] === 'Объявление успешно создано') {
            error_log('Объявление создано: ' . $data['title']);
        } else {
            error_log('Ошибка при создании объявления: ' . print_r($body, true));
        }
    }
}