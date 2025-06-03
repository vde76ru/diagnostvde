<?php
/**
 * СИСТЕМА ДИАГНОСТИКИ VDestor
 * Файл: src/views/admin/diagnost.php
 */

// Проверка что файл вызван через Layout::render
if (!isset($this) && !defined('LAYOUT_RENDERING')) {
    http_response_code(403);
    die('Direct access denied');
}

try {
    // Начинаем сбор диагностики
    $startTime = microtime(true);
    $diagnostics = [];

    // 1. ИНФОРМАЦИЯ О СИСТЕМЕ
    $diagnostics['system'] = [
        'title' => '🖥️ Информация о системе',
        'data' => [
            'Hostname' => gethostname(),
            'Server IP' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
            'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'Server Time' => date('Y-m-d H:i:s'),
            'Timezone' => date_default_timezone_get(),
            'OS' => PHP_OS . ' ' . php_uname('r'),
            'PHP Version' => PHP_VERSION
        ]
    ];

    // 2. PHP КОНФИГУРАЦИЯ
    $diagnostics['php'] = [
        'title' => '🐘 PHP Конфигурация',
        'data' => [
            'Version' => PHP_VERSION,
            'Memory Limit' => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time'),
            'Post Max Size' => ini_get('post_max_size'),
            'Upload Max Filesize' => ini_get('upload_max_filesize'),
            'Current Memory Usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'Peak Memory Usage' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
        ],
        'extensions' => [
            'PDO' => extension_loaded('pdo') ? '✅' : '❌',
            'PDO MySQL' => extension_loaded('pdo_mysql') ? '✅' : '❌',
            'JSON' => extension_loaded('json') ? '✅' : '❌',
            'cURL' => extension_loaded('curl') ? '✅' : '❌',
            'Mbstring' => extension_loaded('mbstring') ? '✅' : '❌',
            'OpenSSL' => extension_loaded('openssl') ? '✅' : '❌'
        ]
    ];

    // 3. БАЗА ДАННЫХ
    try {
        if (class_exists('\App\Core\Database')) {
            $pdo = \App\Core\Database::getConnection();
            $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
            
            $diagnostics['database'] = [
                'title' => '🗄️ База данных',
                'status' => '✅ Connected',
                'data' => [
                    'Version' => $dbVersion,
                    'Connection' => 'Active'
                ]
            ];
        } else {
            throw new Exception('Database class not found');
        }
    } catch (Exception $e) {
        $diagnostics['database'] = [
            'title' => '🗄️ База данных',
            'status' => '❌ Error',
            'error' => $e->getMessage()
        ];
    }

    // 4. ФАЙЛОВАЯ СИСТЕМА
    $paths = [
        'Document Root' => $_SERVER['DOCUMENT_ROOT'],
        'Sessions' => session_save_path() ?: '/tmp'
    ];

    $fileSystemChecks = [];
    foreach ($paths as $name => $path) {
        $exists = file_exists($path);
        $writable = $exists && is_writable($path);
        $readable = $exists && is_readable($path);
        
        $fileSystemChecks[$name] = [
            'path' => $path,
            'exists' => $exists ? '✅' : '❌',
            'readable' => $readable ? '✅' : '❌',
            'writable' => $writable ? '✅' : '❌'
        ];
    }

    $diagnostics['filesystem'] = [
        'title' => '📁 Файловая система',
        'paths' => $fileSystemChecks
    ];

    // 5. СЕССИИ
    $diagnostics['sessions'] = [
        'title' => '🔐 Сессии',
        'data' => [
            'Handler' => ini_get('session.save_handler'),
            'Save Path' => session_save_path(),
            'Session ID' => session_id(),
            'Session Status' => session_status() === PHP_SESSION_ACTIVE ? '✅ Active' : '❌ Inactive'
        ]
    ];

    // ФИНАЛЬНАЯ СТАТИСТИКА
    $executionTime = microtime(true) - $startTime;
    $totalChecks = 0;
    $passedChecks = 0;

    // Подсчитываем успешные проверки
    foreach ($diagnostics as $section) {
        if (isset($section['status'])) {
            $totalChecks++;
            if (strpos($section['status'], '✅') !== false) {
                $passedChecks++;
            }
        }
    }

    $overallHealth = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 2) : 75;

} catch (Exception $e) {
    error_log("Diagnostic error: " . $e->getMessage());
    echo '<div class="alert alert-danger">Ошибка при выполнении диагностики: ' . htmlspecialchars($e->getMessage()) . '</div>';
    return;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Диагностика системы - VDestor Admin</title>
    <style>
        .diagnostics-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .diagnostics-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .diagnostics-header h1 {
            margin: 0 0 1rem 0;
            font-size: 2.5rem;
        }
        
        .health-score {
            font-size: 3rem;
            font-weight: bold;
            margin: 1rem 0;
        }
        
        .health-score.good { color: #48bb78; }
        .health-score.warning { color: #f6ad55; }
        .health-score.critical { color: #f56565; }
        
        .diagnostic-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .section-header {
            background: #f7fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .section-content {
            padding: 1.5rem;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .info-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: top;
        }
        
        .info-table tr:last-child td {
            border-bottom: none;
        }
        
        .status-icon {
            font-size: 1.25rem;
        }
        
        .error-box {
            background: #fee;
            border: 1px solid #fcc;
            color: #c53030;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        
        .refresh-btn {
            background: white;
            color: #667eea;
            border: 2px solid white;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            margin-top: 1rem;
        }
        
        .refresh-btn:hover {
            background: transparent;
            color: white;
        }
    </style>
</head>
<body>
    <div class="diagnostics-container">
        <!-- Заголовок -->
        <div class="diagnostics-header">
            <h1>🔍 Диагностика системы VDestor</h1>
            <div class="health-score <?= $overallHealth >= 80 ? 'good' : ($overallHealth >= 60 ? 'warning' : 'critical') ?>">
                <?= $overallHealth ?>% Health Score
            </div>
            <div>
                <strong>Время выполнения:</strong> <?= round($executionTime, 3) ?>s
            </div>
            <a href="/admin/diagnost" class="refresh-btn">🔄 Обновить диагностику</a>
        </div>
        
        <!-- Секции диагностики -->
        <?php foreach ($diagnostics as $key => $section): ?>
        <div class="diagnostic-section">
            <div class="section-header">
                <?= $section['title'] ?? 'Unnamed Section' ?>
                <?php if (isset($section['status'])): ?>
                    <span class="status-icon" style="float: right;"><?= $section['status'] ?></span>
                <?php endif; ?>
            </div>
            <div class="section-content">
                <?php if (isset($section['error'])): ?>
                    <div class="error-box">
                        <strong>Ошибка:</strong> <?= htmlspecialchars($section['error']) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['data'])): ?>
                    <table class="info-table">
                        <?php foreach ($section['data'] as $label => $value): ?>
                        <tr>
                            <td style="width: 40%; font-weight: 500;"><?= htmlspecialchars($label) ?></td>
                            <td><?= htmlspecialchars($value) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
                
                <?php if (isset($section['extensions'])): ?>
                    <h4>PHP Расширения:</h4>
                    <table class="info-table">
                        <?php foreach ($section['extensions'] as $ext => $status): ?>
                        <tr>
                            <td style="width: 40%; font-weight: 500;"><?= htmlspecialchars($ext) ?></td>
                            <td><?= $status ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
                
                <?php if (isset($section['paths'])): ?>
                    <table class="info-table">
                        <thead>
                            <tr style="background: #f7fafc;">
                                <th style="padding: 0.75rem;">Путь</th>
                                <th style="padding: 0.75rem;">Существует</th>
                                <th style="padding: 0.75rem;">Читается</th>
                                <th style="padding: 0.75rem;">Записывается</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($section['paths'] as $name => $info): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($name) ?></strong><br>
                                    <small><?= htmlspecialchars($info['path']) ?></small>
                                </td>
                                <td><?= $info['exists'] ?></td>
                                <td><?= $info['readable'] ?></td>
                                <td><?= $info['writable'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Навигация -->
        <div class="diagnostic-section">
            <div class="section-header">⚡ Действия</div>
            <div class="section-content">
                <a href="/admin" class="refresh-btn" style="background: #667eea; color: white;">← Вернуться в админку</a>
            </div>
        </div>
    </div>
</body>
</html>