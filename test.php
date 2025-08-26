<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\DB;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\QueryBuilder\AsyncQueryBuilder;

/**
 * Comprehensive test suite for AsyncQueryBuilder immutability features
 */
class AsyncQueryBuilderImmutabilityTest
{
    private array $results = [];
    private int $testCount = 0;
    private int $passedCount = 0;

    public function runAllTests(): void
    {
        echo "🚀 Starting AsyncQueryBuilder Immutability Tests\n";
        echo str_repeat("=", 50) . "\n";

        // Test immutable mode (default)
        $this->testImmutableMode();
        
        // Test mutable mode
        $this->testMutableMode();
        
        // Test complex query building in both modes
        $this->testComplexQueryBuilding();
        
        // Test async operations
        $this->testAsyncOperations();
        
        // Test concurrent operations
        $this->testConcurrentOperations();

        $this->printSummary();
    }

    private function testImmutableMode(): void
    {
        echo "\n📋 Testing Immutable Mode (Default)\n";
        echo str_repeat("-", 30) . "\n";

        run(async(function() {
            // Ensure immutable mode
            putenv('DB_IMMUTABLE_QUERY_BUILDER=true');
            AsyncQueryBuilder::resetImmutabilityCache();
            
            // Test basic immutability
            await($this->assertImmutableBehavior());
            
            // Test guard rails
            await($this->assertGuardRails());
            
            // Test method chaining doesn't affect original
            await($this->assertMethodChaining());
        }));
    }

    private function testMutableMode(): void
    {
        echo "\n🔄 Testing Mutable Mode\n";
        echo str_repeat("-", 30) . "\n";

        run(async(function() {
            // Switch to mutable mode
            putenv('DB_IMMUTABLE_QUERY_BUILDER=false');
            AsyncQueryBuilder::resetImmutabilityCache();
            
            // Test mutable behavior
            await($this->assertMutableBehavior());
            
            // Test clone() and mutate() work
            await($this->assertMutableMethods());
        }));
    }

    private function testComplexQueryBuilding(): void
    {
        echo "\n🏗️ Testing Complex Query Building\n";
        echo str_repeat("-", 30) . "\n";

        run_all([
            'immutable_complex' => async(function() {
                putenv('DB_IMMUTABLE_QUERY_BUILDER=true');
                AsyncQueryBuilder::resetImmutabilityCache();
                return await($this->buildComplexQuery());
            }),
            'mutable_complex' => async(function() {
                putenv('DB_IMMUTABLE_QUERY_BUILDER=false');
                AsyncQueryBuilder::resetImmutabilityCache();
                return await($this->buildComplexQuery());
            })
        ]);
    }

    private function testAsyncOperations(): void
    {
        echo "\n⚡ Testing Async Database Operations\n";
        echo str_repeat("-", 30) . "\n";

        run(async(function() {
            putenv('DB_IMMUTABLE_QUERY_BUILDER=true');
            AsyncQueryBuilder::resetImmutabilityCache();
            
            // Test that immutability doesn't break async operations
            await($this->assertAsyncOperationsWork());
        }));
    }

    private function testConcurrentOperations(): void
    {
        echo "\n🔀 Testing Concurrent Operations\n";
        echo str_repeat("-", 30) . "\n";

        run(async(function() {
            await($this->assertConcurrentQueries());
        }));
    }

    private function assertImmutableBehavior(): PromiseInterface
    {
        return async(function() {
            $query1 = DB::table('users')->where('id', 1);
            $query2 = $query1->where('status', 'active');
            $query3 = $query2->orderBy('created_at', 'DESC');

            // All should be different instances
            $this->assert(
                $query1 !== $query2 && $query2 !== $query3 && $query1 !== $query3,
                "Query instances should be different in immutable mode"
            );

            // Original query should remain unchanged
            $this->assert(
                count($query1->getBindings()) === 1,
                "Original query should have only 1 binding"
            );

            $this->assert(
                count($query2->getBindings()) === 2,
                "Second query should have 2 bindings"
            );

            $this->assert(
                count($query3->getBindings()) === 2,
                "Third query should have 2 bindings (orderBy doesn't add bindings)"
            );

            // Test SQL generation
            $sql1 = $query1->toSql();
            $sql2 = $query2->toSql();
            $sql3 = $query3->toSql();

            $this->assert(
                str_contains($sql1, 'WHERE id = ?') && !str_contains($sql1, 'status'),
                "First query should only have id condition"
            );

            $this->assert(
                str_contains($sql2, 'WHERE id = ? AND status = ?'),
                "Second query should have both conditions"
            );

            $this->assert(
                str_contains($sql3, 'ORDER BY created_at DESC'),
                "Third query should have ORDER BY clause"
            );

            echo "✅ Immutable behavior working correctly\n";
        });
    }

    private function assertGuardRails(): PromiseInterface
    {
        return async(function() {
            $query = DB::table('users')->where('id', 1);

            // Test clone() throws exception
            try {
                $query->clone();
                $this->assert(false, "clone() should throw exception in immutable mode");
            } catch (\RuntimeException $e) {
                $this->assert(
                    str_contains($e->getMessage(), 'clone() method cannot be used'),
                    "clone() should throw appropriate exception message"
                );
                echo "✅ clone() guard rail working\n";
            }

            // Test mutate() throws exception
            try {
                $query->mutate();
                $this->assert(false, "mutate() should throw exception in immutable mode");
            } catch (\RuntimeException $e) {
                $this->assert(
                    str_contains($e->getMessage(), 'mutate() method cannot be used'),
                    "mutate() should throw appropriate exception message"
                );
                echo "✅ mutate() guard rail working\n";
            }
        });
    }

    private function assertMethodChaining(): PromiseInterface
    {
        return async(function() {
            $baseQuery = DB::table('users');

            // Build multiple variations from the same base
            $activeUsers = $baseQuery->where('status', 'active');
            $inactiveUsers = $baseQuery->where('status', 'inactive');
            $adminUsers = $baseQuery->where('role', 'admin');

            // Each should be different instances
            $this->assert(
                $activeUsers !== $inactiveUsers && 
                $inactiveUsers !== $adminUsers && 
                $activeUsers !== $adminUsers,
                "All query variations should be different instances"
            );

            // Each should have different SQL
            $activeSql = $activeUsers->toSql();
            $inactiveSql = $inactiveUsers->toSql();
            $adminSql = $adminUsers->toSql();

            $this->assert(
                str_contains($activeSql, "status = ?") && 
                !str_contains($activeSql, "role = ?"),
                "Active users query should only have status condition"
            );

            $this->assert(
                str_contains($adminSql, "role = ?") && 
                !str_contains($adminSql, "status = ?"),
                "Admin users query should only have role condition"
            );

            echo "✅ Method chaining preserves immutability\n";
        });
    }

    private function assertMutableBehavior(): PromiseInterface
    {
        return async(function() {
            $query = DB::table('users')->where('id', 1);
            $sameQuery = $query->where('status', 'active');

            // Should be the same instance in mutable mode
            $this->assert(
                $query === $sameQuery,
                "Query instances should be the same in mutable mode"
            );

            // Should have accumulated bindings
            $this->assert(
                count($query->getBindings()) === 2,
                "Mutable query should accumulate bindings"
            );

            echo "✅ Mutable behavior working correctly\n";
        });
    }

    private function assertMutableMethods(): PromiseInterface
    {
        return async(function() {
            $query = DB::table('users')->where('id', 1);

            // Test clone() works
            $cloned = $query->clone();
            $this->assert(
                $cloned !== $query,
                "clone() should create different instance in mutable mode"
            );

            $this->assert(
                $cloned->toSql() === $query->toSql(),
                "Cloned query should have same SQL"
            );

            // Test mutate() works (returns self)
            $mutated = $query->mutate();
            $this->assert(
                $mutated === $query,
                "mutate() should return same instance in mutable mode"
            );

            echo "✅ Mutable methods working correctly\n";
        });
    }

    private function buildComplexQuery(): PromiseInterface
    {
        return async(function() {
            $query = DB::table('users')
                ->select('id, name, email, created_at')
                ->leftJoin('profiles', 'users.id = profiles.user_id')
                ->where('users.status', 'active')
                ->where('users.age', '>', 18)
                ->whereIn('users.role', ['admin', 'moderator', 'user'])
                ->whereNotNull('users.email_verified_at')
                ->orWhere('users.is_premium', true)
                ->groupBy('users.id')
                ->having('COUNT(profiles.id)', '>', 0)
                ->orderBy('users.created_at', 'DESC')
                ->limit(50, 10);

            $sql = $query->toSql();
            $bindings = $query->getBindings();

            // Verify complex query structure
            $this->assert(
                str_contains($sql, 'LEFT JOIN profiles'),
                "Complex query should contain JOIN"
            );

            $this->assert(
                str_contains($sql, 'WHERE') && str_contains($sql, 'OR'),
                "Complex query should contain WHERE and OR conditions"
            );

            $this->assert(
                str_contains($sql, 'GROUP BY') && str_contains($sql, 'HAVING'),
                "Complex query should contain GROUP BY and HAVING"
            );

            $this->assert(
                str_contains($sql, 'ORDER BY') && str_contains($sql, 'LIMIT'),
                "Complex query should contain ORDER BY and LIMIT"
            );

            $this->assert(
                count($bindings) >= 5,
                "Complex query should have multiple bindings"
            );

            echo "✅ Complex query building works in current mode\n";
            return ['sql' => $sql, 'bindings' => $bindings];
        });
    }

    private function assertAsyncOperationsWork(): PromiseInterface
    {
        return async(function() {
            // Test that queries can be built and prepared for execution
            // (We're not actually executing against a real database)
            
            $queries = [
                DB::table('users')->where('status', 'active'),
                DB::table('posts')->where('published', true),
                DB::table('comments')->where('approved', true)
            ];

            // Test concurrent query building
            $results = await(all(array_map(function($query) {
                return async(function() use ($query) {
                    // Simulate some async work
                    await(delay(0.1));
                    return [
                        'sql' => $query->toSql(),
                        'bindings' => $query->getBindings()
                    ];
                });
            }, $queries)));

            $this->assert(
                count($results) === 3,
                "Should be able to build multiple queries concurrently"
            );

            foreach ($results as $result) {
                $this->assert(
                    !empty($result['sql']) && is_array($result['bindings']),
                    "Each query should have SQL and bindings"
                );
            }

            echo "✅ Async operations work with immutable queries\n";
        });
    }

    private function assertConcurrentQueries(): PromiseInterface
    {
        return async(function() {
            // Test building many queries concurrently
            $tasks = [];
            for ($i = 1; $i <= 10; $i++) {
                $tasks[] = async(function() use ($i) {
                    await(delay(0.05)); // Small delay to simulate work
                    
                    $query = DB::table('users')
                        ->where('id', $i)
                        ->where('status', 'active')
                        ->orderBy('created_at');
                    
                    return [
                        'id' => $i,
                        'sql' => $query->toSql(),
                        'binding_count' => count($query->getBindings())
                    ];
                });
            }

            $results = await(concurrent($tasks, 5));

            $this->assert(
                count($results) === 10,
                "Should handle concurrent query building"
            );

            foreach ($results as $result) {
                $this->assert(
                    $result['binding_count'] === 2 && !empty($result['sql']),
                    "Each concurrent query should be built correctly"
                );
            }

            echo "✅ Concurrent query building works\n";
        });
    }

    private function assert(bool $condition, string $message): void
    {
        $this->testCount++;
        
        if ($condition) {
            $this->passedCount++;
            $this->results[] = "✅ {$message}";
        } else {
            $this->results[] = "❌ {$message}";
            echo "❌ FAILED: {$message}\n";
        }
    }

    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "📊 Test Summary\n";
        echo str_repeat("=", 50) . "\n";
        
        echo "Total Tests: {$this->testCount}\n";
        echo "Passed: {$this->passedCount}\n";
        echo "Failed: " . ($this->testCount - $this->passedCount) . "\n";
        
        $percentage = $this->testCount > 0 ? round(($this->passedCount / $this->testCount) * 100, 2) : 0;
        echo "Success Rate: {$percentage}%\n";
        
        if ($this->passedCount === $this->testCount) {
            echo "\n🎉 All tests passed! Immutability implementation is working correctly.\n";
        } else {
            echo "\n⚠️  Some tests failed. Please check the implementation.\n";
        }
        
        echo "\n🔧 Environment Configuration Used:\n";
        echo "- DB_IMMUTABLE_QUERY_BUILDER: " . ($_ENV['DB_IMMUTABLE_QUERY_BUILDER'] ?? 'true') . "\n";
    }
}

echo "🧪 AsyncQueryBuilder Immutability Test Suite\n";
echo "Testing with async/await and loop helpers\n";
echo "==========================================\n";

try {
    $tester = new AsyncQueryBuilderImmutabilityTest();
    $tester->runAllTests();
} catch (Exception $e) {
    echo "❌ Test suite failed with exception: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}