<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Testing\TestingHttpHandler;

echo "====== Easy HTTP Testing Example ======\n\n";

try {
    Task::run(function () {
        // Enable testing mode - this is all you need!
        $testHandler = Http::testing();

        echo "1. Setting up mocks using the static API...\n";

        // Mock requests directly through Http::mock()
        Http::mock('GET')
            ->url('https://api.github.com/users/octocat')
            ->respondWithStatus(200)
            ->json([
                'login' => 'octocat',
                'id' => 1,
                'name' => 'The Octocat',
                'public_repos' => 8,
            ])
            ->delay(0.1)
            ->register()
        ;

        Http::mock('POST')
            ->url('https://api.github.com/repos/*/issues')
            ->withHeader('Authorization', 'Bearer github-token')
            ->withJson(['title' => 'Bug report', 'body' => 'Something is broken'])
            ->respondWithStatus(201)
            ->json(['id' => 123, 'number' => 1, 'state' => 'open'])
            ->register()
        ;

        // Mock a download with larger content for better testing
        Http::mock('GET')
            ->url('https://github.com/*/archive/main.zip')
            ->respondWithStatus(200)
            ->body(str_repeat('ZIP_FILE_CONTENT_', 100)) // 1.8KB of content
            ->header('Content-Type', 'application/zip')
            ->header('Content-Length', '1800')
            ->register()
        ;

        echo "2. Making HTTP requests (these will be mocked)...\n";

        // Regular HTTP requests - no changes needed in your application code!
        $userResponse = await(Http::get('https://api.github.com/users/octocat'));
        echo "   ✓ GET user: {$userResponse->status()}\n";

        $userData = $userResponse->json();
        echo "   ✓ User: {$userData['login']} ({$userData['public_repos']} repos)\n";

        // POST request with authentication
        $issueResponse = await(Http::request()
            ->bearerToken('github-token')
            ->post('https://api.github.com/repos/octocat/Hello-World/issues', [
                'title' => 'Bug report',
                'body' => 'Something is broken',
            ]));

        echo "   ✓ Created issue: #{$issueResponse->json()['number']}\n";

        // File download with cross-platform path
        $downloadPath = TestingHttpHandler::getTempPath('test_repo.zip');
        $downloadResult = await(Http::download(
            'https://github.com/octocat/Hello-World/archive/main.zip',
            $downloadPath
        ));

        echo "   ✓ Downloaded: {$downloadResult['size']} bytes to {$downloadPath}\n";

        echo "3. Making assertions using the static API...\n";

        // Super easy assertions
        $testHandler->assertRequestMade('GET', 'https://api.github.com/users/octocat');
        $testHandler->assertRequestMade('POST', 'https://api.github.com/repos/octocat/Hello-World/issues');
        $testHandler->assertRequestCount(3); // GET user + POST issue + GET download

        echo "   ✓ All assertions passed!\n";

        echo "4. Testing with network simulation...\n";

        // Reset and enable network simulation
        $testHandler->reset();
        $testHandler->enableNetworkSimulation([
            'failure_rate' => 0.2,  // 20% failure rate
            'default_delay' => 0.05, // 50ms delay
        ]);

        // Mock a persistent endpoint
        Http::mock('GET')
            ->url('https://httpbin.org/status/200')
            ->respondWithStatus(200)
            ->body('OK')
            ->persistent()
            ->register()
        ;

        $successCount = 0;
        $failureCount = 0;

        for ($i = 0; $i < 10; $i++) {
            try {
                await(Http::get('https://httpbin.org/status/200'));
                $successCount++;
            } catch (Exception $e) {
                $failureCount++;
            }
        }

        echo "   ✓ Simulation results: {$successCount} success, {$failureCount} failures\n";

        // Show request history
        $history = $testHandler->getRequestHistory();
        echo '   ✓ Made '.count($history)." requests total\n";

        // Clean up downloaded file
        if (file_exists($downloadPath)) {
            unlink($downloadPath);
            echo "   ✓ Cleaned up downloaded file\n";
        }

        // Stop testing mode (return to normal HTTP operations)
        Http::stopTesting();

        echo "5. Back to normal HTTP operations\n";
        echo "   ✓ Testing mode disabled, Http client restored\n";
    });

} catch (Exception $e) {
    echo "\n!!!!!! TEST FAILED !!!!!!\n";
    echo 'Error: '.$e->getMessage()."\n";
    echo "Trace:\n".$e->getTraceAsString()."\n";
} finally {
    // Always clean up testing state
    Http::reset();
}

echo "\n====== Testing Complete ======\n";

echo "====== Fully Automatic HTTP Testing ======\n\n";

try {
    Task::run(function () {
        // Enable testing - that's it!
        Http::testing();

        echo "1. Setting up automatic downloads...\n";

        // Mock downloads with automatic temp file handling
        Http::mock('GET')
            ->url('https://files.example.com/document.pdf')
            ->downloadFile('PDF_CONTENT_HERE', 'document.pdf', 'application/pdf')
            ->register()
        ;

        Http::mock('GET')
            ->url('https://files.example.com/large-file.zip')
            ->downloadLargeFile(50, 'large-file.zip') // 50KB mock file
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/export/*')
            ->downloadFile('CSV,DATA,HERE', null, 'text/csv') // No filename = auto-generated
            ->register()
        ;

        echo "2. Downloads with ZERO path configuration...\n";

        // Download 1: No destination = automatic temp file
        $result1 = await(Http::download('https://files.example.com/document.pdf'));
        echo "   ✓ Auto-downloaded PDF to: {$result1['file']}\n";
        echo "   ✓ File size: {$result1['size']} bytes\n";

        // Download 2: Large file, no destination = automatic temp file
        $result2 = await(Http::download('https://files.example.com/large-file.zip'));
        echo "   ✓ Auto-downloaded ZIP to: {$result2['file']}\n";
        echo "   ✓ File size: {$result2['size']} bytes\n";

        // Download 3: No filename in mock = auto-generated filename
        $result3 = await(Http::download('https://api.example.com/export/users.csv'));
        echo "   ✓ Auto-downloaded CSV to: {$result3['file']}\n";

        echo "3. Manual path still works if you want it...\n";

        // You can still specify paths if you want
        $customPath = TestingHttpHandler::getTempPath('my-custom-file.pdf');
        Http::mock('GET')
            ->url('https://files.example.com/custom.pdf')
            ->downloadFile('CUSTOM_PDF_CONTENT')
            ->register()
        ;

        $result4 = await(Http::download('https://files.example.com/custom.pdf', $customPath));
        echo "   ✓ Downloaded to custom path: {$result4['file']}\n";

        echo "4. Verify all files exist...\n";
        echo '   ✓ File 1 exists: '.(file_exists($result1['file']) ? 'YES' : 'NO')."\n";
        echo '   ✓ File 2 exists: '.(file_exists($result2['file']) ? 'YES' : 'NO')."\n";
        echo '   ✓ File 3 exists: '.(file_exists($result3['file']) ? 'YES' : 'NO')."\n";
        echo '   ✓ File 4 exists: '.(file_exists($result4['file']) ? 'YES' : 'NO')."\n";

        echo "5. Request history...\n";
        $testHandler = Http::getTestingHandler();
        $history = $testHandler->getRequestHistory();
        echo '   ✓ Made '.count($history)." download requests\n";

        echo "6. Automatic cleanup on reset...\n";
        Http::reset(); // This will automatically clean up ALL temp files

        echo "   ✓ After cleanup:\n";
        echo '     - File 1 exists: '.(file_exists($result1['file']) ? 'YES' : 'NO')."\n";
        echo '     - File 2 exists: '.(file_exists($result2['file']) ? 'YES' : 'NO')."\n";
        echo '     - File 3 exists: '.(file_exists($result3['file']) ? 'YES' : 'NO')."\n";
        echo '     - File 4 exists: '.(file_exists($result4['file']) ? 'YES' : 'NO')."\n";
    });

} catch (Exception $e) {
    echo "\n!!!!!! TEST FAILED !!!!!!\n";
    echo 'Error: '.$e->getMessage()."\n";
} finally {
    Http::reset(); // Always cleanup
}

echo "\n====== Fully Automatic Testing Complete ======\n";

echo "====== Enhanced HTTP Testing with Temp File Management ======\n\n";

try {
    Task::run(function () {
        // Enable testing mode
        $testHandler = Http::testing();

        echo "1. Setting up mocks and temp files...\n";

        // Create a temporary directory for this test session
        $tempDir = $testHandler->createTempDirectory('my_test_');
        echo "   ✓ Created temp directory: {$tempDir}\n";

        // Create some temporary files for testing
        $configFile = $testHandler->createTempFile('config.json', json_encode(['api_key' => 'test123']));
        echo "   ✓ Created temp config file: {$configFile}\n";

        // Mock API requests
        Http::mock('GET')
            ->url('https://api.example.com/config')
            ->respondWithStatus(200)
            ->json(['version' => '1.0', 'features' => ['auth', 'cache']])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://files.example.com/download/*')
            ->respondWithStatus(200)
            ->body('LARGE_FILE_CONTENT_'.str_repeat('DATA', 250)) // 1KB content
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Length', '1000')
            ->register()
        ;

        echo "2. Making requests with automatic temp file handling...\n";

        // Regular API call
        $configResponse = await(Http::get('https://api.example.com/config'));
        echo "   ✓ Config API: {$configResponse->status()}\n";

        // Download using the automatic temp path helper
        $downloadPath = TestingHttpHandler::getTempPath('downloaded_file.bin');
        $downloadResult = await(Http::download(
            'https://files.example.com/download/file.bin',
            $downloadPath
        ));

        echo "   ✓ Downloaded {$downloadResult['size']} bytes to: {$downloadPath}\n";

        // Download to the custom temp directory
        $customDownloadPath = $tempDir.DIRECTORY_SEPARATOR.'custom_file.txt';
        Http::mock('GET')
            ->url('https://files.example.com/text/*')
            ->respondWithStatus(200)
            ->body('This is a text file for testing purposes.')
            ->header('Content-Type', 'text/plain')
            ->register()
        ;

        $customResult = await(Http::download(
            'https://files.example.com/text/sample.txt',
            $customDownloadPath
        ));

        echo "   ✓ Downloaded to custom dir: {$customDownloadPath}\n";

        echo "3. Verifying temp files exist...\n";
        echo '   ✓ Config file exists: '.(file_exists($configFile) ? 'YES' : 'NO')."\n";
        echo '   ✓ Downloaded file exists: '.(file_exists($downloadPath) ? 'YES' : 'NO')."\n";
        echo '   ✓ Custom file exists: '.(file_exists($customDownloadPath) ? 'YES' : 'NO')."\n";

        // Show what's in the files
        if (file_exists($configFile)) {
            echo '   ✓ Config content: '.file_get_contents($configFile)."\n";
        }

        echo "4. Testing multiple temp directories...\n";
        $tempDir2 = $testHandler->createTempDirectory('another_test_');
        $tempDir3 = $testHandler->createTempDirectory('third_test_');
        echo "   ✓ Created additional temp dirs: {$tempDir2}, {$tempDir3}\n";

        // Create files in different directories
        $file1 = $testHandler->createTempFile('subdir'.DIRECTORY_SEPARATOR.'nested.txt', 'nested content');
        $file2 = $tempDir2.DIRECTORY_SEPARATOR.'file2.txt';
        file_put_contents($file2, 'manual file');

        echo "   ✓ Created nested file: {$file1}\n";
        echo "   ✓ Created manual file: {$file2}\n";

        echo "5. Making assertions...\n";
        $testHandler->assertRequestMade('GET', 'https://api.example.com/config');
        $testHandler->assertRequestMade('GET', 'https://files.example.com/download/file.bin');
        $testHandler->assertRequestCount(3);
        echo "   ✓ All assertions passed!\n";

        echo "6. Automatic cleanup on reset...\n";
        echo "   ✓ Before reset - temp files exist\n";

        // Reset will automatically clean up all tracked files and directories
        $testHandler->reset();

        echo "   ✓ After reset:\n";
        echo '     - Config file exists: '.(file_exists($configFile) ? 'YES' : 'NO')."\n";
        echo '     - Downloaded file exists: '.(file_exists($downloadPath) ? 'YES' : 'NO')."\n";
        echo '     - Custom file exists: '.(file_exists($customDownloadPath) ? 'YES' : 'NO')."\n";
        echo '     - Temp dir 1 exists: '.(is_dir($tempDir) ? 'YES' : 'NO')."\n";
        echo '     - Temp dir 2 exists: '.(is_dir($tempDir2) ? 'YES' : 'NO')."\n";
        echo '     - Temp dir 3 exists: '.(is_dir($tempDir3) ? 'YES' : 'NO')."\n";

        Http::stopTesting();
        echo "   ✓ Testing mode disabled\n";
    });

} catch (Exception $e) {
    echo "\n!!!!!! TEST FAILED !!!!!!\n";
    echo 'Error: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
} finally {
    // Always ensure cleanup
    Http::reset();
}

echo "\n====== Enhanced Testing Complete ======\n";
