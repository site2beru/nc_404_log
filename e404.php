<?php
$NETCAT_FOLDER = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;
require_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';

/** @var nc_core $nc_core */
/** @var nc_db $db */

// Загрузка частей страницы через partial.php? (см. nc_partial_loader::setup_page_environment())
$nc_is_async_partial_request = isset($this) && $this instanceof nc_partial_loader;

if (!$nc_is_async_partial_request) {
    // -------------------- Обработка запросов к файлам ----------------------------

    // Отдача файлов из защищенной ФС
    if (preg_match('#^' . preg_quote($nc_core->HTTP_FILES_PATH, '#') . '([0-9uct]+)/([0-9]+/)?h_([0-9A-Z]{32})$#i', $nc_core->url->get_parsed_url('path'), $matches)) {

        if ($matches[1] !== 'u' && $matches[1] !== 'c' && $matches[1] !== 't') {
            $matches[1] = (int)$matches[1];
        }

        $file_path = $matches[1] . '/';

        if (strlen($matches[2])) {
            $file_path .= $matches[2];
        }

        $full_file_path = $nc_core->FILES_FOLDER . $file_path . $matches[3];

        if (file_exists($full_file_path)) {

            while (ob_get_level() && @ob_end_clean());

            // sic (remove header)
            if ($use_gzip_compression) {
                header('Content-Encoding: ');
            }

            // get filetime
            $file_time = filemtime($full_file_path);

            // check If-Modified-Since and REDIRECT 304, if needed
            $nc_core->page->update_last_modified_if_timestamp_is_newer($file_time);
            $nc_core->page->send_and_check_cache_validator_headers();

            $file_data = $db->get_row(
                "SELECT f.`ID`, f.`Real_Name`, f.`File_Type`, f.`Content_Disposition`, fl.`Format`
                 FROM `Filetable` as f, `Field` as `fl`
                 WHERE `Virt_Name` = '{$matches[3]}'
                 AND `File_Path` = '/{$file_path}'
                 AND fl.`Field_ID` = f.`Field_ID`
                 LIMIT 1",
                ARRAY_N
            );

            if (!empty($file_data)) {
                list ($file_id, $real_name, $file_type, $attachment, $format) = $file_data;
                if (strpos($format, 'download') !== false) {
                    $db->query("UPDATE `Filetable` SET `Download` = `Download` + 1 WHERE `ID` = '{$file_id}'");
                }
                header("Content-Security-Policy: script-src 'none'"); // запретить выполнение скриптов (html etc.)
                nc_send_file($full_file_path, $real_name, $file_type, $attachment);
            }
        }
    }

    // Отдача сгенерированных обработанных изображений
    $path = $nc_core->url->get_parsed_url('path');
    if (strpos($path, nc_image_base::generated_images_http_path()) === 0) {
        $generator_parameters = nc_image_generator::get_parameters_from_url($path, $_GET);
        $image_generator = nc_image_generator::from_parameters($generator_parameters);
        if ($image_generator->validate_hash($generator_parameters['result_hash'])) {
            $image_absolute_path = $image_generator->generate();
            nc_send_file($image_absolute_path);
        }
    }

    // --------------------- Обработка запросов к robots.txt -----------------------

    if ($nc_core->url->get_parsed_url('path') === '/robots.txt') {
        $robots = $nc_core->catalogue->get_current('Robots');
        $robots = str_ireplace(
            array('%SCHEME', '%HOST'),
            array(nc_get_scheme(), $_SERVER['HTTP_HOST'] ?: $nc_core->catalogue->get_current('Domain')),
            $robots
        );
        nc_set_http_response_code(200);
        header('Last-Modified: ' . $nc_core->catalogue->get_current('LastUpdated'));
        header('Content-Type: text/plain');
        header('Content-Length: ' . strlen($robots));
        ob_clean();
        echo $robots;
        exit;
    }

    // --------------------- Обработка запросов к sitemap.xml ----------------------

    if ($nc_core->url->get_parsed_url('path') === '/sitemap.xml' && nc_module_check_by_keyword('search')) {
        require_once nc_module_folder('search') . 'sitemap.php';
        exit;
    }

    // ------------------------------- Переадресации --------------------------------
    $client_source_url = $nc_core->url->source_url();

    if (!$nc_core->NC_REDIRECT_DISABLED) {
        AttemptToRedirect($client_source_url);
    }

}

// ---------------------------- Глобальные переменные --------------------------

$developer_mode = false;
$admin_mode = false;

$current_catalogue = $nc_core->catalogue->get_by_host_name($nc_core->HTTP_HOST, true); // данные сайта
$catalogue = $nc_core->catalogue->get_current('Catalogue_ID'); // идентификатор сайта

if (!$catalogue) {
    exit;
}

$sub = 0;              // идентификатор раздела
$classID = 0;          // идентификатор компонента
$user_table_mode = false; // флаг работы с системной таблицей, а не с обычным компонентом
$system_table = 0;     // идентификатор системной таблицы (таблица User — 3)
$system_table_fields = array(); // информация о полях системных таблиц, кое-где до сих пор используется как global
$cc = 0;               // идентификатор инфоблока
$_db_cc = null;        // для зеркальных инфоблоков — идентификатор инфоблока в запрошенном разделе
$cc_keyword = '';      // ключевое слово инфоблока
$cc_array = array();   // массив с идентификаторами включенных инфоблоков в разделе
$cc_in_sub = array();  // массив с данными инфоблоков в разделе
$message = 0;          // идентификатор объекта компонента
$redirect_to_url = ''; // URL, на который следует осуществить переадресацию
//$action = null;      // определяет, какой скрипт будет подключаться. Может передаваться в GET, POST

if (nc_module_check_by_keyword('routing')) {
    // нужно загрузить модуль роутинга
    $nc_core->modules->load_env('', false, false, false, 'routing');
}

$e404_sub = $nc_core->catalogue->get_current('E404_Sub_ID');
$title_sub = $nc_core->catalogue->get_current('Title_Sub_ID');
$rules_sub = $nc_core->catalogue->get_current('Rules_Sub_ID');
$page_not_found = false;


// ------- Определение цели запроса (раздела/инфоблока/объекта/скрипта) --------

if ($nc_core->url->get_parsed_url('path') === '/') {
    // Путь к главной странице: без участия модуля маршрутизации
    $sub = $title_sub;
    $nc_core->subdivision->set_current_by_id($sub);
    $cc_in_sub = $nc_core->sub_class->get_by_subdivision_id($sub);
    foreach ((array)$cc_in_sub as $row) {
        if ($row['Checked'] || $row['sysTbl'] == 3) {
            $cc_array[] = $row['Sub_Class_ID'];
        }
    }
    if (count($cc_array)) {
        $cc = $_db_cc = $cc_array[0];
    }
} else {
    $routing_result = nc_resolve_url($nc_core->url, $_SERVER['REQUEST_METHOD']);

    // принятие решения о необходимости переадресации или добавления канонического адреса
    // (только при включённом модуле маршрутизации)
    if (is_array($routing_result) && nc_module_check_by_keyword('routing')) {
        $routing_duplicate_route_action = nc_routing::get_setting('DuplicateRouteAction', $catalogue);
        if ($routing_duplicate_route_action != nc_routing::DUPLICATE_ROUTE_NO_ACTION) {
            // попробуем получить путь, соответствующий полученным параметрам
            $routing_canonical_request = $routing_result;

            $routing_result_variables = nc_array_value($routing_result, 'variables', array());
            if ($routing_result_variables) {
                // подходящий маршрут должен содержать эти переменные в «дополнительных переменных» (query_variables)
                $routing_canonical_request['route_variables'] = $routing_result_variables;
            }

            // добавим GET-переменные, если они есть
            if ($nc_core->input->fetch_get()) {
                $routing_canonical_request['variables'] = array_merge((array)$nc_core->input->fetch_get(), $routing_result_variables);
            }
            unset($routing_canonical_request['variables']['REQUEST_URI']);

            if ($routing_canonical_request['resource_type'] === 'object') {
                // Для объектов в качестве основного пути могут использоваться пути без даты
                $routing_canonical_request['date_is_optional'] = true;

                // Для объектов проверить наличие и значение поля типа event/event_date
                if (!$routing_canonical_request['date']) {
                    try {
                        $routing_object_component_id = (int)$nc_core->sub_class->get_by_id($routing_canonical_request['infoblock_id'], 'Class_ID');
                        $routing_object_date_field = $nc_core->get_component($routing_object_component_id)->get_date_field();
                        if ($routing_object_date_field) {
                            $routing_canonical_request['date'] = $db->get_var(
                                "SELECT DATE_FORMAT(`$routing_object_date_field`, '%Y-%m-%d')
                                 FROM `Message$routing_object_component_id`
                                 WHERE `Message_ID` = " . (int)$routing_canonical_request['object_id']
                            );
                        }
                    }
                    catch (Exception $e) {}
                }
            }

            $routing_canonical_path = (string)nc_routing::get_resource_path($routing_canonical_request['resource_type'], $routing_canonical_request);

            if (parse_url($routing_canonical_path, PHP_URL_PATH) !== $nc_core->SUB_FOLDER . $nc_core->url->get_parsed_url('path')) {
                // найден альтернативный путь
                if ($routing_duplicate_route_action == nc_routing::DUPLICATE_ROUTE_REDIRECT) {
                    $routing_result['redirect_to_url'] = $routing_canonical_path;
                } else {
                    $nc_core->page->set_canonical_link($routing_canonical_path);
                }
            }

            unset($routing_canonical_path, $routing_canonical_request, $routing_duplicate_route_action, $routing_object_component_id, $routing_object_date_field);
        }
    }

    if ($routing_result === false) {
        // Страница не найдена
        $page_not_found = true;
    } elseif (isset($routing_result['redirect_to_url']) && $routing_result['redirect_to_url']) {
        // Нужна переадресация на «правильный» адрес
        $redirect_to_url = $routing_result['redirect_to_url'];
    } elseif (is_array($routing_result)) {
        // Найден подходящий ресурс. Устанавливаем переменные, необходимые
        // для дальнейшей работы.

        // извлечение переменных в глобальную область видимости, добавление в $_GET и input
        if ($routing_result['variables']) {
            $routing_result['variables'] = $nc_core->input->clear_system_vars($routing_result['variables']);

            if (isset($nc_core->security) && $nc_core->SECURITY_XSS_CLEAN) {
                $routing_result['variables'] = array_map_recursive(array($nc_core->security, 'xss_clean'), $routing_result['variables']);
            }

            foreach ($routing_result['variables'] as $_key => $_value) {
                $nc_core->input->set('_GET', $_key, $_value);
                $_GET[$_key] = $_value;
                $$_key = $_value;
            }

            $nc_core->url->set_parsed_url_item('query',
                $nc_core->url->get_parsed_url('query') .
                '&' .
                http_build_query($routing_result['variables'], null, '&')
            );
        }

        // альтернативный адрес скрипта
        if ($routing_result['resource_type'] === 'script') {
            $script_file_name = $nc_core->DOCUMENT_ROOT . '/' . $nc_core->SUB_FOLDER . $routing_result['script_path'];

            if (file_exists($script_file_name)) {
                // значения некоторых переменных могли быть изменены в процессе выполнения скрипта
                define('NC_SCRIPT_FILE_NAME', $script_file_name);
                extract($nc_core->input->prepare_extract(), EXTR_OVERWRITE);
                require NC_SCRIPT_FILE_NAME;
                exit;
            }

            $page_not_found = true;
        } else {
            // инфоблок или объект: установка глобальных переменных
            // тип страницы
            $nc_core->set_page_type($routing_result['format']);

            // информация о разделе
            $sub = $routing_result['folder_id'];
            $nc_core->subdivision->set_current_by_id($sub);

            // информация об инфоблоках в разделе
            $cc_in_sub = $nc_core->sub_class->get_by_subdivision_id($sub);
            foreach ((array)$cc_in_sub as $row) {
                if ($row['Checked'] || $row['sysTbl'] == 3) {
                    $cc_array[] = $row['Sub_Class_ID'];
                }
            }

            // информация об инфоблоке / шаблоне отображения
            $cc = $routing_result['infoblock_id'];
            if ($cc && $routing_result['format'] !== 'html') {
                $cc = $nc_core->sub_class->get_by_id($cc, 'Sub_Class_ID', 0, false, $routing_result['format']);
            }

            $nc_core->sub_class->set_current_by_id($cc);

            $_db_cc = $cc;

            if ($cc) {
                if ($routing_result['resource_type'] !== 'folder') {
                    $cc_keyword = $nc_core->sub_class->get_by_id($cc, 'EnglishName');
                }

                if (!isset($isNaked)) {
                    $isNaked = $nc_core->sub_class->get_by_id($cc, 'isNaked');
                }

                // информация о компоненте
                $classID = $nc_core->sub_class->get_current('Class_ID');
                $system_table = $nc_core->sub_class->get_current('sysTbl');
                $system_table_mode = (bool)$system_table;
            }

            // дата
            $date = $routing_result['date'];

            // действие (определяет подключаемый скрипт)
            if (!isset($action)) { // $action может быть задан в виде переменной
                $action = $routing_result['action'];
            }
        }

        // объект компонента:
        if ($routing_result['resource_type'] === 'object') {
            // информация об объекте
            $message = $routing_result['object_id'];

            switch ($action) {
                case 'full': break;
                case 'subscribe': break;
                case 'edit':    $action = 'message'; break;
                case 'checked': $action = 'message'; $posting = 1; $checked = 1; break;
                case 'delete':  $action = 'message'; $posting = 0; $delete = 1; break;
                case 'drop':    $action = 'message'; $posting = 1; $delete = 1; break;
            }
        }

    }

    $nc_core->page->set_routing_result($routing_result);
    unset($routing_result);
}

$use_multi_sub_class = $sub ? $nc_core->subdivision->get_by_id($sub, 'UseMultiSubClass') : false;

// ---------- Действия в зависимости от результата разбора адреса  -------------

// *** Редирект ***
if ($redirect_to_url && $e404_sub != $sub && in_array($_SERVER['REQUEST_METHOD'], array('GET', 'HEAD'), true)) {
    if ($nc_core->REDIRECT_STATUS === 'on') {
        if ($nc_core->AUTHORIZATION_TYPE === 'session') {
            $redirect_to_url .= strpos($redirect_to_url, '?') ? '&' : '?';
            $redirect_to_url .= session_name() . '=' . session_id();
        }
        header('Location: ' . $redirect_to_url, true, 301);
        exit;
    }
}

// старый способ работы с настройками модулей
$MODULE_VARS = $nc_core->modules->load_env();

// *** Подключение файла для обработки выбранного действия с инфоблоком или объектом ***

// Front user mode
if (!in_array($action, array('index', 'full', 'add', 'search', 'subscribe', 'message'), true)) {
    $action = 'index';
}

if (nc_module_check_by_keyword('auth')) {
    $modify_subs = $nc_core->get_settings('modify_sub', 'auth') ?: '';
} else {
    $modify_subs = '';
}

if ($cc && in_array($sub, nc_preg_split("/\s*,\s*/", $modify_subs))) {
    $action = 'message';
    $user_table_mode = true;
}

if (!$sub || ($sub == $e404_sub && $title_sub != $sub)) {
    $page_not_found = true;
}

// Логирование 404 добавлено 03.02.2026 usp
function nc_log_404_error($url) {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $is_bot = false;
    $is_attack = false;
    $log_type = 'human';
    
    // Определяем ботов
    if (preg_match('/bot|crawler|spider|semrush|dotbot|ahrefs|mj12|scanner/i', $user_agent)) {
        $is_bot = true;
        $log_type = 'bot';
    }
    
    $allowed_domains = ['plat-forma.com'];
    
    $parsed_url = parse_url($url);
    $url_path = $parsed_url['path'] ?? '';
    $url_domain = isset($parsed_url['host']) ? strtolower($parsed_url['host']) : '';
    
    if ($url_domain) {
        $domain_allowed = false;
        foreach ($allowed_domains as $allowed) {
            if (strtolower($allowed) === $url_domain) {
                $domain_allowed = true;
                break;
            }
        }
        if (!$domain_allowed) {
            $is_attack = true;
            $log_type = 'attack';
        }
    }
    
    // Паттерны атак
    $attack_patterns = [
        '/\.(php|asp|aspx|jsp|pl|py|sh|cgi|env|key|secret|credential|tfstate|yml|yaml|json|sql|zip|bak|old|save|log|txt)$/i',
        '/(admin|administrator|wp-admin|wp-login|xmlrpc|phpinfo|_profiler|server-info|debug)/i',
        '/(\.git|\.env|\.htaccess|config|database|backup|\.aws|\.terraform|secrets?|credentials?|storage\/)/i',
        '/(joomla|wordpress|drupal|prestashop|moodle|opencart|bitrix|wlwmanifest\.xml)/i',
        '/(vendor|node_modules|composer|package|package\.json|server\.js|app\.js|main\.js|\.chunk\.js|\.map\.js)/i',
        '/(uploads|cache|tmp|temp|logs|docker|Makefile|Gemfile|requirements\.txt)/i',
        '/(shell|webshell|cmd|command|exec|SELECT|UNION|EXTRACTVALUE|CAST|CHR|CONCAT|0x7e|--|\')/i',
    ];
    
    foreach ($attack_patterns as $pattern) {
        if (preg_match($pattern, $url_path) || preg_match($pattern, $url)) {
            $is_attack = true;
            $log_type = 'attack';
            break;
        }
    }
    
    $cms_attack_paths = [
        '/templates/', '/modules/', '/plugins/', '/components/', '/libraries/',
        '/themes/', '/wp-content/', '/administrator/', '/vendor/', '/admin/',
        '/bk/', '/local/', '/rec/', '/sites/', '/assets/', '/akcc.php',
        '/rip.php', '/about.php', '/admin.php', '/goods.php', '/222.php',
        '/xmlrpc.php', '/wp-login.php', '/phpinfo', '/_profiler/', '/debug/',
        '/.aws/', '/.git/', '/.terraform/', '/secrets/', '/credentials/',
    ];
    
    foreach ($cms_attack_paths as $attack_path) {
        if (strpos($url_path, $attack_path) === 0) {
            $is_attack = true;
            $log_type = 'attack';
            break;
        }
    }
    
    $log_dir = $GLOBALS['nc_core']->FILES_FOLDER . '_logs/';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    // Gbiем в БД
    try {
        $nc_core = nc_Core::get_object();
        $db = $nc_core->db;
        
        $sql = sprintf(
            "INSERT INTO `NC_404_Logs` 
            (`URL`, `IP`, `UserAgent`, `Referer`, `LogType`) 
            VALUES ('%s', '%s', '%s', '%s', '%s')",
            mysqli_real_escape_string($db->dbh, $url),
            mysqli_real_escape_string($db->dbh, $ip),
            mysqli_real_escape_string($db->dbh, substr($user_agent, 0, 1000)),
            mysqli_real_escape_string($db->dbh, $_SERVER['HTTP_REFERER'] ?? ''),
            mysqli_real_escape_string($db->dbh, $log_type)
        );
        
        $db->query($sql);
    } catch (Exception $e) {
        error_log("404 DB Error: " . $e->getMessage());
    }
    
    // Пишем в файл
    if ($is_attack || $is_bot) {
        $attack_log_file = $log_dir . 'attacks_' . date('Y-m') . '.log';
        $attack_type = $is_bot ? 'BOT' : 'ATTACK';
        
        $attack_entry = sprintf(
            "[%s] %s | IP: %s | Type: %s | UA: %s\n",
            date('Y-m-d H:i:s'), $url, $ip, $attack_type, substr($user_agent, 0, 100)
        );
        @file_put_contents($attack_log_file, $attack_entry, FILE_APPEND | LOCK_EX);
    } else {
        $log_file = $log_dir . '404_errors_' . date('Y-m') . '.log';
        $short_log_file = $log_dir . 'short_404_errors_' . date('Y-m') . '.log';
        
        $log_entry = sprintf(
            "[%s] %s | IP: %s | Referer: %s | UA: %s\n",
            date('Y-m-d H:i:s'), $url, $ip, $_SERVER['HTTP_REFERER'] ?? 'none', substr($user_agent, 0, 100)
        );
        
        $short_log_entry = sprintf(
            "[%s] %s\n",
            date('Y-m-d H:i:s'), $url
        );
        
        @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        @file_put_contents($short_log_file, $short_log_entry, FILE_APPEND | LOCK_EX);
    }
    
    // Блокируем зловредов в .htaccess (только атаки, ботов тупо не пускаем)
    if ($is_attack || $is_bot) {
        if ($is_attack) {
            $htaccess = $GLOBALS['nc_core']->DOCUMENT_ROOT . '/.htaccess';
            if (file_exists($htaccess)) {
                $content = @file_get_contents($htaccess);
                if (strpos($content, "Deny from $ip") === false) {
                    $block_rule = "\n# Auto-block " . date('Y-m-d H:i:s') . "\nDeny from $ip\n";
                    @file_put_contents($htaccess, $block_rule, FILE_APPEND);
                }
            }
        }
        
        // Сразу отдаем 403
        header('HTTP/1.0 403 Forbidden');
        echo '<h1>403 Access Denied</h1>';
        exit;
    }
}

if ($page_not_found && !$nc_is_async_partial_request) {
    // Логирование 404 ошибки
    $url = $nc_core->url->source_url();
    nc_log_404_error($url);
    
    // Отправка страницы 404
    nc_send_page_404();
} else {
    // 200 OK
    nc_set_http_response_code(200);
    header('Content-Type: ' . $nc_core->get_content_type());
}

// Предусмотрены следующие «штатные» случаи обработки запросов методом HEAD:
// 1) Модуль поиска методом HEAD проверяет ссылки — для ускорения заканчиваем работу здесь,
//    не генерируя страницу.
// 2) Сеошников очень сильно волнует результат проверки сервисами типа last-modified.com,
//    которые почему-то могут запросить страницу методом HEAD. Чтобы посчитать для них значение
//    заголовка Last-Modified, нам нужно будет собрать всю страницу.
if (
    $_SERVER['REQUEST_METHOD'] === 'HEAD' &&
    class_exists('nc_search', false) &&
    $_SERVER['HTTP_USER_AGENT'] === nc_search::get_setting('CrawlerUserAgent')
) {
    exit;
}

$passed_thru_404 = true;
if (!$nc_is_async_partial_request) {
    require $nc_core->ROOT_FOLDER . $action . '.php';
}