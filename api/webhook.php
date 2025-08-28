<?php
require_once 'config.php';
require_once 'database.php';
require_once 'bot.php';

setCorsHeaders();

// Функция детального логирования
function logDebug($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logData = $data ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
    file_put_contents('debug.log', "[$timestamp] $message\n$logData\n\n", FILE_APPEND);
}

// Логируем все входящие данные
$input = file_get_contents('php://input');
$headers = getallheaders();

logDebug("=== NEW WEBHOOK REQUEST ===");
logDebug("Method: " . $_SERVER['REQUEST_METHOD']);
logDebug("Headers", $headers);
logDebug("Raw input", $input);

// Проверяем, что данные пришли от Telegram
if (empty($input)) {
    logDebug("ERROR: Empty input");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty input']);
    exit;
}

$update = json_decode($input, true);
logDebug("Parsed update", $update);

if (!$update) {
    logDebug("ERROR: Invalid JSON");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// Проверяем структуру webhook'а
if (!isset($update['message'])) {
    logDebug("ERROR: No message in update");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No message']);
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'];
$text = trim($message['text'] ?? '');
$username = $message['from']['username'] ?? 'unknown';

logDebug("Processing message", [
    'chat_id' => $chatId,
    'username' => $username,
    'text' => $text,
    'admin_chat_id' => ADMIN_CHAT_ID,
    'is_admin' => ($chatId == ADMIN_CHAT_ID)
]);

try {
    $bot = new TelegramBot();
    
    if (strpos($text, '/') === 0) {
        // Это команда
        logDebug("Processing command: $text from chat: $chatId");
        
        // Проверяем, что команда пришла от админа
        if ($chatId != ADMIN_CHAT_ID) {
            logDebug("ERROR: Command from non-admin user", [
                'received_from' => $chatId,
                'expected_admin' => ADMIN_CHAT_ID
            ]);
            
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ У вас нет прав для выполнения команд"
            ]);
            
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        $result = $bot->handleCommand($text, $chatId);
        
        logDebug("Command result", [
            'command' => $text,
            'result' => $result,
            'chat_id' => $chatId
        ]);
        
    } else {
        // Это обычное сообщение
        if ($chatId == ADMIN_CHAT_ID) {
            logDebug("Forwarding admin message to client");
            $result = $bot->forwardMessageToClient($text, $chatId);
            logDebug("Forward result: " . ($result ? 'success' : 'failed'));
        } else {
            logDebug("Message from non-admin user ignored", [
                'from_chat_id' => $chatId,
                'admin_chat_id' => ADMIN_CHAT_ID
            ]);
        }
    }
    
} catch (Exception $e) {
    logDebug("ERROR: Exception caught", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

logDebug("Webhook processing completed successfully");

http_response_code(200);
echo json_encode(['status' => 'ok']);
?>