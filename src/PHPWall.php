<?php

namespace Xakki\PHPWall;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Exception;
use PDO;

/**
 * @phpstan-import-type MainData from DB
 * @phpstan-import-type DbConfig from DB
 * @phpstan-import-type CacheServer from Cache
 */
class PHPWall
{
    const VERSION = '0.5.7';

    const RULE_IP = 0;
    const RULE_UA = 1;
    const RULE_POST = 2;
    const RULE_URL = 3;

    const TRUST_DEFAULT = 0; // No trust
    const TRUST_WHITE_LIST = 10; // If matched by trustHosts
    const TRUST_CAPTCHA = 1; // If passed the captcha
    const TRUST_CONTROL = 2; // If whitelisted from the panel

    const POST_WALL_NAME = 'unbunme';
    const KEY_CACHE_INIT = 'phpWallInit';

    /** @var array<string, array<string, string>> */
    protected $locale = [
        'ru' => [
            'Home' => 'На главную',
            'Attention' => 'Внимание',
            'Your IP [{$0}] has been blocked for suspicious activity.' => 'Ваш IP [{$0}] был заблокирован за подозрительную активность.',
            'If you want to remove the lock, please complete the check.' => 'Если вы хотите снять блокировку, то пройдите проверку.',
            'Unblock' => 'Разблокировать',
            'Captcha not valid! Try again.' => 'Проверка не пройдена. Попробуйте еще.',
        ],
        'en' => [
            'Home' => 'Home',
            'Attention' => 'Attention',
            'Your IP [{$0}] has been blocked for suspicious activity.' => 'Your IP [{$0}] has been blocked for suspicious activity.',
            'If you want to remove the lock, please complete the check.' => 'If you want to remove the lock, please complete the check.',
            'Unblock' => 'Unblock',
            'Captcha not valid! Try again.' => 'Captcha not valid! Try again.',
        ],
    ];

    /** @var array<int, string|callable> */
    protected $trustHosts = [
        'ya.ru', 'yandex.ru', 'yandex.com', 'google.com', 'bing.com', 'yahoo.com',
    ];

    /** @var DbConfig */
    protected $dbPdo = [
        'engine' => 'mysql',
        'port' => 3306,
        'host' => '127.0.0.1',
        'dbname' => 'phpwall',
        'username' => 'phpwall',
        'password' => 'CHANGE_ME',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_STRINGIFY_FETCHES => false
        ],
    ];

    /** @var string[] */
    protected $memCacheServers = [
        //'localhost:11211',
    ];

    /** @var CacheServer */
    protected $redisCacheServer = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'readTimeout' => 2.5,
        'connectTimeout' => 2.5,
        'persistent' => true,
        'database' => 0,
    ];

    /** @var (string|callable)[] */
    protected $checkUrlKeyword = [
        '#eval\(#',
        '#\/sqlite#',
        '#\/manager#',
        '#\/setup#',
        '#\/admin#',
        '#\/pma#',
        '#\/phpma#',
        '#\/phpmyadmin#',
        '#\/myadmin#',
        '#\/phpadmin#',
        '#\/mysqladmin#',
        '#\/wp\-login#',
        '#\/wp\-content#',
        '#\/administrator#',
        '#\/wp\-admin#',
        '#\/wp\-includes#',
        '#\/wordpress#',
        '#\/mod_stats\.xml#',
        '#\/mscms#',
        '#\/\.ssh#',
        '#\/\.git#',
        '#\/xmlrpc\.php#',
        '#\/wallet\.dat#',
        '#\/\.bash_history#',
        '#\/webalizer#',
        '#\/wstat#',
        '#\/fckeditor\/editor#',
    ];
    /** @var (string|callable)[] */
    protected $checkUrlKeywordExclude = [];
    /** @var (string|callable)[] */
    protected $checkUaKeyword = [
        '#GuzzleHttp#i',
        '#eval\(#i',
        '#curl#i',
        '#<script>#i',
        '#select #ui',
    ];
    /** @var (string|callable)[] */
    protected $checkUaKeywordExclude = [];
    /** @var (string|callable)[] */
    protected $checkPostKeyword = [
        '#eval\(#',
        '#curl#',
    ];
    /** @var (string|callable)[] */
    protected $checkPostKeywordExclude = [];

    /** @var string */
    private $userIp = '';
    /** @var string */
    private $userAgent = '';
    /** @var int */
    private $ipFrc = 0;
    /** @var string */
    private $errorMessage = '';
    /** @var Cache */
    private $cache;
    /** @var Db */
    private $db;
    /** @var string|null */
    private $lang = null;

    /** @var string */
    protected $secretRequest = 'CHANGE_ME';
    /** @var string */
    protected $googleCaptchaSiteKey = 'CHANGE_ME';
    /** @var string */
    protected $googleCaptchaSecretKey = 'CHANGE_ME';
    /** @var LoggerInterface|null */
    protected $logger = null;
    /** @var bool */
    protected $debug = 0;
    /** @var int */
    protected $try = 2;
    /** @var bool */
    protected $allowLogRequest = true;
    /** @var string */
    protected $cachePrefix = 'phpwall';
    /** @var string */
    protected $wallTpl = 'ban-view.php';
    /** @var int */
    protected $banTimeOut = 259200; // 3 days
    /** @var int */
    protected $banTimeOutEachDay = 43200;
    /** @var int */
    protected $banTimeOutEachRequest = 3600;
    /**
     * If session bad request more that evilFr, then less work with DB
     * @var int
     */
    protected $evilFr = 20;
    /** @var int */
    protected $ddosFr = 100;
    /** @var bool */
    protected $checkUrl = true;
    /** @var bool */
    protected $checkUa = true;
    /** @var bool */
    protected $checkUaEmpty = true;
    /** @var bool */
    protected $checkPost = true;
    /** @var bool */
    protected $checkIp = true;
    /** @var string */
    protected $redirectByIp = EnumRedirectType::REDIRECT_TYPE_INFO;
    /** @var string */
    protected $redirectByCheck = EnumRedirectType::REDIRECT_TYPE_INFO;

    /**
     * @param array $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $config = [], LoggerInterface $logger = null)
    {
        if (isset($_SERVER['argv']) && !defined('PHPUNIT_INIT')) {
            return;
        }

        if ($logger) {
            $this->logger = $logger;
        }

        try {
            $this->setProperty($config);
            $this->setUserData();
            $this->setLang();

            $this->cache = $this->getCache();
            $this->db = $this->getDb();

            if ($this->handleViewRequest()) {
                return; // View request was handled and exited
            }

            if (substr($this->userIp, 0, 4) === '172.' || substr($this->userIp, 0, 4) === '127.') {
                return;
            }

            $this->init();
        } catch (Exception $e) {
            $this->log(LogLevel::ERROR, $e);
        }
    }

    protected function setProperty($config)
    {
        $props = self::getPropertyValidator();
        foreach ($config as $k => $r) {
            if (!empty($props[$k])) {
                if ($props[$k] === 'array') {
                    $this->$k = array_merge($this->$k, $r);
                } elseif ($props[$k] === 'bool') {
                    $this->$k = (bool) $r;
                } elseif ($props[$k] === 'int') {
                    $this->$k = (int) $r;
                } else {
                    $this->$k = (string) $r;
                }
            } else {
                $this->log(LogLevel::WARNING, 'An unspecified parameter: ' . $k);
            }
        }

        if ($this->dbPdo['password'] === 'CHANGE_ME' || $this->secretRequest === 'CHANGE_ME') {
            exit('CRITICAL: Please change the default value of `CHANGE_ME` to something more complicated.');
        }
    }

    /**
     * @return string[]
     */
    protected static function getPropertyValidator() {
        return [
            'wallTpl' => 'string',
            'cachePrefix' => 'string',
            'secretRequest' => 'string',
            'secretRequestRemove' => 'string',
            'googleCaptchaSiteKey' => 'string',
            'googleCaptchaSecretKey' => 'string',
            'debug' => 'int',
            'try' => 'int',
            'memCacheServers' => 'array',
            'redisCacheServer' => 'array',
            'dbPdo' => 'array',
            'banTimeOut' => 'int',
            'banTimeOutEachDay' => 'int',
            'banTimeOutEachRequest' => 'int',
            'evilFr' => 'int',
            'checkIp' => 'bool',
            'checkUrl' => 'bool',
            'checkUa' => 'bool',
            'checkUaEmpty' => 'bool',
            'checkPost' => 'bool',
            'checkUrlKeyword' => 'array',
            'checkUrlKeywordExclude' => 'array',
            'checkUaKeyword' => 'array',
            'checkUaKeywordExclude' => 'array',
            'checkPostKeyword' => 'array',
            'checkPostKeywordExclude' => 'array',
            'redirectByIp' => 'string',
            'redirectByCheck' => 'string',
            'trustHosts' => 'array',
            'lang' => 'string',
            'locale' => 'array',
        ];
    }

    /**
     * @return Cache
     */
    protected function getCache()
    {
        return new Cache($this, $this->memCacheServers, $this->redisCacheServer, $this->cachePrefix);
    }

    /**
     * @return Db
     */
    protected function getDb()
    {
        return new Db($this->logger, $this->dbPdo);
    }

    /**
     * @return bool
     */
    protected function handleViewRequest()
    {
        if (empty($_GET[$this->secretRequest])) {
            return false;
        }

        try {
            $view = new View($this, $this->db, $this->cache, $this->secretRequest);
            $view->dispatch(); // This will exit
        } catch (Exception $e) {
            $this->log(LogLevel::CRITICAL, $e);
            exit('PHPWall: View has encountered a critical error.');
        }
        return true;
    }

    /**
     * @return void
     */
    protected function setUserData()
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REMOTE_ADDR', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $this->userIp = (string) $_SERVER[$header];
                break;
            }
        }
        $this->userIp = explode(',', $this->userIp)[0];
        $this->userAgent = !empty($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }

    /**
     * @return void
     */
    protected function setLang()
    {
        if ($this->lang) { // Lang was forced in config
            return;
        }
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $this->lang = 'en';
            return;
        }
        $k = array_keys($this->locale);
        if (preg_match('/(' . implode('|', $k) . ')/iu', (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'], $m)) {
            $this->lang = $m[1];
        } else {
            $this->lang = 'en';
        }
    }

    /**
     * @return string|null
     */
    public function getLang()
    {
        return $this->lang;
    }

    /**
     * @return void
     */
    protected function init()
    {
        if ($this->checkIp && !$this->checkIp()) {
            $this->wallAlarmAction(self::RULE_IP);
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($this->checkUrl && !$this->checkUrl($requestUri)) {
            $this->wallAlarmAction(self::RULE_URL);
        }

        if ($this->checkUa && !$this->checkUa($this->userAgent)) {
            $this->wallAlarmAction(self::RULE_UA);
        }

        if ($this->checkPost && !empty($_POST) && !$this->checkPost($_POST)) {
            $this->wallAlarmAction(self::RULE_POST);
        }
    }

    /**
     * @return bool
     */
    protected function checkIp()
    {
        if (empty($this->userIp)) {
            $this->checkIp = false; // Skip check if IP is not identified
            return true;
        }

        $this->ipFrc = $this->cache->getIpCacheFrequency($this->userIp);

        if ($this->ipFrc > $this->try) {
            if ($this->ipFrc > $this->ddosFr) {
                $this->redirectByIp = EnumRedirectType::REDIRECT_TYPE_SELF;
            }
            return false; // Block
        }

        return true; // Allow
    }

    /**
     * @param string $str
     * @return bool
     */
    public function checkUrl($str)
    {
        foreach ($this->getMatchRules($this->checkUrlKeyword, $str) as $item) {
            if ($this->hasMatchRules($this->checkUrlKeywordExclude, $str)) {
                continue;
            }
            if ($this->debug === 2) {
                exit('Block by URL: ' . $str . PHP_EOL . json_encode($item));
            }
            return $this->ruleApply(self::RULE_URL, is_string($item) ? $item : $str);
        }
        return true;
    }

    /**
     * @param string $str
     * @return bool
     */
    public function checkUa($str)
    {
        if (empty($str) && $this->checkUaEmpty) {
            return $this->ruleApply(self::RULE_UA, '*empty*');
        }
        if (mb_strlen($str) > 500) {
            return $this->ruleApply(self::RULE_UA, 'Too long > 500');
        }

        foreach ($this->getMatchRules($this->checkUaKeyword, $str) as $item) {
            if ($this->hasMatchRules($this->checkUaKeywordExclude, $str)) {
                continue;
            }
            if ($this->debug === 2) {
                exit('Block by UA: ' . $str . PHP_EOL . json_encode($item));
            }
            return $this->ruleApply(self::RULE_UA, is_string($item) ? $item : $str);
        }

        return true;
    }

    /**
     * @param mixed $postData
     * @return bool
     */
    public function checkPost($postData)
    {
        if (is_array($postData)) {
            foreach ($postData as $value) {
                if (!$this->checkPost($value)) {
                    return false;
                }
            }
        } else {
            $strValue = (string) $postData;
            foreach ($this->getMatchRules($this->checkPostKeyword, $strValue) as $item) {
                if ($this->hasMatchRules($this->checkPostKeywordExclude, $strValue)) {
                    continue;
                }
                if ($this->debug === 2) {
                    exit('Block by POST: ' . $strValue . PHP_EOL . json_encode($item));
                }
                return $this->ruleApply(self::RULE_POST, is_string($item) ? $item : $strValue);
            }
        }
        return true;
    }

    /**
     * @param (string|callable)[] $rules
     * @param string $str
     * @return \Generator<string|callable>
     */
    protected function getMatchRules(array $rules, $str)
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $res = preg_match($rule, $str);
                if ($res > 0) {
                    yield $rule;
                } elseif ($res === false) {
                    $this->log(LogLevel::WARNING, 'BAD regexp: ' . $rule);
                }
            } elseif (is_callable($rule) && call_user_func($rule, $str)) {
                yield $rule;
            }
        }
    }

    /**
     * @param (string|callable)[] $rules
     * @param string $str
     * @return bool
     */
    protected function hasMatchRules(array $rules, $str)
    {
        foreach ($this->getMatchRules($rules, $str) as $_) {
            return true;
        }
        return false;
    }

    /**
     * @param int $rule
     * @param string $word
     * @return bool
     */
    protected function ruleApply($rule, $word)
    {
        $this->log(LogLevel::INFO, 'Trigger by ' . View::RULE_TYPE_MAP[$rule] . ': ' . $word);
        $this->incrementBadIp($this->userIp);

        if ($this->allowLogRequest) {
            $this->db->addLog($this->userIp, $rule, $word, $this->ipFrc);
        }

        if ($this->ipFrc <= $this->try) {
            return true;
        }

        $trust = $this->cache->getIpCacheTrust($this->userIp);
        return $trust === self::TRUST_WHITE_LIST;
    }

    /**
     * @param int $byRule
     * @return void
     */
    protected function wallAlarmAction($byRule)
    {
        $this->log(LogLevel::NOTICE, 'wallAlarm: ' . View::RULE_TYPE_MAP[$byRule]);

        $redirectType = ($byRule === self::RULE_IP) ? $this->redirectByIp : $this->redirectByCheck;

        switch ($redirectType) {
            case EnumRedirectType::REDIRECT_TYPE_SELF:
                self::redirect('//' . $this->userIp);
                break;
            case EnumRedirectType::REDIRECT_TYPE_INFO:
                if (!empty($_POST[self::POST_WALL_NAME]) && $this->unBunByCaptcha()) {
                    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
                    self::redirect('//' . $host);
                }
                $phpWall = $this;
                include $this->wallTpl;
                exit();
            default:
                self::redirect($redirectType);
                break;
        }
        exit('No rule');
    }

    /**
     * @param string $url
     * @return void
     */
    protected static function redirect($url)
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, 301);
        } else {
            echo '<script>location.href="' . $url . '";</script>';
        }
        exit();
    }

    /**
     * @return bool
     */
    protected function unBunByCaptcha()
    {
        $captchaResponse = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : null;
        if (!$captchaResponse) {
            $this->errorMessage = 'Captcha not valid!';
            return false;
        }

        if ($this->verifyCaptcha((string)$captchaResponse)) {
            $this->setIpIsTrust($this->userIp, self::TRUST_CAPTCHA);
            return true;
        }

        $this->errorMessage = 'Captcha not valid! Try again.';
        return false;
    }

    /**
     * @param string $response
     * @return bool
     */
    private function verifyCaptcha($response)
    {
        $myCurl = curl_init();
        curl_setopt_array($myCurl, [
            CURLOPT_URL => 'https://www.google.com/recaptcha/api/siteverify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $this->googleCaptchaSecretKey,
                'response' => $response,
                'remoteip' => $this->userIp,
            ]),
        ]);
        $apiResponse = curl_exec($myCurl);
        curl_close($myCurl);

        if (!is_string($apiResponse) || empty($apiResponse)) {
            return false;
        }

        $decodedResponse = json_decode($apiResponse, true);
        return is_array($decodedResponse) && !empty($decodedResponse['success']);
    }

    /**
     * @param MainData|array<string, mixed> $data
     * @return int
     */
    protected function getBunTimeout(array $data)
    {
        if (empty($data)) {
            return $this->banTimeOut;
        }
        return $this->banTimeOut +
            ($data['request_bad_days'] - 1) * $this->banTimeOutEachDay +
            ($data['request_bad'] * $this->banTimeOutEachRequest);
    }

    /**
     * @param string $ip
     * @return void
     */
    protected function incrementBadIp($ip)
    {
        $this->ipFrc++;

        $saveToDb = false;
        if ($this->ipFrc === $this->try) {
            $saveToDb = true;
        } elseif ($this->ipFrc > $this->try && $this->ipFrc < $this->evilFr) {
            $saveToDb = ($this->ipFrc % 3) === 0;
        } elseif ($this->ipFrc >= $this->evilFr) {
            $saveToDb = ($this->ipFrc % 100) === 0;
        }

        if ($saveToDb) {
            $this->db->beginTransaction();
            try {
                $data = $this->db->getMainByIp($ip);
                $bunTimeout = $this->getBunTimeout($data);

                if ($data) {
                    $data = $this->db->updateBadIp($data, $this->ipFrc, $bunTimeout);
                } else {
                    $hostname = $this->getHostnameByIp($ip);
                    $trust = self::TRUST_DEFAULT;
                    if ($this->isTrustIp($hostname, $ip)) {
                        $trust = self::TRUST_WHITE_LIST;
                    }
                    $data = $this->db->insertBadIp($ip, $this->userAgent, $this->ipFrc, $bunTimeout, $hostname, $trust);
                }
                $this->db->commit();

                $this->cache->setIpCache($ip, $bunTimeout, $data['trust']);
            } catch (Exception $e) {
                $this->db->rollback();
                $this->log(LogLevel::ERROR, $e);
            }
        } else {
            $this->cache->setIpCache($ip, $this->banTimeOut);
        }
    }

    /**
     * @param string $ip
     * @return string
     */
    protected function getHostnameByIp($ip)
    {
        return gethostbyaddr($ip) ?: '';
    }

    /*****************************************************/

    /**
     * @param string $level
     * @param string|\Throwable $message
     * @param array<mixed> $context
     * @return void
     */
    public function log($level, $message, array $context = [])
    {
        if ($this->logger) {
            $this->logger->log($level, (string) $message, $context);
        } else {
            trigger_error((string) $message, E_USER_NOTICE);
        }
    }

    /**
     * @param string $ip
     * @param int $trust
     * @return void
     */
    public function setIpIsTrust($ip, $trust)
    {
        $this->cache->setIpIsTrust($ip, $trust);
        $this->db->setIpIsTrust($ip, $trust);
    }

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
        return $this->googleCaptchaSiteKey;
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
     * @param string $ip
     * @return bool
     */
    public function isTrustIp($host, $ip)
    {
        foreach ($this->trustHosts as $r) {
            if ($r === $ip) {
                return true;
            }
            if (is_string($r) && substr($host, -(strlen($r))) === $r) {
                return true;
            }
            if (is_callable($r) && call_user_func($r, $host)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $message
     * @param array<int, string|int> $params
     * @return string
     */
    public function locale($message, array $params = [])
    {
        $translated = isset($this->locale[$this->lang][$message]) ? $this->locale[$this->lang][$message] : $message;
        if (!empty($params)) {
            foreach ($params as $k => $p) {
                $translated = str_replace('{$' . $k . '}', (string) $p, $translated);
            }
        }
        return htmlspecialchars($translated, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return void
     */
    public function restoreCache()
    {
        if ($this->cache->get(self::KEY_CACHE_INIT)) {
            return;
        }

        $checkVal = microtime() . '-' . rand(0, 100000);
        $this->cache->set(self::KEY_CACHE_INIT, $checkVal, 60);
        usleep(10000); // 10ms

        if ($this->cache->get(self::KEY_CACHE_INIT) !== $checkVal) {
            return;
        }

        $this->cache->set(self::KEY_CACHE_INIT, microtime());
        $data = $this->db->getDataForRestore();
        $this->log(LogLevel::INFO, 'restoreCache, count: ' . count($data));
        if (empty($data)) {
            return;
        }

        foreach ($data as $r) {
            $this->cache->setIpCache($r['ip'], $this->getBunTimeout($r), (int)$r['trust']);
        }
    }

}
