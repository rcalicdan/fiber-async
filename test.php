<?php

require_once __DIR__ . '/vendor/autoload.php';


$testDir = __DIR__ . '/test_async_files';

function cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    rmdir($dir);
}

// Ensure a clean state before starting
cleanup($testDir);
mkdir($testDir, 0777, true);

echo "✅ Test environment prepared.\n\n";

// --- Running the Async Tests ---

try {
    run(function () use ($testDir) {
        echo "🏃‍ Running tests...\n";

        // 1. Test: write_file_async() and read_file_async()
        echo "  - Testing: Basic Write and Read\n";
        $testFile1 = $testDir . '/test1.txt';
        $content = 'Hello, async world! ' . rand();
        $bytesWritten = await(write_file_async($testFile1, $content));
        assert($bytesWritten > 0, 'write_file_async should return bytes written.');
        $readContent = await(read_file_async($testFile1));
        assert($readContent === $content, 'Read content should match written content.');
        echo "    ✔ Passed: Basic Write and Read\n";

        // 2. Test: file_exists_async()
        echo "  - Testing: File Exists\n";
        assert(await(file_exists_async($testFile1)) === true, 'file_exists_async should find existing file.');
        assert(await(file_exists_async($testDir . '/non_existent_file.txt')) === false, 'file_exists_async should not find non-existent file.');
        echo "    ✔ Passed: File Exists\n";

        // 3. Test: append_file_async()
        echo "  - Testing: Append to File\n";
        $appendedContent = ' -- More data.';
        await(append_file_async($testFile1, $appendedContent));
        $finalContent = await(read_file_async($testFile1));
        assert($finalContent === $content . $appendedContent, 'Appended content should be correct.');
        echo "    ✔ Passed: Append to File\n";

        // 4. Test: copy_file_async()
        echo "  - Testing: Copy File\n";
        $copiedFile = $testDir . '/test1_copy.txt';
        await(copy_file_async($testFile1, $copiedFile));
        assert(await(file_exists_async($copiedFile)) === true, 'Copied file should exist.');
        $copiedContent = await(read_file_async($copiedFile));
        assert($copiedContent === $finalContent, 'Copied content should match original.');
        echo "    ✔ Passed: Copy File\n";
        
        // 5. Test: rename_file_async()
        echo "  - Testing: Rename File\n";
        $renamedFile = $testDir . '/test1_renamed.txt';
        await(rename_file_async($testFile1, $renamedFile));
        assert(await(file_exists_async($testFile1)) === false, 'Old file should not exist after rename.');
        assert(await(file_exists_async($renamedFile)) === true, 'New file should exist after rename.');
        echo "    ✔ Passed: Rename File\n";
        
        // 6. Test: create_directory_async() (recursive)
        echo "  - Testing: Create Directory Recursively\n";
        $nestedDir = $testDir . '/a/b/c';
        await(create_directory_async($nestedDir, ['recursive' => true]));
        assert(is_dir($nestedDir), 'Nested directory should be created.');
        echo "    ✔ Passed: Create Directory Recursively\n";
        
        // 7. Test: file_stats_async() and helpers
        echo "  - Testing: File Stats\n";
        $statsContent = '1234567890';
        $statsFile = $testDir . '/stats.txt';
        await(write_file_async($statsFile, $statsContent));
        
        $stats = await(file_stats_async($statsFile));
        assert(is_array($stats) && $stats['size'] === 10, 'file_stats_async should return correct stats array.');
        
        $size = await(get_file_size_async($statsFile));
        assert($size === 10, 'get_file_size_async should return correct size.');

        $mtime = await(get_file_mtime_async($statsFile));
        assert($mtime > time() - 5, 'get_file_mtime_async should return a recent timestamp.');
        echo "    ✔ Passed: File Stats\n";

        // 8. Test: delete_file_async()
        echo "  - Testing: Delete File\n";
        await(delete_file_async($copiedFile));
        assert(await(file_exists_async($copiedFile)) === false, 'File should be deleted.');
        echo "    ✔ Passed: Delete File\n";
        
        // 9. Test: remove_directory_async() (recursive)
        echo "  - Testing: Remove Directory Recursively\n";
        // The /a/b/c structure is already created
        await(remove_directory_async($testDir . '/a'));
        assert(!is_dir($testDir . '/a'), 'Directory should be removed.');
        echo "    ✔ Passed: Remove Directory Recursively\n";

        // 10. Test Streaming copy
        echo "  - Testing: Streaming Copy\n";
        $largeContent = str_repeat("This is a test stream. ", 1000); // Create some content
        $sourceStreamFile = $testDir . '/source_stream.txt';
        $destStreamFile = $testDir . '/dest_stream.txt';
        await(write_file_async($sourceStreamFile, $largeContent));
        await(copy_file_stream_async($sourceStreamFile, $destStreamFile));
        $destContent = await(read_file_async($destStreamFile));
        assert($destContent === $largeContent, 'Stream-copied content must match the source.');
        echo "    ✔ Passed: Streaming Copy\n";


    });

    echo "\n🎉 \e[32mAll asynchronous I/O tests passed successfully!\e[0m\n";

} catch (Throwable $e) {
    echo "\n❌ \e[31mTest failed with exception:\e[0m\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    // --- Teardown ---
    cleanup($testDir);
    echo "✅ Test environment cleaned up.\n";
}