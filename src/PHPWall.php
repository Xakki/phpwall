<?php

namespace Xakki\PHPWall;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

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
    const VERSION = '0.8.1';
    const REDIRECT_TYPE_INFO = 'info'; // Show page info about bun
    const REDIRECT_TYPE_SELF = 'self'; // self redirect

    const RULE_IP = 0;
    const RULE_UA = 1;
    const RULE_POST = 2;
    const RULE_URL = 3;

    const TRUST_DEFAULT = 0; // no trust
    const TRUST_SEARCH = 10; // If matched by trustHosts
    const TRUST_CAPTCHA = 1; // If passed the captcha
    const TRUST_CONTROL = 2; // If entered into panel

    const POST_WALL_NAME = 'unbunme';
    const KEY_CACHE_INIT = 'phpWallInit';

    const ALLOW_PROPERTY = [
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
    protected $secretRequest = 'CHANGE_ME';
    protected $secretRequestRemove = 'CHANGE_ME';

    // get key on https://www.google.com/recaptcha/admin/
    protected $googleCaptcha = [
        'sitekey' => 'CHANGE_ME',
        'sicretkey' => 'CHANGE_ME',
    ];
    protected $debug = false;
    protected $try = 2; // Allowed try request before get bun
    protected $logMode = 1; // 0 disabled log; 1 - enable log

    protected $memcache = [
        'localhost',
        11211,
    ];

    protected $dbPdo = [
        'engine' => 'mysql',
        'port' => 3306,
        'host' => 'localhost',
        'dbname' => 'phpwall',
        'username' => 'phpwall',
        'password' => 'CHANGE_ME',
        'options' => [],
    ];

    protected $cachePrefix = 'phpwall';
    protected $wallTpl = 'ban-view.php';

    protected $banTimeOut = 86400 * 3;
    protected $banTimeOutEachDay = 43200;
    protected $banTimeOutEachRequest = 3600;
    protected $evilFr = 20; // If session bad request more that evilFr, then less work with DB
    protected $ddosFr = 100; // Forse self reirect

    protected $checkIp = true; // if need check by IP
    protected $checkUrl = true; // if need check by url
    protected $checkUa = true; // if need check by user_agent
    protected $checkUaEmpty = false; // if need check user_agent for empty
    protected $checkPost = true; // if need check by POST

    protected $checkUrlKeyword = [
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
    protected $checkUrlKeywordExclude = [];
    protected $checkUaKeyword = [
        'eval(',
        'curl',
    ];
    protected $checkUaKeywordExclude = [];

    protected $checkPostKeyword = [
        'eval(',
        'curl',
    ];
    protected $checkPostKeywordExclude = [];

    protected $redirectByIp = self::REDIRECT_TYPE_INFO;// `self` | `info` | custom url  // action if ban by IP
    protected $redirectByCheck = self::REDIRECT_TYPE_INFO;// `self` | `info` | custom url // action if bun by over check

    protected $trustHosts = [
        'ya.ru',
        'yandex.ru',
        'yandex.com',
        'google.com',
        'bing.com',
        'yahoo.com',
    ];

    protected $lang = 'en';// Default locale

    protected $locale = [
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

    private $userIp;
    // Cached IP request frequency
    private $ipFrc = 0;
    private $errorMessage = '';

    /** @var LoggerInterface|null  */
    private $logger;
    /** @var Cache  */
    private $cache;
    /** @var Db  */
    private $conn;

    /**
     * @param array $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $config = [], LoggerInterface $logger = null)
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

    /**
     * @return void
     */
    protected function setUserIp()
    {
        if (isset($_SERVER['HTTP_X_REMOTE_ADDR'])) {
            $this->userIp = $_SERVER['HTTP_X_REMOTE_ADDR'];
        } else {
            $this->userIp = $_SERVER['REMOTE_ADDR'];
        }
    }

    /**
     * @return void
     */
    protected function setLang()
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

    /**
     * @param array $config
     * @return void
     */
    protected function setProperty($config)
    {
        foreach ($config as $k => $r) {
            if (!empty(self::ALLOW_PROPERTY[$k])) {
                if (is_array($this->$k)) {
                    $this->$k = array_merge($this->$k, $r);
                } else {
                    $this->$k = $r;
                }
            }
        }
    }

    /**
     * @param string $level
     * @param string|Exception $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, $context = [])
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
        $this->logger->log($level, (string) $message, $context);
    }

    /**
     * @return bool
     */
    private function init()
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

    /**
     * @return bool
     */
    private function checkIp()
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

    /**
     * @return bool
     */
    private function checkUrl()
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

    /**
     * @return bool
     */
    private function checkUa()
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

    /**
     * @return bool
     */
    private function checkPost()
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

    /**
     * @param int $rule
     * @param string $word
     * @return bool
     * @throws Exception
     */
    private function ruleApply($rule, $word)
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

    /**
     * @param int $byRule
     * @return void
     */
    private function wallAlarmAction($byRule)
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
                $phpWall = $this;
                include $this->wallTpl;
                exit();
            } else {
                self::redirect($rule);
            }
        }
        exit('No rule');
    }

    /**
     * @param string $url
     * @return void
     */
    protected static function redirect($url)
    {
        header('Location: ' . $url, true, 301);
        exit();
    }

    /**
     * @return bool
     */
    private function unBunByCaptcha()
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
            if (is_string($response)) {
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

    /**
     * @param string $ip
     * @param int $trust
     * @return void
     * @throws Exception
     */
    public function setIpIsTrust($ip, $trust)
    {
        $this->cache->setIpIsTrust($ip, $trust);
        $this->conn->setIpIsTrust($ip, $trust);
    }

    /**
     * @param array $data
     * @return int
     */
    public function getBunTimeout(array $data)
    {
        return $this->banTimeOut +
            ($data['request_bad_days'] - 1) * $this->banTimeOutEachDay +
            ($data['request_bad'] * $this->banTimeOutEachRequest);
    }

    /**
     * @return void
     * @throws Exception
     */
    private function incrementBadIp()
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
                $data['trust']
            );
        } else {
            $this->cache->setIpCache(
                $ip,
                $this->banTimeOut
            );
        }
    }

    /*****************************************************/
    /*****************************************************/
    /*****************************************************/

    /**
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    /**
     * @return int
     */
    public function getDosFr()
    {
        return $this->ddosFr;
    }

    /**
     * @return string
     */
    public function getGoogleCaptchaSiteKey()
    {
        return $this->googleCaptcha['sitekey'];
    }

    /**
     * @return string
     */
    public function getUserIp()
    {
        return $this->userIp;
    }

    /**
     * @param string $host
     * @return bool
     */
    public function isTrustIp($host)
    {
        foreach ($this->trustHosts as $r) {
            if (strpos($host, $r) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $message
     * @param array $params
     * @return string
     */
    public function locale($message, array $params = [])
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

    /**
     * @return void
     * @throws Exception
     */
    public function restoreCache()
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
            if ($ip) {
                $this->cache->setIpCache($ip, $this->getBunTimeout($r), $r['trust']);
            }
        }
    }
}
