<?php
require_once '../api/config.php';
require_once '../api/database.php';

// Обработка AJAX запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'setup') {
        $botToken = trim($_POST['bot_token'] ?? '');
        $adminChatId = trim($_POST['admin_chat_id'] ?? '');
        $webhookUrl = trim($_POST['webhook_url'] ?? '');
        $baseUrl = trim($_POST['base_url'] ?? '');
        
        // Валидация входных данных
        $errors = [];
        
        if (empty($botToken)) {
            $errors[] = 'Bot Token обязателен';
        } elseif (!preg_match('/^\d+:[a-zA-Z0-9_-]+$/', $botToken)) {
            $errors[] = 'Неверный формат Bot Token';
        }
        
        if (empty($adminChatId)) {
            $errors[] = 'Admin Chat ID обязателен';
        } elseif (!is_numeric($adminChatId)) {
            $errors[] = 'Admin Chat ID должен быть числом';
        }
        
        if (empty($webhookUrl)) {
            $errors[] = 'Webhook URL обязателен';
        } elseif (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !str_starts_with($webhookUrl, 'https://')) {
            $errors[] = 'Webhook URL должен быть действительным HTTPS URL';
        }
        
        if (empty($baseUrl)) {
            $errors[] = 'Base URL обязателен';
        } elseif (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Base URL должен быть действительным URL';
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }
        
        try {
            // Проверяем доступность Telegram API
            $testUrl = "https://api.telegram.org/bot{$botToken}/getMe";
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'method' => 'GET'
                ]
            ]);
            
            $result = @file_get_contents($testUrl, false, $context);
            if ($result === false) {
                throw new Exception('Не удается подключиться к Telegram API');
            }
            
            $botInfo = json_decode($result, true);
            if (!$botInfo['ok']) {
                throw new Exception('Неверный Bot Token: ' . ($botInfo['description'] ?? 'Unknown error'));
            }
            
			$configPath = '../api/config.php';
			if (!file_exists($configPath)) {
				throw new Exception('Файл конфигурации config.php не найден');
			}
            
            // Обновление widget.js
			$widgetJsPath = '../widget.js';
			$widgetUpdated = false;

			if (file_exists($widgetJsPath)) {
				$widgetContent = file_get_contents($widgetJsPath);
				if ($widgetContent !== false) {
					// Формируем правильный API URL (всегда HTTPS)
					$protocol = 'https://';
					$currentDomain = $_SERVER['HTTP_HOST'];
					$currentPath = dirname($_SERVER['REQUEST_URI']);
					$basePath = str_replace('/install', '', $currentPath);
					$newApiUrl = $protocol . $currentDomain . $basePath . '/api/chat.php';

					$pattern = "/apiUrl:\s*options\.apiUrl\s*\|\|\s*['\"][^'\"]*['\"]/";
					$replacement = "apiUrl: options.apiUrl || '$newApiUrl'";

					$updatedContent = preg_replace($pattern, $replacement, $widgetContent);

					if ($updatedContent !== null && $updatedContent !== $widgetContent) {
						if (file_put_contents($widgetJsPath, $updatedContent) !== false) {
							$widgetUpdated = true;
						} else {
							throw new Exception('Не удается обновить файл widget.js');
						}
					}
				} else {
					throw new Exception('Не удается прочитать файл widget.js');
				}
			}
            
            // Читаем и обновляем конфигурацию
			$configContent = file_get_contents($configPath);

			$configContent = preg_replace(
				"/define\('BOT_TOKEN',\s*'[^']*'\);/",
				"define('BOT_TOKEN', '{$botToken}');",
				$configContent
			);

			$configContent = preg_replace(
				"/define\('ADMIN_CHAT_ID',\s*'[^']*'\);/",
				"define('ADMIN_CHAT_ID', '{$adminChatId}');",
				$configContent
			);

			$configContent = preg_replace(
				"/define\('BASE_URL',\s*'[^']*'\);/",
				"define('BASE_URL', '{$baseUrl}');",
				$configContent
			);

			if (file_put_contents($configPath, $configContent) === false) {
				throw new Exception('Не удается записать файл конфигурации');
			}
            
            // Устанавливаем webhook
            $webhookData = [
                'url' => $webhookUrl,
                'max_connections' => 40,
                'allowed_updates' => ['message']
            ];
            
            $webhookContext = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($webhookData),
                    'timeout' => 10
                ]
            ]);
            
            $webhookResult = @file_get_contents(
                "https://api.telegram.org/bot{$botToken}/setWebhook",
                false,
                $webhookContext
            );
            
            if ($webhookResult === false) {
                throw new Exception('Не удается установить webhook');
            }
            
            $webhookResponse = json_decode($webhookResult, true);
            if (!$webhookResponse['ok']) {
                throw new Exception('Ошибка установки webhook: ' . ($webhookResponse['description'] ?? 'Unknown error'));
            }
            
            // Инициализируем базу данных
            $database = new Database();
            
            // Отправляем тестовое сообщение админу
            $testMessage = "🎉 Telegram Chat Widget успешно установлен!\n\n";
            $testMessage .= "🤖 Бот: @{$botInfo['result']['username']}\n";
            $testMessage .= "📅 Дата установки: " . date('d.m.Y H:i:s') . "\n";
            if ($widgetUpdated && isset($newApiUrl)) {
                $testMessage .= "🔗 API URL: {$newApiUrl}\n";
            }
            $testMessage .= "\nВиджет готов к работе!";
            
            $testContext = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode([
                        'chat_id' => $adminChatId,
                        'text' => $testMessage,
                        'parse_mode' => 'HTML'
                    ]),
                    'timeout' => 10
                ]
            ]);
            
            @file_get_contents(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                false,
                $testContext
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'Установка завершена успешно!',
                'bot_info' => $botInfo['result'],
                'webhook_info' => $webhookResponse['result'] ?? null,
                'widget_updated' => $widgetUpdated,
                'api_url' => $newApiUrl ?? null
            ]);
            
        } catch (Exception $e) {
            
            echo json_encode([
                'success' => false,
                'errors' => ['Ошибка установки: ' . $e->getMessage()]
            ]);
        }
        
        exit;
    }
    
    if ($_POST['action'] === 'test_webhook') {
        $webhookUrl = trim($_POST['webhook_url'] ?? '');
        
        if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            echo json_encode(['success' => false, 'message' => 'Неверный URL']);
            exit;
        }
        
        // Проверяем доступность webhook URL
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode(['test' => true]),
                'timeout' => 5
            ]
        ]);
        
        $result = @file_get_contents($webhookUrl, false, $context);
        
        if ($result !== false) {
            echo json_encode(['success' => true, 'message' => 'Webhook доступен']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Webhook недоступен']);
        }
        
        exit;
    }
    
    if ($_POST['action'] === 'test_widget') {
        $apiUrl = trim($_POST['api_url'] ?? '');
        
        if (empty($apiUrl)) {
            echo json_encode(['success' => false, 'message' => 'API URL не указан']);
            exit;
        }
        
        // Проверяем доступность API
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);
        
        $result = @file_get_contents($apiUrl . '?test=1', false, $context);
        $httpCode = 200;
        
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (strpos($header, 'HTTP') === 0) {
                    $httpCode = (int) substr($header, 9, 3);
                    break;
                }
            }
        }
        
        if ($result !== false || $httpCode < 500) {
            echo json_encode([
                'success' => true, 
                'message' => 'API доступен',
                'http_code' => $httpCode
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'API недоступен',
                'http_code' => $httpCode
            ]);
        }
        
        exit;
    }
}

// Проверяем текущую конфигурацию
$isConfigured = false;
$currentConfig = [];

if (defined('BOT_TOKEN') && BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE') {
    $isConfigured = true;
    $currentConfig = [
        'bot_token' => BOT_TOKEN,
        'admin_chat_id' => ADMIN_CHAT_ID,
        'base_url' => BASE_URL
    ];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 Установка Telegram Chat Widget</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #0088cc 0%, #005999 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .status-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid #28a745;
        }
        
        .status-card.warning {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        
        .instructions {
            background: #e3f2fd;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid #2196f3;
        }
        
        .instructions h3 {
            color: #1976d2;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .instructions ol {
            padding-left: 20px;
        }
        
        .instructions li {
            margin-bottom: 8px;
            line-height: 1.6;
        }
        
        .form-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #495057;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #0088cc;
            box-shadow: 0 0 0 3px rgba(0,136,204,0.1);
        }
        
        .form-group .help-text {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .btn {
            background: #0088cc;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn:hover {
            background: #006699;
            transform: translateY(-1px);
        }
        
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .alert-info {
            background: #cce7ff;
            border: 1px solid #b3d9ff;
            color: #004085;
        }
        
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .code-section {
            margin: 15px 0;
        }

        .code-section h4 {
            color: #1976d2;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .code-block {
            background: #2d3748;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 15px 0;
            border: 1px solid #4a5568;
            position: relative;
        }

        .code-comment {
            color: #68d391;
            font-style: italic;
        }

        .integration-options table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin-top: 15px;
        }

        .integration-options table th {
            background: #f8f9fa !important;
            font-weight: 600;
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .integration-options table td {
            padding: 8px;
            border: 1px solid #ddd;
        }

        .integration-options table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .testing-section {
            margin-top: 25px;
            padding: 20px;
            background: #e3f2fd;
            border-radius: 8px;
        }

        .testing-section h4 {
            color: #1976d2;
            margin-bottom: 15px;
        }

        .testing-section ol {
            margin: 0;
            padding-left: 20px;
        }

        .testing-section ol li {
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .troubleshooting {
            margin-top: 20px;
            padding: 15px;
            background: #fff3cd;
            border-radius: 8px;
            border-left: 4px solid #ffc107;
        }

        .troubleshooting h4 {
            color: #856404;
        }

        .troubleshooting ul {
            margin: 10px 0;
            padding-left: 20px;
        }

        .troubleshooting ul li {
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .troubleshooting code {
            background: #f8f9fa;
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 12px;
        }

        .code-copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #4299e1;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            z-index: 1;
        }

        .code-copy-btn:hover {
            background: #3182ce;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🤖 Telegram Chat Widget</h1>
            <p>Система установки и настройки</p>
        </div>
        
        <div class="content">
            <?php if ($isConfigured): ?>
                <div class="status-card">
                    <h3>✅ Виджет уже настроен</h3>
                    <p>Система успешно сконфигурирована и готова к работе.</p>
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; font-weight: 600;">Текущие настройки</summary>
                        <div style="margin-top: 10px; font-family: monospace; background: #f8f9fa; padding: 10px; border-radius: 4px;">
                            <div>Bot Token: <?= substr($currentConfig['bot_token'], 0, 10) ?>...</div>
                            <div>Admin Chat ID: <?= $currentConfig['admin_chat_id'] ?></div>
                            <div>Base URL: <?= $currentConfig['base_url'] ?></div>
                        </div>
                    </details>
                </div>
            <?php else: ?>
                <div class="status-card warning">
                    <h3>⚙️ Требуется настройка</h3>
                    <p>Виджет не настроен. Пожалуйста, заполните форму ниже для завершения установки.</p>
                </div>
            <?php endif; ?>
            
            <div class="instructions">
                <h3>📋 Инструкции по настройке</h3>
                <ol>
                    <li><strong>Создайте Telegram бота:</strong>
                        <ul style="margin-top: 5px; margin-left: 20px;">
                            <li>Напишите <code>@BotFather</code> в Telegram</li>
                            <li>Отправьте команду <code>/newbot</code></li>
                            <li>Следуйте инструкциям и получите токен</li>
                        </ul>
                    </li>
                    <li><strong>Получите ваш Chat ID:</strong>
                        <ul style="margin-top: 5px; margin-left: 20px;">
                            <li>Напишите <code>@userinfobot</code> в Telegram</li>
                            <li>Отправьте любое сообщение</li>
                            <li>Скопируйте ваш ID из ответа</li>
                        </ul>
                    </li>
                    <li><strong>Настройте webhook URL:</strong>
                        <ul style="margin-top: 5px; margin-left: 20px;">
                            <li>Должен быть доступен по HTTPS</li>
                            <li>Указывает на файл <code>webhook.php</code></li>
                        </ul>
                    </li>
                </ol>
            </div>
            
            <div id="alertContainer"></div>
            
            <form id="setupForm" class="form-section">
                <h3 style="margin-bottom: 20px; color: #495057;">🔧 Настройки подключения</h3>
                
                <div class="form-group">
                    <label for="bot_token">🤖 Bot Token *</label>
                    <input type="text" id="bot_token" name="bot_token" 
                           placeholder="123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw" 
                           value="<?= htmlspecialchars($currentConfig['bot_token'] ?? '') ?>" required>
                    <div class="help-text">Токен получен от @BotFather</div>
                </div>
                
                <div class="form-group">
                    <label for="admin_chat_id">👤 Admin Chat ID *</label>
                    <input type="text" id="admin_chat_id" name="admin_chat_id" 
                           placeholder="123456789" 
                           value="<?= htmlspecialchars($currentConfig['admin_chat_id'] ?? '') ?>" required>
                    <div class="help-text">Ваш Telegram Chat ID для получения уведомлений</div>
                </div>
                
                <div class="form-group">
                    <label for="webhook_url">🔗 Webhook URL *</label>
                    <input type="url" id="webhook_url" name="webhook_url" 
                           placeholder="https://yourdomain.com/widget/api/webhook.php" required>
                    <div class="help-text">HTTPS URL для получения сообщений от Telegram</div>
                </div>
                
                <div class="form-group">
                    <label for="base_url">🌐 Base URL *</label>
                    <input type="url" id="base_url" name="base_url" 
                           placeholder="https://yourdomain.com/widget/api/" 
                           value="<?= htmlspecialchars($currentConfig['base_url'] ?? '') ?>" required>
                    <div class="help-text">Базовый URL для API виджета</div>
                </div>
                
                <div class="grid">
                    <button type="button" id="testWebhookBtn" class="btn btn-secondary">
                        🧪 Тест Webhook
                    </button>
                    <button type="submit" id="setupBtn" class="btn btn-success">
                        🚀 Установить виджет
                    </button>
                </div>
            </form>
            
            <div class="instructions">
                <h3>📄 Подключение к сайту</h3>
                <p>После успешной установки добавьте этот код в ваш HTML:</p>
                
                <?php
                // ОБНОВЛЕННАЯ ЛОГИКА: Принудительно используем HTTPS
                $protocol = 'https://'; // Всегда HTTPS для продакшена
                $domain = $_SERVER['HTTP_HOST'];
                $currentPath = dirname($_SERVER['REQUEST_URI']);
                $basePath = str_replace('/install', '', $currentPath);
                
                $widgetJsUrl = $protocol . $domain . $basePath . '/widget.js';
                $apiUrl = $protocol . $domain . $basePath . '/api/chat.php';
                
                // Проверяем, доступен ли HTTPS (для отладки)
                $httpsAvailable = true;
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'ignore_errors' => true
                    ]
                ]);
                
                $testResult = @file_get_contents($apiUrl, false, $context);
                if ($testResult === false) {
                    $httpsAvailable = false;
                }
                ?>
                
                <?php if (!$httpsAvailable): ?>
                <div class="alert alert-warning" style="margin-bottom: 20px;">
                    <strong>⚠️ Предупреждение:</strong> HTTPS может быть недоступен. 
                    Проверьте SSL-сертификат для домена <code><?= $domain ?></code>
                </div>
                <?php endif; ?>
                
                <div class="code-section">
                    <h4>1️⃣ Основной код для интеграции</h4>
                    <div class="code-block">
<span class="code-comment">&lt;!-- Telegram Chat Widget --&gt;</span>
&lt;script src="<?= $widgetJsUrl ?>"&gt;&lt;/script&gt;
&lt;script&gt;
    window.initTelegramChatWidget({
        apiUrl: '<?= $apiUrl ?>', <span class="code-comment">// HTTPS URL</span>
        position: 'bottom-right',  <span class="code-comment">// bottom-left, top-right, top-left</span>
        theme: 'blue',             <span class="code-comment">// blue, green, purple</span>
        pollInterval: 2000         <span class="code-comment">// проверка каждые 2 секунды</span>
    });
&lt;/script&gt;
                    </div>
                </div>
                
                <?php if (!$httpsAvailable): ?>
                <div class="code-section" style="margin-top: 20px;">
                    <h4>🔧 Альтернативный код (HTTP fallback)</h4>
                    <div class="code-block">
<span class="code-comment">&lt;!-- Для случаев когда HTTPS недоступен --&gt;</span>
&lt;script src="<?= str_replace('https://', 'http://', $widgetJsUrl) ?>"&gt;&lt;/script&gt;
&lt;script&gt;
    window.initTelegramChatWidget({
        apiUrl: '<?= str_replace('https://', 'http://', $apiUrl) ?>',
        position: 'bottom-right',
        theme: 'blue',
        pollInterval: 2000
    });
&lt;/script&gt;
                    </div>
                    <div class="alert alert-warning" style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 4px;">
                        <strong>⚠️ Внимание:</strong> HTTP-версия может не работать на HTTPS-сайтах из-за Mixed Content Policy
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="integration-options" style="margin-top: 25px;">
                    <h4>🎛️ Параметры настройки</h4>
                    <table>
                        <tr style="background: #f8f9fa;">
                            <th>Параметр</th>
                            <th>Варианты</th>
                            <th>Описание</th>
                        </tr>
                        <tr>
                            <td><code>position</code></td>
                            <td>bottom-right, bottom-left, top-right, top-left</td>
                            <td>Позиция виджета на странице</td>
                        </tr>
                        <tr>
                            <td><code>theme</code></td>
                            <td>blue, green, purple</td>
                            <td>Цветовая схема виджета</td>
                        </tr>
                        <tr>
                            <td><code>pollInterval</code></td>
                            <td>1000-5000 (мс)</td>
                            <td>Частота проверки новых сообщений</td>
                        </tr>
                    </table>
                </div>
                
                <div class="testing-section">
                    <h4>🧪 Тестирование интеграции</h4>
                    <ol>
                        <li>Скопируйте код выше и вставьте в ваш HTML файл</li>
                        <li>Откройте сайт в браузере</li>
                        <li>Найдите кнопку чата в правом нижнем углу</li>
                        <li>Протестируйте отправку сообщения</li>
                        <li>Проверьте получение уведомления в Telegram</li>
                    </ol>
                </div>
                
                <div class="troubleshooting">
                    <h4>⚠️ Возможные проблемы</h4>
                    <ul>
                        <li><strong>Виджет не появляется:</strong> Проверьте консоль браузера (F12) на наличие ошибок</li>
                        <li><strong>API недоступен:</strong> Убедитесь, что файлы загружены в папку <code>widget/</code></li>
                        <li><strong>CORS ошибки:</strong> Проверьте настройки сервера или используйте тот же домен</li>
                        <li><strong>Webhook не работает:</strong> Убедитесь, что URL доступен по HTTPS</li>
                        <li><strong>Mixed Content:</strong> Используйте HTTPS для всех ресурсов на HTTPS-сайтах</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('setupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('setupBtn');
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Установка...';
            
            const formData = new FormData(this);
            formData.append('action', 'setup');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('success', `✅ ${result.message}`);
                    
                    if (result.bot_info) {
                        showAlert('info', `🤖 Подключен бот: @${result.bot_info.username} (${result.bot_info.first_name})`);
                    }
                    
                    // НОВОЕ: Показываем информацию об обновлении виджета
                    if (result.widget_updated) {
                        showAlert('success', `📝 Виджет обновлен с новым API URL: ${result.api_url}`);
                    }
                    
                    if (result.api_url) {
                        // Обновляем отображаемые URL в инструкциях
                        updateIntegrationCode(result.api_url);
                    }
                    
                    setTimeout(() => {
                        location.reload();
                    }, 4000); // Увеличили время для чтения сообщений
                } else {
                    showAlert('danger', '❌ Ошибки установки:<br>' + result.errors.join('<br>'));
                }
                
            } catch (error) {
                showAlert('danger', '❌ Ошибка соединения: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
        
        document.getElementById('testWebhookBtn').addEventListener('click', async function() {
            const webhookUrl = document.getElementById('webhook_url').value;
            
            if (!webhookUrl) {
                showAlert('danger', '❌ Введите Webhook URL');
                return;
            }
            
            const btn = this;
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Тестирование...';
            
            const formData = new FormData();
            formData.append('action', 'test_webhook');
            formData.append('webhook_url', webhookUrl);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('success', '✅ ' + result.message);
                } else {
                    showAlert('danger', '❌ ' + result.message);
                }
                
            } catch (error) {
                showAlert('danger', '❌ Ошибка тестирования: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
        
        function showAlert(type, message) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.innerHTML = message;
            
            container.appendChild(alert);
            
            setTimeout(() => {
                alert.remove();
            }, 8000);
        }
        
        // НОВАЯ ФУНКЦИЯ: Обновление кода интеграции в реальном времени
        function updateIntegrationCode(apiUrl) {
            const codeBlocks = document.querySelectorAll('.code-block');
            
            codeBlocks.forEach(block => {
                if (block.textContent.includes('apiUrl:')) {
                    // Обновляем отображение кода с новым URL
                    const content = block.innerHTML;
                    const updatedContent = content.replace(
                        /apiUrl:\s*'[^']*'/g,
                        `apiUrl: '${apiUrl}'`
                    );
                    block.innerHTML = updatedContent;
                }
            });
        }
        
        // Добавляем кнопки копирования к блокам кода
        document.addEventListener('DOMContentLoaded', function() {
            const codeBlocks = document.querySelectorAll('.code-block');
            
            codeBlocks.forEach(function(block, index) {
                // Создаем контейнер с относительным позиционированием
                const container = document.createElement('div');
                container.style.position = 'relative';
                
                // Оборачиваем блок кода в контейнер
                block.parentNode.insertBefore(container, block);
                container.appendChild(block);
                
                // Создаем кнопку копирования
                const copyBtn = document.createElement('button');
                copyBtn.className = 'code-copy-btn';
                copyBtn.textContent = '📋 Копировать';
                
                copyBtn.addEventListener('click', function() {
                    const textArea = document.createElement('textarea');
                    textArea.value = block.textContent;
                    document.body.appendChild(textArea);
                    textArea.select();
                    
                    try {
                        document.execCommand('copy');
                        copyBtn.textContent = '✅ Скопировано!';
                        setTimeout(() => {
                            copyBtn.textContent = '📋 Копировать';
                        }, 2000);
                    } catch (err) {
                        copyBtn.textContent = '❌ Ошибка';
                        setTimeout(() => {
                            copyBtn.textContent = '📋 Копировать';
                        }, 2000);
                    }
                    
                    document.body.removeChild(textArea);
                });
                
                container.appendChild(copyBtn);
            });
            
            // Автозаполнение URL на основе текущего домена
            const baseUrlInput = document.getElementById('base_url');
            const webhookInput = document.getElementById('webhook_url');
            
            if (!baseUrlInput.value) {
                const currentDomain = window.location.origin;
                const currentPath = window.location.pathname.replace('/install/setup.php', '');
                baseUrlInput.value = currentDomain + currentPath + '/api/';
            }
            
            if (!webhookInput.value) {
                const currentDomain = window.location.origin;
                const currentPath = window.location.pathname.replace('/install/setup.php', '');
                webhookInput.value = currentDomain + currentPath + '/api/webhook.php';
            }
            
            // Принудительно используем HTTPS в автозаполнении
            if (baseUrlInput.value.startsWith('http://')) {
                baseUrlInput.value = baseUrlInput.value.replace('http://', 'https://');
            }
            
            if (webhookInput.value.startsWith('http://')) {
                webhookInput.value = webhookInput.value.replace('http://', 'https://');
            }
        });
    </script>
</body>
</html>