<?php

namespace Rcalicdan\FiberAsync\ValueObjects;

class PDOOperation
{
    public const TYPE_CONNECT = 'connect';
    public const TYPE_QUERY = 'query';
    public const TYPE_PREPARE = 'prepare';
    public const TYPE_EXECUTE = 'execute';
    public const TYPE_FETCH_ALL = 'fetchAll';
    public const TYPE_FETCH = 'fetch';
    public const TYPE_BEGIN = 'begin';
    public const TYPE_COMMIT = 'commit';
    public const TYPE_ROLLBACK = 'rollback';
    public const TYPE_LAST_INSERT_ID = 'lastInsertId';
    public const TYPE_ROW_COUNT = 'rowCount';
    public const TYPE_CLOSE_CURSOR = 'closeCursor';
    public const TYPE_CLOSE = 'close';

    private string $id;
    private string $type;
    private array $payload; // SQL, params, config, etc.
    /** @var callable */
    private $callback; // To resolve/reject the initiating promise
    private array $options; // e.g., fetch mode, PDO attributes

    /** @var \PDO|\PDOStatement|null The actual blocking PDO resource/object */
    private mixed $pdoResource = null; // Used for statement execution

    public function __construct(string $type, array $payload, callable $callback, array $options = [])
    {
        $this->id = uniqid('pdo_op_', true);
        $this->type = $type;
        $this->payload = $payload;
        $this->callback = $callback;
        $this->options = $options;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setPdoResource(mixed $resource): void
    {
        $this->pdoResource = $resource;
    }

    public function getPdoResource(): mixed
    {
        return $this->pdoResource;
    }

    public function executeCallback(?\Throwable $error = null, mixed $result = null): void
    {
        try {
            ($this->callback)($error, $result);
        } catch (\Throwable $e) {
            error_log('PDO operation callback error: '.$e->getMessage());
        }
    }
}