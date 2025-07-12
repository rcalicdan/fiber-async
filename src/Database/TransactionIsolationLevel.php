<?php

namespace Rcalicdan\FiberAsync\Database;

enum TransactionIsolationLevel: string
{
    case RepeatableRead = 'REPEATABLE READ';
    case ReadCommitted = 'READ COMMITTED';
    case ReadUncommitted = 'READ UNCOMMITTED';
    case Serializable = 'SERIALIZABLE';
}
