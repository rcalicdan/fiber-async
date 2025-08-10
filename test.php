<?php

use Rcalicdan\FiberAsync\Promise\Promise;

require_once __DIR__.'/vendor/autoload.php';

$microtime = microtime(true);
$results = run(function () {
    $Promise = Promise::race([
        'google' => http()->get('https://www.google.com')->then(fn () => print "google wins\n"),
        'facebook' => http()->get('https://www.facebook.com')->then(fn () => print "facebook wins\n"),
        'linkedIn' => http()->get('https://www.linkedin.com')->then(fn () => print "linkedIn wins\n"),
        'instagram' => http()->get('https://www.instagram.com')->then(fn () => print "instagram wins\n"),
    ]);

    return await($Promise);
});

$microtime = microtime(true) - $microtime;
echo $microtime.PHP_EOL;
