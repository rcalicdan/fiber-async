<?php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Database\Result; 
use PDO;
use PDOStatement;
use Rcalicdan\FiberAsync\ValueObjects\PDOOperation;
use Throwable;

class PDOOperationHandler
{
    /** @var array<string, PDO> Map of connection IDs to active PDO instances */
    private array $activeConnections = [];
    /** @var array<string, PDOStatement> Map of statement IDs to active PDOStatement instances */
    private array $activeStatements = [];

    // Simulated network latency (in milliseconds)
    private array $latencyConfig = [
        'connect' => 10,
        'query' => 5,
        'prepare' => 3,
        'execute' => 5,
        'fetch' => 1, // Per row or per batch, simplified here
        'transaction' => 2,
        'close' => 1,
    ];

    public function setLatencyConfig(array $config): void
    {
        $this->latencyConfig = array_merge($this->latencyConfig, $config);
    }

    public function executeOperation(PDOOperation $operation): void
    {
        $type = $operation->getType();
        $payload = $operation->getPayload();
        $options = $operation->getOptions();
        $resourceId = $operation->getId(); // For connections, operation ID is connection ID

        try {
            $this->simulateLatency($type);

            switch ($type) {
                case PDOOperation::TYPE_CONNECT:
                    $this->handleConnect($operation);
                    break;
                case PDOOperation::TYPE_QUERY:
                    $this->handleQuery($operation);
                    break;
                case PDOOperation::TYPE_PREPARE:
                    $this->handlePrepare($operation);
                    break;
                case PDOOperation::TYPE_EXECUTE:
                    $this->handleExecute($operation);
                    break;
                case PDOOperation::TYPE_FETCH_ALL:
                    $this->handleFetchAll($operation);
                    break;
                case PDOOperation::TYPE_FETCH:
                    $this->handleFetch($operation);
                    break;
                case PDOOperation::TYPE_BEGIN:
                    $this->handleBeginTransaction($operation);
                    break;
                case PDOOperation::TYPE_COMMIT:
                    $this->handleCommit($operation);
                    break;
                case PDOOperation::TYPE_ROLLBACK:
                    $this->handleRollback($operation);
                    break;
                case PDOOperation::TYPE_LAST_INSERT_ID:
                    $this->handleLastInsertId($operation);
                    break;
                case PDOOperation::TYPE_ROW_COUNT:
                    $this->handleRowCount($operation);
                    break;
                case PDOOperation::TYPE_CLOSE_CURSOR:
                    $this->handleCloseCursor($operation);
                    break;
                case PDOOperation::TYPE_CLOSE:
                    $this->handleClose($operation);
                    break;
                default:
                    throw new \InvalidArgumentException("Unknown PDO operation type: {$type}");
            }
        } catch (Throwable $e) {
            $operation->executeCallback($e);
        }
    }

    private function simulateLatency(string $type): void
    {
        $delayMs = $this->latencyConfig[$type] ?? 0;
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function handleConnect(PDOOperation $operation): void
    {
        ['dsn' => $dsn, 'username' => $username, 'password' => $password, 'options' => $options] = $operation->getPayload();
        $pdo = new PDO($dsn, $username, $password, $options);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Ensure exceptions on errors

        $connId = $operation->getId(); // Use operation ID as connection ID
        $this->activeConnections[$connId] = $pdo;
        $operation->executeCallback(null, $connId); // Resolve with connection ID
    }

    private function handleQuery(PDOOperation $operation): void
    {
        ['connId' => $connId, 'sql' => $sql] = $operation->getPayload();
        $pdo = $this->getConnection($connId);

        $stmt = $pdo->query($sql);
        if ($stmt instanceof PDOStatement) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = new Result($rows); // Wrap rows in our Result object
            $operation->executeCallback(null, $result);
        } else {
            // For DDL/DML, PDO::query returns false or affected rows count directly
            // For simplicity, we'll assume it's always returning a result set or throwing.
            // In a real implementation, you'd check for affected rows if not a SELECT
            $operation->executeCallback(null, true); // Indicate success for non-select
        }
    }

    private function handlePrepare(PDOOperation $operation): void
    {
        ['connId' => $connId, 'sql' => $sql] = $operation->getPayload();
        $pdo = $this->getConnection($connId);

        $stmt = $pdo->prepare($sql);
        $stmtId = uniqid('pdo_stmt_', true);
        $this->activeStatements[$stmtId] = $stmt;
        $operation->executeCallback(null, $stmtId); // Resolve with statement ID
    }

    private function handleExecute(PDOOperation $operation): void
    {
        ['stmtId' => $stmtId, 'params' => $params] = $operation->getPayload();
        $stmt = $this->getStatement($stmtId);

        $success = $stmt->execute($params);

        if ($success) {
            // For simplicity, if it was a SELECT, fetch all, otherwise assume OkPacket-like success
            if ($stmt->columnCount() > 0) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result = new Result($rows);
                $operation->executeCallback(null, $result);
            } else {
                // For DML, return affected rows or a simple success object
                $operation->executeCallback(null, new class($stmt->rowCount(), $stmt->lastInsertId()) {
                    public int $affectedRows;
                    public int $lastInsertId;
                    public function __construct(int $affectedRows, string $lastInsertId) {
                        $this->affectedRows = $affectedRows;
                        $this->lastInsertId = (int) $lastInsertId; // PDO lastInsertId is string
                    }
                });
            }
        } else {
            // This path might not be hit if ERRMODE_EXCEPTION is set, but good for completeness
            $errorInfo = $stmt->errorInfo();
            throw new \RuntimeException("PDO Statement execution failed: {$errorInfo[2]} (SQLSTATE: {$errorInfo[0]}, Code: {$errorInfo[1]})");
        }
    }

    private function handleFetchAll(PDOOperation $operation): void
    {
        ['stmtId' => $stmtId] = $operation->getPayload();
        $fetchMode = $operation->getOptions()['fetchMode'] ?? PDO::FETCH_ASSOC;
        $stmt = $this->getStatement($stmtId);

        $rows = $stmt->fetchAll($fetchMode);
        $operation->executeCallback(null, $rows);
    }

    private function handleFetch(PDOOperation $operation): void
    {
        ['stmtId' => $stmtId] = $operation->getPayload();
        $fetchMode = $operation->getOptions()['fetchMode'] ?? PDO::FETCH_ASSOC;
        $stmt = $this->getStatement($stmtId);

        $row = $stmt->fetch($fetchMode);
        $operation->executeCallback(null, $row);
    }

    private function handleBeginTransaction(PDOOperation $operation): void
    {
        ['connId' => $connId] = $operation->getPayload();
        $pdo = $this->getConnection($connId);
        $pdo->beginTransaction();
        $operation->executeCallback(null, true);
    }

    private function handleCommit(PDOOperation $operation): void
    {
        ['connId' => $connId] = $operation->getPayload();
        $pdo = $this->getConnection($connId);
        $pdo->commit();
        $operation->executeCallback(null, true);
    }

    private function handleRollback(PDOOperation $operation): void
    {
        ['connId' => $connId] = $operation->getPayload();
        $pdo = $this->getConnection($connId);
        $pdo->rollBack();
        $operation->executeCallback(null, true);
    }

    private function handleLastInsertId(PDOOperation $operation): void
    {
        ['connId' => $connId, 'name' => $name] = $operation->getPayload();
        $pdo = $this->getConnection($connId);
        $id = $pdo->lastInsertId($name);
        $operation->executeCallback(null, $id);
    }

    private function handleRowCount(PDOOperation $operation): void
    {
        ['stmtId' => $stmtId] = $operation->getPayload();
        $stmt = $this->getStatement($stmtId);
        $count = $stmt->rowCount();
        $operation->executeCallback(null, $count);
    }

    private function handleCloseCursor(PDOOperation $operation): void
    {
        ['stmtId' => $stmtId] = $operation->getPayload();
        $stmt = $this->getStatement($stmtId);
        $success = $stmt->closeCursor();
        $operation->executeCallback(null, $success);
    }

    private function handleClose(PDOOperation $operation): void
    {
        ['connId' => $connId] = $operation->getPayload();
        if (isset($this->activeConnections[$connId])) {
            $pdo = $this->activeConnections[$connId];
            unset($this->activeConnections[$connId]);
            // PDO instances are closed when they go out of scope, no explicit close needed.
            // However, we clear any associated statements.
            foreach ($this->activeStatements as $stmtId => $stmt) {
                if (method_exists($stmt, 'getWrappedPDO') && $stmt->getWrappedPDO() === $pdo) { // Hypothetical method
                    unset($this->activeStatements[$stmtId]);
                }
            }
            $operation->executeCallback(null, true);
        } else {
            $operation->executeCallback(new \RuntimeException("Connection {$connId} not found."), false);
        }
    }

    private function getConnection(string $connId): PDO
    {
        if (! isset($this->activeConnections[$connId])) {
            throw new \RuntimeException("PDO Connection with ID {$connId} not found or closed.");
        }

        return $this->activeConnections[$connId];
    }

    private function getStatement(string $stmtId): PDOStatement
    {
        if (! isset($this->activeStatements[$stmtId])) {
            throw new \RuntimeException("PDO Statement with ID {$stmtId} not found or closed.");
        }

        return $this->activeStatements[$stmtId];
    }
}