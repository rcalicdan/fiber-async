<?php
// manual_loop_test.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\AsyncStream;

echo "🧪 Manual Event Loop Management Test\n";
echo "====================================\n";

// Test 1: Using run_stateful instead of run
echo "Test 1: Using run_stateful\n";
echo "--------------------------\n";

try {
    echo "🚀 Testing run_stateful...\n";
    
    $result = run_stateful(function() {
        echo "  📝 Opening file...\n";
        $stream = await(AsyncStream::open('stateful_test.txt', 'w'));
        
        echo "  ✍️ Writing...\n";
        await(AsyncStream::write($stream, "Stateful test"));
        
        echo "  🚿 Flushing...\n";
        await(AsyncStream::flush($stream));
        
        echo "  🔒 Closing...\n";
        AsyncStream::close($stream);
        
        echo "  🧹 Cleanup...\n";
        if (file_exists('stateful_test.txt')) {
            unlink('stateful_test.txt');
        }
        
        echo "  ✅ Done\n";
        return true;
    });
    
    echo "✅ run_stateful test: " . ($result ? 'PASS' : 'FAIL') . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Error with run_stateful: " . $e->getMessage() . "\n\n";
}

// Test 2: Using async + run combination
echo "Test 2: Using async function with run\n";
echo "-------------------------------------\n";

try {
    echo "🚀 Creating async function...\n";
    
    // Create the async function first
    $result = async(function() {
        echo "  📝 In async function - Opening file...\n";
        $stream = await(AsyncStream::open('async_func_test.txt', 'w'));
        
        echo "  ✍️ Writing...\n";
        await(AsyncStream::write($stream, "Async function test"));
        
        echo "  🚿 Flushing...\n";
        await(AsyncStream::flush($stream));
        
        echo "  🔒 Closing...\n";
        AsyncStream::close($stream);
        
        echo "  🧹 Cleanup...\n";
        if (file_exists('async_func_test.txt')) {
            unlink('async_func_test.txt');
        }
        
        echo "  ✅ Async function done\n";
        return true;
    })->await();
    
    echo "🔄 Running async function...\n";
    
    echo "✅ Async function test: " . ($result ? 'PASS' : 'FAIL') . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Error with async function: " . $e->getMessage() . "\n\n";
}

// Test 3: Reset AsyncStream between operations
echo "Test 3: Reset AsyncStream between operations\n";
echo "--------------------------------------------\n";

try {
    echo "🔄 Resetting AsyncStream...\n";
    AsyncStream::reset();
    
    echo "🚀 Testing after reset...\n";
    $result = run_with_timeout(function() {
        echo "  📝 Opening file after reset...\n";
        $stream = await(AsyncStream::open('reset_test.txt', 'w'));
        
        echo "  ✍️ Writing...\n";
        await(AsyncStream::write($stream, "Reset test"));
        
        echo "  🔒 Closing...\n";
        AsyncStream::close($stream);
        
        echo "  🧹 Cleanup...\n";
        if (file_exists('reset_test.txt')) {
            unlink('reset_test.txt');
        }
        
        echo "  ✅ Reset test done\n";
        return true;
    }, 3.0);
    
    echo "✅ Reset test: " . ($result ? 'PASS' : 'FAIL') . "\n\n";
    
} catch (Exception $e) {
    echo "❌ Error with reset test: " . $e->getMessage() . "\n\n";
}

echo "🏁 Manual loop management tests completed\n";
exit(0);