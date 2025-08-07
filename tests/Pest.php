<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncHttp;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;

pest()->extend(Tests\TestCase::class)->in('Feature', 'Integration');
pest()->extend(Tests\TestCase::class)->in('Unit');
pest()->extend(Tests\TestCase::class)->in('Performance');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

// Your existing custom expectation is preserved.
expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Resets all core singletons and clears test state.
 *
 * This function is the single source of truth for test setup. By calling it
 * in each test file's `beforeEach` hook, we ensure perfect test isolation.
 */
function resetEventLoop()
{
    EventLoop::reset();
    Async::reset();
    AsyncHttp::reset();
    clearFilesystemCache();
}

/**
 * Helper to recursively delete the default filesystem cache directory.
 */
function clearFilesystemCache()
{
    $cacheDir = getcwd().'/cache/http';
    if (! is_dir($cacheDir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        ($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
    }
    @rmdir($cacheDir);
}

function something()
{
    // ..
}
