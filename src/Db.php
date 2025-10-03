<?php

namespace Xakki\PHPWall;

use Exception;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @phpstan-type MainData array{request_total: int, request_session: int, request_bad: int, request_bad_days: int, request_bad_days_up: string, ip: string, create: string, update: string, expire: string, ua: string, host: string, trust: int}
 * @phpstan-type DbConfig array{engine: string, port: int, host: string, dbname: string, username: string, password: string, options: array<mixed>}
 */
class Db
{
    const TABLE_MAIN = 'iplist';
    const TABLE_LOG = 'iplog';

    /** @var PDO|null */
    private $pdo = null;

    /** @var DbConfig  */
    private $config;

    /** @var PHPWall */
    protected $owner;

    /**
     * @param PHPWall $owner
     * @param DbConfig  $config
     */
    public function __construct(PHPWall $owner, array $config)
    {
        $this->owner = $owner;
        $this->config = $config;
    }

    /**
     * @return bool
     * @throws PDOException
     */
    public function beginTransaction()
    {
        $res = $this->connect()->beginTransaction();
        if (!$res) {
            throw new PDOException('Cant begin transaction');
        }
        return true;
    }

    /**
     * @return bool
     */
    public function commit()
    {
        if ($this->connect()->inTransaction()) {
            return $this->connect()->commit();
        }
        return false;
    }

    /**
     * @return bool
     */
    public function rollback()
    {
        if ($this->connect()->inTransaction()) {
            return $this->connect()->rollback();
        }
        return false;
    }

    /**
     * @param string $table
     * @param array<string, string|numeric|bool>  $where
     * @return bool
     */
    public function deleteRow($table, array $where)
    {
        $whereClause = implode(' AND ', array_map(function ($key) {
            return "`$key` = :$key";
        }, array_keys($where)));
        $q = 'DELETE FROM ' . $table . ' WHERE ' . $whereClause;

        $stmt = $this->connect()->prepare($q);
        $res = $stmt->execute($where);
        $err = $stmt->errorInfo();
        if ($err[1]) {
            $this->owner->log(LogLevel::ERROR, 'SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        } else {
            $this->owner->log(LogLevel::INFO, 'DELETE  `' . $table . '`, WHERE ' . json_encode($where));
        }
        return $res;
    }


    /**
     * @param string $table
     * @param array<string|int, string|numeric|bool>  $where
     * @param string $select
     * @param string $additionQuery
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function selectAllSql($table, array $where, $select = '*', $additionQuery = '')
    {
        return $this
            ->selectSql($table, $where, $select, $additionQuery)
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param string $table
     * @param array<string|int, string|numeric|bool>  $where
     * @param string $select
     * @param string $additionQuery
     * @param bool   $flag
     * @return PDOStatement
     * @throws Exception
     */
    public function selectSql($table, array $where, $select = '*', $additionQuery = '', $flag = false)
    {
        $whereParts = [];
        $bind = [];
        foreach ($where as $key => $value) {
            if (is_numeric($key)) {
                $whereParts[] = $value;
            } else {
                $whereParts[] = "`$key` = :$key";
                $bind[$key] = $value;
            }
        }
        $q = 'SELECT ' . $select . ' FROM ' . $table . ' WHERE ' . implode(' AND ', $whereParts);


        if ($additionQuery) {
            $q .= $additionQuery;
        }

        /** @var PDOStatement|false $stmt */
        $stmt = $this->connect()->prepare($q);
        if (!$stmt) {
            throw new PDOException('Cant prepare query');
        }

        try {
            $stmt->execute($bind);
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

    /**
     * @throws \ErrorException
     */
    protected function migrationRun()
    {
        $sql = file_get_contents(__DIR__ . '/../migration.sql');
        if (!$sql) {
            throw new \ErrorException('Cant read migration.sql');
        }
        $flag = $this->connect()->exec($sql);

        if ($flag === false) {
            $err = $this->connect()->errorInfo();
            $this->owner->log(LogLevel::ERROR, 'Migration SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        } else {
            $this->owner->log(LogLevel::INFO, 'Migration success');
        }
    }

    /**
     * @return PDO
     */
    protected function connect()
    {
        if ($this->pdo) {
            return $this->pdo;
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
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return $this->pdo;
    }

    /**
     * @param string[] $where
     * @param string $orderBy
     * @return MainData[]
     */
    private function getDataControlView(array $where, $orderBy)
    {
        $select = '*, INET6_NTOA(ip) as ip';
        // @phpstan-ignore return.type
        return $this->selectAllSql(self::TABLE_MAIN, $where, $select, " ORDER BY $orderBy DESC LIMIT 1000");
    }

    /**
     * @return MainData[]
     */
    public function getDataControlViewActive()
    {
        return $this->getDataControlView(['`expire` > NOW()'], '`update`');
    }

    /**
     * @return MainData[]
     */
    public function getDataControlViewSleep()
    {
        return $this->getDataControlView(['`expire` <= NOW()'], '`update`');
    }

    /**
     * @return MainData[]
     */
    public function getDataControlViewMost()
    {
        return $this->getDataControlView(['`request_total` > 100'], '`request_total`');
    }

    /*******************/

    /**
     * @return MainData[]
     */
    public function getDataForRestore()
    {
        // @phpstan-ignore return.type
        return $this->selectAllSql(
            self::TABLE_MAIN,
            ['`expire` > NOW()'],
            '*, INET6_NTOA(ip) as ip'
        );
    }

    /**
     * @param string $ip
     * @param int $trust
     * @param int $expirationSecond
     */
    public function setIpIsTrust($ip, $trust, $expirationSecond)
    {
        $this->updateSql(
            self::TABLE_MAIN,
            ['ip=INET6_ATON("'.$ip.'")'],
            ['trust' => $trust, 'expire=TIMESTAMPADD(SECOND,'.$expirationSecond.',NOW())']
        );
    }

    /**
     * @param string $table
     * @param array<string|int, string|numeric|bool>  $where
     * @param array<string|numeric, string|numeric|bool>  $set
     * @return bool
     */
    public function updateSql($table, array $where, array $set)
    {
        $setParts = [];
        $setBind = [];
        foreach ($set as $k => $v) {
            if (is_numeric($k)) {
                $setParts[] = (string) $v;
            } else {
                $setParts[] = '`' . $k . '`=:' . $k;
                $setBind[$k] = $v;
            }
        }

        $whereParts = [];
        foreach ($where as $k => $v) {
            if (is_numeric($k)) {
                $whereParts[] = $v;
            } else {
                $whereParts[] = '`' . $k . '`=:' . $k;
                $setBind[$k] = $v;
            }
        }

        $q = 'UPDATE ' . $table . ' SET ' . implode(', ', $setParts) . ' WHERE ' . implode(' AND ', $whereParts);

        $stmt = $this->connect()->prepare($q);

        $res = $stmt->execute($setBind);
        $err = $stmt->errorInfo();
        if ($err[1]) {
            $this->owner->log(LogLevel::ERROR, 'SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        } else {
            $this->owner->log(LogLevel::INFO, 'UPDATE `' . $table . '`, SET ' . json_encode($set) . ', WHERE ' . json_encode($where));
        }

        return $res;
    }

    /**
     * @param string $ip
     * @return MainData|array{}
     * @throws Exception
     */
    public function getMainByIp($ip)
    {
        return $this->selectOneSql(self::TABLE_MAIN, ['ip=INET6_ATON("'.$ip.'")'], '*, INET6_NTOA(ip) as ip');
    }

    /**
     * @param string $table
     * @param array<string|int, string|numeric|bool>  $where
     * @param string $select
     * @return array<string, mixed>|array{}
     * @throws Exception
     */
    public function selectOneSql($table, array $where, $select = '*')
    {
        $data = $this
            ->selectSql($table, $where, $select, ' FOR UPDATE')
            ->fetch(PDO::FETCH_ASSOC);
        return is_array($data) ? $data : [];
    }

    /**
     * @param string $ip
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function getAllLogByIp($ip)
    {
        return $this->selectAllSql(self::TABLE_LOG, ['ip=INET6_ATON("'.$ip.'")'], '*', ' order by id DESC LIMIT 100');
    }

    /**
     * @param string $ip
     * @param string $rule
     * @param string $word
     * @param int $ipFrc
     */
    public function addLog($ip, $rule, $word, $ipFrc)
    {
        $dataLog = [
            'ip' => $ip,
            'rule' => $rule,
            'data' => mb_substr($word, 0, 254),
            'try' => $ipFrc,
            'create' => 0,
        ];
        $this->insertSql(self::TABLE_LOG, $dataLog);
    }

    /**
     * @param string $table
     * @param array<string, string|numeric|bool>  $data
     * @return int
     */
    public function insertSql($table, array $data)
    {
        $bindData = $data;
        $valuePlaceholders = [];

        $keys = array_keys($data);
        foreach ($keys as $key) {
            if ($key === 'create' || $key === 'update') {
                $valuePlaceholders[] = 'NOW()';
                unset($bindData[$key]);
            } elseif ($key === 'ip') {
                $valuePlaceholders[] = 'INET6_ATON("'.$bindData[$key].'")';
                unset($bindData[$key]);
            } else {
                $valuePlaceholders[] = ':' . $key;
            }
        }

        $q = 'INSERT INTO ' . $table . ' (`' . implode('`, `', $keys) . '`) VALUES (' . implode(', ', $valuePlaceholders) . ')';

        $stmt = $this->connect()->prepare($q);
        if (!$stmt) {
            throw new PDOException('Cant prepare query');
        }

        $stmt->execute($bindData);
        $err = $stmt->errorInfo();
        if (!$err[1]) {
            $id = $this->connect()->lastInsertId();
            $this->owner->log(LogLevel::INFO, 'INSERT `' . $table . '`, ID = ' . $id);
            return (int)$id;
        }

        $this->owner->log(LogLevel::ERROR, 'SQL error: ' . $err[2] . ', ' . $err[1] . ', ' . $err[0]);
        return 0;
    }

    /**
     * @param string $ip
     * @param string $userAgent
     * @param int $ipFrc
     * @param int $bunTimeout
     * @param string $hostname
     * @param int $trust
     * @return MainData
     */
    public function insertBadIp($ip, $userAgent, $ipFrc, $bunTimeout, $hostname, $trust)
    {
        $data = [
            'request_total' => $ipFrc,
            'request_session' => $ipFrc,
            'request_bad' => 1,
            'request_bad_days' => 1,
            'request_bad_days_up' => date('Y-m-d'),
            'ip' => $ip,
            'create' => date('Y-m-d H:i:s'),
            'update' => date('Y-m-d H:i:s'),
            'expire' => date('Y-m-d H:i:s', time() + $bunTimeout),
            'ua' => mb_substr($userAgent, 0, 255),
            'host' => substr($hostname, -128),
            'trust' => $trust,
        ];

        $this->insertSql(self::TABLE_MAIN, $data);
        return $data;
    }

    /**
     * @param MainData $data
     * @param int   $ipFrc
     * @param int   $bunTimeout
     * @return MainData
     */
    public function updateBadIp(array $data, $ipFrc, $bunTimeout)
    {
        $upd = [
            'expire' => date('Y-m-d H:i:s', time() + $bunTimeout),
            'request_session' => $ipFrc,
        ];

        if ($ipFrc <= $data['request_session']) {
            $requestsToAdd = $ipFrc;
        } else {
            $requestsToAdd = $ipFrc - (int)$data['request_session'];
        }
        $upd['request_total'] = (int)$data['request_total'] + $requestsToAdd;
        $upd['request_bad'] = (int)$data['request_bad'] + $requestsToAdd;


        if ($data['request_bad_days_up'] != date('Y-m-d')) {
            $upd['request_bad_days'] = (int)$data['request_bad_days'] + 1;
            $upd['request_bad_days_up'] = date('Y-m-d');
        }

        $this->updateSql(self::TABLE_MAIN, ['ip=INET6_ATON("'.$data['ip'].'")'], $upd);
        return array_merge($data, $upd);
    }
}
