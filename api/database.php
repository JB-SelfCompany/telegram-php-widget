<?php
require_once 'config.php';

class Database {
    public $pdo; // Делаем публичным для доступа из bot.php
    
    public function __construct() {
        $this->initDatabase();
    }
    
    private function initDatabase() {
        try {
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->createTables();
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    private function createTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS chats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT UNIQUE NOT NULL,
            client_name TEXT NOT NULL,
            client_phone TEXT NOT NULL,
            status TEXT DEFAULT 'waiting',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id INTEGER NOT NULL,
            sender_type TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chat_id) REFERENCES chats (id)
        );
        
        CREATE TABLE IF NOT EXISTS admin_chat_mapping (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_chat_id TEXT NOT NULL,
            chat_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chat_id) REFERENCES chats (id)
        );
        
        CREATE INDEX IF NOT EXISTS idx_session_id ON chats(session_id);
        CREATE INDEX IF NOT EXISTS idx_chat_messages ON messages(chat_id);
        CREATE INDEX IF NOT EXISTS idx_admin_mapping ON admin_chat_mapping(admin_chat_id);
        ";
        
        $this->pdo->exec($sql);
    }
    
    public function createChat($sessionId, $name, $phone) {
        $stmt = $this->pdo->prepare("
            INSERT INTO chats (session_id, client_name, client_phone) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$sessionId, $name, $phone]);
        return $this->pdo->lastInsertId();
    }
    
    public function getChatBySession($sessionId) {
        $stmt = $this->pdo->prepare("SELECT * FROM chats WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function updateChatStatus($chatId, $status) {
        $stmt = $this->pdo->prepare("
            UPDATE chats SET status = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$status, $chatId]);
    }
    
    public function addMessage($chatId, $senderType, $message) {
        $stmt = $this->pdo->prepare("
            INSERT INTO messages (chat_id, sender_type, message) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$chatId, $senderType, $message]);
        
        // Обновляем время последней активности чата только для клиентских сообщений
        if ($senderType === 'client') {
            $this->updateChatStatus($chatId, 'active');
        }
        
        return $this->pdo->lastInsertId();
    }
    
    public function getMessages($chatId, $lastMessageId = 0) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM messages 
            WHERE chat_id = ? AND id > ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$chatId, $lastMessageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function closeChat($chatId) {
        $this->updateChatStatus($chatId, 'closed');
    }
}
?>