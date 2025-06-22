<?php

use Rcalicdan\FiberAsync\Background;

require_once __DIR__ . '/vendor/autoload.php';

Background::run(function () {
    sleep(5);
    file_put_contents(__DIR__ . "/log.txt", "Task completed at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
});

echo "Task sent to background\n";
