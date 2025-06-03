<?php
/**
 * ПОЛНАЯ СИСТЕМА ДИАГНОСТИКИ VDestor
 * Файл: src/views/admin/diagnost.php
 */


use App\Core\Database;
use App\Core\Cache;
use App\Core\Logger;
use App\Core\Config;
use App\Services\AuthService;
use App\Services\CartService;
use App\Services\SearchService;
use App\Services\DynamicProductDataService;
use App\Services\MetricsService;
use App\Services\QueueService;
use App\Services\EmailService;

// Проверка прав доступа
if (!AuthService::checkRole('admin')) {
    header('HTTP/1.1 403 Forbidden');
    die('Access denied');
}

// Устанавливаем максимальные лимиты для диагностики
@ini_set('max_execution_time', 0); // Без ограничений
@ini_set('memory_limit', '40G');    // 40GB для диагностики
@ini_set('display_errors', 1);
error_reporting(E_ALL);

// Отключаем буферизацию для живого вывода
@ob_end_flush();
@ob_implicit_flush(true);

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
        'Server Load' => implode(', ', sys_getloadavg()),
        'Uptime' => shell_exec('uptime') ?: 'Unknown'
    ]
];

// 2. PHP КОНФИГУРАЦИЯ
$phpChecks = [
    'version' => ['current' => PHP_VERSION, 'required' => '7.4.0', 'check' => version_compare(PHP_VERSION, '7.4.0', '>=')],
    'memory_limit' => ['current' => ini_get('memory_limit'), 'required' => '256M', 'check' => true],
    'max_execution_time' => ['current' => ini_get('max_execution_time'), 'required' => '300', 'check' => true],
    'post_max_size' => ['current' => ini_get('post_max_size'), 'required' => '32M', 'check' => true],
    'upload_max_filesize' => ['current' => ini_get('upload_max_filesize'), 'required' => '32M', 'check' => true],
    'session.gc_maxlifetime' => ['current' => ini_get('session.gc_maxlifetime'), 'required' => '1440', 'check' => true],
    'error_reporting' => ['current' => error_reporting(), 'required' => 'E_ALL', 'check' => true],
    'display_errors' => ['current' => ini_get('display_errors'), 'required' => '0 (production)', 'check' => true]
];

$diagnostics['php'] = [
    'title' => '🐘 PHP Конфигурация',
    'checks' => $phpChecks,
    'extensions' => [
        'PDO' => extension_loaded('pdo'),
        'PDO MySQL' => extension_loaded('pdo_mysql'),
        'JSON' => extension_loaded('json'),
        'cURL' => extension_loaded('curl'),
        'Mbstring' => extension_loaded('mbstring'),
        'OpenSSL' => extension_loaded('openssl'),
        'GD' => extension_loaded('gd'),
        'Zip' => extension_loaded('zip'),
        'Session' => extension_loaded('session'),
        'Redis' => extension_loaded('redis'),
        'APCu' => extension_loaded('apcu')
    ]
];

// 3. ПАМЯТЬ И РЕСУРСЫ
$memoryUsage = memory_get_usage(true);
$memoryPeak = memory_get_peak_usage(true);
$memoryLimit = ini_get('memory_limit');

$diagnostics['memory'] = [
    'title' => '💾 Память и ресурсы',
    'data' => [
        'Current Memory Usage' => formatBytes($memoryUsage),
        'Peak Memory Usage' => formatBytes($memoryPeak),
        'Memory Limit' => $memoryLimit,
        'Memory Usage %' => $memoryLimit != '-1' ? round(($memoryUsage / parseBytes($memoryLimit)) * 100, 2) . '%' : 'Unlimited',
        'Free System Memory' => formatBytes(getSystemMemoryInfo()['MemFree'] ?? 0),
        'Total System Memory' => formatBytes(getSystemMemoryInfo()['MemTotal'] ?? 0),
        'CPU Cores' => shell_exec('nproc') ?: 'Unknown'
    ]
];

// 4. ФАЙЛОВАЯ СИСТЕМА
$paths = [
    'Root' => $_SERVER['DOCUMENT_ROOT'],
    'Logs' => '/var/log/vdestor',
    'Cache' => '/tmp/vdestor_cache',
    'Config' => Config::getConfigPath() ?: '/etc/vdestor/config',
    'Uploads' => $_SERVER['DOCUMENT_ROOT'] . '/uploads',
    'Assets' => $_SERVER['DOCUMENT_ROOT'] . '/assets/dist',
    'Sessions' => session_save_path() ?: '/tmp'
];

$fileSystemChecks = [];
foreach ($paths as $name => $path) {
    $exists = file_exists($path);
    $writable = $exists && is_writable($path);
    $readable = $exists && is_readable($path);
    $size = $exists && is_dir($path) ? getDirSize($path) : 0;
    
    $fileSystemChecks[$name] = [
        'path' => $path,
        'exists' => $exists,
        'readable' => $readable,
        'writable' => $writable,
        'permissions' => $exists ? substr(sprintf('%o', fileperms($path)), -4) : 'N/A',
        'size' => formatBytes($size),
        'status' => $exists && $readable && $writable ? '✅' : '❌'
    ];
}

// Дисковое пространство
$diskFree = disk_free_space('/');
$diskTotal = disk_total_space('/');
$diskUsed = $diskTotal - $diskFree;

$diagnostics['filesystem'] = [
    'title' => '📁 Файловая система',
    'paths' => $fileSystemChecks,
    'disk' => [
        'Total Space' => formatBytes($diskTotal),
        'Used Space' => formatBytes($diskUsed),
        'Free Space' => formatBytes($diskFree),
        'Usage %' => round(($diskUsed / $diskTotal) * 100, 2) . '%'
    ]
];

// 5. БАЗА ДАННЫХ
try {
    $pdo = Database::getConnection();
    
    // Версия и статус
    $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
    $dbUptime = $pdo->query("SHOW STATUS WHERE Variable_name = 'Uptime'")->fetch();
    $dbConnections = $pdo->query("SHOW STATUS WHERE Variable_name = 'Threads_connected'")->fetch();
    $dbMaxConnections = $pdo->query("SHOW VARIABLES WHERE Variable_name = 'max_connections'")->fetch();
    
    // Размер БД
    $dbSize = $pdo->query("
        SELECT 
            SUM(data_length + index_length) as size,
            COUNT(*) as tables_count
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
    ")->fetch();
    
    // Статистика таблиц
    $tablesInfo = $pdo->query("
        SELECT 
            table_name,
            table_rows,
            ROUND((data_length + index_length) / 1024 / 1024, 2) as size_mb,
            engine,
            table_collation
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
        ORDER BY (data_length + index_length) DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);
    
    // Проверка важных таблиц
    $requiredTables = [
        'products', 'users', 'carts', 'prices', 'stock_balances', 'categories', 
        'brands', 'series', 'cities', 'warehouses', 'sessions', 'audit_logs',
        'application_logs', 'metrics', 'job_queue', 'specifications'
    ];
    
    $existingTables = array_column($tablesInfo, 'table_name');
    $missingTables = array_diff($requiredTables, $existingTables);
    
    // Медленные запросы
    $slowQueries = $pdo->query("
        SELECT COUNT(*) as count 
        FROM information_schema.processlist 
        WHERE command != 'Sleep' AND time > 5
    ")->fetchColumn();
    
    $diagnostics['database'] = [
        'title' => '🗄️ База данных',
        'status' => '✅ Connected',
        'info' => [
            'Version' => $dbVersion,
            'Uptime' => formatTime($dbUptime['Value'] ?? 0),
            'Active Connections' => $dbConnections['Value'] . ' / ' . $dbMaxConnections['Value'],
            'Database Size' => formatBytes($dbSize['size'] ?? 0),
            'Tables Count' => $dbSize['tables_count'] ?? 0,
            'Missing Tables' => empty($missingTables) ? 'None' : implode(', ', $missingTables),
            'Slow Queries' => $slowQueries
        ],
        'tables' => $tablesInfo
    ];
    
} catch (\Exception $e) {
    $diagnostics['database'] = [
        'title' => '🗄️ База данных',
        'status' => '❌ Error',
        'error' => $e->getMessage()
    ];
}

// 6. OPENSEARCH/ELASTICSEARCH
try {
    $client = \OpenSearch\ClientBuilder::create()
        ->setHosts(['localhost:9200'])
        ->setConnectionParams(['timeout' => 5, 'connect_timeout' => 3])
        ->build();
    
    // Проверка здоровья кластера
    $health = $client->cluster()->health();
    
    // Информация о кластере
    $clusterInfo = $client->info();
    
    // Статистика индексов
    $indices = $client->indices()->stats(['index' => 'products*']);
    
    // Проверка алиасов
    $aliases = [];
    try {
        $aliasInfo = $client->indices()->getAlias(['name' => 'products_current']);
        $aliases = array_keys($aliasInfo);
    } catch (\Exception $e) {
        $aliases = ['Not found'];
    }
    
    $diagnostics['opensearch'] = [
        'title' => '🔍 OpenSearch/Elasticsearch',
        'status' => $health['status'] === 'green' ? '✅ Healthy' : ($health['status'] === 'yellow' ? '⚠️ Warning' : '❌ Critical'),
        'info' => [
            'Version' => $clusterInfo['version']['number'] ?? 'Unknown',
            'Cluster Name' => $health['cluster_name'],
            'Status' => $health['status'],
            'Nodes' => $health['number_of_nodes'],
            'Active Shards' => $health['active_shards'],
            'Indices Count' => count($indices['indices'] ?? []),
            'Current Alias' => implode(', ', $aliases),
            'Total Documents' => array_sum(array_column($indices['indices'] ?? [], 'primaries.docs.count'))
        ]
    ];
    
} catch (\Exception $e) {
    $diagnostics['opensearch'] = [
        'title' => '🔍 OpenSearch/Elasticsearch',
        'status' => '❌ Not Available',
        'error' => $e->getMessage()
    ];
}

// 7. КЕШ СИСТЕМА
try {
    $cacheTest = uniqid('test_');
    Cache::set($cacheTest, 'test_value', 60);
    $cacheRead = Cache::get($cacheTest);
    Cache::delete($cacheTest);
    
    $cacheStats = Cache::getStats();
    
    $diagnostics['cache'] = [
        'title' => '⚡ Кеш система',
        'status' => $cacheRead === 'test_value' ? '✅ Working' : '❌ Not Working',
        'stats' => $cacheStats
    ];
    
} catch (\Exception $e) {
    $diagnostics['cache'] = [
        'title' => '⚡ Кеш система',
        'status' => '❌ Error',
        'error' => $e->getMessage()
    ];
}

// 8. СЕССИИ
$sessionHandler = ini_get('session.save_handler');
$sessionPath = session_save_path();
$sessionGC = ini_get('session.gc_maxlifetime');

// Проверка сессий в БД
$dbSessions = 0;
if ($sessionHandler === 'user' || Config::get('session.save_handler') === 'db') {
    try {
        $dbSessions = $pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
    } catch (\Exception $e) {
        $dbSessions = 'Error: ' . $e->getMessage();
    }
}

$diagnostics['sessions'] = [
    'title' => '🔐 Сессии',
    'data' => [
        'Handler' => $sessionHandler,
        'Save Path' => $sessionPath,
        'GC Lifetime' => $sessionGC . ' seconds',
        'Current Session ID' => session_id(),
        'Session Status' => session_status() === PHP_SESSION_ACTIVE ? '✅ Active' : '❌ Inactive',
        'Sessions in DB' => $dbSessions
    ]
];

// 9. ОЧЕРЕДИ ЗАДАЧ
try {
    $queueStats = QueueService::getStats();
    
    $diagnostics['queues'] = [
        'title' => '📋 Очереди задач',
        'stats' => [
            'Queue Length' => $queueStats['queue_length'] ?? 0,
            'By Status' => $queueStats['by_status'] ?? [],
            'By Type' => $queueStats['by_type'] ?? []
        ]
    ];
    
} catch (\Exception $e) {
    $diagnostics['queues'] = [
        'title' => '📋 Очереди задач',
        'status' => '❌ Error',
        'error' => $e->getMessage()
    ];
}

// 10. МЕТРИКИ
try {
    $metricsStats = MetricsService::getStats('day');
    
    $diagnostics['metrics'] = [
        'title' => '📊 Метрики (последние 24 часа)',
        'summary' => $metricsStats['summary'] ?? [],
        'performance' => $metricsStats['performance'] ?? [],
        'errors' => count($metricsStats['errors'] ?? [])
    ];
    
} catch (\Exception $e) {
    $diagnostics['metrics'] = [
        'title' => '📊 Метрики',
        'status' => '❌ Error',
        'error' => $e->getMessage()
    ];
}

// 11. API ПРОВЕРКА
$apiEndpoints = [
    '/api/test' => 'Test API',
    '/api/search?q=test&limit=1' => 'Search API',
    '/api/availability?product_ids=1&city_id=1' => 'Availability API',
    '/api/autocomplete?q=авт&limit=5' => 'Autocomplete API'
];

$apiChecks = [];
foreach ($apiEndpoints as $endpoint => $name) {
    $startApiTime = microtime(true);
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://' . $_SERVER['HTTP_HOST'] . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $responseTime = round((microtime(true) - $startApiTime) * 1000, 2);
        
        $apiChecks[$name] = [
            'endpoint' => $endpoint,
            'status' => $httpCode === 200 ? '✅' : '❌',
            'http_code' => $httpCode,
            'response_time' => $responseTime . 'ms',
            'response_preview' => substr($response, 0, 100) . '...'
        ];
        
    } catch (\Exception $e) {
        $apiChecks[$name] = [
            'endpoint' => $endpoint,
            'status' => '❌',
            'error' => $e->getMessage()
        ];
    }
}

$diagnostics['api'] = [
    'title' => '🌐 API Endpoints',
    'checks' => $apiChecks
];

// 12. БЕЗОПАСНОСТЬ
$securityChecks = [
    'HTTPS' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '✅' : '❌',
    'X-Content-Type-Options' => isset(getallheaders()['X-Content-Type-Options']) ? '✅' : '❌',
    'X-Frame-Options' => isset(getallheaders()['X-Frame-Options']) ? '✅' : '❌',
    'X-XSS-Protection' => isset(getallheaders()['X-XSS-Protection']) ? '✅' : '❌',
    'Strict-Transport-Security' => isset(getallheaders()['Strict-Transport-Security']) ? '✅' : '❌',
    'Config Directory Protected' => !is_readable('/etc/vdestor/config/.htaccess') ? '✅' : '❌'
];

$diagnostics['security'] = [
    'title' => '🔒 Безопасность',
    'checks' => $securityChecks,
    'config_issues' => Config::validateSecurity()
];

// 13. ЛОГИ И ОШИБКИ
$logsPath = '/var/log/vdestor';
$logFiles = [];
if (is_dir($logsPath)) {
    foreach (glob($logsPath . '/*.log') as $logFile) {
        $logFiles[basename($logFile)] = [
            'size' => formatBytes(filesize($logFile)),
            'modified' => date('Y-m-d H:i:s', filemtime($logFile)),
            'last_lines' => tailFile($logFile, 5)
        ];
    }
}

// Последние ошибки из БД
$lastErrors = [];
try {
    $lastErrors = $pdo->query("
        SELECT level, message, created_at 
        FROM application_logs 
        WHERE level IN ('error', 'critical', 'emergency')
        ORDER BY created_at DESC 
        LIMIT 10
    ")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    $lastErrors = ['Error loading logs: ' . $e->getMessage()];
}

$diagnostics['logs'] = [
    'title' => '📜 Логи и ошибки',
    'files' => $logFiles,
    'last_errors' => $lastErrors
];

// 14. ПРОЦЕССЫ И СЕРВИСЫ
$processes = [
    'PHP-FPM' => shell_exec('ps aux | grep php-fpm | grep -v grep | wc -l'),
    'MySQL' => shell_exec('ps aux | grep mysql | grep -v grep | wc -l'),
    'Nginx' => shell_exec('ps aux | grep nginx | grep -v grep | wc -l'),
    'Redis' => shell_exec('ps aux | grep redis | grep -v grep | wc -l'),
    'Queue Workers' => shell_exec('ps aux | grep queue:work | grep -v grep | wc -l')
];

$diagnostics['processes'] = [
    'title' => '⚙️ Процессы и сервисы',
    'running' => array_map('trim', $processes),
    'php_processes' => shell_exec('ps aux | grep php | grep -v grep') ?: 'Unable to get process list'
];

// 15. CRONJOBS
$cronJobs = shell_exec('crontab -l 2>/dev/null') ?: 'No cron jobs or unable to read';

$diagnostics['cron'] = [
    'title' => '⏰ Cron задачи',
    'jobs' => $cronJobs
];

// 16. EMAIL СИСТЕМА
try {
    $emailLogs = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
            MAX(sent_at) as last_sent
        FROM email_logs
        WHERE sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetch();
    
    $diagnostics['email'] = [
        'title' => '📧 Email система',
        'stats' => [
            'Emails sent (7 days)' => $emailLogs['total'] ?? 0,
            'Emails opened' => $emailLogs['opened'] ?? 0,
            'Last sent' => $emailLogs['last_sent'] ?? 'Never',
            'Mail function' => function_exists('mail') ? '✅ Available' : '❌ Not available'
        ]
    ];
    
} catch (\Exception $e) {
    $diagnostics['email'] = [
        'title' => '📧 Email система',
        'status' => '❌ Error',
        'error' => $e->getMessage()
    ];
}

// 17. ИНТЕГРАЦИИ
$integrations = [];
$configIntegrations = Config::get('integrations', []);
foreach ($configIntegrations as $name => $config) {
    $integrations[$name] = [
        'enabled' => $config['enabled'] ?? false,
        'url' => $config['url'] ?? 'Not configured'
    ];
}

$diagnostics['integrations'] = [
    'title' => '🔗 Интеграции',
    'services' => $integrations ?: ['None configured']
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
    if (isset($section['checks'])) {
        foreach ($section['checks'] as $check) {
            $totalChecks++;
            if ((is_array($check) && isset($check['status']) && strpos($check['status'], '✅') !== false) ||
                (is_string($check) && strpos($check, '✅') !== false) ||
                (is_bool($check) && $check === true)) {
                $passedChecks++;
            }
        }
    }
}

$overallHealth = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 2) : 0;

// Вспомогательные функции
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function parseBytes($str) {
    $str = trim($str);
    if ($str === '-1') return PHP_INT_MAX;
    $last = strtolower($str[strlen($str) - 1]);
    $value = (int)$str;
    switch ($last) {
        case 'g': $value *= 1024 * 1024 * 1024; break;
        case 'm': $value *= 1024 * 1024; break;
        case 'k': $value *= 1024; break;
    }
    return $value;
}

function formatTime($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return "{$days}d {$hours}h {$minutes}m";
}

function getSystemMemoryInfo() {
    $meminfo = [];
    if (file_exists('/proc/meminfo')) {
        $lines = file('/proc/meminfo');
        foreach ($lines as $line) {
            list($key, $val) = explode(':', $line, 2);
            $meminfo[$key] = (int)trim(str_replace(' kB', '', $val)) * 1024;
        }
    }
    return $meminfo;
}

function getDirSize($dir) {
    $size = 0;
    if (!is_dir($dir)) return $size;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function tailFile($filepath, $lines = 10) {
    if (!file_exists($filepath)) return [];
    $f = fopen($filepath, "rb");
    if (!$f) return [];
    fseek($f, -1, SEEK_END);
    if (fread($f, 1) != "\n") $lines--;
    $output = '';
    $chunk = '';
    while (ftell($f) > 0 && $lines >= 0) {
        $seek = min(ftell($f), 4096);
        fseek($f, -$seek, SEEK_CUR);
        $output = ($chunk = fread($f, $seek)) . $output;
        fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
        $lines -= substr_count($chunk, "\n");
    }
    fclose($f);
    return array_slice(explode("\n", trim($output)), -10);
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
            max-width: 1400px;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.1);
            padding: 1rem;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }
        
        .stat-label {
            font-size: 0.875rem;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
        }
        
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
        
        .info-table th {
            text-align: left;
            padding: 0.75rem;
            background: #f7fafc;
            font-weight: 600;
            border-bottom: 1px solid #e2e8f0;
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
        
        .check-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .check-item {
            background: #f7fafc;
            padding: 1rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .check-label {
            font-weight: 500;
        }
        
        .check-value {
            font-family: monospace;
            font-size: 0.875rem;
            color: #4a5568;
        }
        
        .error-box {
            background: #fee;
            border: 1px solid #fcc;
            color: #c53030;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        
        .warning-box {
            background: #fffaf0;
            border: 1px solid #feb2b2;
            color: #c05621;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        
        .code-block {
            background: #2d3748;
            color: #a0aec0;
            padding: 1rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.875rem;
            overflow-x: auto;
            white-space: pre-wrap;
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
        }
        
        .refresh-btn:hover {
            background: transparent;
            color: white;
        }
        
        .action-buttons {
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-btn.primary {
            background: #667eea;
            color: white;
        }
        
        .action-btn.primary:hover {
            background: #5a67d8;
        }
        
        .action-btn.secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .action-btn.secondary:hover {
            background: #cbd5e0;
        }
        
        .logs-preview {
            background: #1a202c;
            color: #a0aec0;
            padding: 1rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.75rem;
            max-height: 200px;
            overflow-y: auto;
            margin-top: 0.5rem;
        }
        
        .table-scroll {
            overflow-x: auto;
        }
        
        .small-table {
            font-size: 0.875rem;
        }
        
        .small-table th,
        .small-table td {
            padding: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .diagnostics-header h1 {
                font-size: 1.75rem;
            }
            
            .health-score {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .check-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Время выполнения</div>
                    <div class="stat-value"><?= round($executionTime, 3) ?>s</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Проверок выполнено</div>
                    <div class="stat-value"><?= $totalChecks ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Успешных проверок</div>
                    <div class="stat-value"><?= $passedChecks ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Время сервера</div>
                    <div class="stat-value"><?= date('H:i:s') ?></div>
                </div>
            </div>
            
            <div class="action-buttons">
                <a href="/admin/diagnost" class="refresh-btn">🔄 Обновить диагностику</a>
                <button onclick="exportDiagnostics()" class="refresh-btn">📥 Экспорт отчета</button>
            </div>
        </div>
        
        <!-- Секции диагностики -->
        <?php foreach ($diagnostics as $key => $section): ?>
        <div class="diagnostic-section" id="section-<?= $key ?>">
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
                            <td style="width: 40%; font-weight: 500;"><?= $label ?></td>
                            <td>
                                <?php if (is_array($value)): ?>
                                    <pre style="margin: 0;"><?= htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT)) ?></pre>
                                <?php else: ?>
                                    <?= htmlspecialchars($value) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
                
                <?php if (isset($section['checks'])): ?>
                    <div class="check-grid">
                        <?php foreach ($section['checks'] as $checkName => $checkResult): ?>
                        <div class="check-item">
                            <span class="check-label"><?= $checkName ?></span>
                            <?php if (is_array($checkResult)): ?>
                                <?php if (isset($checkResult['status'])): ?>
                                    <span class="status-icon"><?= $checkResult['status'] ?></span>
                                <?php elseif (isset($checkResult['current'])): ?>
                                    <span class="check-value">
                                        <?= $checkResult['current'] ?> 
                                        <?= $checkResult['check'] ? '✅' : '❌' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="check-value"><?= htmlspecialchars(json_encode($checkResult)) ?></span>
                                <?php endif; ?>
                            <?php elseif (is_bool($checkResult)): ?>
                                <span class="status-icon"><?= $checkResult ? '✅' : '❌' ?></span>
                            <?php else: ?>
                                <span><?= htmlspecialchars($checkResult) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['extensions'])): ?>
                    <div class="check-grid">
                        <?php foreach ($section['extensions'] as $ext => $loaded): ?>
                        <div class="check-item">
                            <span class="check-label"><?= $ext ?></span>
                            <span class="status-icon"><?= $loaded ? '✅ Loaded' : '❌ Not loaded' ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['info'])): ?>
                    <table class="info-table">
                        <?php foreach ($section['info'] as $label => $value): ?>
                        <tr>
                            <td style="width: 40%; font-weight: 500;"><?= $label ?></td>
                            <td><?= htmlspecialchars($value) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
                
                <?php if (isset($section['tables']) && is_array($section['tables'])): ?>
                    <h4 style="margin-top: 1.5rem;">Таблицы базы данных:</h4>
                    <div class="table-scroll">
                        <table class="info-table small-table">
                            <thead>
                                <tr>
                                    <th>Таблица</th>
                                    <th>Строк</th>
                                    <th>Размер (MB)</th>
                                    <th>Engine</th>
                                    <th>Collation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($section['tables'] as $table): ?>
                                <tr>
                                    <td><?= htmlspecialchars($table['table_name']) ?></td>
                                    <td><?= number_format($table['table_rows']) ?></td>
                                    <td><?= $table['size_mb'] ?></td>
                                    <td><?= htmlspecialchars($table['engine']) ?></td>
                                    <td><?= htmlspecialchars($table['table_collation']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['paths'])): ?>
                    <table class="info-table">
                        <thead>
                            <tr>
                                <th>Путь</th>
                                <th>Статус</th>
                                <th>Права</th>
                                <th>Размер</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($section['paths'] as $name => $info): ?>
                            <tr>
                                <td>
                                    <strong><?= $name ?></strong><br>
                                    <code style="font-size: 0.75rem;"><?= htmlspecialchars($info['path']) ?></code>
                                </td>
                                <td><?= $info['status'] ?></td>
                                <td><?= $info['permissions'] ?></td>
                                <td><?= $info['size'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <?php if (isset($section['disk'])): ?>
                    <h4 style="margin-top: 1.5rem;">Дисковое пространство:</h4>
                    <table class="info-table">
                        <?php foreach ($section['disk'] as $label => $value): ?>
                        <tr>
                            <td style="width: 40%; font-weight: 500;"><?= $label ?></td>
                            <td><?= $value ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
                
                <?php if (isset($section['stats'])): ?>
                    <?php if (is_array($section['stats']) && !empty($section['stats'])): ?>
                        <?php foreach ($section['stats'] as $statName => $statValue): ?>
                            <?php if (is_array($statValue)): ?>
                                <h4 style="margin-top: 1rem;"><?= $statName ?>:</h4>
                                <pre class="code-block"><?= htmlspecialchars(json_encode($statValue, JSON_PRETTY_PRINT)) ?></pre>
                            <?php else: ?>
                                <div style="margin: 0.5rem 0;">
                                    <strong><?= $statName ?>:</strong> <?= htmlspecialchars($statValue) ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <pre class="code-block"><?= htmlspecialchars(json_encode($section['stats'], JSON_PRETTY_PRINT)) ?></pre>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($section['files'])): ?>
                    <h4>Файлы логов:</h4>
                    <?php foreach ($section['files'] as $filename => $fileInfo): ?>
                        <div style="margin-bottom: 1rem;">
                            <strong><?= htmlspecialchars($filename) ?></strong> 
                            (<?= $fileInfo['size'] ?>, изменен: <?= $fileInfo['modified'] ?>)
                            <?php if (!empty($fileInfo['last_lines'])): ?>
                                <div class="logs-preview">
                                    <?php foreach ($fileInfo['last_lines'] as $line): ?>
                                        <?= htmlspecialchars($line) ?><br>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (isset($section['last_errors']) && is_array($section['last_errors'])): ?>
                    <h4>Последние ошибки:</h4>
                    <div class="table-scroll">
                        <table class="info-table small-table">
                            <thead>
                                <tr>
                                    <th>Уровень</th>
                                    <th>Сообщение</th>
                                    <th>Время</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($section['last_errors'] as $error): ?>
                                    <?php if (is_array($error)): ?>
                                    <tr>
                                        <td><span class="badge badge-<?= $error['level'] ?>"><?= $error['level'] ?></span></td>
                                        <td><?= htmlspecialchars(substr($error['message'], 0, 100)) ?>...</td>
                                        <td><?= $error['created_at'] ?></td>
                                    </tr>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="3"><?= htmlspecialchars($error) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['running'])): ?>
                    <div class="check-grid">
                        <?php foreach ($section['running'] as $process => $count): ?>
                        <div class="check-item">
                            <span class="check-label"><?= $process ?></span>
                            <span class="check-value">
                                <?= $count ?> процесс(ов)
                                <?= $count > 0 ? '✅' : '❌' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['jobs']) && is_string($section['jobs'])): ?>
                    <pre class="code-block"><?= htmlspecialchars($section['jobs']) ?></pre>
                <?php endif; ?>
                
                <?php if (isset($section['summary'])): ?>
                    <h4>Сводка метрик:</h4>
                    <div class="table-scroll">
                        <table class="info-table small-table">
                            <thead>
                                <tr>
                                    <th>Тип метрики</th>
                                    <th>Количество</th>
                                    <th>Среднее</th>
                                    <th>Мин</th>
                                    <th>Макс</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($section['summary'] as $type => $metrics): ?>
                                <tr>
                                    <td><?= htmlspecialchars($type) ?></td>
                                    <td><?= $metrics['count'] ?? 0 ?></td>
                                    <td><?= $metrics['average'] ?? 'N/A' ?></td>
                                    <td><?= $metrics['min'] ?? 'N/A' ?></td>
                                    <td><?= $metrics['max'] ?? 'N/A' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['performance'])): ?>
                    <h4>Производительность:</h4>
                    <div class="check-grid">
                        <?php foreach ($section['performance'] as $metric => $value): ?>
                        <div class="check-item">
                            <span class="check-label"><?= str_replace('_', ' ', $metric) ?></span>
                            <span class="check-value"><?= is_numeric($value) ? round($value, 3) : $value ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['config_issues']) && is_array($section['config_issues']) && !empty($section['config_issues'])): ?>
                    <div class="warning-box">
                        <strong>Проблемы конфигурации:</strong>
                        <ul style="margin: 0.5rem 0 0 1.5rem;">
                            <?php foreach ($section['config_issues'] as $issue): ?>
                            <li><?= htmlspecialchars($issue) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($section['services'])): ?>
                    <div class="check-grid">
                        <?php foreach ($section['services'] as $service => $config): ?>
                        <div class="check-item">
                            <span class="check-label"><?= $service ?></span>
                            <span class="check-value">
                                <?php if (is_array($config)): ?>
                                    <?= $config['enabled'] ? '✅ Включен' : '❌ Выключен' ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($config) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Дополнительные действия -->
        <div class="diagnostic-section">
            <div class="section-header">
                ⚡ Быстрые действия
            </div>
            <div class="section-content">
                <div class="action-buttons">
                    <button onclick="clearCache()" class="action-btn secondary">
                        🗑️ Очистить кеш
                    </button>
                    <button onclick="runIndexing()" class="action-btn secondary">
                        🔍 Переиндексация OpenSearch
                    </button>
                    <button onclick="clearLogs()" class="action-btn secondary">
                        📜 Очистить старые логи
                    </button>
                    <button onclick="optimizeDB()" class="action-btn secondary">
                        🗄️ Оптимизировать БД
                    </button>
                    <a href="/admin" class="action-btn primary">
                        ← Вернуться в админку
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Функция экспорта диагностики
    function exportDiagnostics() {
        const diagnostics = <?= json_encode($diagnostics) ?>;
        const dataStr = JSON.stringify({
            timestamp: new Date().toISOString(),
            health_score: <?= $overallHealth ?>,
            execution_time: <?= $executionTime ?>,
            diagnostics: diagnostics
        }, null, 2);
        
        const dataUri = 'data:application/json;charset=utf-8,'+ encodeURIComponent(dataStr);
        const exportFileDefaultName = 'vdestor-diagnostics-' + new Date().toISOString().slice(0,10) + '.json';
        
        const linkElement = document.createElement('a');
        linkElement.setAttribute('href', dataUri);
        linkElement.setAttribute('download', exportFileDefaultName);
        linkElement.click();
    }
    
    // Функции для быстрых действий
    function clearCache() {
        if (confirm('Очистить весь кеш системы?')) {
            fetch('/api/admin/clear-cache', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': '<?= \App\Core\CSRF::token() ?>',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message || 'Кеш очищен');
                location.reload();
            })
            .catch(err => alert('Ошибка: ' + err));
        }
    }
    
    function runIndexing() {
        if (confirm('Запустить переиндексацию товаров в OpenSearch?')) {
            alert('Функция в разработке. Используйте консольную команду: php bin/console opensearch:reindex');
        }
    }
    
    function clearLogs() {
        if (confirm('Очистить логи старше 30 дней?')) {
            alert('Функция в разработке. Используйте консольную команду: php bin/console logs:cleanup');
        }
    }
    
    function optimizeDB() {
        if (confirm('Оптимизировать таблицы базы данных?')) {
            alert('Функция в разработке. Используйте консольную команду: php bin/console db:optimize');
        }
    }
    
    // Автообновление каждые 30 секунд
    let autoRefreshInterval;
    function toggleAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
            document.getElementById('autoRefreshBtn').textContent = '▶️ Автообновление';
        } else {
            autoRefreshInterval = setInterval(() => location.reload(), 30000);
            document.getElementById('autoRefreshBtn').textContent = '⏸️ Остановить';
        }
    }
    
    // Плавная прокрутка к секциям
    document.querySelectorAll('a[href^="#section-"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        });
    });
    
    // Подсветка критических значений
    document.addEventListener('DOMContentLoaded', function() {
        // Подсвечиваем ошибки красным
        document.querySelectorAll('.status-icon').forEach(el => {
            if (el.textContent.includes('❌')) {
                el.style.color = '#dc3545';
            } else if (el.textContent.includes('⚠️')) {
                el.style.color = '#ffc107';
            } else if (el.textContent.includes('✅')) {
                el.style.color = '#28a745';
            }
        });
        
        // Добавляем навигацию по секциям
        const nav = document.createElement('div');
        nav.style.cssText = 'position: fixed; right: 20px; top: 50%; transform: translateY(-50%); background: white; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-height: 80vh; overflow-y: auto;';
        nav.innerHTML = '<h4 style="margin: 0 0 1rem 0;">Быстрая навигация</h4>';
        
        <?php foreach ($diagnostics as $key => $section): ?>
        nav.innerHTML += '<a href="#section-<?= $key ?>" style="display: block; padding: 0.5rem; text-decoration: none; color: #4a5568; hover: background: #f7fafc;"><?= $section['title'] ?></a>';
        <?php endforeach; ?>
        
        document.body.appendChild(nav);
    });
    </script>
</body>
</html>