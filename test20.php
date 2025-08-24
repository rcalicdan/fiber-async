<?php

use Rcalicdan\FiberAsync\Api\HttpTestingAssistant;

require 'vendor/autoload.php';

const URL = 'https://api.github.com/users/octocat';

$mockClient = HttpTestingAssistant::fresh()->activate();

$mockClient->mock('GET')
    ->url(URL)
    ->respondWithStatus(200)
    ->header('Content-Type', 'application/json')
    ->header('Content-Length', '1800')
    ->json([
        "success" => true,
        "data" => [
            "login" => "octocat",
            "id" => 1,
            "node_id" => "MDQ6VXNlcjE=",
            "avatar_url" => "https://avatars.githubusercontent.com/u/583231?v=4",
            "gravatar_id" => "",
            "url" => "https://api.github.com/users/octocat",
            "html_url" => "https://github.com/octocat",
            "followers_url" => "https://api.github.com/users/octocat/followers",
            "following_url" => "https://api.github.com/users/octocat/following{/other_user}",
            "gists_url" => "https://api.github.com/users/octocat/gists{/gist_id}",
            "starred_url" => "https://api.github.com/users/octocat/starred{/owner}{/repo}",
            "subscriptions_url" => "https://api.github.com/users/octocat/subscriptions",
            "organizations_url" => "https://api.github.com/users/octocat/orgs",
            "repos_url" => "https://api.github.com/users/octocat/repos",
            "events_url" => "https://api.github.com/users/octocat/events{/privacy}",
            "received_events_url" => "https://api.github.com/users/octocat/received_events",
            "type" => "User",
            "site_admin" => false,
        ],
    ])
    ->delay(random_float(0.1, 0.5))
    ->register();


$start = microtime(true);
run(function () use ($mockClient) {
    $response = await(fetch(URL));
    echo json_encode($response->getHeaders(), JSON_PRETTY_PRINT);
});
$end = microtime(true);
echo "\nTime taken: " . ($end - $start) . " seconds\n";
