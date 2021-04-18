<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Exception;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LogLevel;

class Connection
{
    private PHPWall $owner;
    private ?PDO $pdo = null;
    private array $config;

    public function __construct(PHPWall $owner, array $config)
    {
        $this->owner = $owner;
        $this->config = $config;
    }

    private function connect(): void
    {
        if ($this->pdo) {
            return;
        }

        $this->pdo = new PDO(
            $this->config['engine']
            . ':host=' . $this->config['host']
            . ';port=' . $this->config['port']
            . ';dbname=' . $this->config['dbname'],
            $this->config['username'],
            $this->config['password'],
            $this->config['options']
        );
    }

    private function migrationRun(): void
    {
        $this->connect();
        $sql = file_get_contents(__DIR__ . '/../migration.sql');

        if (!$this->pdo->exec($sql)) {
            $err = $this->pdo->errorInfo();
            $this->owner->log(LogLevel::ERROR, 'Migration SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        } else {
            $this->owner->log(LogLevel::INFO, 'Migration success');
        }
    }

    public function beginTransaction(): bool
    {
        $this->connect();
        $res = $this->pdo->beginTransaction();
        if (!$res) {
            throw new PDOException('Cant begin transaction');
        }
        return true;
    }

    public function commit(): bool
    {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->commit();
        }
        return false;
    }

    public function selectAllSql(string $table, array $where, string $select = '*', string $additionQuery = ''): array
    {
        return $this
            ->selectSql($table, $where, $select, $additionQuery)
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function selectSql(string $table, array $where, string $select = '*', string $additionQuery = '', bool $flag = false): PDOStatement
    {
        $this->connect();
        $q = 'SELECT ' . $select . ' FROM ' . $table . ' WHERE ';
        $f = false;
        foreach ($where as $k => $v) {
            if (!$f) {
                $f = true;
            } else {
                $q .= ' AND ';
            }
            if (is_string($k)) {
                $q .= '`' . $k . '`=:' . $k;
            } else {
                $q .= $v;
            }
        }

        if ($additionQuery) {
            $q .= $additionQuery;
        }

        $stmt = $this->pdo->prepare($q);
        if (!$stmt) {
            throw new PDOException('Cant prepare query');
        }
        $bind = [];
        foreach ($where as $k => $v) {
            if (is_string($k)) {
                $bind[$k] = $v;
            }
        }
        try {
            $res = $stmt->execute($bind);
        } catch (Exception $e) {
            if ($e->getCode() == '42S02') {
                $this->migrationRun();
                return $this->selectSql($table, $where, $select, $additionQuery, true);
            }
        }

        $err = $stmt->errorInfo();
        if ($err[1]) {
            if ($err[1] == 1146 && !$flag) {
                $this->migrationRun();
                return $this->selectSql($table, $where, $select, $additionQuery, true);
            }
            throw new Exception('SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        } else {
            $this->owner->log(LogLevel::INFO, 'SELECT `' . $table . '`, WHERE ' . json_encode($where));
        }
        return $stmt;
    }

    public function selectOneSql(string $table, array $where, string $select = '*'): array
    {
        return $this
            ->selectSql($table, $where, $select, ' FOR UPDATE')
            ->fetch(PDO::FETCH_ASSOC);
    }

    public function updateSql(string $table, array $where, array $bind): bool
    {
        $this->connect();
        $q = 'UPDATE ' . $table . ' SET ';
        $f = false;
        foreach ($bind as $k => $v) {
            if (!$f) {
                $f = true;
            } else {
                $q .= ',';
            }
            $q .= '`' . $k . '`=:' . $k;
        }
        $q .= ' WHERE ';

        $f = false;
        foreach ($where as $k => $v) {
            if (!$f) {
                $f = true;
            } else {
                $q .= ' AND ';
            }
            $q .= '`' . $k . '`=:' . $k;
        }

        $stmt = $this->pdo->prepare($q);

        $res = $stmt->execute($bind + $where);
        $err = $stmt->errorInfo();
        if ($err[1]) {
            $this->owner->log(LogLevel::ERROR, 'SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        } else {
            $this->owner->log(LogLevel::INFO, 'UPDATE `' . $table . '`, SET ' . json_encode($bind) . ', WHERE ' . json_encode($where));
        }

        return $res;
    }

    public function insertSql(string $table, array $data): int
    {
        $this->connect();
        $keys = array_keys($data);
        $q = 'INSERT INTO ' . $table . ' (`' . implode('`, `', $keys) . '`) VALUES (';
        $f = false;
        foreach ($data as $key => $v) {
            if (!$f) {
                $f = true;
            } else {
                $q .= ', ';
            }
            if ($key == 'create') {
                $q .= 'NOW()';
                unset($data[$key]);
            } else {
                $q .= ':' . $key;
            }
        }
        $q .= ')';
        $stmt = $this->pdo->prepare($q);
        if (!$stmt) {
            throw new PDOException('Cant prepare query');
        }

        $stmt->execute($data);
        $err = $stmt->errorInfo();
        if (!$err[1]) {
            $id = $this->pdo->lastInsertId();
            $this->owner->log(LogLevel::INFO, 'INSERT `' . $table . '`, ID = ' . $id);
            return (int) $id;
        }

        $this->owner->log(LogLevel::ERROR, 'SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        return 0;
    }

    public function deleteRow(string $table, array $where): bool
    {
        $this->connect();
        $q = 'DELETE FROM ' . $table . ' WHERE ';
        $f = false;
        foreach ($where as $k => $v) {
            if (!$f) {
                $f = true;
            } else {
                $q .= ' AND ';
            }
            $q .= '`' . $k . '`=:' . $k;
        }
        $stmt = $this->pdo->prepare($q);
        $res = $stmt->execute($where);
        $err = $stmt->errorInfo();
        if ($err[1]) {
            $this->owner->log(LogLevel::ERROR, 'SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        } else {
            $this->owner->log(LogLevel::INFO, 'DELETE  `' . $table . '`, WHERE ' . json_encode($where));
        }
        return $res;
    }

    /*******************/

    public function getDataControlView(bool $showInactiveIp, int $bunTimeout): array
    {
        if ($showInactiveIp) {
            $select = 'ip,`create`,`update`,request_total,request_session,request_bad,request_bad_days,request_bad_days_up';
            $q = '`update` <= DATE_SUB(NOW(),INTERVAL ' . $bunTimeout . ' SECOND)';
        } else {
            $select = '*';
            $q = '`update` > DATE_SUB(NOW(),INTERVAL ' . $bunTimeout . ' SECOND)';
        }
        return $this->selectAllSql(PHPWall::TABLE_MAIN, [$q], $select, ' ORDER BY `update` DESC LIMIT 1000');
    }

    public function getDataForRestore(int $bunTimeout): array
    {
        return $this->selectAllSql(
            PHPWall::TABLE_MAIN,
            ['`update` > DATE_SUB(NOW(),INTERVAL ' . $bunTimeout . ' SECOND)'],
            'ip,`update`,request_session'
        );
    }
}
