<?php

namespace xakki\phpwall;
/*
   PhpWall- scan protect

   1) Create table (dont foget change pass `CHANGE_ME`)
   ```
   CREATE DATABASE `phpwall` CHARACTER SET 'utf8';
   CREATE USER 'phpwall'@'localhost' IDENTIFIED BY 'CHANGE_ME';
   GRANT ALL PRIVILEGES ON phpwall.* TO 'phpwall'@'localhost';
   FLUSH PRIVILEGES;
   ```
//  set password for 'student_loc' = PASSWORD('*****');
 */

class PhpWall
{
    const RULE_IP = 0;
    const RULE_UA = 1;
    const RULE_POST = 2;
    const RULE_URL = 3;
    const TRUST_DEFAULT = 0;
    const TRUST_SEARCH = 10;
    const TRUST_CAPTCHA = 1;
    private $_userIp;
    private $_memcacheIpInfo;
    private $secretRequest = 'CHANGE_ME'; // special access to control
    private $secretRequestRemove = 'CHANGE_ME'; // special access to control
    private $googleCaptha = [
        'sitekey' => 'CHANGE_ME',
        'sicretkey' => 'CHANGE_ME',
    ];
    private $debug = false;
    private $try = 3;
    private $logMode = 1; // 0 отключен полностью; 1 - логируем только при первом обнаружении; 2 - логируем при обнаружении и любых блокировок
    private $memcache = [
        'localhost',
        11211,
    ];
    private $dbPdo = [
        'engine' => 'mysql',
        'port' => 3306,
        'host' => 'localhost',
        'dbname' => 'phpwall',
        'username' => 'phpwall',
        'password' => 'CHANGE_ME',
        'options' => [],
    ];
    private $dbTableMain = 'iplist';
    private $dbTableLog = 'iplog';
    private $bunTimeout = 86400 * 3;
    private $bunTimeoutForDay = 43200;
    private $bunTimeoutForAll = 21600;
    private $check_ip = true; // if need check by IP
    private $check_url = true; // if need check by url
    private $check_ua = true; // if need check by user_agent
    private $check_ua_empty = false; // if need check user_agent for empty
    private $check_post = true; // if need check by POST

    private $check_url_keyword = [
        'eval(',
        'sqlite',
        'manager',
        'phpmyadmin',
        '/setup',
        '/admin',
        'myadmin',
        '/pma',
        'phpma',
        'phpadmin',
        'mysqladmin',
        'wp-login',
        'wp-content',
        'administrator',
        'wp-admin',
        'wp-includes',
        'wordpress',
        'mod_stats.xml',
        'mscms',
        '.ssh',
        '.git',
        'xmlrpc.php',
        'wallet.dat',
        '.bash_history',
        'webalizer',
        '/wstat',
        'fckeditor/editor',
    ];
    private $check_url_keyword_exclude = [];
    private $check_ua_keyword = [
        'eval(',
    ];
    private $check_ua_keyword_exclude = [];

    private $check_post_keyword = [
        'eval(',
    ];
    private $check_post_keyword_exclude = [];

    private $redirectByIp = 'info';// `self` | `info` | url  // action if ban by IP
    private $redirectByCheck = 'info';// `self` | `info` | url // action if bun by over check

    private $trustHosts = [
        'yandex.ru',
        'yandex.com',
        'google.com',
        'bing.com',
        'yahoo.com',
    ];
    private $typeList = [
        self::RULE_IP => 'IP',
        self::RULE_UA => 'UA',
        self::RULE_POST => 'POST',
        self::RULE_URL => 'URL',
    ];
    private $trustList = [
        self::TRUST_SEARCH => 'Search',
        self::TRUST_DEFAULT => '-',
        self::TRUST_CAPTCHA => 'CAPTCHA',
    ];
    private $lang = 'en';
    private $locale = [
        'ru' => [
            'Home' => 'На главную',
            'Attention' => 'Внимание',
            'Your IP [{$0}] has been blocked for suspicious activity.' => 'Ваш IP [{$0}] был заблокирован за подозрительную активность.',
            'If you want to remove the lock, then go check the captcha.' => 'Если вы хотите снять блокировку, то пройдите проверку капчей.',
            'Unbun' => 'Разблокировать',
            'Captcha not valid! Try again.' => 'Проверка не пройдена. Попробуйте еще.',
        ],
    ];
    private $errorMessage = '';

    private $evilFr = 20; // Если начинают долбить запросами, то только с мемкэшем работаем
    private $_MEMCACHE;
    private $_PDO;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $Logger;

    public function __construct($config = [], \Psr\Log\LoggerInterface $Logger = null)
    {
        if ($Logger) $this->Logger = $Logger;
        foreach ($config as $k => $r) {
            if (isset($this->$k)) {
                if (is_array($this->$k)) {
                    $this->$k = array_merge($this->$k, $r);
                } else {
                    $this->$k = $r;
                }
            }
        }
        if (empty($config['lang']) && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) && strpos($_SERVER['HTTP_ACCEPT_LANGUAGE'], 'ru')) {
            $this->lang = 'ru';
        }
        try {
            $this->main();
        } catch (\Exception $e) {
            $this->log(\Psr\Log\LogLevel::ERROR, $e);
            if ($this->debug) {
                echo '<pre>' . $e->__toString() . '</pre>';
                exit('ERROR');
            }
        }
    }

    protected function log($level, $message, array $context = []): void
    {
        if (!$this->Logger) return;
        $this->Logger->log($level, $message, $context);
    }

    private function main()
    {

        if (isset($_SERVER['argv'])) {
            // TODO
            //            if ($_SERVER['argv'][1] === 'phpWallTask')
            //                $this->phpWallTask();
        } else {
            if (isset($_SERVER['HTTP_X_REMOTE_ADDR'])) $this->_userIp = $_SERVER['HTTP_X_REMOTE_ADDR']; else
                $this->_userIp = $_SERVER['REMOTE_ADDR'];

            if (!empty($_GET[$this->secretRequest])) {
                $this->controlPanel();
            }

            if ($this->check_ip) {
                $this->phpCheckIp();
            }

            if ($this->check_url) {
                $this->checkUrl();
            }

            if ($this->check_ua) {
                $this->checkUa();
            }

            if ($this->check_post && !empty($_POST) && count($_POST)) {
                $this->checkPost();
            }

        }
        return true;
    }

    private function controlPanel()
    {
        $btrstr = '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">';
        $rmKey = $this->secretRequestRemove;
        if (isset($_GET['_logip'])) {
            $ip = $this->dtr_pton($_GET['_logip']);

            echo $btrstr;
            echo '<a href="?' . $this->secretRequest . '=1"><h1>PHPWALL</h1></a>';
            echo '<h2>IP Log ' . $_GET['_logip'] . '</h2>';

            $data1 = $this->selectAllSql($this->dbTableMain, ['ip' => $ip]);
            $this->printTable(array_keys($data1[0]), $data1);

            $data = $this->selectAllSql($this->dbTableLog, ['ip' => $ip]);
            $head = ['IP', 'Date', 'Try', 'rule', 'data'];
            $rows = [];
            foreach ($data as $r) {
                $rows[] = [
                    $r['id'],
                    $r['create'],
                    $r['try'],
                    $this->typeList[$r['rule']],
                    $r['data']
                ];
            }
            $this->printTable($head, $rows);
        } else {
            if (isset($_GET['_inactiveIp'])) {
                $select = 'ip,`create`,`update`,request_total,request_session,request_bad,request_bad_days,request_bad_days_up';
                $q = '`update` <= DATE_SUB(NOW(),INTERVAL ' . $this->bunTimeout . ' SECOND)';
            } else {
                $select = '*';
                $q = '`update` > DATE_SUB(NOW(),INTERVAL ' . $this->bunTimeout . ' SECOND)';
            }
            $data = $this->selectAllSql($this->dbTableMain, [$q], $select);

            if (isset($_GET[$rmKey])) {
                $ip = $_GET[$rmKey];
                $this->memcache()->delete('phpWall-' . $ip);

                $stmt = $this->pdo()->prepare('DELETE FROM ' . $this->dbTableMain . ' WHERE ip=:ip');
                $stmt->execute(['ip' => $this->dtr_pton($ip)]);
                header('Location: ' . $_SERVER['HTTP_REFERER'], true, 301);
                exit();
            }
            echo $btrstr;
            echo '<a href="?' . $this->secretRequest . '=1"><h1>PHPWALL</h1></a>';
            echo '<p>Timeout for unblock: ' . $this->bunTimeout . 'sec.</p>';
            echo '<p>Count ip: ' . count($data) . '.</p>';

            echo '<div class="btn-group" role="group">
              <a type="button" class="btn btn-secondary' . (isset($_GET['_inactiveIp']) ? ' active' : '') . '" href="?' . $this->secretRequest . '=1&tt=' . time() . '">Active</a>
              <a type="button" class="btn btn-secondary' . (isset($_GET['_inactiveIp']) ? '' : ' active') . '" href="?' . $this->secretRequest . '=1&tt=' . time() . '&_inactiveIp=1">Sleep</a>
            </div>';

            $head = ['IP', 'Date', 'Expire', 'Bun rq', 'Total rq', 'Bad rq', 'Bad days', 'Is trust', 'Host', 'ua', '.', '.'];
            $rows= [];
            foreach ($data as $r) {
                $flag = false;
                try {
                    $r['ip'] = $this->dtr_ntop($r['ip']);
                    $flag = true;
                } catch (Exception $e) {
                    $r['ip'] = $e->getMessage();
                }
                $rows[] = [
                    $r['ip'],
                    $r['create'] . '<br/>' . $r['update'],
                    date('m-d H:i', $this->calculateTimeOut($r)),
                    $r['request_session'],
                    $r['request_total'],
                    $r['request_bad'],
                    $r['request_bad_days'],
                    (isset($this->trustList[$r['trust']]) ? $this->trustList[$r['trust']] : ''),
                    (!empty($r['host']) ? htmlspecialchars($r['host']) : ''),
                    (!empty($r['ua']) ? htmlspecialchars($r['ua']) : ''),
                    ($flag ? '<a href="?' . $this->secretRequest . '=1&tt=' . time() . '&_logip=' . $r['ip'] . '">Logs</a>' : ''),
                    ($flag ? '<a href="?' . $this->secretRequest . '=1&tt=' . time() . '&' . $rmKey . '=' . $r['ip'] . '">X</a>' : '')
                ];
            }

            $this->printTable($head, $rows);


            if ($this->memcache) {
                $m = $this->memcache(true);
                if (!$m) {
                    echo '<h3>Memcache not available</h3>';
                } else {
                    $ipInfo = $this->memcache()->get($this->getKeyIp());
                    if ($ipInfo) {
                        echo '<h3>IP info ' . $this->_userIp . ' <a href="?' . $this->secretRequest . '=1&tt=' . time() . '&' . $rmKey . '=' . $this->_userIp . '">X</a></h3><pre>';
                        echo '<p>' . $ipInfo['t'] . ' - ' . $ipInfo['fr'] . '</p>';
                    }
                    echo '</pre>';
                }
            }
        }

        exit('');
    }

    protected function printTable(array $head, array $rows)
    {
        echo '<table class="table table-striped table-hover"><thead class="thead-dark"><tr>';
        foreach($head as $item) {
            echo '<th>'.$item.'</th>';
        }
        echo '</tr></thead><tbody></tbody>';
        foreach($rows as $row) {
            echo '<tr>';
            foreach($row as $i) {
                echo '<td>'.$i.'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function selectAllSql($table, array $where, $select = '*')
    {
        return $this->selectSql($table, $where, $select)->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function selectSql($table, array $where, $select = '*', $forUpdate = false, $flag = false)
    {
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
        if ($forUpdate) {
            $q .= ' FOR UPDATE';
        }
        if ($this->debug) {
            echo PHP_EOL . $q;
        }

        $stmt = $this->pdo()->prepare($q);
        $bind = [];
        foreach ($where as $k => $v) {
            if (is_string($k)) {
                $bind[$k] = $v;
            }
        }
        $res = $stmt->execute($bind);
        $err = $stmt->errorInfo();
        if ($err[1]) {
            if ($err[1] == 1146) {
                if (!$flag) {
                    $this->createDB();
                    return $this->selectSql($table, $where, $select, $forUpdate, true);
                }
                exit('<p class="alert alert-danger">' . $err[1] . ': ' . $err[2] . '</p>');
            }
            if (!$this->debug) {
                trigger_error($err[2] . ':' . $err[1] . ':' . $err[0], E_USER_WARNING);
            } else {
                print_r('<hr><pre>');
                print_r($err);
                print_r('</pre>');
            }
            exit('ERROR');
        }
        return $stmt;
    }

    /**
     * @return \PDO
     */
    private function pdo()
    {
        if (!$this->_PDO) {
            $this->_PDO = new \PDO($this->dbPdo['engine'] . ':host=' . $this->dbPdo['host'] . ';port=' . $this->dbPdo['port'] . ';dbname=' . $this->dbPdo['dbname'], $this->dbPdo['username'], $this->dbPdo['password'], $this->dbPdo['options']);
            if (!$this->_PDO) {
                if (!empty($_GET[$this->secretRequest])) {
                    echo '<p>Pdo cant init</p>';
                } else {
                    throw new \Exception('Pdo cant init');
                }
            }
        }
        return $this->_PDO;
    }

    private function createDB()
    {
        //  DEFAULT current_timestamp() -  работает только в 5.6 >
        $sql = 'CREATE TABLE ' . $this->dbTableMain . ' (
  `ip` varbinary(16) NOT NULL,
  `create` datetime NOT NULL,
  `update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `request_total` int(11),
  `request_session` int(11),
  `request_bad` int(11),
  `request_bad_days` int(11),
  `request_bad_days_up` date,
  `trust` tinyint(1),
  `host` varchar(128),
  `ua` varchar(255),
  PRIMARY KEY (`ip`),
  KEY `update` (`update`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';
        if (!$this->pdo()->exec($sql)) {
            var_dump($this->pdo()->errorInfo());
        }

        $sql = 'CREATE TABLE ' . $this->dbTableLog . ' (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `create` datetime NOT NULL,
  `ip` varbinary(16) NOT NULL,
  `rule` tinyint(1),
  `data` varchar(255),
  `try` int(11),
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';
        if (!$this->pdo()->exec($sql)) {
            var_dump($this->pdo()->errorInfo());
        }
    }

    private function dtr_pton($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return current(unpack("A4", inet_pton($ip)));
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return current(unpack("A16", inet_pton($ip)));
        }
        throw new \Exception("Please supply a valid IPv4 or IPv6 address");
    }

    // TODO
    // 2- проверка и если есть, то блокируем с предупреждением на 10 мин
    // 3 - проверка и блокируем на 1 час, 12, 24, 48, 7 дней
    /****************************/
    // 't' - время последнего плохого запроса
    // 'fr' - кол-во плохих запросов за сессию

    /**
     * @return \Memcached|null
     */
    private function memcache($restore = false)
    {
        if (!$this->_MEMCACHE) {
            $mc_load = false;
            if (!extension_loaded('memcached')) {
                $prefix = (PHP_SHLIB_SUFFIX === 'dll') ? 'php_' : '';
                if (function_exists('dl') and dl($prefix . 'memcached.' . PHP_SHLIB_SUFFIX)) $mc_load = true;
            } else
                $mc_load = true;
            if ($mc_load) {
                $this->_MEMCACHE = new \Memcached;
                if (!$this->_MEMCACHE->addServer($this->memcache[0], $this->memcache[1])) {
                    trigger_error('Memcached is down', E_USER_WARNING);
                    return false;
                }

                if ($restore) {
                    $f = $this->_MEMCACHE->get('phpWallInit');
                    if (!$f) {
                        $this->restoreCache();
                    }
                }
            }
        }
        return $this->_MEMCACHE;
    }

    private function restoreCache()
    {
        $this->memcache()->set('phpWallInit', time());
        $data = $this->selectAllSql($this->dbTableMain, ['`update` > DATE_SUB(NOW(),INTERVAL ' . $this->bunTimeout . ' SECOND)'], 'ip,`update`,request_session');
        if ([$data]) foreach ($data as $r) {
            try {
                $ip = $this->dtr_ntop($r['ip']);
                $this->memcache()->set('phpWall-' . $ip, [
                    't' => $r['update'],
                    'fr' => $r['request_session'],
                ], $r['update'] + $this->bunTimeout);
            } catch (\Exception $e) {
            }
        }
    }

    private function dtr_ntop($str)
    {
        $l = strlen($str);
        $format = 'A4';
        if ($l > 5) $format = 'A16';
        return inet_ntop(pack($format, $str));
    }

    private function calculateTimeOut($data)
    {
        return (time() + $this->bunTimeout + ($data['request_bad_days'] * $this->bunTimeoutForDay) + ($data['request_bad'] * $this->bunTimeoutForAll));
    }

    /*****************************************************/
    /*****************************************************/
    /*****************************************************/

    private function getKeyIp()
    {
        return 'phpWall-' . $this->_userIp;
    }

    private function phpCheckIp()
    {
        if (empty($this->_userIp) || !$this->memcache()) {
            $this->check_ip = false;
            return false;
        }
        $keyIp = $this->getKeyIp();
        $this->_memcacheIpInfo = $this->memcache()->get($keyIp);
        if ($this->_memcacheIpInfo) {
            if ($this->_memcacheIpInfo['fr'] <= $this->try) {
                // даем еще попытку
            } else {
                // блокируем
                $this->updateBadIp();
                return $this->wallAlarm(self::RULE_IP);
            }
        }

        return true;
    }

    /**
     * Обновление по заблокированному IP
     * @throws \Exception
     */
    private function updateBadIp()
    {
        // Для защиты от небольшого ДДОСа, от большого это не спасет(
        if ($this->_memcacheIpInfo['fr'] >= $this->evilFr && fmod($this->_memcacheIpInfo['fr'], 100) != 0) {
            $this->_memcacheIpInfo['t'] = time();
            $this->_memcacheIpInfo['fr']++;
            $this->memcache()->set($this->getKeyIp(), $this->_memcacheIpInfo, time() + $this->bunTimeout);
        } else {
            if (!$this->pdo()->beginTransaction()) {
                throw new \Exception('Cant begin transaction');
            }

            $binIp = $this->dtr_pton($this->_userIp);

            $data = $this->selectOneSql($this->dbTableMain, ['ip' => $binIp]);

            $upd = [];

            // Если в кэше большее значение , то записываем в бз из кэша
            if ($this->_memcacheIpInfo['fr'] > $data['request_session']) {
                $upd['request_total'] += ($this->_memcacheIpInfo['fr'] - (int)$data['request_session']);
                $upd['request_session'] = $this->_memcacheIpInfo['fr'];
            } else {
                $upd['request_total'] = (int)$data['request_total'] + 1;
                $upd['request_session'] = (int)$data['request_session'] + 1;
            }

            $this->_memcacheIpInfo['t'] = time();
            $this->_memcacheIpInfo['fr']++;

            if ($data['request_bad_days_up'] != date('Y-m-d')) {
                $upd['request_bad_days'] = $data['request_bad_days'] + 1;
                $upd['request_bad_days_up'] = date('Y-m-d');
            }

            $this->memcache()->set($this->getKeyIp(), $this->_memcacheIpInfo, time() + $this->bunTimeout);

            $this->updateSql($this->dbTableMain, ['ip' => $binIp], $upd);
            $this->pdo()->commit();
        }
    }

    private function selectOneSql($table, array $where, $select = '*')
    {
        return $this->selectSql($table, $where, $select, true)->fetch(\PDO::FETCH_ASSOC);
    }

    private function updateSql($table, array $where, array $bind)
    {
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

        if ($this->debug) {
            print_r('<pre>**');
            print_r($q);
            print_r($bind);
            print_r($where);
            print_r('</pre>');
        }

        $stmt = $this->pdo()->prepare($q);

        $res = $stmt->execute($bind + $where);
        $err = $stmt->errorInfo();
        if ($err[1]) {
            if (!$this->debug) {
                trigger_error($err[2] . ':' . $err[1] . ':' . $err[0], E_USER_WARNING);
            } else {
                print_r('<pre>');
                print_r($err);
                print_r('</pre>');
                exit();
            }
        }
        return $stmt->execute();
    }

    private function wallAlarm($byRule)
    {

        if ($byRule == 'ip') $rule = $this->redirectByIp; else
            $rule = $this->redirectByCheck;
        if ($rule) {
            if ($rule == 'self') $url = 'http://' . $this->_userIp; elseif ($rule == 'info') {

                if (!empty($_POST['unbunme'])) {
                    $this->unBun();
                }

                include 'phpwall-vew.php';
                exit();
            } else {
                $url = $rule;
            }
            header('Location: ' . $url, true, 301);
        }
        exit();
    }

    private function unBun()
    {

        if (!empty($_POST['g-recaptcha-response'])) {
            $flag = false;
            $myCurl = curl_init();
            curl_setopt_array($myCurl, [
                CURLOPT_URL => 'https://www.google.com/recaptcha/api/siteverify',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'secret' => $this->googleCaptha['sicretkey'],
                    'response' => $_POST['g-recaptcha-response'],
                    'remoteip' => $_SERVER['REMOTE_ADDR'],
                ]),
            ]);
            $response = curl_exec($myCurl);
            curl_close($myCurl);
            if ($response) {
                $response = json_decode($response, true);
                if ($response['success']) {
                    $flag = true;
                }
            }
            if ($flag) {
                $this->memcache()->delete($this->getKeyIp());
                $this->updateSql($this->dbTableMain, ['ip' => $this->dtr_pton($this->_userIp)], ['trust' => self::TRUST_CAPTCHA]);
                header('Location: ' . $_SERVER['REQUEST_URI'], true, 301);
                exit();
            } else {
                $this->errorMessage = 'Captcha not valid! Try again.';
            }
        } else {
            $this->errorMessage = 'Captcha not valid! Try again.';
        }
    }

    private function checkUrl()
    {
        foreach ($this->check_url_keyword as $word) {
            if (is_string($word)) {
                if (strpos($_SERVER['REQUEST_URI'], $word) !== false) {
                    if (count($this->check_url_keyword_exclude)) {
                        foreach ($this->check_url_keyword_exclude as $word2) {
                            if (strpos($_SERVER['REQUEST_URI'], $word2) === 0) {
                                continue 2;
                            }
                        }
                    }
                    return $this->addBadIp(self::RULE_URL, $_SERVER['REQUEST_URI']);
                }
            } elseif (is_callable($word)) {
                if (call_user_func($word, $_SERVER['REQUEST_URI'])) {
                    return $this->addBadIp(self::RULE_URL, $_SERVER['REQUEST_URI']);
                }
            }
        }
        return true;
    }

    /**
     * @param $rule
     * @param $word
     * @return bool|void
     * @throws \Exception
     */
    private function addBadIp($rule, $word)
    {
        if (!$this->pdo()->beginTransaction()) {
            throw new \Exception('Cant begin transaction');
        }
        $binIp = $this->dtr_pton($this->_userIp);
        $data = $this->selectOneSql($this->dbTableMain, ['ip' => $binIp]);

        if (!$this->_memcacheIpInfo) {
            $this->_memcacheIpInfo = [
                't' => time(),
                'fr' => 1,
            ];
        } else {
            $this->_memcacheIpInfo['fr']++;
        }

        if ($data) {
            if ($this->debug) {
                print_r('<pre>!!!');
                var_dump($data);
                var_dump($this->_memcacheIpInfo);
                print_r('</pre>');
            }
            $upd = [
                'request_total' => (int)$data['request_total'] + 1,
                'request_session' => $this->_memcacheIpInfo['fr'],
                'request_bad' => (int)$data['request_bad'] + 1,
            ];
            if ($data['request_bad_days_up'] != date('Y-m-d')) {
                $upd['request_bad_days'] = (int)$data['request_bad_days'] + 1;
                $upd['request_bad_days_up'] = date('Y-m-d');
            }
            $this->updateSql($this->dbTableMain, ['ip' => $binIp], $upd);

        } else {
            $data = [
                'request_total' => $this->_memcacheIpInfo['fr'],
                'request_session' => $this->_memcacheIpInfo['fr'],
                'request_bad' => 1,
                'request_bad_days' => 1,
                'request_bad_days_up' => date('Y-m-d'),
                'ip' => $binIp,
                'create' => 0,
                'ua' => mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255),
                'host' => substr(gethostbyaddr($this->_userIp), -128),
                'trust' => self::TRUST_DEFAULT,
            ];
            if ($this->isTrustIp($data['host'])) {
                $data['trust'] = self::TRUST_SEARCH;
            }
            $this->insertSql($this->dbTableMain, $data);
        }

        if (!$data['trust']) {
            $this->memcache()->set($this->getKeyIp(), $this->_memcacheIpInfo, $this->calculateTimeOut($data));
        }

        $this->pdo()->commit();

        if ($this->logMode > 0) {
            $data = [
                'ip' => $binIp,
                'rule' => $rule,
                'data' => $word,
                'try' => $this->_memcacheIpInfo['fr'],
                'create' => 0,
            ];
            $this->insertSql($this->dbTableLog, $data);
        }

        if ($data['trust'] != self::TRUST_SEARCH && 1 >= $this->try) {
            return $this->wallAlarm($rule);
        }

        if ($this->debug) {
            exit('<h3>----- DEBUG MODE ON -----</h3>');
        }

        return false;
    }

    private function isTrustIp($host)
    {
        foreach ($this->trustHosts as $r) {
            if (strpos($host, $r) !== false) return true;
        }
        return false;
    }

    private function insertSql($table, array $data)
    {
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
        $stmt = $this->pdo()->prepare($q);

        if ($this->debug) {
            print_r('<hr/><pre>**');
            print_r($q);
            print_r($data);
            print_r('</pre>');
        }

        //        foreach ($data as $k=>$v) {
        //            if($k=='ip') {
        //                  $data_type = \PDO::PARAM_STR;
        //            } elseif (is_int($v)) {
        //                $data_type = \PDO::PARAM_INT;
        //            } else {
        //                $data_type = \PDO::PARAM_STR;
        //            }
        //            $stmt->bindParam($k, $v, $data_type);
        //        }
        $res = $stmt->execute($data);
        $err = $stmt->errorInfo();
        if ($err[1]) {
            if (!$this->debug) {
                trigger_error($err[2] . ':' . $err[1] . ':' . $err[0], E_USER_WARNING);
            } else {
                print_r('<pre>');
                print_r($err);
                print_r('</pre>');
                exit('ERROR');
            }
            return 0;
        } else {
            $id = $this->pdo()->lastInsertId();
            if ($this->debug) {
                echo ' RES= ';
                var_dump($res, $id);
            }
            return $id;
        }
    }

    private function checkUa()
    {
        if (!$_SERVER['HTTP_USER_AGENT'] && $this->check_ua_empty) {
            return $this->addBadIp(self::RULE_UA, '*empty*');
        } else {
            foreach ($this->check_ua_keyword as $word) {
                if (is_string($word)) {
                    if (strpos($_SERVER['HTTP_USER_AGENT'], $word) !== false) {
                        if (count($this->check_ua_keyword_exclude)) {
                            foreach ($this->check_ua_keyword_exclude as $word2) {
                                if (strpos($_SERVER['HTTP_USER_AGENT'], $word2) === 0) {
                                    continue 2;
                                }
                            }
                        }
                        return $this->addBadIp(self::RULE_UA, $word);
                    }
                } elseif (is_callable($word)) {
                    if (call_user_func($word, $_SERVER['HTTP_USER_AGENT'])) {
                        return $this->addBadIp(self::RULE_UA, $_SERVER['HTTP_USER_AGENT']);
                    }
                }
            }
        }
        return true;
    }

    private function checkPost()
    {
        $post = json_encode($_POST, JSON_UNESCAPED_UNICODE);
        foreach ($this->check_post_keyword as $word) {
            if (is_string($word)) {
                if (strpos($post, $word) !== false) {
                    if (count($this->check_post_keyword_exclude)) {
                        foreach ($this->check_post_keyword_exclude as $word2) {
                            if (strpos($post, $word2) !== false) {
                                continue 2;
                            }
                        }
                    }
                    return $this->addBadIp(self::RULE_POST, $word);
                }
            } elseif (is_callable($word)) {
                if (call_user_func($word, $post)) {
                    return $this->addBadIp(self::RULE_POST, $post);
                }
            }
        }
    }

    /*************************************************/
    /*************************************************/
    /*************************************************/

    public function locale($message, $params = [])
    {
        if (isset($this->locale[$this->lang][$message])) {
            $message = $this->locale[$this->lang][$message];
        }
        if (count($params)) {
            foreach ($params as $k => $p) {
                $message = str_replace('{$' . $k . '}', $p, $message);
            }
        }
        return $message;
    }

}
