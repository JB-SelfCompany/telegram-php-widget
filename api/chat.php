<?php
require_once 'config.php';
require_once 'database.php';
require_once 'bot.php';

setCorsHeaders();

$db = new Database();
$bot = new TelegramBot();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'POST':
        if ($action === 'start') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data || !$data['name'] || !$data['phone']) {
                http_response_code(400);
                echo json_encode(['error' => 'Name and phone required']);
                exit;
            }
            
            $sessionId = uniqid();
            $chatId = $db->createChat($sessionId, $data['name'], $data['phone']);
            
            // Уведомляем админа
            $chatData = $db->getChatBySession($sessionId);
            $chatData['id'] = $chatId;
            $bot->notifyAdminNewChat($chatData);
            
            echo json_encode([
                'status' => 'success',
                'session_id' => $sessionId,
                'chat_id' => $chatId
            ]);
        }
        
        if ($action === 'send') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data || !$data['session_id'] || !$data['message']) {
                http_response_code(400);
                echo json_encode(['error' => 'Session ID and message required']);
                exit;
            }
            
            $chat = $db->getChatBySession($data['session_id']);
            if (!$chat) {
                http_response_code(404);
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }
            
            $messageId = $db->addMessage($chat['id'], 'client', $data['message']);
            
            // Уведомляем админа о новом сообщении
            $notificationText = "💬 Новое сообщение в чате #{$chat['id']} от {$chat['client_name']}:\n\n{$data['message']}";
            sendTelegramRequest('sendMessage', [
                'chat_id' => ADMIN_CHAT_ID,
                'text' => $notificationText
            ]);
            
            echo json_encode(['status' => 'success', 'message_id' => $messageId]);
        }
        break;
        
    case 'GET':
        if ($action === 'messages') {
            $sessionId = $_GET['session_id'] ?? '';
            $lastMessageId = (int)($_GET['last_message_id'] ?? 0);
            
            if (!$sessionId) {
                http_response_code(400);
                echo json_encode(['error' => 'Session ID required']);
                exit;
            }
            
            $chat = $db->getChatBySession($sessionId);
            if (!$chat) {
                http_response_code(404);
                echo json_encode(['error' => 'Chat not found']);
                exit;
            }
            
            $messages = $db->getMessages($chat['id'], $lastMessageId);
            
            echo json_encode([
                'status' => 'success',
                'messages' => $messages,
                'chat_status' => $chat['status']
            ]);
        }
        break;
}
?>