<?php

use Rcalicdan\FiberAsync\Api\DB;
use Rcalicdan\FiberAsync\Config\ConfigLoader;

$dbFile = sys_get_temp_dir().'/advanced_query_test_'.uniqid().'.sqlite';

beforeAll(function () use ($dbFile) {
    run(function () use ($dbFile) {
        DB::reset();

        $fileBasedConfig = [
            'database' => [
                'default' => 'test_file',
                'connections' => [
                    'test_file' => ['driver' => 'sqlite', 'database' => $dbFile],
                ],
                'pool_size' => 5,
            ],
        ];

        $reflection = new ReflectionClass(ConfigLoader::class);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $instance = ConfigLoader::getInstance();
        $configProperty->setValue($instance, $fileBasedConfig);

        await(DB::rawExecute('
            CREATE TABLE sales_data (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_name VARCHAR(255),
                category VARCHAR(100),
                quantity_sold INTEGER,
                unit_price DECIMAL(10,2),
                order_date DATETIME
            )
        '));

        $data = [
            ['product_name' => 'Laptop', 'category' => 'Electronics', 'quantity_sold' => 5, 'unit_price' => 1200.00, 'order_date' => '2023-01-15'],
            ['product_name' => 'Mouse', 'category' => 'Electronics', 'quantity_sold' => 20, 'unit_price' => 25.00, 'order_date' => '2023-01-20'],
            ['product_name' => 'Keyboard', 'category' => 'Electronics', 'quantity_sold' => 15, 'unit_price' => 75.00, 'order_date' => '2023-02-01'],
            ['product_name' => 'Async PHP', 'category' => 'Books', 'quantity_sold' => 50, 'unit_price' => 40.00, 'order_date' => '2023-01-05'],
            ['product_name' => 'Pest Cookbook', 'category' => 'Books', 'quantity_sold' => 30, 'unit_price' => 30.00, 'order_date' => '2023-02-10'],
            ['product_name' => 'Stapler', 'category' => 'Office', 'quantity_sold' => 100, 'unit_price' => 10.00, 'order_date' => '2023-01-25'],
        ];

        await(DB::table('sales_data')->insertBatch($data));
    });
});

afterAll(function () use ($dbFile) {
    DB::reset();
    if (file_exists($dbFile)) {
        unlink($dbFile);
    }
});

describe('AsyncQueryBuilder Advanced Expressions and Aggregates', function () {
    it('can use raw expressions in select and order by with a CASE statement', function () {
        run(function () {
            $revenueExpression = 'quantity_sold * unit_price';
            $tierExpression = "CASE WHEN {$revenueExpression} >= 6000 THEN 'Tier A' WHEN {$revenueExpression} >= 2000 THEN 'Tier B' ELSE 'Tier C' END";
            $query = DB::table('sales_data')->select(['product_name', "$revenueExpression as total_revenue", "$tierExpression as performance_tier"])->orderBy('performance_tier')->orderBy('total_revenue', 'DESC');
            $result = await($query->get());
            expect($result)->toHaveCount(6)->and($result[0]['product_name'])->toBe('Laptop');
        });
    });

    it('can handle complex aggregate queries with multiple HAVING clauses', function () {
        run(function () {
            $query = DB::table('sales_data')
                ->select(['category', 'AVG(unit_price) as avg_price', 'COUNT(id) as item_count'])
                ->groupBy('category')
                ->havingRaw('COUNT(id) > 1 AND AVG(unit_price) > 100.0')
                ->orderBy('category')
            ;

            $result = await($query->get());

            expect($result)->toHaveCount(1);
            expect($result[0]['category'])->toBe('Electronics');
        });
    });

    it('can handle scalar subqueries in whereRaw', function () {
        run(function () {
            $subQuery = DB::table('sales_data')->select('AVG(unit_price)');
            $subSql = $subQuery->toSql();
            $query = DB::table('sales_data')->whereRaw("unit_price > ({$subSql})")->orderBy('unit_price', 'DESC');
            $result = await($query->get());
            expect($result)->toHaveCount(1)->and($result[0]['product_name'])->toBe('Laptop');
        });
    });
});
