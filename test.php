<?php

use Rcalicdan\FiberAsync\Api\Promise;


require_once __DIR__ . '/vendor/autoload.php';

$microtime = microtime(true);
$results = run(function () {
    $googleStartTime = microtime(true);
    $facebookStartTime = microtime(true);
    $twitterStartTime = microtime(true);
    $instagramStartTime = microtime(true);

    $Promise = Promise::all([
        'google' => http()->get('https://www.google.com')
            ->then(function ($response) use ($googleStartTime) {
                $googleEndTime = microtime(true);
                echo 'Google: ' . ($googleEndTime - $googleStartTime) . PHP_EOL;
                echo $response->getStatusCode() . PHP_EOL;

            }),
        'facebook' => http()->get('https://www.facebook.com')
            ->then(function ($response) use ($facebookStartTime) {
                $facebookEndTime = microtime(true);
                echo 'Facebook: ' . ($facebookEndTime - $facebookStartTime) . PHP_EOL;
                echo $response->getStatusCode() . PHP_EOL;
            }),
        'linkedIn' => http()->get('https://www.linkedin.com')
            ->then(function ($response) use ($twitterStartTime) {
                $twitterEndTime = microtime(true);
                echo 'LinkedIn: ' . ($twitterEndTime - $twitterStartTime) . PHP_EOL;
                echo $response->getStatusCode() . PHP_EOL;
            }),
        'instagram' => http()->get('https://www.instagram.com')
            ->then(function ($response) use ($instagramStartTime) {
                $instagramEndTime = microtime(true);
                echo 'Instagram: ' . ($instagramEndTime - $instagramStartTime) . PHP_EOL;
                echo $response->getStatusCode() . PHP_EOL;
            }),
    ]);

    return await($Promise);
});

$microtime = microtime(true) - $microtime;
echo $microtime . PHP_EOL;
