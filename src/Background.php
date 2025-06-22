<?php

namespace Rcalicdan\FiberAsync;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;

class Background
{
    private static ?string $cachedWorkerUrl = null;
    private static array $config = [
        'worker_filename' => 'bg_worker.php',
        'timeout' => 1,
        'auto_create_worker' => true,
        'log_errors' => true,
        'fallback_host' => 'localhost:8000',
    ];

    // =====================================
    // PUBLIC API
    // =====================================

    public static function run(array|Closure $task): void
    {
        $payload = self::serializePayload($task);
        $workerUrl = self::getWorkerUrl();
        
        self::postAsync($workerUrl, ['payload' => $payload]);
    }

    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    public static function clearCache(): void
    {
        self::$cachedWorkerUrl = null;
    }

    // =====================================
    // PAYLOAD SERIALIZATION
    // =====================================

    private static function serializePayload(array|Closure $task): string
    {
        if ($task instanceof Closure) {
            return self::serializeClosure($task);
        }

        return self::serializeArray($task);
    }

    private static function serializeClosure(Closure $closure): string
    {
        $serialized = serialize(new SerializableClosure($closure));

        return json_encode([
            'type' => 'closure',
            'closure' => base64_encode($serialized),
        ]);
    }

    private static function serializeArray(array $data): string
    {
        return json_encode([
            'type' => 'array',
            'data' => $data,
        ]);
    }

    // =====================================
    // WORKER DISCOVERY
    // =====================================

    private static function getWorkerUrl(): string
    {
        if (self::$cachedWorkerUrl !== null) {
            return self::$cachedWorkerUrl;
        }

        // Strategy 1: Environment variable
        if ($url = self::checkEnvironmentVariable()) {
            return self::$cachedWorkerUrl = $url;
        }

        // Strategy 2: Search common locations
        if ($url = self::searchCommonLocations()) {
            return self::$cachedWorkerUrl = $url;
        }

        // Strategy 3: Auto-create worker
        if (self::$config['auto_create_worker']) {
            if ($url = self::createWorkerIfNeeded()) {
                return self::$cachedWorkerUrl = $url;
            }
        }

        throw new \RuntimeException('Could not locate or create bg_worker.php. Set FIBERASYNC_WORKER environment variable or disable auto_create_worker.');
    }

    private static function checkEnvironmentVariable(): ?string
    {
        $workerPath = getenv('FIBERASYNC_WORKER');
        
        if ($workerPath && file_exists($workerPath)) {
            return self::filePathToUrl($workerPath);
        }

        return null;
    }

    private static function searchCommonLocations(): ?string
    {
        $searchPaths = self::getSearchPaths();

        foreach ($searchPaths as $path) {
            if ($path && file_exists($path)) {
                return self::filePathToUrl($path);
            }
        }

        return null;
    }

    private static function createWorkerIfNeeded(): ?string
    {
        $workerPath = self::determineWorkerCreationPath();
        
        if (!file_exists($workerPath)) {
            $workerContent = self::generateWorkerContent();
            
            if (file_put_contents($workerPath, $workerContent) === false) {
                if (self::$config['log_errors']) {
                    error_log("FiberAsync: Could not create worker at {$workerPath}");
                }
                return null;
            }
        }

        return self::filePathToUrl($workerPath);
    }

    // =====================================
    // PATH RESOLUTION
    // =====================================

    private static function getSearchPaths(): array
    {
        $paths = [];
        $workerFilename = self::$config['worker_filename'];

        // Current script's directory (most common case)
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $paths[] = dirname($_SERVER['SCRIPT_FILENAME']) . '/' . $workerFilename;
        }

        // Document root locations
        if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']) {
            $paths[] = $_SERVER['DOCUMENT_ROOT'] . '/' . $workerFilename;
            $paths[] = $_SERVER['DOCUMENT_ROOT'] . '/public/' . $workerFilename;
        }

        // Project root based locations
        if ($projectRoot = self::findProjectRoot()) {
            $paths = array_merge($paths, self::getProjectRootPaths($projectRoot, $workerFilename));
        }

        // Current working directory
        $paths[] = getcwd() . '/' . $workerFilename;

        return array_filter($paths);
    }

    private static function findProjectRoot(): ?string
    {
        $currentDir = __DIR__;

        while ($currentDir !== dirname($currentDir)) {
            if (self::isProjectRoot($currentDir)) {
                return $currentDir;
            }
            $currentDir = dirname($currentDir);
        }

        return null;
    }

    private static function isProjectRoot(string $directory): bool
    {
        return file_exists($directory . '/composer.json') || 
               file_exists($directory . '/vendor/autoload.php');
    }

    private static function getProjectRootPaths(string $projectRoot, string $workerFilename): array
    {
        return [
            $projectRoot . '/public/' . $workerFilename,
            $projectRoot . '/web/' . $workerFilename,
            $projectRoot . '/htdocs/' . $workerFilename,
            $projectRoot . '/www/' . $workerFilename,
            $projectRoot . '/' . $workerFilename,
        ];
    }

    private static function determineWorkerCreationPath(): string
    {
        $workerFilename = self::$config['worker_filename'];

        // Try document root first
        if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] && is_writable($_SERVER['DOCUMENT_ROOT'])) {
            return $_SERVER['DOCUMENT_ROOT'] . '/' . $workerFilename;
        }

        // Try current script directory
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $scriptDir = dirname($_SERVER['SCRIPT_FILENAME']);
            if (is_writable($scriptDir)) {
                return $scriptDir . '/' . $workerFilename;
            }
        }

        // Try current working directory
        if (is_writable(getcwd())) {
            return getcwd() . '/' . $workerFilename;
        }

        // Fallback to current working directory
        return getcwd() . '/' . $workerFilename;
    }

    // =====================================
    // URL HELPERS
    // =====================================

    private static function filePathToUrl(string $filePath): string
    {
        // Check if we're running in a web context
        if (!self::isWebContext()) {
            return self::buildFallbackUrl($filePath);
        }

        // Normalize path separators
        $filePath = str_replace('\\', '/', $filePath);
        
        if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']) {
            $documentRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            $relativePath = str_replace($documentRoot, '', $filePath);
            
            $scheme = self::getScheme();
            $host = self::getHost();
            
            return "{$scheme}://{$host}{$relativePath}";
        }

        return self::buildFallbackUrl($filePath);
    }

    private static function isWebContext(): bool
    {
        return isset($_SERVER['HTTP_HOST']) || isset($_SERVER['SERVER_NAME']);
    }

    private static function getScheme(): string
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }
        
        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            return 'https';
        }
        
        return 'http';
    }

    private static function getHost(): string
    {
        // Try HTTP_HOST first (most reliable)
        if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']) {
            return $_SERVER['HTTP_HOST'];
        }
        
        // Fallback to SERVER_NAME with port
        if (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME']) {
            $host = $_SERVER['SERVER_NAME'];
            
            // Add port if not standard
            if (isset($_SERVER['SERVER_PORT'])) {
                $port = $_SERVER['SERVER_PORT'];
                $scheme = self::getScheme();
                
                if (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443)) {
                    $host .= ':' . $port;
                }
            }
            
            return $host;
        }
        
        // Ultimate fallback
        return self::$config['fallback_host'];
    }

    private static function buildFallbackUrl(string $filePath): string
    {
        $filename = basename($filePath);
        $scheme = self::getScheme();
        $host = self::getHost();
        
        return "{$scheme}://{$host}/{$filename}";
    }

    // =====================================
    // HTTP CLIENT
    // =====================================

    private static function postAsync(string $url, array $params): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'timeout' => self::$config['timeout'],
                'ignore_errors' => true, // Don't throw errors for HTTP error codes
            ]
        ]);

        $result = @fopen($url, 'r', false, $context);
        
        // if ($result === false && self::$config['log_errors']) {
        //     error_log("FiberAsync: Failed to connect to worker at {$url}");
        // }
        
        if ($result) {
            fclose($result);
        }
    }

    // =====================================
    // WORKER GENERATION
    // =====================================

    private static function generateWorkerContent(): string
    {
        return '<?php

// Auto-generated FiberAsync background worker
// Generated on: ' . date('Y-m-d H:i:s') . '

' . self::getAutoloadSection() . '

ignore_user_abort(true);
set_time_limit(0);

$payload = json_decode($_POST[\'payload\'] ?? \'{}\', true);

try {
    switch ($payload[\'type\'] ?? null) {
        case \'closure\':
            handleClosureTask($payload);
            break;
            
        case \'array\':
            handleArrayTask($payload);
            break;
            
        default:
            logError("Unknown task type: " . ($payload[\'type\'] ?? \'null\'));
    }
} catch (Throwable $e) {
    logError("Task execution failed: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}

function handleClosureTask(array $payload): void
{
    $serialized = base64_decode($payload[\'closure\']);
    $closure = unserialize($serialized)->getClosure();
    
    if ($closure instanceof Closure) {
        $closure();
    } else {
        logError("Invalid closure in payload");
    }
}

function handleArrayTask(array $payload): void
{
    $data = $payload[\'data\'] ?? [];
    
    if (isset($data[\'callback\']) && is_callable($data[\'callback\'])) {
        call_user_func($data[\'callback\'], $data);
    } else {
        // Default behavior - log the task
        logTask("Array task received", $data);
    }
}

function logError(string $message): void
{
    error_log("FiberAsync Error: " . $message);
}

function logTask(string $message, array $data = []): void
{
    $logEntry = $message . (!empty($data) ? \' - Data: \' . json_encode($data) : \'\');
    error_log("FiberAsync: " . $logEntry);
}
';
    }

    private static function getAutoloadSection(): string
    {
        $possibleAutoloads = [
            '__DIR__ . \'/vendor/autoload.php\'',
            '__DIR__ . \'/../vendor/autoload.php\'',
            '__DIR__ . \'/../../vendor/autoload.php\'',
            '__DIR__ . \'/../../../vendor/autoload.php\'',
        ];

        $autoloadChecks = [];
        foreach ($possibleAutoloads as $autoload) {
            $autoloadChecks[] = "if (file_exists({$autoload})) require {$autoload};";
        }

        return implode("\n", $autoloadChecks);
    }

    // =====================================
    // DEBUGGING HELPERS
    // =====================================

    public static function debug(): array
    {
        return [
            'config' => self::$config,
            'cached_worker_url' => self::$cachedWorkerUrl,
            'search_paths' => self::getSearchPaths(),
            'project_root' => self::findProjectRoot(),
            'env_worker' => getenv('FIBERASYNC_WORKER'),
            'is_web_context' => self::isWebContext(),
            'detected_host' => self::getHost(),
            'detected_scheme' => self::getScheme(),
            'server_vars' => [
                'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'not set',
                'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'not set',
                'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? 'not set',
                'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'not set',
                'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? 'not set',
            ],
        ];
    }

    public static function testWorkerConnection(): bool
    {
        try {
            $workerUrl = self::getWorkerUrl();
            
            // Test with a simple array task
            self::postAsync($workerUrl, [
                'payload' => json_encode([
                    'type' => 'array',
                    'data' => ['test' => 'connection']
                ])
            ]);
            
            return true;
        } catch (\Exception $e) {
            if (self::$config['log_errors']) {
                error_log("FiberAsync connection test failed: " . $e->getMessage());
            }
            return false;
        }
    }
}