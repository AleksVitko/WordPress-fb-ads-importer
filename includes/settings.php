<?php
/**
 * Регистрация настроек плагина
 */
add_action('admin_init', 'fb_ads_importer_register_settings');
function fb_ads_importer_register_settings() {
    register_setting('fb_ads_importer_settings_group', 'fb_ads_importer_auth_token');
    register_setting('fb_ads_importer_settings_group', 'fb_ads_importer_fb_access_token');
    register_setting('fb_ads_importer_settings_group', 'fb_ads_importer_fb_group_id');
    register_setting('fb_ads_importer_settings_group', 'fb_ads_importer_default_category');
    register_setting('fb_ads_importer_settings_group', 'fb_ads_importer_post_type');
    register_setting('fb_ads_importer_settings_group', 'fb_ads_importer_enable_import');
}

/**
 * Отображение страницы настроек плагина
 */
function fb_ads_importer_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('У вас недостаточно прав для просмотра этой страницы.'));
    }

    ?>
    <div class="wrap">
        <?php
        // Вывод сообщений об ошибках или уведомлений
        settings_errors('fb_ads_importer_settings_group');
        ?>

        <h1>Настройки FB Ads Importer</h1>
        <form method="post" action="options.php">
            <?php
            // Вывод скрытых полей безопасности
            settings_fields('fb_ads_importer_settings_group');
            // Вывод секции настроек
            do_settings_sections('fb-ads-importer-settings');
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Токен авторизации API</th>
                    <td>
                        <input type="text" name="fb_ads_importer_auth_token" value="<?php echo esc_attr(get_option('fb_ads_importer_auth_token')); ?>" class="regular-text" />
                        <p class="description">
                            Введите секретный токен для защиты API. Этот токен будет использоваться для авторизации запросов.<br>
                            <strong>Как создать токен:</strong><br>
                            1. Используйте случайную комбинацию букв, цифр и специальных символов (например, abc123!@#secretToken456).<br>
                            2. Вы можете сгенерировать токен автоматически с помощью функции <code>wp_generate_password()</code> в файле functions.php:<br>
                            <code>echo wp_generate_password(32, true, true);</code><br>
                            <strong>Важно:</strong> Сохраните токен в надёжном месте, так как после сохранения его нельзя будет увидеть снова.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Facebook Access Token</th>
                    <td>
                        <input type="text" name="fb_ads_importer_fb_access_token" value="<?php echo esc_attr(get_option('fb_ads_importer_fb_access_token')); ?>" class="regular-text" />
                        <p class="description">Введите ваш Facebook Access Token для доступа к группе.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Facebook Group ID</th>
                    <td>
                        <input type="text" name="fb_ads_importer_fb_group_id" value="<?php echo esc_attr(get_option('fb_ads_importer_fb_group_id')); ?>" class="regular-text" />
                        <p class="description">Введите ID вашей Facebook-группы.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Категория по умолчанию</th>
                    <td>
                        <select name="fb_ads_importer_default_category" class="regular-text">
                            <option value="">-- Выберите категорию --</option>
                            <?php
                            $categories = get_categories(array('hide_empty' => false));
                            if (!empty($categories)) {
                                $selected_category = get_option('fb_ads_importer_default_category');
                                foreach ($categories as $category) {
                                    $selected = ($category->slug === $selected_category) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($category->slug) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
                                }
                            } else {
                                echo '<option value="" disabled>Категории не найдены</option>';
                            }
                            ?>
                        </select>
                        <p class="description">Выберите категорию по умолчанию. Если категория из запроса не существует, будет использована эта категория.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Включить импорт</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fb_ads_importer_enable_import" value="1" <?php checked('1', get_option('fb_ads_importer_enable_import')); ?> />
                            Включить автоматический импорт объявлений из Facebook-группы.
                        </label>
                        <p class="description">Отметьте этот флажок, чтобы начать импорт объявлений.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}