<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncPromise;

class ExecutorHandler
{
    public function executeExecutor(?callable $executor, callable $resolve, callable $reject): void
    {
        if (!$executor) {
            return;
        }

        try {
            $executor($resolve, $reject);
        } catch (\Throwable $e) {
            $reject($e);
        }
    }
}