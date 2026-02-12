<?php
session_start();

$_SESSION['payment'] = False;

// = $_SESSION['code_used'];
//https://api.telegram.org/bot8489545762:AAF6cIchhO2iNRyCJtPECglVemMlTrajXBk/setWebhook?url=https://parser.f3nix.ru/romeogpt/index.php
// Токен вашего бота
$telegramToken = '8489545762:AAF6cIchhO2iNRyCJtPECglVemMlTrajXBk';
const BOT_TOKEN = '8489545762:AAF6cIchhO2iNRyCJtPECglVemMlTrajXBk';
const API_URL = 'https://api.telegram.org/bot' . BOT_TOKEN . '/';
$botToken = $telegramToken;
$apiGpt = "sk-QNgF4DfusPVPeXhIpBlCg2uPPpaPIRBt";
$address_api = "https://api.proxyapi.ru/openrouter/v1/chat/completions";
//$providerToken = '381764678:TEST:142665'; //тестовый
$providerToken = '390540012:LIVE:87870'; // боевой

function sendInvoiceTelegram($botToken, $chatId, $providerToken, $productData) {
    global $ordersFile; // Путь к orders.json

    // 1. Генерируем payload и сохраняем заказ в orders.json
    $payload = 'order_' . time() . '_' . rand(1000, 9999);


    $order = [
        'product' => $productData['title'],
        'price' => $productData['amount'],
        'currency' => 'RUB',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'user_id' => $chatId, // Для простоты: chat_id = user_id
        'chat_id' => $chatId
    ];

    // Сохраняем в orders.json
    $orders = json_decode(file_get_contents($ordersFile), true) ?? [];
    $orders[$payload] = $order;
    file_put_contents(
        $ordersFile,
        json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    // 2. Формируем параметры инвойса
    $url = 'https://api.telegram.org/bot' . $botToken . '/sendInvoice';

    $params = [
        'chat_id' => $chatId,
        'title' => $productData['title'],
        'description' => $productData['description'],
        'payload' => $payload,
        'provider_token' => $providerToken,
        'currency' => 'RUB',
        'prices' => [
            [
                'label' => $productData['label'],
                'amount' => $productData['amount']
            ]
        ],
        'start_parameter' => 'buy_' . $payload,
        'need_email' => true,
        'send_email_to_provider' => true,
        'provider_data' => array(
            'receipt' => array(
                'items' => array(
                    array(
                        'description' => $productData['description'],
                        'quantity' => 1,
                        'amount' => array(
                            'value' => $productData['amount'] / 100,
                            'currency' => 'RUB'
                        ),
                        'vat_code' => 1,
                        'payment_mode' => 'full_payment',
                        'payment_subject' => 'commodity',
                    )
                )
            )
        )
    ];

    // 3. Отправляем запрос
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Таймаут 10 сек

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 4. Обработка ответа
    if ($curlError) {
        return [
            'success' => false,
            'error' => 'Curl error: ' . $curlError,
            'payload' => $payload
        ];
    }

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => 'HTTP error: ' . $httpCode,
            'response' => $response,
            'payload' => $payload
        ];
    }

    $result = json_decode($response, true);

    if (isset($result['ok']) && $result['ok'] === true) {
        return [
            'success' => true,
            'message' => 'Счёт создан успешно!',
            'payload' => $payload,
            'telegram_response' => $result
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Telegram error: ' . ($result['description'] ?? 'Unknown error'),
            'payload' => $payload,
            'telegram_response' => $result
        ];
    }
}

// Функция для отправки сообщения в Telegram
function sendMessage($chatId, $text, $replyMarkup = null, $audio = null): void
{
    global $telegramToken;

    if(!empty($audio)) {
        $url = "https://api.telegram.org/bot$telegramToken/sendAudio";
        $data = [
            'chat_id' => $chatId,
            'audio'   => new CURLFile($audio)
        ];
    } else {

        $url = "https://api.telegram.org/bot$telegramToken/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML', // или 'HTML'
            'reply_markup' => $replyMarkup
        ];
    }
    file_get_contents($url . '?' . http_build_query($data));
}

function sendTypingStatus($chatId): void
{
    global $telegramToken;
    $url = "https://api.telegram.org/bot{$telegramToken}/sendChatAction";
    $data = [
        'chat_id' => $chatId,
        'action'  => 'typing'
    ];

    file_get_contents($url, false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data)
        ]
    ]));
}

function queryGreenApi($title, $tags, $prompt)
{
    $input = [
        'callback_url' => 'https://parser.f3nix.ru/romeogpt/udio-callback.php',  // ваш endpoint
        'title' => $title,
        'tags' => $tags,
        'prompt' => $prompt,
        'translate_input' => false,
        'model' => 'v5'
    ];

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer sk-kOynhYaAwjVYOCTqSvWrwv9ILYpL6rsZYxx0NpNRwVndBtk4Ksj4Eea4Y4g6'
    ];

    $url_endpoint = 'https://api.gen-api.ru/api/v1/networks/suno';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url_endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($input));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        die('Ошибка отправки запроса: ' . curl_error($ch));
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        die('Ошибка декодирования JSON: ' . json_last_error_msg());
    }

    if (!isset($data['request_id'])) {

        if(!is_numeric($data['error'])) {
            return $data['error'];
        }

    }

    return $data['request_id'];
}

function sendGpt($content, $style)
{
    global $apiGpt;
    global $address_api;

    $prompt = 'Представь, что ты поэт и композитор с глубоким пониманием эмоций, 
    ритма и звучания слов — твоя задача написать стихи для песни 
    в стиле '.$style.' на тему '.$content.', где каждая строка должна быть наполнена яркими образами, 
    естественным ритмом и эмоциональной дугой, идеально подходящей для вокала: 
    в поп-стиле — лёгкой, запоминающейся и мелодичной, с акцентом на чувства и повторяющийся припев; 
    в рок-стиле — дерзкой, с налётом бунтарства или глубокой рефлексии, с резкими акцентами и мощными кульминациями; 
    в балладе — лиричной, с плавным развитием и тонкими нюансами; 
    в хип-хопе — ритмичной, с чёткой дыхательной структурой и игрой слов — пиши так, 
    будто текст уже звучит в инструментальной аранжировке, избегай абстракций, 
    не используй сложные метафоры, которые невозможно спеть, сделай текст живым, 
    чтобы слушатель почувствовал его сердцем, а не разумом — стихи должны быть готовы к мелодии, а не просто к чтению.';

    // Данные для отправки
    $data = [
        //'model' => 'google/gemma-3-27b-it:free',
        'model' => 'arcee-ai/trinity-large-preview:free',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
            //['role' => 'user', 'content' => 'Напиши текст песни на тему: '.$content.' в стиле '.$style]
        ]
    ];

    // Инициализация curl
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $address_api);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Таймаут 30 сек

// Заголовки (базовые + возможные для прокси)
    $headers = [
        'Authorization: Bearer ' . $apiGpt,
        'Content-Type: application/json',
    ];

// Если прокси требует дополнительный ключ
// $headers[] = 'X-Proxy-Key: ваш_ключ_прокси';

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);


    //$res = json_decode($res, JSON_UNESCAPED_UNICODE);
    $result = json_decode($res, true);

    if(empty($result['error']['message'])) {

        // save event to log
        file_put_contents(
            'logs/queryUserGptText'.rand(1, 9999) .'.txt',
            var_export(
                [
                    'res' => $result,
                ],
                true
            )
        );

        //$content = $res['response'][0]['message']['content'];
        return $result['choices'][0]['message']['content'];


    } else {
        return $result['error']['message'];
    }

    curl_close($ch);

}

// Путь к JSON-файлу
//$dataFile = __DIR__ . '/users.json';

// Функция для загрузки состояния из JSON-файла
function loadState($chatId) {
    //__DIR__ . '/payments.json'; // Все платежи
    $filePath = __DIR__ . "/users/state_$chatId.json";
    if (file_exists($filePath)) {
        return json_decode(file_get_contents($filePath), true);
    }
    return ['step' => 1];
}

// Функция для сохранения состояния в JSON-файл
function saveState($chatId, $stateData) {
    $filePath = __DIR__ . "/users/state_$chatId.json";
    file_put_contents($filePath, json_encode($stateData));
}

/**
 * @throws Exception
 */
function getUniqueChatIdsFromJson(string $filePath): array {
    $jsonContent = file_get_contents($filePath);
    $data = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ошибка при чтении JSON: " . json_last_error_msg());
    }

    $chatIds = [];

    foreach ($data as $item) {
        $chatIds[] = $item['user_id'];
    }

    return array_values(array_unique($chatIds));
}

function getChatIdsFromStateFiles(string $directory): array {
    $chatIds = [];

    // Получаем список всех файлов в директории
    $files = scandir($directory);

    foreach ($files as $file) {
        // Проверяем, соответствует ли имя файла шаблону "state_123456789.json"
        if (preg_match('/^state_(\d+)\.json$/', $file, $matches)) {
            $chatId = (int)$matches[1]; // Извлекаем только цифры
            $chatIds[] = $chatId;
        }
    }

    // Удаляем дубликаты и возвращаем массив
    return array_values(array_unique($chatIds));
}

// Функция: ответ на pre_checkout_query
function handlePreCheckoutQuery($query) {
    global $botToken;

    // Ваша логика проверки (пример: есть ли заказ в orders.json?)
    $payload = $query['invoice_payload'];
    $orders = json_decode(file_get_contents($GLOBALS['ordersFile']), true) ?? [];

    $isValid = isset($orders[$payload]) && $orders[$payload]['status'] === 'active';


    $url = "https://api.telegram.org/bot{$botToken}/answerPreCheckoutQuery";
    $params = [
        'pre_checkout_query_id' => $query['id'],
        'ok' => $isValid
    ];

    if (!$isValid) {
        $params['error_message'] = 'Заказ недействителен или отменён.';
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);


    file_put_contents('payment_log.txt', 'PreCheckout response: ' . $response . PHP_EOL, FILE_APPEND);
}

// Функция: обработка успешного платежа
function handleSuccessfulPayment($message) {
    global $botToken, $paymentsFile, $ordersFile;

    $payment = $message['successful_payment'];
    $chatId = $message['chat']['id'];
    $payload = $payment['invoice_payload'];

    // Собираем данные платежа
    $paymentData = [
        'payload' => $payload,
        'amount' => $payment['total_amount'] / 100, // В рублях
        'currency' => $payment['currency'],
        'provider_charge_id' => $payment['provider_payment_charge_id'],
        'telegram_charge_id' => $payment['telegram_payment_charge_id'],
        'timestamp' => time(),
        'chat_id' => $chatId
    ];

    // 1. Сохраняем платёж в payments.json
    $payments = json_decode(file_get_contents($paymentsFile), true) ?? [];
    $payments[] = $paymentData;
    file_put_contents($paymentsFile, json_encode($payments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 2. Обновляем статус заказа в orders.json (если есть)
    $orders = json_decode(file_get_contents($ordersFile), true) ?? [];
    if (isset($orders[$payload])) {
        $orders[$payload]['status'] = 'paid';
        $orders[$payload]['paid_at'] = date('Y-m-d H:i:s');
        file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    $_SESSION['payment'] = True;

    // 3. Отправляем подтверждение пользователю
    sendMessage($chatId, "✅ Оплата прошла успешно!\n\n".
        "Сумма: {$paymentData['amount']} {$paymentData['currency']}\n".
        "ID платежа: {$paymentData['provider_charge_id']}\n\n".
        "Спасибо за покупку!"
    );

}

// (Опционально) простой лог
function logLine(string $s): void {
    file_put_contents(__DIR__ . '/bot.log', date('c') . ' ' . $s . PHP_EOL, FILE_APPEND);
}

function tg(string $method, array $params = []): array {
    $ch = curl_init(API_URL . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException("curl error: $err");
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("bad json: $raw");
    return $data;
}

// Пути к JSON‑файлам
$paymentsFile = __DIR__ . '/payments.json'; // Все платежи
$ordersFile = __DIR__ . '/orders.json';   // Заказы (для связи payload ↔ товар)

// Получение обновлений от Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Логирование (для отладки)
file_put_contents('payment_log.txt', date('Y-m-d H:i:s') . ' | ' . json_encode($update) . PHP_EOL, FILE_APPEND);

// Обрабатываем pre_checkout_query
if (isset($update['pre_checkout_query'])) {
    $q = $update['pre_checkout_query'];

    file_put_contents('q_log.txt', date('Y-m-d H:i:s') . ' | ' . json_encode($q) . PHP_EOL, FILE_APPEND);

    if($q['currency'] == 'XTR') {

        // Здесь можно валидировать payload/сумму/пользователя
        tg('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $q['id'],
            'ok' => true,
            // 'error_message' => 'Оплата временно недоступна', // если ok=false
        ]);

        http_response_code(200);
        exit;
    } else handlePreCheckoutQuery($update['pre_checkout_query']);
}

$message = $update['message'];
$chatId = $message['chat']['id'];
$text = $message['text'];
$userId = $message['from']['id'];
$username = $message['from']['username'] ?? 'unknown';

// Обрабатываем successful_payment
if (isset($message['successful_payment'])) {

        $p = $message['successful_payment'];

        // Для Stars: currency = "XTR"
        $currency = $p['currency'] ?? '';
        if($currency == 'XTR') {
            $total = $p['total_amount'] ?? 0; // в "минимальных единицах" Telegram для XTR
            $chargeId = $p['telegram_payment_charge_id'] ?? '';
            $payload = $p['invoice_payload'] ?? '';

            // TODO: сохраните в БД: user_id, chargeId, total, currency, payload
            logLine("PAID chat={$chatId} currency={$currency} total={$total} charge={$chargeId} payload={$payload}");

            tg('sendMessage', [
                'chat_id' => $chatId,
                'text' => "Спасибо! Платеж получен: {$total} {$currency}.",
            ]);
            $_SESSION['payment'] = True;
            http_response_code(200);
        } else handleSuccessfulPayment($update['message']);
}

if (!$update) {
    exit;
}

$buttons = [
    '📋 Создать стихи',
    '🖊 Получить песню на свои стихи',
    '🙏 Помощь проекту',
    '❤️ Оставить отзыв',
];

if($chatId == 231372338) {
    $buttons[] = 'ℹ️ Информационное сообщение';
}

// Если это первое сообщение — отправляем клавиатуру
if (loadState($chatId)['step'] === 1 && !in_array($text, $buttons)) {
    $keyboard = [
        'keyboard' => array_map(function($name) {
            return [$name];
        }, $buttons),
        'one_time_keyboard' => true,
        'resize_keyboard' => true
    ];
    sendMessage($chatId, "Привет, <b>$username!</b>\nЯ ваш бот для создание песен и стихов.\n\n<b>Выберите Ваше действие:</b>", json_encode($keyboard));
    exit;
}

global $stateData;

//seska = session_id();

// Загрузка состояния
$stateData = loadState($chatId);

if (in_array($text, $buttons)) {
    if ($text === '📋 Создать стихи') {
        sendMessage($chatId, '<b>Для создание песни нужно создать стихотворение. Какое вы дадите название песни? (Например: Осень):</b>');
        $stateData['step'] = 2;
        $stateData['user'] = $username;
        saveState($chatId, $stateData);
        exit;
    }

    if ($text === '🖊 Получить песню на свои стихи') {
        sendMessage($chatId, "<b>Выложите сюда свои стихи:</b>\nНапример:\n[Куплет 1]\nВополе береза стояла\n[Привет]\nВополе кудрявая стояла");
        $stateData['step'] = 2;
        $stateData['user'] = $username;
        saveState($chatId, $stateData);
        exit;
    }

    if ($text === '🙏 Помощь проекту') {
        sendMessage($chatId, "<b>Напишите количество звезд для пожертвования (Например: 10):</b>");
        $stateData['step'] = 8;
        $stateData['user'] = $username;
        saveState($chatId, $stateData);
        exit;
    }

    if ($text === '❤️ Оставить отзыв') {
        sendMessage($chatId, '<b>Напишите здесь ваш отзыв:</b>');
        $stateData['step'] = 7;
        $stateData['user'] = $username;
        saveState($chatId, $stateData);
        exit;
    }

    if ($text === 'ℹ️ Информационное сообщение') {
        sendMessage($chatId, '<b>Напишите ваше информационное сообщение:</b>');
        $stateData['step'] = 6;
        $stateData['user'] = $username;
        saveState($chatId, $stateData);
        exit;
    }
}

// Обработка состояний
$step = $stateData['step'];

switch ($step) {
        case 2:
            $stateData['name'] = $text; // название песни

            // стили песен
            $buttons2 = [
                ['Поп', 'Рок'],
                ['Джаз', 'Блюз'],
                ['RnB', 'Шансон'],
                ['Лирика', 'Выйти']
            ];

            $keyboard2 = [
                'keyboard' => $buttons2,
                'one_time_keyboard' => true,
                'resize_keyboard' => true
            ];

            $_SESSION['code_used'] = "Оплачено";

            sendMessage($chatId, "<b>Какой стиль песни сделать?</b>", json_encode($keyboard2));

            $stateData['step'] = 3;
            saveState($chatId, $stateData);

            break;

        case 3:
            $stateData['style'] = $text; // стиль песни

            if($stateData['style'] == 'Выйти') {
                $stateData = ['step' => 1];
                saveState($chatId, $stateData);
                sendMessage($chatId, "Чтобы начать заново нажми команду: /start");
                exit();
            }

            $name_songs = $stateData['name']; // название песни

            // 1. Получили запрос от пользователя
            sendMessage($chatId, "Обрабатываю ваш запрос. Прошу Вас подождать некоторое время...");

            // 2. Показываем, что «печатаем»
            sendTypingStatus($chatId);

            if(str_word_count($name_songs) > 6) {
                $query = $name_songs;
                $name_songs = "-";
            } else {
                $query = sendGpt($stateData['name'], $stateData['style']); // создает через ИИ стихи
            }

            // стили песен
            $buttons3 = [
                'Другой вариант',
                'Создать музыку',
                'Выйти'
            ];

            $keyboard3 = [
                'keyboard' => array_map(function($name) {
                    return [$name];
                }, $buttons3),
                'one_time_keyboard' => true,
                'resize_keyboard' => true
            ];

            $chars = ['*','#', '<', '>', '/']; // символы для удаления
            $query = str_replace($chars, '', $query);
            $stateData['lyrics'] = $query;

            sendMessage($chatId, $query, json_encode($keyboard3));

            $stateData['name'] = $name_songs;
            $stateData['step'] = 4;
            saveState($chatId, $stateData);
            
            break;

        case 4:
            $stateData['zapros'] = $text;

            $name_songs = $stateData['name'];
            $style = $stateData['style'];
            $lyrics = $stateData['lyrics'];


            if($stateData['zapros'] == 'Выйти') {
                $stateData = ['step' => 1];
                saveState($chatId, $stateData);
                sendMessage($chatId, "Чтобы начать заново нажми команду: /start");
                exit();
            }

            if($stateData['zapros'] == 'Другой вариант') {
                $stateData['step'] = 2;
                saveState($chatId, $stateData);
                sendMessage($chatId, '<b>Повторите название Вашей песни?</b>');
            }

            if($stateData['zapros'] == 'Создать музыку') {

                    $buttons4 = [
                        'Выйти'
                    ];

                    $keyboard4 = [
                        'keyboard' => array_map(function($name) {
                            return [$name];
                        }, $buttons4),
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true
                    ];

                    $productData = [
                        'title' => 'Покупка Песни',
                        'description' => 'Сгенерированная музыка',
                        'label' => 'Музыка',
                        'amount' => 30000 // 300.00 руб.30000
                    ];

                    $res_payment = sendInvoiceTelegram($botToken, $chatId, $providerToken, $productData);

                    $payload = 'donate_' . time();

                    tg('sendInvoice', [
                        'chat_id' => $chatId,
                        'title' => 'Покупка Песни',
                        'description' => 'Сгенерированная музыка',
                        'payload' => $payload,
                        'currency' => 'XTR',
                        'prices' => [
                            ['label' => 'Сгенерированная музыка', 'amount' => 50], // 50 Stars (пример)
                        ],
                        'start_parameter' => 'donate',
                    ]);

                    $pay = "<b>Заказ сформирован!</b>\n";
                    $pay .= "<b>Номер заказа:</b> ".$res_payment['payload']."\n";
                    $pay .= "Оплатить можно выше: КАРТОЙ или ЗВЕЗДАМИ"."\n\n";
                    $pay .= "<b>Пример:</b> https://parser.f3nix.ru/romeogpt/sample.mp3";

                    sendMessage($chatId, $pay, json_encode($keyboard4));

                    $stateData['step'] = 5;
                    saveState($chatId, $stateData);
            }

            break;

    case 5:
        $stateData['payment'] = $text;

        $buttons5 = [
            'Выйти'
        ];

        $keyboard5 = [
            'keyboard' => array_map(function($name) {
                return [$name];
            }, $buttons5),
            'one_time_keyboard' => true,
            'resize_keyboard' => true
        ];

        if($stateData['payment'] == 'Выйти') {
            $stateData = ['step' => 1];
            saveState($chatId, $stateData);
            sendMessage($chatId, "Чтобы начать заново нажми команду: /start");
            exit();
        }

        $name_songs = $stateData['name'];
        $style = $stateData['style'];
        $lyrics = $stateData['lyrics'];

        if($_SESSION['payment']) $data_new = queryGreenApi($name_songs, $style, $lyrics); else $data_new = 0;

        //$data_new = 38007261; // тестовый

        if(is_numeric($data_new) and $_SESSION['payment']) {

            $message_ready = "Ваш запрос на создание песни отправлен под <b>№: ".$data_new."</b>. Пожалуйста ожидайте в течении 3 минут, а потом скачивайте:\n\n";
            $message_ready .= "<b>Песня 1:</b> https://parser.f3nix.ru/romeogpt/generated_audio/audio_".$data_new."_0.mp3\n";
            $message_ready .= "<b>Песня 2:</b> https://parser.f3nix.ru/romeogpt/generated_audio/audio_".$data_new."_1.mp3";

            sendMessage($chatId, $message_ready, json_encode($keyboard5));

            $data_new = 0;
            $_SESSION['payment'] = False;

        } else  {
            if(!is_numeric($data_new)) {
                sendMessage($chatId, $data_new, json_encode($keyboard5));
            }

        }

        break;

    case 6:
        $stateData['info'] = $text;
        $new_message = $stateData['info'];

        $chatIds = getChatIdsFromStateFiles(__DIR__ . '/users/');

        foreach ($chatIds as $chatIdNew) {
            sendMessage($chatIdNew, $new_message);
        }

        // Сброс состояния
        $stateData = ['step' => 1];
        saveState($chatId, $stateData);

        break;

    case 7:
        $stateData['otziv'] = $text;

        file_put_contents('otzivs.txt', 'Отзыв от пользователя ('.$username.'): ' . $stateData['otziv'] . PHP_EOL, FILE_APPEND);

        $new_message = "Большое спасибо за Ваш отзыв!❤️\n\nЧтобы начать заново нажми команду: /start";
        sendMessage($chatId, $new_message);

        // Сброс состояния
        $stateData = ['step' => 1];
        saveState($chatId, $stateData);

        break;

    case 8:

        $stateData['donat'] = $text;

        if(is_numeric($stateData['donat'])) {

            $payload = 'donate_' . time();

            tg('sendInvoice', [
                'chat_id' => $chatId,
                'title' => 'Кинь копеечку',
                'description' => 'Помоги пожалуйста звездочкой для развития бота',
                'payload' => $payload,
                'currency' => 'XTR',
                'prices' => [
                    ['label' => 'Донат', 'amount' => $stateData['donat']], // 50 Stars (пример)
                ],
                'start_parameter' => 'donate',
            ]);

            if($stateData['donat'] >= 100) {

                $productData2 = [
                    'title' => 'Кинь копеечку',
                    'description' => 'Помоги пожалуйста монеткой для развития бота',
                    'label' => 'Донат',
                    'amount' => $stateData['donat'] * 100
                ];

                sendInvoiceTelegram($botToken, $chatId, $providerToken, $productData2);
            }
        }

        // Сброс состояния
        $stateData = ['step' => 1];
        saveState($chatId, $stateData);

        break;
}