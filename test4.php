<?php 

require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;

run(function () {
  try {
        $response = await(http()->retry()->get('https://httpbin.org/ip'));

        $body = $response->getBody();
        echo "   Direct response body: " . substr($body, 0, 100) . "...\n";

        $data = json_decode($body, true);
        if ($data && isset($data['origin'])) {
            echo "   Direct IP: " . $data['origin'] . "\n";
            $directIp = $data['origin'];
        } else {
            echo "   Could not parse IP from response\n";
        }
    } catch (Exception $e) {
        echo "   Error: " . $e->getMessage() . "\n";
        return;
    }
});
