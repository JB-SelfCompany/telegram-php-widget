class TelegramChatWidget {
    constructor(options = {}) {
        this.options = {
            apiUrl: options.apiUrl || 'https://your-domain.com/widget/api/chat.php',
            position: options.position || 'bottom-right',
            theme: options.theme || 'blue',
            pollInterval: options.pollInterval || 2000,
            ...options
        };
        
        this.sessionId = null;
        this.chatId = null;
        this.lastMessageId = 0;
        this.isOpen = false;
        this.pollTimer = null;
        
        this.init();
    }
    
    init() {
        this.createStyles();
        this.createWidget();
        this.attachEvents();
    }
    
    createStyles() {
        if (document.getElementById('telegram-widget-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'telegram-widget-styles';
        style.textContent = `
            :root {
                --primary-blue: #1a73e8;
                --dark-blue: #0d47a1;
                --light-blue: #e3f2fd;
                --accent-orange: #ff6d00;
                --light-gray: #f5f5f5;
                --dark-gray: #333;
                --white: #ffffff;
                --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }

            .telegram-widget {
                position: fixed;
                ${this.options.position.includes('right') ? 'right: 20px;' : 'left: 20px;'}
                ${this.options.position.includes('bottom') ? 'bottom: 20px;' : 'top: 20px;'}
                width: 350px;
                height: 500px; /* Фиксированная высота */
                background: var(--white);
                border-radius: 15px;
                box-shadow: var(--shadow);
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                z-index: 10000;
                transition: all 0.3s ease;
                transform: translateY(100%);
                opacity: 0;
                display: flex;
                flex-direction: column;
                overflow: hidden; /* Предотвращаем внешнюю прокрутку */
            }
            
            .telegram-widget.open {
                transform: translateY(0);
                opacity: 1;
            }
            
            .telegram-widget-trigger {
                position: fixed;
                ${this.options.position.includes('right') ? 'right: 20px;' : 'left: 20px;'}
                ${this.options.position.includes('bottom') ? 'bottom: 20px;' : 'top: 20px;'}
                width: 60px;
                height: 60px;
                background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue));
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                box-shadow: var(--shadow);
                z-index: 10001;
                transition: all 0.3s ease;
            }
            
            .telegram-widget-trigger:hover {
                transform: scale(1.1);
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            }
            
            .telegram-widget-header {
                background: linear-gradient(90deg, var(--primary-blue), var(--dark-blue));
                color: var(--white);
                padding: 20px;
                border-radius: 15px 15px 0 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-shrink: 0; /* Не сжимается */
            }

            .telegram-widget-header h3 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
            }
            
            .telegram-widget-close {
                background: none;
                border: none;
                color: var(--white);
                font-size: 24px;
                cursor: pointer;
                width: 30px;
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                transition: all 0.3s ease;
            }
            
            .telegram-widget-close:hover {
                background: rgba(255, 255, 255, 0.2);
                transform: rotate(90deg);
            }
            
            .telegram-widget-content {
                flex: 1;
                display: flex;
                flex-direction: column;
                padding: 0;
                overflow: hidden; /* Убираем прокрутку контента */
            }
            
            /* ИСПРАВЛЕНИЕ 1: Стили формы с правильным позиционированием */
            .telegram-widget-form {
                display: flex;
                flex-direction: column;
                gap: 15px;
                padding: 25px;
                height: 100%;
                justify-content: center; /* Центрирование по вертикали */
                align-items: stretch; /* Растягивание элементов */
            }

            .telegram-widget-form.hidden {
                display: none;
            }
            
            .telegram-widget-input {
                padding: 15px;
                border: 2px solid #e1e5e9;
                border-radius: 10px;
                font-size: 16px;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                transition: all 0.3s ease;
                width: 100%;
                box-sizing: border-box;
            }
            
            .telegram-widget-input:focus {
                outline: none;
                border-color: var(--primary-blue);
                box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
            }
            
            .telegram-widget-btn {
                background: linear-gradient(90deg, var(--primary-blue), var(--dark-blue));
                color: var(--white);
                border: none;
                padding: 16px 20px;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                margin-top: 10px;
            }
            
            .telegram-widget-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(26, 115, 232, 0.4);
            }
            
            .telegram-widget-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
                transform: none;
            }
            
            /* ИСПРАВЛЕНИЕ 2: Правильная структура чата без лишней прокрутки */
            .telegram-widget-chat {
                display: none;
                flex-direction: column;
                height: 100%;
                overflow: hidden;
            }

            .telegram-widget-chat.active {
                display: flex;
            }
            
            .telegram-widget-status {
                text-align: center;
                padding: 15px;
                font-size: 14px;
                font-weight: 500;
                background: linear-gradient(135deg, var(--light-blue), var(--white));
                color: var(--dark-blue);
                border-bottom: 1px solid #e1e5e9;
                flex-shrink: 0;
            }
            
            /* ИСПРАВЛЕНИЕ 2: Область сообщений с правильной прокруткой */
            .telegram-widget-messages {
                flex: 1;
                overflow-y: auto; /* Прокрутка только здесь */
                padding: 15px;
                display: flex;
                flex-direction: column;
                gap: 12px;
                background: #f8f9fa;
            }

            /* Стилизация скроллбара */
            .telegram-widget-messages::-webkit-scrollbar {
                width: 6px;
            }

            .telegram-widget-messages::-webkit-scrollbar-track {
                background: rgba(0, 0, 0, 0.1);
                border-radius: 3px;
            }

            .telegram-widget-messages::-webkit-scrollbar-thumb {
                background: var(--primary-blue);
                border-radius: 3px;
            }

            .telegram-widget-messages::-webkit-scrollbar-thumb:hover {
                background: var(--dark-blue);
            }
            
            .telegram-widget-message {
                max-width: 80%;
                padding: 12px 16px;
                border-radius: 15px;
                font-size: 14px;
                line-height: 1.4;
                word-wrap: break-word;
                position: relative;
                animation: messageSlide 0.3s ease-out;
            }

            @keyframes messageSlide {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .telegram-widget-message.client {
                background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue));
                color: var(--white);
                align-self: flex-end;
                border-bottom-right-radius: 5px;
            }
            
            .telegram-widget-message.admin {
                background: var(--white);
                color: var(--dark-gray);
                align-self: flex-start;
                border-bottom-left-radius: 5px;
                border: 1px solid #e1e5e9;
            }

            .telegram-widget-message.system {
                background: linear-gradient(135deg, var(--light-blue), var(--white));
                color: var(--dark-blue);
                align-self: center;
                text-align: center;
                font-size: 12px;
                font-style: italic;
                border-radius: 20px;
                max-width: 90%;
            }
            
            /* ИСПРАВЛЕНИЕ 2: Область ввода без прокрутки */
            .telegram-widget-input-area {
                display: flex;
                gap: 10px;
                padding: 15px;
                border-top: 1px solid #e1e5e9;
                background: var(--white);
                flex-shrink: 0; /* Не сжимается */
                align-items: flex-end;
            }
            
            .telegram-widget-message-input {
                flex: 1;
                padding: 12px 16px;
                border: 2px solid #e1e5e9;
                border-radius: 20px;
                resize: none;
                font-size: 14px;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                min-height: 20px;
                max-height: 80px;
                overflow-y: auto;
                transition: border-color 0.3s ease;
                outline: none;
            }

            .telegram-widget-message-input:focus {
                border-color: var(--primary-blue);
            }
            
            .telegram-widget-send-btn {
                background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue));
                border: none;
                border-radius: 50%;
                width: 45px;
                height: 45px;
                color: var(--white);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s ease;
                flex-shrink: 0;
            }
            
            .telegram-widget-send-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(26, 115, 232, 0.4);
            }

            .telegram-widget-send-btn:active {
                transform: translateY(0);
            }

            /* Адаптивность */
            @media (max-width: 480px) {
                .telegram-widget {
                    width: calc(100vw - 40px);
                    height: 80vh;
                    right: 20px;
                    left: 20px;
                }
            }
        `;
        
        document.head.appendChild(style);
    }
    
    createWidget() {
        // Создаем триггер кнопку
        this.trigger = document.createElement('div');
        this.trigger.className = 'telegram-widget-trigger';
        this.trigger.innerHTML = `
            <svg width="28" height="28" viewBox="0 0 24 24" fill="white">
                <path d="M20,2H4A2,2 0 0,0 2,4V22L6,18H20A2,2 0 0,0 22,16V4A2,2 0 0,0 20,2M6,9V7H18V9H6M14,11V13H6V11H14M16,15V17H6V15H16Z"/>
            </svg>
        `;
        
        // Создаем виджет
        this.widget = document.createElement('div');
        this.widget.className = 'telegram-widget';
        this.widget.innerHTML = `
            <div class="telegram-widget-header">
                <h3>💬 Онлайн чат</h3>
                <button class="telegram-widget-close">×</button>
            </div>
            <div class="telegram-widget-content">
                <div class="telegram-widget-form">
                    <input type="text" class="telegram-widget-input" placeholder="Ваше имя" id="client-name">
                    <input type="tel" class="telegram-widget-input" placeholder="Номер телефона" id="client-phone">
                    <button class="telegram-widget-btn" id="start-chat-btn">Начать чат</button>
                </div>
                
                <div class="telegram-widget-chat">
                    <div class="telegram-widget-status">Подключаемся к оператору...</div>
                    <div class="telegram-widget-messages"></div>
                    <div class="telegram-widget-input-area">
                        <textarea class="telegram-widget-message-input" placeholder="Введите сообщение..."></textarea>
                        <button class="telegram-widget-send-btn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M2,21L23,12L2,3V10L17,12L2,14V21Z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(this.trigger);
        document.body.appendChild(this.widget);
    }
    
    // Остальные методы остаются прежними, но с важными исправлениями:
    
    showChatInterface() {
        const form = this.widget.querySelector('.telegram-widget-form');
        const chat = this.widget.querySelector('.telegram-widget-chat');
        
        // ИСПРАВЛЕНИЕ 1: Правильное скрытие/показ
        form.style.display = 'none';
        chat.style.display = 'flex';
    }
    
    // ИСПРАВЛЕНИЕ 1: Полный сброс состояния
    showFormInterface() {
        const form = this.widget.querySelector('.telegram-widget-form');
        const chat = this.widget.querySelector('.telegram-widget-chat');
        
        // Скрываем чат
        chat.style.display = 'none';
        
        // Показываем форму
        form.style.display = 'flex';
        
        // Полный сброс формы
        const nameInput = this.widget.querySelector('#client-name');
        const phoneInput = this.widget.querySelector('#client-phone');
        const startBtn = this.widget.querySelector('#start-chat-btn');
        
        nameInput.value = '';
        phoneInput.value = '';
        startBtn.disabled = false;
        startBtn.textContent = 'Начать чат';
        
        // Очистка чата
        this.widget.querySelector('.telegram-widget-messages').innerHTML = '';
        this.widget.querySelector('.telegram-widget-message-input').value = '';
        this.widget.querySelector('.telegram-widget-status').textContent = 'Подключаемся к оператору...';
        
        // Сброс переменных
        this.sessionId = null;
        this.chatId = null;
        this.lastMessageId = 0;
        
        // Остановка таймера
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }
    
    attachEvents() {
        // Открытие/закрытие виджета
        this.trigger.addEventListener('click', () => this.toggleWidget());
        this.widget.querySelector('.telegram-widget-close').addEventListener('click', () => this.closeWidget());
        
        // Начало чата
        this.widget.querySelector('#start-chat-btn').addEventListener('click', () => this.startChat());
        
        // Отправка сообщения
        const sendBtn = this.widget.querySelector('.telegram-widget-send-btn');
        const messageInput = this.widget.querySelector('.telegram-widget-message-input');
        
        sendBtn.addEventListener('click', () => this.sendMessage());
        messageInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });
        
        // Auto-resize textarea
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 80) + 'px';
        });
    }
    
    toggleWidget() {
        if (this.isOpen) {
            this.closeWidget();
        } else {
            this.openWidget();
        }
    }
    
    openWidget() {
        this.isOpen = true;
        this.widget.classList.add('open');
        this.trigger.style.display = 'none';
    }
    
    closeWidget() {
        this.isOpen = false;
        this.widget.classList.remove('open');
        this.trigger.style.display = 'flex';
        
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }
    
    async startChat() {
        const name = this.widget.querySelector('#client-name').value.trim();
        const phone = this.widget.querySelector('#client-phone').value.trim();
        
        if (!name || !phone) {
            alert('Пожалуйста, заполните все поля');
            return;
        }
        
        const btn = this.widget.querySelector('#start-chat-btn');
        btn.disabled = true;
        btn.textContent = 'Подключаемся...';
        
        try {
            const response = await fetch(`${this.options.apiUrl}?action=start`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, phone })
            });
            
            const data = await response.json();
            
            if (data.status === 'success') {
                this.sessionId = data.session_id;
                this.chatId = data.chat_id;
                this.showChatInterface();
                this.startPolling();
            } else {
                throw new Error(data.error || 'Ошибка при создании чата');
            }
        } catch (error) {
            alert('Ошибка: ' + error.message);
            btn.disabled = false;
            btn.textContent = 'Начать чат';
        }
    }
    
    async sendMessage() {
        const messageInput = this.widget.querySelector('.telegram-widget-message-input');
        const message = messageInput.value.trim();
        
        if (!message || !this.sessionId) return;
        
        messageInput.value = '';
        messageInput.style.height = 'auto';
        
        // Добавляем сообщение в интерфейс
        this.addMessageToChat('client', message);
        
        try {
            await fetch(`${this.options.apiUrl}?action=send`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: this.sessionId,
                    message: message
                })
            });
        } catch (error) {
            console.error('Ошибка отправки сообщения:', error);
        }
    }
    
    addMessageToChat(senderType, message) {
        const messagesContainer = this.widget.querySelector('.telegram-widget-messages');
        const messageEl = document.createElement('div');
        messageEl.className = `telegram-widget-message ${senderType}`;
        messageEl.textContent = message;
        
        messagesContainer.appendChild(messageEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    addSystemMessage(message) {
        const messagesContainer = this.widget.querySelector('.telegram-widget-messages');
        const messageEl = document.createElement('div');
        messageEl.className = 'telegram-widget-message system';
        messageEl.textContent = message;
        
        messagesContainer.appendChild(messageEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    startPolling() {
        this.pollTimer = setInterval(() => {
            this.checkNewMessages();
        }, this.options.pollInterval);
    }
    
    async checkNewMessages() {
        if (!this.sessionId) return;
        
        try {
            const response = await fetch(
                `${this.options.apiUrl}?action=messages&session_id=${this.sessionId}&last_message_id=${this.lastMessageId}`
            );
            const data = await response.json();
            
            if (data.status === 'success') {
                // Обновляем статус
                const statusElement = this.widget.querySelector('.telegram-widget-status');
                
                if (data.chat_status === 'waiting') {
                    statusElement.textContent = '⏳ Ожидаем оператора...';
                    statusElement.style.background = 'linear-gradient(135deg, #fff3cd, #ffeaa7)';
                    statusElement.style.color = '#856404';
                } else if (data.chat_status === 'active') {
                    statusElement.textContent = '✅ Оператор в сети';
                    statusElement.style.background = 'linear-gradient(135deg, var(--light-blue), var(--white))';
                    statusElement.style.color = 'var(--dark-blue)';
                } else if (data.chat_status === 'closed') {
                    statusElement.textContent = '❌ Чат завершен';
                    statusElement.style.background = 'linear-gradient(135deg, #f8d7da, #f5c6cb)';
                    statusElement.style.color = '#721c24';
                    
                    clearInterval(this.pollTimer);
                    setTimeout(() => {
                        this.showFormInterface();
                    }, 3000);
                    return;
                }
                
                // Добавляем новые сообщения
                data.messages.forEach(msg => {
                    if (msg.sender_type === 'admin') {
                        this.addMessageToChat('admin', msg.message);
                    } else if (msg.sender_type === 'system') {
                        this.addSystemMessage(msg.message);
                    }
                    this.lastMessageId = Math.max(this.lastMessageId, msg.id);
                });
            }
        } catch (error) {
            console.error('Ошибка получения сообщений:', error);
        }
    }
}

// Глобальная функция для инициализации виджета
window.initTelegramChatWidget = function(options) {
    return new TelegramChatWidget(options);
};