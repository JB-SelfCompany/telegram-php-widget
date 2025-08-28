<?php
require_once 'config.php';
require_once 'database.php';

class TelegramBot {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function notifyAdminNewChat($chatData) {
        $message = "🔔 Новый чат от клиента!\n\n";
        $message .= "👤 Имя: {$chatData['client_name']}\n";
        $message .= "📞 Телефон: {$chatData['client_phone']}\n";
        $message .= "🆔 ID чата: {$chatData['id']}\n";
        $message .= "🕐 Время: " . date('d.m.Y H:i:s') . "\n\n";
        $message .= "Для принятия чата введите: /accept_{$chatData['id']}";
        
        return sendTelegramRequest('sendMessage', [
            'chat_id' => ADMIN_CHAT_ID,
            'text' => $message,
            'parse_mode' => 'HTML'
        ]);
    }
    
    public function handleCommand($command, $fromChatId) {
        error_log("Processing command: $command from chat: $fromChatId");
        
        // Обработка команды /accept_X
        if (preg_match('/^\/accept_(\d+)$/', $command, $matches)) {
            error_log("Accept command matched, chat_id: " . $matches[1]);
            
            $chatDbId = (int)$matches[1];
            
            // Находим чат по ID (не по session_id!)
            $stmt = $this->db->pdo->prepare("SELECT * FROM chats WHERE id = ?");
            $stmt->execute([$chatDbId]);
            $chat = $stmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("Chat found: " . json_encode($chat));
            
            if ($chat && $chat['status'] === 'waiting') {
                // Обновляем статус чата
                $this->db->updateChatStatus($chatDbId, 'active');
                
                // Сохраняем связь админа с чатом
                $this->setAdminChatMapping($fromChatId, $chatDbId);
                
                $message = "✅ Вы приняли чат с клиентом {$chat['client_name']}\n";
                $message .= "📱 Телефон: {$chat['client_phone']}\n";
                $message .= "💬 Теперь вы можете отвечать на сообщения клиента.\n";
                $message .= "❌ Для завершения чата: /close_{$chatDbId}";
                
                $result = sendTelegramRequest('sendMessage', [
                    'chat_id' => $fromChatId,
                    'text' => $message
                ]);
                
                error_log("Accept response sent: " . json_encode($result));
                
                // Уведомляем клиента о подключении оператора
                $this->db->addMessage($chatDbId, 'system', 'Оператор подключился к чату');
                
                return true;
            } else {
                $errorMsg = $chat ? "Чат уже принят или имеет статус: {$chat['status']}" : "Чат не найден";
                error_log("Accept failed: $errorMsg");
                
                sendTelegramRequest('sendMessage', [
                    'chat_id' => $fromChatId,
                    'text' => "❌ Чат #{$chatDbId} не найден или уже принят"
                ]);
                return false;
            }
        }
        
        // Обработка команды /close_X
        if (preg_match('/^\/close_(\d+)$/', $command, $matches)) {
            error_log("Close command matched, chat_id: " . $matches[1]);
            
            $chatDbId = (int)$matches[1];
            
            // Закрываем чат
            $this->db->closeChat($chatDbId);
            
            // Уведомляем клиента о завершении
            $this->db->addMessage($chatDbId, 'system', 'Чат завершен оператором');
            
            // Удаляем связь админа с чатом
            $this->removeAdminChatMapping($fromChatId, $chatDbId);
            
            sendTelegramRequest('sendMessage', [
                'chat_id' => $fromChatId,
                'text' => "❌ Чат #{$chatDbId} завершен"
            ]);
            
            return true;
        }
        
        // Команда не распознана
        error_log("Command not recognized: $command");
        sendTelegramRequest('sendMessage', [
            'chat_id' => $fromChatId,
            'text' => "❓ Команда не распознана: $command"
        ]);
        
        return false;
    }
    
    public function forwardMessageToClient($message, $fromChatId) {
        // Получаем активный чат для этого админа
        $activeChatId = $this->getActiveChatForAdmin($fromChatId);
        
        if ($activeChatId) {
            $this->db->addMessage($activeChatId, 'admin', $message);
            
            sendTelegramRequest('sendMessage', [
                'chat_id' => $fromChatId,
                'text' => "✅ Сообщение отправлено клиенту"
            ]);
            
            return true;
        } else {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $fromChatId,
                'text' => "❌ Нет активного чата. Примите чат командой /accept_X"
            ]);
            
            return false;
        }
    }
    
    private function setAdminChatMapping($adminChatId, $chatDbId) {
        try {
            $stmt = $this->db->pdo->prepare("
                INSERT OR REPLACE INTO admin_chat_mapping (admin_chat_id, chat_id, created_at)
                VALUES (?, ?, CURRENT_TIMESTAMP)
            ");
            $result = $stmt->execute([$adminChatId, $chatDbId]);
            
            error_log("Admin mapping set: admin=$adminChatId, chat=$chatDbId, result=" . ($result ? 'success' : 'failed'));
            
            return $result;
        } catch (PDOException $e) {
            error_log("Database error in setAdminChatMapping: " . $e->getMessage());
            return false;
        }
    }
    
    // Удаляем связь между админом и чатом
    private function removeAdminChatMapping($adminChatId, $chatDbId) {
        $stmt = $this->db->pdo->prepare("
            DELETE FROM admin_chat_mapping 
            WHERE admin_chat_id = ? AND chat_id = ?
        ");
        $stmt->execute([$adminChatId, $chatDbId]);
    }
    
    // Получаем активный чат для админа
    private function getActiveChatForAdmin($adminChatId) {
        try {
            $stmt = $this->db->pdo->prepare("
                SELECT acm.chat_id, c.status 
                FROM admin_chat_mapping acm
                JOIN chats c ON acm.chat_id = c.id
                WHERE acm.admin_chat_id = ? AND c.status = 'active'
                ORDER BY acm.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$adminChatId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("Active chat for admin $adminChatId: " . json_encode($result));
            
            return $result ? $result['chat_id'] : null;
        } catch (PDOException $e) {
            error_log("Database error in getActiveChatForAdmin: " . $e->getMessage());
            return null;
        }
    }
}
?>
