<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Exception;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LogLevel;

class Db
{
    public const TABLE_MAIN = 'iplist';
    public const TABLE_LOG = 'iplog';

    private PHPWall $owner;
    private ?PDO $pdo = null;
    private array $config;

    public function __construct(PHPWall $owner, array $config)
    {
        $this->owner = $owner;
        $this->config = $config;
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

    public function getDataControlViewActive(): array
    {
        $select = '*';
        $q = '`expire` > NOW()';
        return $this->selectAllSql(self::TABLE_MAIN, [$q], $select, ' ORDER BY `update` DESC LIMIT 1000');
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

    protected function connect(): void
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

    public function getDataControlViewSleep(): array
    {
        $select = 'ip,`create`,`update`,request_total,request_session,request_bad,request_bad_days,request_bad_days_up, trust';
        $q = '`expire` <= NOW()';
        return $this->selectAllSql(self::TABLE_MAIN, [$q], $select, ' ORDER BY `update` DESC LIMIT 1000');
    }

    public function getDataControlViewMost(): array
    {
        $select = 'ip,`create`,`update`,request_total,request_session,request_bad,request_bad_days,request_bad_days_up, trust';
        $q = '`request_total` > 100';
        return $this->selectAllSql(self::TABLE_MAIN, [$q], $select, ' ORDER BY `request_total` DESC LIMIT 1000');
    }

    /*******************/

    public function getDataForRestore(): array
    {
        return $this->selectAllSql(
            self::TABLE_MAIN,
            ['`expire` > NOW()'],
            'ip,`update`,request_session,request_bad_days,request_bad,trust'
        );
    }

    public function setIpIsTrust(string $ip, int $trust): void
    {
        $this->updateSql(
            self::TABLE_MAIN,
            ['ip' => Tools::convertIp2Number($ip)],
            ['trust' => $trust, 'expire=NOW()']
        );
    }

    public function updateSql(string $table, array $where, array $set): bool
    {
        $this->connect();
        $q = 'UPDATE ' . $table . ' SET ';
        $f = false;
        foreach ($set as $k => $v) {
            if (!$f) {
                $f = true;
            } else {
                $q .= ',';
            }
            if (is_numeric($k)) {
                $q .= $v;
                unset($set[$k]);
            } else {
                $q .= '`' . $k . '`=:' . $k;
            }
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

        $res = $stmt->execute($set + $where);
        $err = $stmt->errorInfo();
        if ($err[1]) {
            $this->owner->log(LogLevel::ERROR, 'SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        } else {
            $this->owner->log(LogLevel::INFO, 'UPDATE `' . $table . '`, SET ' . json_encode($set) . ', WHERE ' . json_encode($where));
        }

        return $res;
    }

    public function getMainByIp(string $ip): array
    {
        return $this->selectOneSql(self::TABLE_MAIN, ['ip' => Tools::convertIp2Number($ip)]);
    }

    public function selectOneSql(string $table, array $where, string $select = '*'): array
    {
        $data = $this
            ->selectSql($table, $where, $select, ' FOR UPDATE')
            ->fetch(PDO::FETCH_ASSOC);
        return is_array($data) ? $data : [];
    }

    public function getAllLogByIp(string $ip): array
    {
        return $this->selectAllSql(self::TABLE_LOG, ['ip' => Tools::convertIp2Number($ip)]);
    }

    public function addLog(string $ip, int $rule, string $word, int $ipFrc): void
    {
        $dataLog = [
            'ip' => Tools::convertIp2Number($ip),
            'rule' => $rule,
            'data' => $word,
            'try' => $ipFrc,
            'create' => 0,
        ];
        $this->insertSql(self::TABLE_LOG, $dataLog);
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
            return (int)$id;
        }

        $this->owner->log(LogLevel::ERROR, 'SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        return 0;
    }

    public function insertBadIp(string $ip, int $ipFrc, int $bunTimeout): array
    {
        $data = [
            'request_total' => $ipFrc,
            'request_session' => $ipFrc,
            'request_bad' => 1,
            'request_bad_days' => 1,
            'request_bad_days_up' => date('Y-m-d'),
            'ip' => Tools::convertIp2Number($ip),
            'create' => 0,
            'expire' => date('Y-m-d H:i:s', time() + $bunTimeout),
            'ua' => !empty($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'host' => substr(gethostbyaddr($ip), -128),
            'trust' => PHPWall::TRUST_DEFAULT,
        ];

        if ($this->owner->isTrustIp($data['host'])) {
            $data['trust'] = PHPWall::TRUST_SEARCH;
        }

        $this->insertSql(self::TABLE_MAIN, $data);
        return $data;
    }

    public function updateBadIp(array $data, int $ipFrc, int $bunTimeout): array
    {
        if ($ipFrc <= $data['request_session']) {
            $upd = [
                'expire' => date('Y-m-d H:i:s', time() + $bunTimeout),
                'request_total' => $data['request_total'] + $ipFrc,
                'request_session' => $ipFrc,
                'request_bad' => $data['request_bad'] + $ipFrc,
            ];
        } else {
            $diff = $ipFrc - $data['request_session'];
            $upd = [
                'expire' => date('Y-m-d H:i:s', time() + $bunTimeout),
                'request_total' => $data['request_total'] + $diff,
                'request_session' => $ipFrc,
                'request_bad' => $data['request_bad'] + $diff,
            ];
        }

        if ($data['request_bad_days_up'] != date('Y-m-d')) {
            $upd['request_bad_days'] = (int)$data['request_bad_days'] + 1;
            $upd['request_bad_days_up'] = date('Y-m-d');
        }

        $this->updateSql(self::TABLE_MAIN, ['ip' => $data['ip']], $upd);
        return array_merge($data, $upd);
    }
}
