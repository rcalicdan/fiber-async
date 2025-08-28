<?php

require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\Socket;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Socket\AsyncSocketOperations;

class FiberAsyncProxy
{
    private string $host;
    private int $port;
    private bool $debug;
    private EventLoop $eventLoop;
    private AsyncSocketOperations $socketOps;
    private array $stats = [
        'connections' => 0,
        'requests' => 0,
        'bytes_transferred' => 0,
        'start_time' => 0,
        'active_connections' => 0,
    ];
    private bool $running = false;
    private $serverSocket = null;

    public function __construct(string $host = '127.0.0.1', int $port = 8888, bool $debug = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->debug = $debug;
        $this->eventLoop = EventLoop::getInstance();
        $this->socketOps = new AsyncSocketOperations();
        $this->stats['start_time'] = time();
    }

    public function start(): void
    {
        $this->running = true;
        $this->log("FiberAsync Proxy starting on {$this->host}:{$this->port}");
        
        try {
            // Create server socket immediately
            $this->createServerSocket();
            
            // Start stats display timer
            $this->eventLoop->addTimer(5.0, [$this, 'printStats']);
            
            // Run the event loop
            $this->eventLoop->run();
        } catch (Exception $e) {
            $this->log("Server start failed: " . $e->getMessage());
            throw $e;
        }
    }

    private function createServerSocket(): void
    {
        // Create server socket with proper options
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'backlog' => 128,
            ]
        ]);

        $this->serverSocket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno, $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->serverSocket) {
            throw new Exception("Failed to create server socket: {$errstr} (errno: {$errno})");
        }

        stream_set_blocking($this->serverSocket, false);
        $this->log("Server socket created and listening...");

        // Accept connections asynchronously
        $this->eventLoop->addStreamWatcher($this->serverSocket, function() {
            $this->acceptConnection();
        });
    }

    private function acceptConnection(): void
    {
        // Accept multiple connections in one go
        while (($clientSocket = @stream_socket_accept($this->serverSocket, 0, $peername)) !== false) {
            $this->stats['connections']++;
            $this->stats['active_connections']++;
            
            $this->log("New connection from {$peername}");

            // Handle client in async context with proper error handling
            async(function() use ($clientSocket, $peername) {
                try {
                    await($this->handleClient($clientSocket, $peername));
                } catch (Exception $e) {
                    $this->log("Client handler error for {$peername}: " . $e->getMessage());
                } finally {
                    $this->stats['active_connections']--;
                }
            });
        }
    }

    private function handleClient($clientSocket, string $peername)
    {
        return async(function() use ($clientSocket, $peername) {
            $socket = null;
            try {
                stream_set_blocking($clientSocket, false);
                stream_set_timeout($clientSocket, 30);
                $socket = new Socket($clientSocket, $this->socketOps);

                // Read the HTTP request with better timeout handling
                $request = await(timeout($this->readHttpRequest($socket), 30.0));
                
                if (empty($request)) {
                    $this->log("Empty request from {$peername}");
                    return;
                }

                $this->stats['requests']++;
                $requestLine = strtok($request, "\r\n");
                $this->log("Processing request from {$peername}: {$requestLine}");

                // Parse the request
                $parsedRequest = $this->parseHttpRequest($request);
                
                if (!$parsedRequest) {
                    await($this->sendErrorResponse($socket, 400, 'Bad Request'));
                    return;
                }

                // Handle the request based on method
                if ($parsedRequest['method'] === 'CONNECT') {
                    await($this->handleConnect($socket, $parsedRequest, $peername));
                } else {
                    await($this->handleHttp($socket, $parsedRequest, $peername));
                }

            } catch (Exception $e) {
                $this->log("Error handling client {$peername}: " . $e->getMessage());
                if ($socket) {
                    try {
                        await($this->sendErrorResponse($socket, 500, 'Internal Server Error'));
                    } catch (Exception $sendError) {
                        $this->log("Failed to send error response: " . $sendError->getMessage());
                    }
                }
            } finally {
                if ($socket) {
                    $socket->close();
                }
                $this->log("Connection closed for {$peername}");
            }
        });
    }

    private function readHttpRequest(Socket $socket)
    {
        return async(function() use ($socket) {
            $request = '';
            $maxSize = 1024 * 1024; // 1MB max request size
            $headerComplete = false;
            $contentLength = 0;
            
            while (strlen($request) < $maxSize) {
                try {
                    // Read in smaller chunks for better responsiveness
                    $data = await($socket->read(4096, 10.0));
                    
                    if ($data === null || $data === '') {
                        // Socket closed or no more data
                        break;
                    }

                    $request .= $data;
                    $this->stats['bytes_transferred'] += strlen($data);

                    // Check if we have complete headers
                    if (!$headerComplete && strpos($request, "\r\n\r\n") !== false) {
                        $headerComplete = true;
                        $headerEndPos = strpos($request, "\r\n\r\n") + 4;
                        $headers = substr($request, 0, $headerEndPos);
                        
                        // Check for Content-Length
                        if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $matches)) {
                            $contentLength = (int) $matches[1];
                        }
                    }
                    
                    // If headers are complete, check if we have all the body
                    if ($headerComplete) {
                        if ($contentLength === 0) {
                            // No body expected
                            break;
                        } else {
                            $headerEndPos = strpos($request, "\r\n\r\n") + 4;
                            $currentBodyLength = strlen($request) - $headerEndPos;
                            if ($currentBodyLength >= $contentLength) {
                                // We have the complete request
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->log("Error reading request: " . $e->getMessage());
                    break;
                }
            }

            return $request;
        });
    }

    private function parseHttpRequest(string $request): ?array
    {
        if (empty($request)) {
            return null;
        }

        $lines = explode("\r\n", $request);
        
        if (empty($lines[0])) {
            return null;
        }

        // Parse request line: METHOD URL HTTP/VERSION
        if (!preg_match('/^(\w+)\s+(.+)\s+HTTP\/([\d.]+)$/', $lines[0], $matches)) {
            return null;
        }

        $method = $matches[1];
        $url = $matches[2];
        $version = $matches[3];
        $headers = [];

        // Parse headers
        for ($i = 1; $i < count($lines); $i++) {
            if (empty($lines[$i])) {
                break;
            }
            
            if (strpos($lines[$i], ':') !== false) {
                [$name, $value] = explode(':', $lines[$i], 2);
                $headers[trim($name)] = trim($value);
            }
        }

        // Extract body if present
        $headerEndPos = strpos($request, "\r\n\r\n");
        $body = $headerEndPos !== false ? substr($request, $headerEndPos + 4) : '';

        return [
            'method' => $method,
            'url' => $url,
            'version' => $version,
            'headers' => $headers,
            'body' => $body,
            'raw' => $request
        ];
    }

    private function handleConnect(Socket $clientSocket, array $request, string $peername)
    {
        return async(function() use ($clientSocket, $request, $peername) {
            $this->log("CONNECT request from {$peername} to {$request['url']}");

            // Parse host:port from CONNECT URL
            if (!preg_match('/^([^:]+):(\d+)$/', $request['url'], $matches)) {
                await($this->sendErrorResponse($clientSocket, 400, 'Bad CONNECT Request'));
                return;
            }

            $targetHost = $matches[1];
            $targetPort = (int) $matches[2];

            $targetSocket = null;
            try {
                // Connect to target server asynchronously with proper timeout
                $targetSocket = await(timeout(
                    $this->socketOps->connect("tcp://{$targetHost}:{$targetPort}", 15.0),
                    20.0
                ));

                // Send success response immediately
                $response = "HTTP/1.1 200 Connection established\r\n\r\n";
                await($clientSocket->write($response));

                $this->log("CONNECT tunnel established: {$peername} -> {$targetHost}:{$targetPort}");

                // Start bidirectional tunneling
                await($this->tunnel($clientSocket, $targetSocket, $peername));

            } catch (Exception $e) {
                $this->log("CONNECT failed for {$peername}: " . $e->getMessage());
                if ($targetSocket) {
                    $targetSocket->close();
                }
                await($this->sendErrorResponse($clientSocket, 502, 'Bad Gateway - ' . $e->getMessage()));
            }
        });
    }

    private function handleHttp(Socket $clientSocket, array $request, string $peername)
    {
        return async(function() use ($clientSocket, $request, $peername) {
            $this->log("HTTP {$request['method']} request from {$peername} to {$request['url']}");

            try {
                // Prepare headers, removing proxy-specific ones
                $headers = $this->filterHeaders($request['headers']);
                
                // Add Connection: close to prevent keep-alive issues
                $headers['Connection'] = 'close';

                // Build the HTTP request
                $httpRequest = "{$request['method']} {$request['url']} HTTP/{$request['version']}\r\n";
                foreach ($headers as $name => $value) {
                    $httpRequest .= "{$name}: {$value}\r\n";
                }
                $httpRequest .= "\r\n";
                
                if (!empty($request['body'])) {
                    $httpRequest .= $request['body'];
                }

                // Parse URL to get target host and port
                $urlParts = parse_url($request['url']);
                if (!$urlParts) {
                    throw new Exception("Invalid URL: {$request['url']}");
                }

                $targetHost = $urlParts['host'];
                $targetPort = $urlParts['port'] ?? (($urlParts['scheme'] === 'https') ? 443 : 80);

                // Connect to target server
                $targetSocket = await(timeout(
                    $this->socketOps->connect("tcp://{$targetHost}:{$targetPort}", 15.0),
                    20.0
                ));

                // Send request to target server
                await($targetSocket->write($httpRequest));

                // Forward response back to client
                await($this->forwardRawResponse($targetSocket, $clientSocket));
                
                $targetSocket->close();
                $this->log("HTTP request completed for {$peername}");

            } catch (Exception $e) {
                $this->log("HTTP request failed for {$peername}: " . $e->getMessage());
                await($this->sendErrorResponse($clientSocket, 502, 'Bad Gateway - ' . $e->getMessage()));
            }
        });
    }

    private function tunnel(Socket $clientSocket, Socket $targetSocket, string $peername)
    {
        return async(function() use ($clientSocket, $targetSocket, $peername) {
            $this->log("Starting tunnel for {$peername}");

            try {
                // Create two async tasks for bidirectional data transfer
                $clientToTarget = async(function() use ($clientSocket, $targetSocket, $peername) {
                    try {
                        while (!$clientSocket->isClosed() && !$targetSocket->isClosed()) {
                            $data = await($clientSocket->read(8192, 1.0));
                            if ($data === null || $data === '') {
                                break;
                            }
                            
                            await($targetSocket->write($data));
                            $this->stats['bytes_transferred'] += strlen($data);
                        }
                    } catch (Exception $e) {
                        $this->log("Client->Target tunnel error for {$peername}: " . $e->getMessage());
                    }
                });

                $targetToClient = async(function() use ($clientSocket, $targetSocket, $peername) {
                    try {
                        while (!$clientSocket->isClosed() && !$targetSocket->isClosed()) {
                            $data = await($targetSocket->read(8192, 1.0));
                            if ($data === null || $data === '') {
                                break;
                            }
                            
                            await($clientSocket->write($data));
                            $this->stats['bytes_transferred'] += strlen($data);
                        }
                    } catch (Exception $e) {
                        $this->log("Target->Client tunnel error for {$peername}: " . $e->getMessage());
                    }
                });

                // Wait for either direction to complete with timeout
                await(timeout(race([$clientToTarget, $targetToClient]), 300.0)); // 5 minute tunnel timeout
                
            } catch (Exception $e) {
                $this->log("Tunnel error for {$peername}: " . $e->getMessage());
            } finally {
                $targetSocket->close();
                $this->log("Tunnel closed for {$peername}");
            }
        });
    }

    private function forwardRawResponse(Socket $sourceSocket, Socket $targetSocket)
    {
        return async(function() use ($sourceSocket, $targetSocket) {
            try {
                while (!$sourceSocket->isClosed() && !$targetSocket->isClosed()) {
                    $data = await($sourceSocket->read(8192, 5.0));
                    if ($data === null || $data === '') {
                        break;
                    }
                    
                    await($targetSocket->write($data));
                    $this->stats['bytes_transferred'] += strlen($data);
                }
            } catch (Exception $e) {
                $this->log("Error forwarding response: " . $e->getMessage());
                throw $e;
            }
        });
    }

    private function sendErrorResponse(Socket $socket, int $code, string $message)
    {
        return async(function() use ($socket, $code, $message) {
            $body = "Proxy Error: {$message}";
            $response = "HTTP/1.1 {$code} {$message}\r\n";
            $response .= "Content-Type: text/plain\r\n";
            $response .= "Content-Length: " . strlen($body) . "\r\n";
            $response .= "Connection: close\r\n\r\n";
            $response .= $body;

            try {
                await($socket->write($response));
            } catch (Exception $e) {
                $this->log("Failed to send error response: " . $e->getMessage());
            }
        });
    }

    private function filterHeaders(array $headers): array
    {
        // Remove proxy-specific and connection-specific headers
        $skipHeaders = [
            'proxy-connection', 
            'proxy-authorization', 
            'proxy-authenticate',
            'connection',
            'upgrade',
            'te',
            'trailer',
        ];

        $filtered = [];
        foreach ($headers as $name => $value) {
            if (!in_array(strtolower($name), $skipHeaders)) {
                $filtered[$name] = $value;
            }
        }

        return $filtered;
    }

    public function printStats(): void
    {
        $uptime = time() - $this->stats['start_time'];
        $mb = round($this->stats['bytes_transferred'] / 1024 / 1024, 2);
        
        echo "\n=== Proxy Stats ===\n";
        echo "Uptime: {$uptime}s\n";
        echo "Total Connections: {$this->stats['connections']}\n";
        echo "Active Connections: {$this->stats['active_connections']}\n";
        echo "Total Requests: {$this->stats['requests']}\n";
        echo "Data Transferred: {$mb} MB\n";
        echo "Memory Usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB\n";
        echo "==================\n\n";

        // Schedule next stats print
        if ($this->running) {
            $this->eventLoop->addTimer(5.0, [$this, 'printStats']);
        }
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            echo "[" . date('H:i:s') . "] {$message}\n";
        }
    }

    public function stop(): void
    {
        $this->running = false;
        if ($this->serverSocket) {
            fclose($this->serverSocket);
        }
        $this->eventLoop->stop();
    }
}

if (php_sapi_name() === 'cli') {
    $proxy = new FiberAsyncProxy('127.0.0.1', 8888, true);
    
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGINT, function() use ($proxy) {
            echo "\nShutting down proxy...\n";
            $proxy->stop();
            exit(0);
        });
    }
    
    try {
        $proxy->start();
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}