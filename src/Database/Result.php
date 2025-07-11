<?php

namespace Rcalicdan\FiberAsync\Database;

/**
 * Represents the result of a successful database query that returned rows.
 */
class Result implements \IteratorAggregate
{
    private int $position = 0;
    private int $rowCount;

    /**
     * @param array $rows An array of associative arrays representing the result set.
     */
    public function __construct(
        private readonly array $rows
    ) {
        $this->rowCount = count($this->rows);
    }

    /**
     * Fetches the next row from the result set as an associative array.
     * Returns null if there are no more rows.
     *
     * @return array|null
     */
    public function fetchAssoc(): ?array
    {
        if ($this->position >= $this->rowCount) {
            return null;
        }

        // Return the row at the current position and then increment the pointer
        return $this->rows[$this->position++];
    }

    /**
     * Fetches all rows from the result set as an array of associative arrays.
     *
     * @return array
     */
    public function fetchAllAssoc(): array
    {
        return $this->rows;
    }

    /**
     * Allows the Result object to be used directly in a foreach loop.
     *
     * @return \Traversable
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->rows);
    }

    /**
     * Gets the number of rows in the result set.
     *
     * @return int
     */
    public function getRowCount(): int
    {
        return $this->rowCount;
    }
}
