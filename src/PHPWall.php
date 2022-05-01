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
    public const REDIRECT_TYPE_INFO = 'info'; // Show page info about bun
    public const REDIRECT_TYPE_SELF = 'self'; // self redirect

    public const RULE_IP = 0;
    public const RULE_UA = 1;
    public const RULE_POST = 2;
    public const RULE_URL = 3;

    public const TRUST_DEFAULT = 0; // no trust
    public const TRUST_SEARCH = 10; // If matched by trustHosts
    public const TRUST_CAPTCHA = 1; // If passed the captcha
    public const TRUST_CONTROL = 2; // If entered into panel

    public const POST_WALL_NAME = 'unbunme';
    public const KEY_CACHE_INIT = 'phpWallInit';

    public const ALLOW_PROPERTY = [
        'wallTpl' => 1,
        'cachePrefix' => 1,
        'secretRequest' => 1,
        'secretRequestRemove' => 1,
        'googleCaptcha' => 1,
        'debug' => 1,
        'try' => 1,
        'logMode' => 1,
        'memcache' => 1,
        'dbPdo' => 1,
        'banTimeOut' => 1,
        'banTimeOutEachDay' => 1,
        'banTimeOutEachRequest' => 1,
        'evilFr' => 1,
        'checkIp' => 1,
        'checkUrl' => 1,
        'checkUa' => 1,
        'checkUaEmpty' => 1,
        'checkPost' => 1,
        'checkUrlKeyword' => 1,
        'checkUrlKeywordExclude' => 1,
        'checkUaKeyword' => 1,
        'checkUaKeywordExclude' => 1,
        'checkPostKeyword' => 1,
        'checkPostKeywordExclude' => 1,
        'redirectByIp' => 1,
        'redirectByCheck' => 1,
        'trustHosts' => 1,
        'lang' => 1,
        'locale' => 1,
    ];

    // special access to control
    protected string $secretRequest = 'CHANGE_ME';
    protected string $secretRequestRemove = 'CHANGE_ME';

    // get key on https://www.google.com/recaptcha/admin/
    protected array $googleCaptcha = [
        'sitekey' => 'CHANGE_ME',
        'sicretkey' => 'CHANGE_ME',
    ];
    protected bool $debug = false;
    protected int $try = 2; // Allowed try request before get bun
    protected int $logMode = 1; // 0 disabled log; 1 - enable log

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

    protected string $cachePrefix = 'phpwall';
    protected string $wallTpl = 'ban-view.php';

    protected int $banTimeOut = 86400 * 3;
    protected int $banTimeOutEachDay = 43200;
    protected int $banTimeOutEachRequest = 3600;
    protected int $evilFr = 20; // If session bad request more that evilFr, then less work with DB
    protected int $ddosFr = 100; // Forse self reirect

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
        '/phpma',
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
        'ya.ru',
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
            'If you want to remove the lock, then pass the check out.' => 'Если вы хотите снять блокировку, то пройдите проверку.',
            'Unbun' => 'Разблокировать',
            'Captcha not valid! Try again.' => 'Проверка не пройдена. Попробуйте еще.',
        ],
    ];

    /////////////////////////////////////////////////

    private string $userIp;
    // Cached IP request frequency
    private int $ipFrc = 0;
    private string $errorMessage = '';

    private ?LoggerInterface $logger = null;
    private ?Cache $cache = null;
    private ?Db $conn = null;

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

            $this->cache = new Cache($this, $this->memcache, $this->cachePrefix);
            $this->conn = new Db($this, $this->dbPdo);

            if (!empty($_GET[$this->secretRequest])) {
                if ($this->secretRequest == 'CHANGE_ME') {
                    exit('CHANGE the secretRequest & secretRequestRemove');
                }

                try {
                    new View($this, $this->conn, $this->cache, $this->secretRequest, $this->secretRequestRemove);
                } catch (Exception $e) {
                    $this->log(LogLevel::CRITICAL, $e);
                    exit('View has error');
                }
            }

            $this->init();
        } catch (Exception $e) {
            $this->log(LogLevel::ERROR, $e);
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

    protected function setProperty(array $config): void
    {
        foreach ($config as $k => $r) {
            if (isset(self::ALLOW_PROPERTY[$k])) {
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
            if (!$this->checkIp()) {
                $this->wallAlarmAction(self::RULE_IP);
            }
        }

        if ($this->checkUrl) {
            if (!$this->checkUrl()) {
                $this->wallAlarmAction(self::RULE_URL);
            }
        }

        if ($this->checkUa) {
            if (!$this->checkUa()) {
                $this->wallAlarmAction(self::RULE_UA);
            }
        }

        if ($this->checkPost && !empty($_POST) && count($_POST)) {
            if (!$this->checkPost()) {
                $this->wallAlarmAction(self::RULE_POST);
            }
        }

        return true;
    }

    private function checkIp(): bool
    {
        if (empty($this->userIp)) {
            // skip
            $this->checkIp = false;
            return true;
        }

        $this->ipFrc = (int)$this->cache->getIpCacheFrequency($this->userIp);

        if ($this->ipFrc) {
            if ($this->ipFrc <= $this->try) {
                // даем еще попытку
                return true;
            } else {
                if ($this->ipFrc > $this->ddosFr) {
                    $this->redirectByIp = self::REDIRECT_TYPE_SELF;
                }
                return false;
            }
        }

        return true;
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
                    return $this->ruleApply(self::RULE_URL, Tools::highLight($_SERVER['REQUEST_URI'], $word));
                }
            } elseif (is_callable($word)) {
                if (call_user_func($word, $_SERVER['REQUEST_URI'])) {
                    return $this->ruleApply(self::RULE_URL, $_SERVER['REQUEST_URI']);
                }
            }
        }
        return true;
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

    private function ruleApply(int $rule, string $word): bool
    {
        $this->incrementBadIp();

        if ($this->logMode > 0) {
            $this->conn->addLog($this->userIp, $rule, $word, $this->ipFrc);
        }

        if ($this->ipFrc <= $this->try) {
            // try skip
            return true;
        }

        $trust = $this->cache->getIpCacheTrust($this->userIp);
        if ($trust !== self::TRUST_SEARCH) {
            return false;
        }

        return true;
    }

    private function wallAlarmAction(int $byRule): void
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
                if (!empty($_POST[self::POST_WALL_NAME])) {
                    if ($this->unBunByCaptcha()) {
                        self::redirect('//' . $_SERVER['HTTP_HOST']);
                    }
                }

                include $this->wallTpl;
                exit();
            } else {
                self::redirect($rule);
            }
        }
        exit('No rule');
    }

    protected static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 301);
        exit();
    }

    private function unBunByCaptcha(): bool
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
                    'remoteip' => $this->userIp,
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
                $this->setIpIsTrust($this->userIp, self::TRUST_CAPTCHA);
                return true;
            } else {
                $this->errorMessage = 'Captcha not valid! Try again.';
            }
        } else {
            $this->errorMessage = 'Captcha not valid!';
        }
        return false;
    }

    public function setIpIsTrust(string $ip, int $trust): void
    {
        $this->cache->setIpIsTrust($ip, $trust);
        $this->conn->setIpIsTrust($ip, $trust);
    }

    public function getBunTimeout(array $data): int
    {
        return $this->banTimeOut +
            ($data['request_bad_days'] - 1) * $this->banTimeOutEachDay +
            ($data['request_bad'] * $this->banTimeOutEachRequest);
    }

    private function incrementBadIp(): void
    {
        $this->ipFrc++;
        $saveToDb = true;

        if ($this->ipFrc < $this->try) {
            $saveToDb = false;
        } elseif ($this->ipFrc >= $this->evilFr) {
            //Для защиты от небольшого ДДОСа
            $saveToDb = fmod($this->ipFrc, 100) == 0;
        } elseif ($this->ipFrc !== $this->try) {
            $saveToDb = fmod($this->ipFrc, 3) == 0;
        }

        $ip = $this->userIp;

        if ($saveToDb) {
            $this->conn->beginTransaction();

            $data = $this->conn->getMainByIp($ip);

            $bunTimeout = $this->getBunTimeout($data);

            if ($data) {
                $data = $this->conn->updateBadIp($data, $this->ipFrc, $bunTimeout);
            } else {
                $data = $this->conn->insertBadIp($ip, $this->ipFrc, $bunTimeout);
            }

            $this->conn->commit();

            $this->cache->setIpCache(
                $ip,
                $bunTimeout,
                $data['trust'],
            );
        } else {
            $this->cache->setIpCache(
                $ip,
                $this->banTimeOut,
            );
        }
    }

    /*****************************************************/
    /*****************************************************/
    /*****************************************************/

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getDosFr(): int
    {
        return $this->ddosFr;
    }

    public function getGoogleCaptchaSiteKey(): string
    {
        return $this->googleCaptcha['sitekey'];
    }

    public function getUserIp(): string
    {
        return $this->userIp;
    }

    public function isTrustIp(string $host): bool
    {
        foreach ($this->trustHosts as $r) {
            if (str_contains($host, $r)) {
                return true;
            }
        }
        return false;
    }

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
        $f = $this->cache->get(self::KEY_CACHE_INIT);
        if ($f) {
            return;
        }
        $checkVal = microtime() . '-' . rand(0, 100000);
        $this->cache->set(self::KEY_CACHE_INIT, $checkVal);
        usleep(10);
        if ($this->cache->get(self::KEY_CACHE_INIT) !== $checkVal) {
            return;
        }

        $data = $this->conn->getDataForRestore();
        if (!$data) {
            return;
        }
        foreach ($data as $r) {
            $ip = Tools::convertIp2String($r['ip']);
            $this->cache->setIpCache($ip, $this->getBunTimeout($r), $r['trust']);
        }
    }
}
