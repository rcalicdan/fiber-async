<?php

require 'vendor/autoload.php';

run(function () {
    $response = await(http_get("https://api.example.com", ['key' => 'value']));
    echo $response->body();
});
