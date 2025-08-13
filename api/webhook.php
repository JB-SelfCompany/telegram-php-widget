<?php
require_once 'config.php';
require_once 'database.php';
require_once 'bot.php';

// Логирование для отладки
function logMessage($message) {
    error_log("[Telegram Webhook] " . $message);
}

setCorsHeaders();

// Получаем данные от Telegram
$input = file_get_contents('php://input');
logMessage("Received data: " . $input);

$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    logMessage("Invalid JSON received");
    exit('Invalid JSON');
}

$bot = new TelegramBot();

// Обрабатываем сообщение
if (isset($data['message'])) {
    $message = $data['message'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $fromUser = $message['from']['username'] ?? $message['from']['first_name'] ?? 'Unknown';
    
    logMessage("Message from {$fromUser} (Chat ID: {$chatId}): {$text}");
    
    // Проверяем, это команда?
    if (strpos($text, '/') === 0) {
        logMessage("Processing command: {$text}");
        $result = $bot->handleCommand($text, $chatId);
        logMessage("Command result: " . ($result ? 'success' : 'failed'));
    } else {
        // Это обычное сообщение
        if ($chatId == ADMIN_CHAT_ID) {
            logMessage("Forwarding admin message to client");
            $bot->forwardMessageToClient($text, $chatId);
        } else {
            logMessage("Message from non-admin user ignored");
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
?>