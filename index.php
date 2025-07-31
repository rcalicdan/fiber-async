<?php
require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\Api\Promise;

const QUERY_COUNT = 1000;
const POOL_SIZE = 100;
const LATENCY_SECONDS = 0.01;

$mysqlConfig = [
    'driver' => 'mysql', 
    'host'     => 'localhost',
    'database' => 'yo',
    'username' => 'root',
    'password' => 'Reymart1234',
    'port'     => 3309,
    'charset' => 'utf8mb4'
];


function build_dsn_from_config(array $config): string
{
    return sprintf(
        "mysql:host=%s;port=%d;dbname=%s;charset=%s",
        $config['host'] ?? 'localhost',
        $config['port'] ?? 3306,
        $config['database'] ?? '',
        $config['charset'] ?? 'utf8mb4'
    );
}

function setup_performance_db_if_needed(PDO $pdo, int $rowCount): void
{
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM perf_test");
    if ($stmt && $stmt->fetch()['count'] == $rowCount) {
        return;
    }

    $pdo->exec("DROP TABLE IF EXISTS perf_test");
    $createSql = "CREATE TABLE perf_test (id INT AUTO_INCREMENT PRIMARY KEY, data TEXT)";
    $pdo->exec($createSql);
    $stmt = $pdo->prepare("INSERT INTO perf_test (data) VALUES (?)");
    for ($i = 0; $i < $rowCount; $i++) {
        $stmt->execute(['data-' . $i]);
    }
}


$page_start_time = microtime(true);
$method = $_GET['method'] ?? 'none'; 
$results = [];

try {
    $setupPdo = new PDO(build_dsn_from_config($mysqlConfig), $mysqlConfig['username'] ?? null, $mysqlConfig['password'] ?? null);
    setup_performance_db_if_needed($setupPdo, QUERY_COUNT);
    unset($setupPdo);
} catch (PDOException $e) {
    die("Database setup failed: " . $e->getMessage());
}


if ($method === 'hibla') {
    AsyncPDO::init($mysqlConfig, POOL_SIZE);

    $results = run(function () {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $tasks = [];
        for ($i = 0; $i < QUERY_COUNT; $i++) {
            $tasks[] = AsyncPDO::run(function (PDO $pdo) {
                $idToFind = rand(1, QUERY_COUNT);
                $stmt = $pdo->prepare("SELECT id FROM perf_test WHERE id = ?");
                $stmt->execute([$idToFind]);
                $stmt->fetch(PDO::FETCH_ASSOC);
                await(delay(LATENCY_SECONDS));
                return true;
            });
        }

        await(Promise::all($tasks));

        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage();
        return [
            'time' => $endTime - $startTime,
            'memory' => $endMemory - $startMemory,
            'qps' => QUERY_COUNT / ($endTime - $startTime)
        ];
    });
} elseif ($method === 'sync') {
    $dsn = build_dsn_from_config($mysqlConfig);
    $pdo = new PDO($dsn, $mysqlConfig['username'] ?? null, $mysqlConfig['password'] ?? null);
    $stmt = $pdo->prepare("SELECT id FROM perf_test WHERE id = ?");

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    for ($i = 0; $i < QUERY_COUNT; $i++) {
        $idToFind = rand(1, QUERY_COUNT);
        $stmt->execute([$idToFind]);
        $stmt->fetch(PDO::FETCH_ASSOC);
        usleep((int)(LATENCY_SECONDS * 1000000));
    }

    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage();
    $results = [
        'time' => $endTime - $startTime,
        'memory' => $endMemory - $startMemory,
        'qps' => QUERY_COUNT / ($endTime - $startTime)
    ];
}

$total_page_load_ms = (microtime(true) - $page_start_time) * 1000;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AsyncPDO Web Benchmark</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
        }

        h1,
        h2 {
            color: #111;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .links {
            margin: 30px 0;
        }

        .links a {
            margin-right: 20px;
            font-weight: bold;
            font-size: 1.2em;
            text-decoration: none;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
        }

        .btn-sync {
            background-color: #6c757d;
        }

        .btn-hibla {
            background-color: #28a745;
        }

        .perf-box {
            border: 2px solid #ddd;
            padding: 20px;
            margin-top: 20px;
            border-radius: 8px;
        }

        .perf-box h2 {
            margin-top: 0;
        }

        .perf-box td {
            padding: 10px;
        }

        .perf-box th {
            text-align: right;
            padding-right: 15px;
            color: #666;
        }

        .highlight {
            font-weight: bold;
            font-size: 1.3em;
            color: #000;
        }

        .note {
            background-color: #fff3cd;
            border-left: 4px solid #ffeeba;
            padding: 15px;
            margin-top: 30px;
        }

        .code {
            background-color: #eee;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }
    </style>
</head>

<body>

    <h1>AsyncPDO Web Context Benchmark</h1>
    <p>This page benchmarks the performance of running <strong><?php echo QUERY_COUNT; ?></strong> database queries with an artificial latency of <strong><?php echo (LATENCY_SECONDS * 1000); ?>ms</strong> each. The goal is to measure the total I/O time within a real web page request.</p>

    <div class="links">
        <a href="?method=sync" class="btn-sync">Run Synchronous Benchmark</a>
        <a href="?method=hibla" class="btn-hibla">Run Hibla Concurrent Benchmark</a>
    </div>

    <?php if ($method !== 'none'): ?>
        <div class="perf-box">
            <h2>Results for: <span class="highlight"><?php echo ($method === 'sync' ? 'Synchronous PDO' : 'Hibla AsyncPDO'); ?></span></h2>
            <table>
                <tr>
                    <th>Total I/O Time:</th>
                    <td><span class="highlight"><?php echo number_format($results['time'], 4); ?> s</span></td>
                </tr>
                <tr>
                    <th>Queries Per Second (QPS):</th>
                    <td><span class="highlight"><?php echo number_format($results['qps'], 2); ?></span></td>
                </tr>
                <tr>
                    <th>Peak Memory Usage (Delta):</th>
                    <td><span class="highlight"><?php echo number_format($results['memory'] / 1024, 2); ?> KB</span></td>
                </tr>
                <tr>
                    <th>Total Page Generation Time:</th>
                    <td><?php echo number_format($total_page_load_ms, 2); ?> ms</td>
                </tr>
            </table>
        </div>
    <?php endif; ?>

    <div class="note">
        <strong>How to Test:</strong> Click the buttons above to run the benchmarks. The "Total I/O Time" shows how long the database operations took. Notice how the Hibla Concurrent benchmark time is close to the latency of a single query, while the Synchronous time is close to <code class="code"><?php echo QUERY_COUNT; ?> * <?php echo (LATENCY_SECONDS * 1000); ?>ms</code>.
    </div>

</body>

</html>