<?php

namespace Rcalicdan\FiberAsync\MySQL;

enum TransactionIsolationLevel: string
{
    case RepeatableRead = 'REPEATABLE READ';
    case ReadCommitted = 'READ COMMITTED';
    case ReadUncommitted = 'READ UNCOMMITTED';
    case Serializable = 'SERIALIZABLE';
}
