<?php

use Rcalicdan\FiberAsync\Background;

require_once __DIR__ . '/vendor/autoload.php';

Background::run(function () {
    sleep(5);
    file_put_contents(__DIR__ . "/log.txt", "Closure finished!\n", FILE_APPEND);
});

echo "Closure sent to background\n";
