<?php

namespace ReactphpX\CycleDatabase;

use Cycle\Database\StatementInterface;

/**
 * Adapts React\MySQL QueryResult to Cycle StatementInterface.
 */
class AsyncStatement implements StatementInterface, \IteratorAggregate
{
    private string $queryString;
    private $result;
    private bool $closed = false;

    public function __construct(string $queryString, $result)
    {
        $this->queryString = $queryString;
        $this->result = $result;
    }

    public function getQueryString(): string
    {
        return $this->queryString;
    }

    public function fetch(int $mode = self::FETCH_OBJ): mixed
    {
        if (!isset($this->result->resultRows)) {
            return false;
        }
        $row = array_shift($this->result->resultRows);
        if ($row === null) {
            return false;
        }
        return $this->castRow($row, $mode);
    }

    public function fetchColumn(?int $columnNumber = null): mixed
    {
        $row = $this->fetch(self::FETCH_NUM);
        if ($row === false) {
            return false;
        }
        $idx = $columnNumber ?? 0;
        return $row[$idx] ?? null;
    }

    public function fetchAll(int $mode = self::FETCH_OBJ): array
    {
        if (!isset($this->result->resultRows)) {
            return [];
        }
        $rows = $this->result->resultRows;
        $this->result->resultRows = [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->castRow($row, $mode);
        }
        return $out;
    }

    public function rowCount(): int
    {
        if (isset($this->result->affectedRows)) {
            return (int)$this->result->affectedRows;
        }
        if (isset($this->result->resultRows)) {
            return (int)count($this->result->resultRows);
        }
        return 0;
    }

    public function columnCount(): int
    {
        if (!isset($this->result->resultFields)) {
            return 0;
        }
        return (int)count($this->result->resultFields);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function getIterator(): \Traversable
    {
        if (!isset($this->result->resultRows)) {
            return new \ArrayIterator([]);
        }
        return new \ArrayIterator($this->result->resultRows);
    }

    private function castRow(array $row, int $mode)
    {
        return match ($mode) {
            self::FETCH_NUM => array_values($row),
            self::FETCH_OBJ => (object)$row,
            default => $row,
        };
    }
}


