<?php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Exception;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\MysqlConfig;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\PendingCommand;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\PreparedStatement;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\StatementResult;
use Rcalicdan\FiberAsync\Handlers\Mysql\MysqlProtocolHandler;

class Connection
{
    public const STATE_DISCONNECTED = 0;
    public const STATE_CONNECTING = 1;
    public const STATE_ENABLING_CRYPTO = 2;
    public const STATE_AWAITING_HANDSHAKE = 3;
    public const STATE_AUTHENTICATING = 4;
    public const STATE_IDLE = 5;
    public const STATE_BUSY = 6;
    public const STATE_CLOSING = 7;

    private const PACKET_HEADER_LENGTH = 4;

    public int $state = self::STATE_DISCONNECTED;
    private $socket;
    private string $readBuffer = '';
    private ?PromiseInterface $connectPromise = null;
    private array $commandQueue = [];
    private ?object $currentCommandContext = null;

    public function __construct(
        private readonly Pool $pool,
        private readonly MysqlConfig $config,
        private readonly AsyncEventLoop $loop,
        private readonly MysqlProtocolHandler $protocol
    ) {}

    public function connect(): PromiseInterface
    {
        if ($this->connectPromise) {
            return $this->connectPromise;
        }

        $this->state = self::STATE_CONNECTING;
        $this->connectPromise = new \Rcalicdan\FiberAsync\AsyncPromise(function ($resolve, $reject) {
            $socket = stream_socket_client(
                $this->config->getConnectionString(),
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_ASYNC_CONNECT,
                stream_context_create($this->config->getSslContextOptions())
            );

            if (!$socket) {
                $this->state = self::STATE_DISCONNECTED;
                return $reject(new Exception("Connection failed: $errstr", $errno));
            }
            stream_set_blocking($socket, false);
            $this->socket = $socket;

            $this->loop->addStreamWatcher($this->socket, function () {
                $this->onReadable();
            });
        });

        $timerId = $this->loop->addTimer(10, function () {
            if ($this->state < self::STATE_IDLE) {
                $this->connectPromise?->reject(new Exception('Connection timed out'));
                $this->close();
            }
        });
        $this->connectPromise->finally(fn() => $this->loop->cancelTimer($timerId));
        $this->state = self::STATE_AWAITING_HANDSHAKE;

        return $this->connectPromise;
    }

    private function onReadable(): void
    {
        if (!is_resource($this->socket) || feof($this->socket)) {
            $this->close(new Exception("Connection lost"));
            return;
        }

        $data = fread($this->socket, 65535);
        if ($data === false || $data === '') return;
        $this->readBuffer .= $data;

        while (($packetLength = $this->getPacketLength()) !== null) {
            if (strlen($this->readBuffer) >= $packetLength + self::PACKET_HEADER_LENGTH) {
                $this->readBuffer = substr($this->readBuffer, self::PACKET_HEADER_LENGTH);
                $packet = substr($this->readBuffer, 0, $packetLength);
                $this->readBuffer = substr($this->readBuffer, $packetLength);
                $this->handlePacket($packet);
            } else {
                break;
            }
        }
    }

    private function getPacketLength(): ?int
    {
        if (strlen($this->readBuffer) < self::PACKET_HEADER_LENGTH) {
            return null;
        }
        $header = substr($this->readBuffer, 0, self::PACKET_HEADER_LENGTH);
        return unpack('V', $header)[1] & 0xFFFFFF;
    }

    private function handlePacket(string $packet): void
    {
        if ($this->state === self::STATE_AWAITING_HANDSHAKE) {
            $this->protocol->parseHandshakePacket($packet);
            $clientFlags = MysqlProtocolHandler::CLIENT_PROTOCOL_41
                | MysqlProtocolHandler::CLIENT_SECURE_CONNECTION
                | MysqlProtocolHandler::CLIENT_TRANSACTIONS
                | MysqlProtocolHandler::CLIENT_PLUGIN_AUTH
                | MysqlProtocolHandler::CLIENT_CONNECT_WITH_DB
                | MysqlProtocolHandler::CLIENT_DEPRECATE_EOF;

            if ($this->config->sslEnabled) {
                $this->state = self::STATE_ENABLING_CRYPTO;
                $this->enqueue(new PendingCommand(PendingCommand::TYPE_SSL_REQUEST, $clientFlags));
            } else {
                $this->state = self::STATE_AUTHENTICATING;
                $this->enqueue(new PendingCommand(PendingCommand::TYPE_HANDSHAKE_RESPONSE, $clientFlags));
            }
            return;
        }

        $cmd = $this->currentCommandContext->command ?? null;
        $response = $this->protocol->parseResponse($packet);

        if (isset($response->errorCode)) {
            $cmd?->promise->reject(new Exception($response->errorMessage, $response->errorCode));
            $this->finishCurrentCommand();
            return;
        }

        if ($this->state === self::STATE_AUTHENTICATING) {
            if (isset($response->pluginName)) {
                $this->enqueue(new PendingCommand(PendingCommand::TYPE_AUTH_SWITCH, $response->authData));
            } else {
                $this->state = self::STATE_IDLE;
                $this->connectPromise->resolve($this);
                $this->finishCurrentCommand();
            }
            return;
        }

        $this->processCommandResponse($cmd, $response, $packet);
    }

    private function processCommandResponse(PendingCommand $cmd, $response, string $packet)
    {
        $ctx = $this->currentCommandContext;

        if ($cmd->type === PendingCommand::TYPE_PREPARE) {
            if ($ctx->state === 'awaiting_prepare_ok') {
                $ctx->result = $this->protocol->parsePrepareOk($packet);
                $ctx->state = ($ctx->result->paramCount > 0) ? 'awaiting_param_defs' : 'awaiting_column_defs';
                if ($ctx->result->paramCount === 0 && $ctx->result->columnCount === 0) {
                    $cmd->promise->resolve(new PreparedStatement($this->pool, $ctx->result->statementId, 0, 0));
                    $this->finishCurrentCommand();
                }
                return;
            }

            if ($ctx->state === 'awaiting_param_defs') {
                if (isset($response->warningCount)) {
                    $ctx->state = 'awaiting_column_defs';
                    if ($ctx->result->columnCount === 0) {
                        $cmd->promise->resolve(new PreparedStatement($this->pool, $ctx->result->statementId, $ctx->result->paramCount, 0));
                        $this->finishCurrentCommand();
                    }
                } else {
                    $ctx->params[] = $this->protocol->parseFieldPacket($packet);
                }
                return;
            }

            if ($ctx->state === 'awaiting_column_defs') {
                if (isset($response->warningCount)) {
                    $cmd->promise->resolve(new PreparedStatement($this->pool, $ctx->result->statementId, $ctx->result->paramCount, $ctx->result->columnCount));
                    $this->finishCurrentCommand();
                } else {
                    $ctx->columns[] = $this->protocol->parseFieldPacket($packet);
                }
                return;
            }
        }


        if ($cmd->type === PendingCommand::TYPE_QUERY || ($cmd->type === PendingCommand::TYPE_EXECUTE && $cmd->data['hasRows'])) {
            if ($ctx->state === 'new') {
                if (isset($response['column_count'])) {
                    $ctx->state = 'awaiting_fields';
                    $ctx->columnCount = $response['column_count'];
                    $ctx->columns = [];
                } else {
                    $cmd->promise->resolve(new StatementResult(null, $response->affectedRows, $response->lastInsertId));
                    $this->finishCurrentCommand();
                }
            } elseif ($ctx->state === 'awaiting_fields') {
                if (isset($response->warningCount)) {
                    $ctx->state = 'awaiting_rows';
                } else {
                    $ctx->columns[] = $this->protocol->parseFieldPacket($packet);
                }
            } elseif ($ctx->state === 'awaiting_rows') {
                if (isset($response->warningCount)) {
                    $cmd->promise->resolve(new StatementResult($ctx->rows));
                    $this->finishCurrentCommand();
                } else {
                    $ctx->rows[] = $cmd->type === PendingCommand::TYPE_QUERY
                        ? $this->protocol->parseRowDataPacket($packet, $ctx->columns)
                        : $this->protocol->parseBinaryRowDataPacket($packet, $ctx->columns);
                }
            }
        } else {
            $cmd->promise->resolve(new StatementResult(null, $response->affectedRows ?? null, $response->lastInsertId ?? null));
            $this->finishCurrentCommand();
        }
    }

    public function enqueue(PendingCommand $command): PromiseInterface
    {
        $this->commandQueue[] = $command;
        $this->processQueue();
        return $command->promise;
    }

    private function processQueue(): void
    {
        if (($this->state < self::STATE_IDLE && !in_array($this->state, [self::STATE_ENABLING_CRYPTO, self::STATE_AUTHENTICATING])) || $this->state === self::STATE_BUSY || empty($this->commandQueue)) {
            return;
        }

        $command = array_shift($this->commandQueue);
        $this->state = self::STATE_BUSY;
        $this->currentCommandContext = (object)['command' => $command, 'state' => ($command->type === PendingCommand::TYPE_PREPARE) ? 'awaiting_prepare_ok' : 'new', 'rows' => [], 'params' => [], 'columns' => []];

        $this->protocol->resetSequence();
        $packet = match ($command->type) {
            PendingCommand::TYPE_QUERY => $this->protocol->createQueryPacket($command->data),
            PendingCommand::TYPE_PREPARE => $this->protocol->createPreparePacket($command->data),
            PendingCommand::TYPE_EXECUTE => $this->protocol->createStatementExecutePacket($command->data['id'], $command->data['params']),
            PendingCommand::TYPE_CLOSE_STMT => $this->protocol->createStatementClosePacket($command->data),
            PendingCommand::TYPE_AUTH_SWITCH => $this->protocol->createAuthSwitchResponsePacket($this->config->password, $command->data),
            PendingCommand::TYPE_SSL_REQUEST => $this->protocol->createSslRequestPacket($command->data),
            PendingCommand::TYPE_HANDSHAKE_RESPONSE => $this->protocol->createHandshakeResponsePacket($this->config, $command->data),
            PendingCommand::TYPE_QUIT => $this->protocol->createQuitPacket(),
        };

        if ($command->type === PendingCommand::TYPE_SSL_REQUEST) {
            $this->protocol->sequenceId = 1;
            fwrite($this->socket, $packet);
            if (stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true) {
                $this->state = self::STATE_AUTHENTICATING;
                $clientFlags = $command->data | MysqlProtocolHandler::CLIENT_SSL;
                $this->enqueue(new PendingCommand(PendingCommand::TYPE_HANDSHAKE_RESPONSE, $clientFlags));
            } else {
                $this->close(new Exception("Failed to enable SSL/TLS"));
            }
        } else {
            fwrite($this->socket, $packet);
        }
    }

    private function finishCurrentCommand(): void
    {
        $this->currentCommandContext = null;
        $this->state = ($this->state === self::STATE_CLOSING) ? self::STATE_CLOSING : self::STATE_IDLE;
        $this->processQueue();
    }

    public function close(?Exception $error = null): void
    {
        if ($this->state === self::STATE_DISCONNECTED) return;

        $this->state = self::STATE_DISCONNECTED;
        if (is_resource($this->socket)) fclose($this->socket);
        $this->socket = null;

        $error = $error ?? new Exception("Connection closed");
        $this->connectPromise?->reject($error);

        if ($this->currentCommandContext?->command) {
            $this->currentCommandContext->command->promise->reject($error);
        }
        foreach ($this->commandQueue as $command) {
            $command->promise->reject($error);
        }
        $this->commandQueue = [];
        $this->currentCommandContext = null;
    }
}
