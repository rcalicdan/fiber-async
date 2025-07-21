<?php

namespace Rcalicdan\FiberAsync\MySQL;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

class PreparedStatement
{
    private MySQLClient $client;
    private int $statementId;
    private int $numParams;

    public function __construct(MySQLClient $client, int $statementId, int $numParams)
    {
        $this->client = $client;
        $this->statementId = $statementId;
        $this->numParams = $numParams;
    }

    /**
     * Executes the prepared statement with the given parameters.
     *
     * @param  array  $params  The parameters to bind to the placeholders.
     * @return PromiseInterface A promise that resolves with the query result.
     */
    public function execute(array $params = []): PromiseInterface
    {
        if (count($params) !== $this->numParams) {
            return Async::reject(new \InvalidArgumentException(
                "Incorrect number of parameters: expected {$this->numParams}, got ".count($params)
            ));
        }

        return $this->client->getQueryHandler()->executeStatement($this->statementId, $params);
    }

    /**
     * Closes the prepared statement on the server.
     *
     * @return PromiseInterface A promise that resolves when the statement is closed.
     */
    public function close(): PromiseInterface
    {
        return $this->client->getQueryHandler()->closeStatement($this->statementId);
    }
}
