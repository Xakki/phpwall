<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/*
   PhpWall- scan protect

   1) Create table (dont foget change pass `CHANGE_ME`)
   ```
   CREATE DATABASE `phpwall` CHARACTER SET 'utf8';
   CREATE USER 'phpwall'@'%' IDENTIFIED BY 'CHANGE_ME';
   GRANT ALL PRIVILEGES ON phpwall.* TO 'phpwall'@'%';
   FLUSH PRIVILEGES;
   ```
//  set password for 'phpwall' = PASSWORD('*****');
 */

class PHPWall
{
    public const VERSION = '0.8.1';
    public const REDIRECT_TYPE_INFO = 'info';
    public const REDIRECT_TYPE_SELF = 'self';

    public const TABLE_MAIN = 'iplist';
    public const TABLE_LOG = 'iplog';

    public const RULE_IP = 0;
    public const RULE_UA = 1;
    public const RULE_POST = 2;
    public const RULE_URL = 3;

    public const TRUST_DEFAULT = 0;
    public const TRUST_SEARCH = 10; // Найденны по соответствияю с $this->trustHosts
    public const TRUST_CAPTCHA = 1; // Прошедшие капчу
    public const TRUST_CONTROL = 2; // Тот кто заходил в панельку

    // special access to control
    protected string $secretRequest = 'CHANGE_ME';
    protected string $secretRequestRemove = 'CHANGE_ME';

    // get key on https://www.google.com/recaptcha/admin/
    protected array $googleCaptcha = [
        'sitekey' => 'CHANGE_ME',
        'sicretkey' => 'CHANGE_ME',
    ];
    protected bool $debug = false;
    protected int $try = 2;
    protected int $logMode = 1; // 0 отключен полностью; 1 - логируем только при первом обнаружении; 2 - логируем при обнаружении и любых блокировок

    protected array $memcache = [
        'localhost',
        11211,
    ];

    protected array $dbPdo = [
        'engine' => 'mysql',
        'port' => 3306,
        'host' => 'localhost',
        'dbname' => 'phpwall',
        'username' => 'phpwall',
        'password' => 'CHANGE_ME',
        'options' => [],
    ];

    protected int $bunTimeout = 86400 * 3;
    protected int $bunTimeoutForDay = 43200;
    protected int $bunTimeoutForAll = 21600;
    protected bool $checkIp = true; // if need check by IP
    protected bool $checkUrl = true; // if need check by url
    protected bool $checkUa = true; // if need check by user_agent
    protected bool $checkUaEmpty = false; // if need check user_agent for empty
    protected bool $checkPost = true; // if need check by POST

    protected array $checkUrlKeyword = [
        'eval(',
        'sqlite',
        '/manager',
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
        '/administrator',
        'wp-admin',
        'wp-includes',
        '/wordpress',
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
    protected array $checkUrlKeywordExclude = [];
    protected array $checkUaKeyword = [
        'eval(',
        'curl',
    ];
    protected array $checkUaKeywordExclude = [];

    protected array $checkPostKeyword = [
        'eval(',
        'curl',
    ];
    protected array $checkPostKeywordExclude = [];

    protected string $redirectByIp = self::REDIRECT_TYPE_INFO;// `self` | `info` | custom url  // action if ban by IP
    protected string $redirectByCheck = self::REDIRECT_TYPE_INFO;// `self` | `info` | custom url // action if bun by over check

    protected array $trustHosts = [
        'yandex.ru',
        'yandex.com',
        'google.com',
        'bing.com',
        'yahoo.com',
    ];

    protected string $lang = 'en';// Default locale

    protected array $locale = [
        'ru' => [
            'Home' => 'На главную',
            'Attention' => 'Внимание',
            'Your IP [{$0}] has been blocked for suspicious activity.' => 'Ваш IP [{$0}] был заблокирован за подозрительную активность.',
            'If you want to remove the lock, then go check the captcha.' => 'Если вы хотите снять блокировку, то пройдите проверку капчей.',
            'Unbun' => 'Разблокировать',
            'Captcha not valid! Try again.' => 'Проверка не пройдена. Попробуйте еще.',
        ],
    ];

    /////////////////////////////////////////////////

    private string $userIp;
    // Cached IP request frequency
    private int $ipfrc = 0;
    private string $errorMessage = '';
    private int $evilFr = 20; // Если начинают долбить запросами, то только с мемкэшем работаем

    private ?LoggerInterface $logger = null;
    private ?Cache $cache = null;
    private ?Connection $conn = null;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        if ($logger) {
            $this->logger = $logger;
        }
        try {
            $this->setUserIp();
            $this->setLang();
            $this->setProperty($config);

            if (isset($_SERVER['argv'])) {
                return;
            }

            $this->cache = new Cache($this, $this->memcache);
            $this->conn = new Connection($this, $this->dbPdo);

            if (!empty($_GET[$this->secretRequest])) {
                if ($this->secretRequest == 'CHANGE_ME') {
                    exit('CHANGE the secretRequest & secretRequestRemove');
                }

                try {
                    new View($this, $this->conn, $this->cache, $this->secretRequest, $this->secretRequestRemove, $this->bunTimeout);
                } catch (Exception $e) {
                    $this->log(LogLevel::CRITICAL, $e);
                    exit('View error');
                }
            }

            $this->init();
        } catch (Exception $e) {
            $this->log(LogLevel::ERROR, $e);
        }
    }

    protected function setLang(): void
    {
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return;
        }
        $k = array_keys($this->locale);
        $v = preg_match('/(' . implode('|', $k) . ')/u', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $m);
        if ($v) {
            $this->lang = $m[1];
        }
    }

    protected function setUserIp(): void
    {
        if (isset($_SERVER['HTTP_X_REMOTE_ADDR'])) {
            $this->userIp = $_SERVER['HTTP_X_REMOTE_ADDR'];
        } else {
            $this->userIp = $_SERVER['REMOTE_ADDR'];
        }
    }

    protected function setProperty(array $config): void
    {
        foreach ($config as $k => $r) {
            if (isset($this->$k)) {
                if (is_array($this->$k)) {
                    $this->$k = array_merge($this->$k, $r);
                } else {
                    $this->$k = $r;
                }
            }
        }
    }

    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        if ($this->debug) {
            echo 'log - <pre>';
            echo $level . PHP_EOL;
            print_r((string)$message);
            echo '<pre>';
            if ($level == LogLevel::ERROR || $level == LogLevel::CRITICAL || $level == LogLevel::EMERGENCY) {
                exit('ERROR');
            }
        }
        if (!$this->logger) {
            return;
        }
        $this->logger->log($level, $message, $context);
    }

    private function init(): bool
    {
        if ($this->checkIp) {
            $this->phpCheckIp();
        }

        if ($this->checkUrl) {
            $this->checkUrl();
        }

        if ($this->checkUa) {
            $this->checkUa();
        }

        if ($this->checkPost && !empty($_POST) && count($_POST)) {
            $this->checkPost();
        }

        return true;
    }

    private function phpCheckIp(): bool
    {
        if (empty($this->userIp)) {
            // skip
            $this->checkIp = false;
            return true;
        }

        $this->ipfrc = $this->getIpCacheFrequency();

        if ($this->ipfrc) {
            if ($this->ipfrc <= $this->try) {
                // даем еще попытку
                return true;
            } else {
                $this->wallAlarm(self::RULE_IP);
            }
        }

        return true;
    }

    /*****************************************************/
    /*****************************************************/
    /*****************************************************/

    protected function getKeyIp(string $ip = ''): string
    {
        return 'phpwall-' . (!empty($ip) ? $ip : $this->userIp);
    }

    protected function getIpCacheFrequency(): int
    {
        return (int)$this->cache->get($this->getKeyIp());
    }

    protected function getIpCacheTrust(): int
    {
        return (int) ($this->cache->get($this->getKeyIp() . '-trust') ?? self::TRUST_DEFAULT);
    }

    protected function setIpCacheTrust(int $trust): bool
    {
        return $this->cache->set($this->getKeyIp() . '-trust', $trust, $this->bunTimeout);
    }

    /**
     * Обновление по заблокированному IP
     *
     * @throws Exception
     */
    private function incrementBadIp(): void
    {
        $res = $this->cache->inc($this->getKeyIp(), $this->bunTimeout);
        if ($res !== false) {
            $this->ipfrc = $res;
        } else {
            $this->ipfrc++;
        }

        $saveToDb = true;

        if ($this->ipfrc <= $this->try) {
            $saveToDb = false;
        } elseif ($this->ipfrc >= $this->evilFr) {
            //Для защиты от небольшого ДДОСа
            $saveToDb = fmod($this->ipfrc, 100) == 0;
        } else {
            $saveToDb = fmod($this->ipfrc, 2) == 0;
        }

        if ($saveToDb) {
            $this->conn->beginTransaction();

            $binIp = Tools::convertIp2Number($this->userIp);

            $data = $this->conn->selectOneSql(self::TABLE_MAIN, ['ip' => $binIp]);

            $upd = [];

            if ($data) {
                if ($this->ipfrc <= $data['request_session']) {
                    $upd = [
                        'request_total' => $data['request_total'] + $this->ipfrc,
                        'request_session' => $this->ipfrc,
                        'request_bad' => $data['request_bad'] + $this->ipfrc,
                    ];
                } else {
                    $diff = $this->ipfrc - $data['request_session'];
                    $upd = [
                        'request_total' => $data['request_total'] + $diff,
                        'request_session' => $this->ipfrc,
                        'request_bad' => $data['request_bad'] + $diff,
                    ];
                }

                if ($data['request_bad_days_up'] != date('Y-m-d')) {
                    $upd['request_bad_days'] = (int)$data['request_bad_days'] + 1;
                    $upd['request_bad_days_up'] = date('Y-m-d');
                }

                $this->conn->updateSql(self::TABLE_MAIN, ['ip' => $binIp], $upd);
            } else {
                $data = [
                    'request_total' => $this->ipfrc,
                    'request_session' => $this->ipfrc,
                    'request_bad' => 1,
                    'request_bad_days' => 1,
                    'request_bad_days_up' => date('Y-m-d'),
                    'ip' => $binIp,
                    'create' => 0,
                    'ua' => !empty($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
                    'host' => substr(gethostbyaddr($this->userIp), -128),
                    'trust' => self::TRUST_DEFAULT,
                ];

                if ($this->isTrustIp($data['host'])) {
                    $data['trust'] = self::TRUST_SEARCH;
                }

                $this->conn->insertSql(self::TABLE_MAIN, $data);
            }

            $this->setIpCacheTrust($data['trust']);

            $this->conn->commit();
        }
    }

    public function calculateTimeOut(array $data): int
    {
        //TODO , хрень какая та
        return (int)(time() + $this->bunTimeout + ($data['request_bad_days'] * $this->bunTimeoutForDay) + ($data['request_bad'] * $this->bunTimeoutForAll));
    }

    /*****************************************************/
    /*****************************************************/
    /*****************************************************/

    private function wallAlarm(int $byRule): void
    {
        if ($this->debug) {
            $this->log(LogLevel::NOTICE, 'wallAlarm: ' . View::TYPE_LIST[$byRule]);
        }

        if ($byRule === self::RULE_IP) {
            $rule = $this->redirectByIp;
        } else {
            $rule = $this->redirectByCheck;
        }

        if ($rule) {
            if ($rule === self::REDIRECT_TYPE_SELF) {
                self::redirect('//' . $this->userIp);
            } elseif ($rule === self::REDIRECT_TYPE_INFO) {
                if (!empty($_POST['unbunme'])) {
                    if ($this->unBun()) {
                        self::redirect('//' . $_SERVER['HTTP_HOST']);
                    }
                }

                include 'ban-view.php';
                exit();
            } else {
                self::redirect($rule);
            }
        }
        exit('No rule');
    }

    /*****************************************************/

    protected static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 301);
        exit();
    }

    private function unBun(): bool
    {
        if (!empty($_POST['g-recaptcha-response'])) {
            $flag = false;
            $myCurl = curl_init();
            curl_setopt_array($myCurl, [
                CURLOPT_URL => 'https://www.google.com/recaptcha/api/siteverify',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'secret' => $this->googleCaptcha['sicretkey'],
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
                $this->setTrustIp($this->userIp, self::TRUST_CAPTCHA);
                return true;
            } else {
                $this->errorMessage = 'Captcha not valid! Try again.';
            }
        } else {
            $this->errorMessage = 'Captcha not valid!';
        }
        return false;
    }

    public function setTrustIp(string $ip, int $trust): void
    {
        $this->cache->delete($this->getKeyIp($ip));
        $this->conn->updateSql(
            self::TABLE_MAIN,
            ['ip' => Tools::convertIp2Number($ip)],
            ['trust' => $trust, 'request_bad' => 0]
        );
    }

    private function checkUrl(): bool
    {
        foreach ($this->checkUrlKeyword as $word) {
            if (is_string($word)) {
                if (strpos($_SERVER['REQUEST_URI'], $word) !== false) {
                    if (count($this->checkUrlKeywordExclude)) {
                        foreach ($this->checkUrlKeywordExclude as $word2) {
                            if (strpos($_SERVER['REQUEST_URI'], $word2) === 0) {
                                continue 2;
                            }
                        }
                    }
                    return $this->ruleApply(self::RULE_URL, $this->highLight($_SERVER['REQUEST_URI'], $word));
                }
            } elseif (is_callable($word)) {
                if (call_user_func($word, $_SERVER['REQUEST_URI'])) {
                    return $this->ruleApply(self::RULE_URL, $_SERVER['REQUEST_URI']);
                }
            }
        }
        return true;
    }

    private function ruleApply(int $rule, string $word): bool
    {
        $this->incrementBadIp();

        if ($this->logMode > 0) {
            $dataLog = [
                'ip' => Tools::convertIp2Number($this->userIp),
                'rule' => $rule,
                'data' => $word,
                'try' => $this->ipfrc,
                'create' => 0,
            ];
            $this->conn->insertSql(self::TABLE_LOG, $dataLog);
        }

        if ($this->ipfrc <= $this->try) {
            // try skip
            return true;
        }

        $trust = $this->getIpCacheTrust();
        if ($trust !== self::TRUST_SEARCH) {
            $this->wallAlarm($rule);
        }

        return true;
    }

    private function isTrustIp(string $host): bool
    {
        foreach ($this->trustHosts as $r) {
            if (str_contains($host, $r)) {
                return true;
            }
        }
        return false;
    }

    protected function highLight(string $txt, string $word): string
    {
        return str_replace($word, '<b>' . $word . '</b>', $txt);
    }

    private function checkUa(): bool
    {
        if (!$_SERVER['HTTP_USER_AGENT'] && $this->checkUaEmpty) {
            return $this->ruleApply(self::RULE_UA, '*empty*');
        } else {
            foreach ($this->checkUaKeyword as $word) {
                if (is_string($word)) {
                    if (strpos($_SERVER['HTTP_USER_AGENT'], $word) !== false) {
                        if (count($this->checkUaKeywordExclude)) {
                            foreach ($this->checkUaKeywordExclude as $word2) {
                                if (strpos($_SERVER['HTTP_USER_AGENT'], $word2) === 0) {
                                    continue 2;
                                }
                            }
                        }
                        return $this->ruleApply(self::RULE_UA, $word);
                    }
                } elseif (is_callable($word)) {
                    if (call_user_func($word, $_SERVER['HTTP_USER_AGENT'])) {
                        return $this->ruleApply(self::RULE_UA, $_SERVER['HTTP_USER_AGENT']);
                    }
                }
            }
        }
        return true;
    }

    ///////////////////////////////////////

    private function checkPost(): bool
    {
        $post = json_encode($_POST, JSON_UNESCAPED_UNICODE);
        foreach ($this->checkPostKeyword as $word) {
            if (is_string($word)) {
                if (strpos($post, $word) !== false) {
                    if (count($this->checkPostKeywordExclude)) {
                        foreach ($this->checkPostKeywordExclude as $word2) {
                            if (strpos($post, $word2) !== false) {
                                continue 2;
                            }
                        }
                    }
                    return $this->ruleApply(self::RULE_POST, $word);
                }
            } elseif (is_callable($word)) {
                if (call_user_func($word, $post)) {
                    return $this->ruleApply(self::RULE_POST, $post);
                }
            }
        }
        return true;
    }

    // TODO
    // 2- проверка и если есть, то блокируем с предупреждением на 10 мин
    // 3 - проверка и блокируем на 1 час, 12, 24, 48, 7 дней
    /****************************/
    // 't' - время последнего плохого запроса
    // 'fr' - кол-во плохих запросов за сессию

    public function locale(string $message, array $params = []): string
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

    public function restoreCache(): void
    {
        $f = $this->cache->get('phpWallInit');
        if ($f) {
            return;
        }
        $checkVal = microtime() . '-' . rand(0, 100000);
        $this->cache->set('phpWallInit', $checkVal);
        usleep(10);
        if ($this->cache->get('phpWallInit') !== $checkVal) {
            return;
        }

        $data = $this->conn->getDataForRestore($this->bunTimeout);
        if (!$data) {
            return;
        }
        foreach ($data as $r) {
            $ip = Tools::convertIp2String($r['ip']);
            $this->cache->set($this->getKeyIp($ip) . '-time', $r['update'], $this->bunTimeout);
            $this->cache->set($this->getKeyIp($ip), $r['request_session'], $this->bunTimeout);
        }
    }

    public function getDataControlView(bool $showInactiveIp = false): array
    {
        return $this->conn->getDataControlView($showInactiveIp, $this->bunTimeout);
    }

    public function getIpInfo(): array
    {
        $key = $this->getKeyIp();
        return [
            'ip' => $this->userIp,
            'time' => (int)$this->cache->get($key . '-time'),
            'cnt' => (int)$this->cache->get($key),
        ];
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getGoogleCaptchaSiteKey(): string
    {
        return $this->googleCaptcha['sitekey'];
    }
}
