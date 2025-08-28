<?php
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Access-Control-Allow-Origin: *");
header("Connection: keep-alive");

function sendSSE($data, $event = null, $id = null) {
    if ($id !== null) echo "id: $id\n";
    if ($event) echo "event: $event\n";
    echo "data: $data\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// Send exactly 5 events with delays to simulate real streaming
for ($i = 1; $i <= 5; $i++) {
    $timestamp = date("H:i:s");
    sendSSE("Controlled test message #{$i} sent at $timestamp", "message", $i);
    
    if ($i < 5) {
        sleep(1); // 1 second delay between events
    }
}

// Send final event to indicate completion
sendSSE("Stream completed successfully", "done", "finished");
?>