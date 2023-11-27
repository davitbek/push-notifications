<?php

namespace App\Models;

use PDO;

class BaseModel
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected PDO $pdo;
    protected array $columns = ['*'];
    protected array $pdoVariables = [];
    protected array $where = [];
    protected array $select = [];
    protected array $order = [];
    protected array $join = [];
    protected ?int $limit = null;

    public function getTable(): string
    {
        if (empty($this->table)) {
            $className = (new \ReflectionClass($this))->getShortName();
            $this->table = strtolower($className) . 's'; // TODO make snake_case and str_plural helpers
        }

        return $this->table;
    }

    public function newQuery(): self
    {
        $this->where = [];
        $this->select = [];
        $this->pdoVariables = [];
        return $this;
    }

    public function select(array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function where(): self
    {
        $arguments = func_get_args();
        if (count($arguments) == 3) {
            $this->where[] = $arguments;
        } elseif (count($arguments) == 2) {
            $this->where[] = [
                $arguments[0],
                '=',
                $arguments[1]
            ];
        } elseif (is_array($arguments[0])) {
            foreach ($arguments[0] as $key => $value) {
                $this->where[] = [
                    $key,
                    '=',
                    $value
                ];
            }
        } else {
            die('Invalid where usage');
        }

        return $this;
    }

    public function whereIn(string $key, array $values): self
    {
        $this->where[] = [$key, 'in', $values];
        return $this;
    }

    public function order(array $order): self
    {
        $this->order = array_merge($this->order, $order);
        return $this;
    }

    public function join(array $join): self
    {
        $this->join = array_merge($this->join, $join);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function get($columns = []): ?array
    {
        $columns = $columns ?: $this->columns;
        $columns = array_merge($this->select, $columns);
        $selectStatement = implode(', ', $columns);
        $sql = sprintf("SELECT %s FROM %s", $selectStatement, $this->getTable()); // TODO protect sql injection

        if ($this->join) {
            foreach ($this->join as $table => $join) {
                $joinType = $join['type'] ?? 'INNER JOIN';
                $ownKey = $join['own_key'] ?? 'id';
                $relatedKey = $join['related_key'];
                $relatedTable = $join['related_table'] ?? $this->getTable();
                $sql .= sprintf(' %s %s ON %s = %s', $joinType, $table, $this->qualifyKey($ownKey, $table), $this->qualifyKey($relatedKey, $relatedTable));
            }
        }

        if ($this->where) {
            $sql .= $this->getWhereStatement($this->where);
        }

        if ($this->limit) {
            $sql .= ' Limit ?';
            $this->pdoVariables[] = $this->limit;
        }
        return $this->execute($sql);
    }

    public function find($id, $columns = []): ?array
    {
        $result = $this->where([$this->primaryKey => $id])->get($columns);
        return $result[0] ?? null;
    }

    public function exists(): bool
    {
        $sql = sprintf("SELECT count(%s) as exist FROM %s ", $this->primaryKey, $this->getTable()) . $this->getWhereStatement($this->where);
        $result = $this->execute($sql);
        return !empty($result[0]['exist']);
    }

    public function create(array $data)
    {
        $values = [];
        foreach ($data as $_) {
            $values[] = '?';
        }
        $sql = sprintf("INSERT INTO %s (%s) VALUES (%s)", $this->getTable(), implode(',', array_keys($data)), implode(', ', $values));
        $this->pdoVariables = array_values($data);
        $this->execute($sql);
        return $this->pdo()->lastInsertId();
    }

    public function update(int $id, array $data)
    {
        $updated = [];
        $this->pdoVariables = [];
        foreach ($data as $key => $value) {
            $updated[] = $key . ' = ?';
            $this->pdoVariables[] = $value;
        }

        $this->pdoVariables[] = $id;
        $sql = 'UPDATE ' . $this->getTable() . '  SET ' . implode(', ' , $updated) . ' where id = ?';

        $this->execute($sql);
        return true;
    }

    public function execute($sql): array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->pdoVariables);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }

    public function pdo()
    {
        if (!empty($this->pdo)) {
            return $this->pdo;
        }
        try {
            $conn = new PDO(sprintf("mysql:host=%s;dbname=%s", config('DB_HOST'), config('DB_NAME')), config('DB_USER_NAME'), config('DB_PASSWORD'));
            // set the PDO error mode to exception
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo = $conn;
        } catch (\PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }

        return $this->pdo;
    }

    protected function qualifyKey(string $key, ?string $table = null): string
    {
        return sprintf('%s.%s', $table ?? $this->getTable(), $key);
    }

    protected function getWhereStatement(array $criteria): string
    {
        $statements = [];
        foreach ($criteria as $where) { // TODO this is a simple case
            if (is_array($where[2])) {
                $prevent = [];
                foreach ($where[2] as $value) {
                    $this->pdoVariables[] = $value;
                    $prevent[] = '? ';
                }

                $statements[] = sprintf('%s %s (%s)', $where[0], $where[1], implode(', ', $prevent));
            } else {
                $this->pdoVariables[] = $where[2];
                $statements[] = sprintf('%s %s ?', $where[0], $where[1]);
            }
        }
        return ' where ' . implode('and ', $statements);
    }
}