<?php
// Конфигурация бота
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');
define('ADMIN_CHAT_ID', 'YOUR_ADMIN_CHAT_ID_HERE');
define('DB_PATH', __DIR__ . '/chat_widget.db');
define('BASE_URL', 'https://your-domain.com/telegram-widget/api/');

// Функция для отправки запросов к Telegram API
function sendTelegramRequest($method, $data = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data)
        ]
    ];
    
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    return json_decode($result, true);
}

// CORS заголовки для API
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
}
?>