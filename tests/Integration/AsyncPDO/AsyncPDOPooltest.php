<?php

use Rcalicdan\FiberAsync\PDO\AsyncPdoPool;
use Rcalicdan\FiberAsync\PDO\DatabaseConfigFactory;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Promise;

function pdo_id(PDO $pdo): int
{
    return spl_object_id($pdo);
}

describe('AsyncPdoPool Initialization and Configuration', function () {
    it('initializes with a valid configuration', function () {
        $config = DatabaseConfigFactory::sqlite(':memory:');
        $pool = new AsyncPdoPool($config, 5);
        $stats = $pool->getStats();

        expect($stats['max_size'])->toBe(5);
        expect($stats['config_validated'])->toBeTrue();
    });

    it('throws an exception for empty configuration', function () {
        expect(fn() => new AsyncPdoPool([], 5))
            ->toThrow(InvalidArgumentException::class, 'Database configuration cannot be empty');
    });

    it('throws an exception for missing driver', function () {
        expect(fn() => new AsyncPdoPool(['database' => ':memory:'], 5))
            ->toThrow(InvalidArgumentException::class, "field 'driver' must be a non-empty string");
    });

    it('throws an exception for missing required fields for a driver', function () {
        expect(fn() => new AsyncPdoPool(['driver' => 'mysql'], 5))
            ->toThrow(InvalidArgumentException::class, "field 'host' cannot be empty for driver 'mysql'");
    });
});

describe('AsyncPdoPool Basic Operations', function () {
    beforeEach(function () {
        $config = DatabaseConfigFactory::sqlite(':memory:');
        $this->pool = new AsyncPdoPool($config, 3);
    });

    afterEach(function () {
        $this->pool->close();
        EventLoop::reset();
    });

    it('can get and release a connection', function () {
        run(function () {
            /** @var AsyncPdoPool $pool */
            $pool = $this->pool;
            $statsBefore = $pool->getStats();
            expect($statsBefore['active_connections'])->toBe(0)
                ->and($statsBefore['pooled_connections'])->toBe(0);

            $connection = await($pool->get());
            expect($connection)->toBeInstanceOf(PDO::class);

            $statsDuring = $pool->getStats();
            expect($statsDuring['active_connections'])->toBe(1)
                ->and($statsDuring['pooled_connections'])->toBe(0);

            $pool->release($connection);

            $statsAfter = $pool->getStats();
            expect($statsAfter['active_connections'])->toBe(1)
                ->and($statsAfter['pooled_connections'])->toBe(1);
        });
    });

    it('reuses a pooled connection', function () {
        run(function () {
            $pool = $this->pool;
            $connection1 = await($pool->get());
            $pool->release($connection1);
            $id1 = pdo_id($connection1);

            $connection2 = await($pool->get());
            $id2 = pdo_id($connection2);
            $pool->release($connection2);

            expect($id2)->toBe($id1);
            expect($pool->getStats()['pooled_connections'])->toBe(1);
        });
    });
});

describe('AsyncPdoPool Connection Limiting and Wait Queue', function () {
    afterEach(function () {
        EventLoop::reset();
    });
    
    it('queues requests when pool is full', function () {
        run(function () {
            $config = DatabaseConfigFactory::sqlite(':memory:');
            $pool = new AsyncPdoPool($config, 1);

            $firstGetPromise = $pool->get();
            $secondGetPromise = $pool->get();

            await(delay(0)); // Allow event loop to process requests

            expect($pool->getStats()['waiting_requests'])->toBe(1);
            
            $firstConnection = await($firstGetPromise);
            $pool->release($firstConnection);
            $secondConnection = await($secondGetPromise);
            $pool->release($secondConnection);
            
            $pool->close();
        });
    });
});

describe('AsyncPdoPool Concurrency and Health', function () {
    beforeEach(function () {
        $config = DatabaseConfigFactory::sqlite(':memory:');
        $this->pool = new AsyncPdoPool($config, 2);
    });

    afterEach(function () {
        $this->pool->close();
        EventLoop::reset();
    });

    it('handles multiple concurrent requests efficiently', function () {
        $pool = $this->pool;
        $tracker = new stdClass();
        $tracker->connectionIds = [];

        run(function () use ($pool, $tracker) {
            $promises = [];
            for ($i = 0; $i < 5; $i++) {
                $promises[] = async(function () use ($tracker, $pool) {
                    $connection = await($pool->get());
                    $tracker->connectionIds[pdo_id($connection)] = true;
                    await(delay(0.02));
                    $pool->release($connection);
                });
            }
            await(all($promises));
        });
        
        expect(count($tracker->connectionIds))->toBe(2);
    });

    it('rejects waiting promises when the pool is closed', function () {
        run(function () {
            $config = DatabaseConfigFactory::sqlite(':memory:');
            $pool = new AsyncPdoPool($config, 1);

            $pool->get();
            $waitingPromise = $pool->get();

            await(delay(0));

            $pool->close();

            $exceptionThrown = false;
            try {
                await($waitingPromise);
            } catch (RuntimeException $e) {
                $exceptionThrown = true;
                expect($e->getMessage())->toBe('Pool is being closed');
            }

            expect($exceptionThrown)->toBeTrue();
        });
    });
});