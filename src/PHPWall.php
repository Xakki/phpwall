<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;
use PDO;

/**
 * @phpstan-import-type MainData from DB
 * @phpstan-import-type DbConfig from DB
 * @phpstan-import-type CacheServer from Cache
 */
class PHPWall
{
    public const VERSION = '0.8.2';

    public const RULE_IP = 0;
    public const RULE_UA = 1;
    public const RULE_POST = 2;
    public const RULE_URL = 3;

    public const TRUST_DEFAULT = 0; // No trust
    public const TRUST_WHITE_LIST = 10; // If matched by trustHosts
    public const TRUST_CAPTCHA = 1; // If passed the captcha
    public const TRUST_CONTROL = 2; // If whitelisted from the panel

    public const POST_WALL_NAME = 'unbunme';
    public const KEY_CACHE_INIT = 'phpWallInit';

    /** @var array<string, array<string, string>> */
    protected array $locale = [
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

    /** @var (string|callable)[] */
    protected array $trustHosts = [
        'ya.ru', 'yandex.ru', 'yandex.com', 'google.com', 'bing.com', 'yahoo.com', '127.0.0.1', '172.0.0.1/8'
    ];

    /** @var DbConfig */
    protected array $dbPdo = [
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

    /**
     * Disabled by default
     * @var array<string>
     */
    protected array $memCacheServers = [
        //'localhost:11211',
    ];

    /**
     * Enable by default
     * @var CacheServer
     */
    protected array $redisCacheServer = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'readTimeout' => 2.5,
        'connectTimeout' => 2.5,
        'persistent' => true,
        'database' => 0,
    ];

    /** @var (string|callable)[] */
    protected array $checkUrlKeyword = [
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
    protected array $checkUrlKeywordExclude = [];

    /** @var (string|callable)[] */
    protected array $checkUaKeyword = [
        '#GuzzleHttp#i',
        '#eval\(#i',
        '#curl#i',
        '#<script>#i',
        '#select #ui',
    ];

    /** @var (string|callable)[] */
    protected array $checkUaKeywordExclude = [];

    /** @var (string|callable)[] */
    protected array $checkPostKeyword = [
        '#eval\(#',
        '#curl#',
    ];

    /** @var (string|callable)[] */
    protected array $checkPostKeywordExclude = [];

    private string $userIp = '';
    private string $userAgent = '';
    private int $ipFrc = 0;
    private string $errorMessage = '';
    // @phpstan-ignore-next-line
    private readonly Cache $cache;
    // @phpstan-ignore-next-line
    private readonly Db $db;
    private ?string $lang = null;

    public function __construct(
        protected readonly string $secretRequest = 'CHANGE_ME',
        protected readonly string $googleCaptchaSiteKey = 'CHANGE_ME',
        protected readonly string $googleCaptchaSecretKey = 'CHANGE_ME',
        protected readonly ?LoggerInterface $logger = null,
        protected readonly int $debug = 0,
        protected readonly int $try = 2,
        protected readonly bool $allowLogRequest = true,
        protected readonly string $cachePrefix = 'phpwall',
        protected readonly string $wallTpl = 'ban-view.php',
        protected readonly int $banTimeOut = 259200,
        protected readonly int $banTimeOutEachDay = 43200,
        protected readonly int $banTimeOutEachRequest = 3600,
        protected readonly int $trustControlTimeout = 3600,
        protected readonly int $evilFr = 20,
        protected readonly int $ddosFr = 100,
        protected readonly bool $checkUrl = true,
        protected readonly bool $checkUa = true,
        protected readonly bool $checkUaEmpty = true,
        protected readonly bool $checkPost = true,
        protected string|EnumRedirectType $redirectByIp = EnumRedirectType::REDIRECT_TYPE_INFO,
        protected string|EnumRedirectType $redirectByCheck = EnumRedirectType::REDIRECT_TYPE_INFO,
        protected bool $checkIp = true,
        // Overridable properties
        ?string $lang = null,
        ?array $dbPdo = null,
        ?array $memCacheServers = null,
        ?array $redisCacheServer = null,
        ?array $trustHosts = null,
        ?array $locale = null,
        ?array $checkUrlKeyword = null,
        ?array $checkUrlKeywordExclude = null,
        ?array $checkUaKeyword = null,
        ?array $checkUaKeywordExclude = null,
        ?array $checkPostKeyword = null,
        ?array $checkPostKeywordExclude = null
    ) {
        if (isset($_SERVER['argv']) && isset($_SERVER['SHELL']) && !defined('PHPUNIT_INIT')) {
            $this->cache = $this->getCache();
            $this->db = $this->getDb();
            return;
        }
        $this->lang = $lang;
        // Merge configurations
        // @phpstan-ignore assign.propertyType
        $this->dbPdo = array_merge($this->dbPdo, $dbPdo ?? []);
        if ($this->dbPdo['password'] === 'CHANGE_ME' || $this->secretRequest === 'CHANGE_ME') {
            $this->cache = $this->getCache();
            $this->db = $this->getDb();
            exit('CRITICAL: Please change the default value of `CHANGE_ME` to something more complicated.');
        }
        $this->memCacheServers = array_merge($this->memCacheServers, $memCacheServers ?? []);
        // @phpstan-ignore assign.propertyType
        $this->redisCacheServer = array_merge($this->redisCacheServer, $redisCacheServer ?? []);
        if ($trustHosts) {
            $this->trustHosts = $trustHosts;
        }
        $this->locale = array_merge($this->locale, $locale ?? []);

        // Merge rules, including defaults from static methods
        $this->checkUrlKeyword = array_merge($this->checkUrlKeyword, $checkUrlKeyword ?? []);
        $this->checkUrlKeywordExclude = array_merge($this->checkUrlKeywordExclude, $checkUrlKeywordExclude ?? []);
        $this->checkUaKeyword = array_merge($this->checkUaKeyword, $checkUaKeyword ?? []);
        $this->checkUaKeywordExclude = array_merge($this->checkUaKeywordExclude, $checkUaKeywordExclude ?? []);
        $this->checkPostKeyword = array_merge($this->checkPostKeyword, $checkPostKeyword ?? []);
        $this->checkPostKeywordExclude = array_merge($this->checkPostKeywordExclude, $checkPostKeywordExclude ?? []);

        try {
            $this->setUserData();
            $this->setLang();

            $this->cache = $this->getCache();
            $this->db = $this->getDb();

            if ($this->handleViewRequest()) {
                return; // View request was handled and exited
            }

            if ($this->isTrustIp($this->userIp)) {
                return;
            }
            $this->init();
        } catch (\Throwable $e) {
            $this->log(LogLevel::ERROR, $e);
        }
    }

    /**
     * @return Cache
     */
    protected function getCache(): Cache
    {
        return new Cache($this, $this->memCacheServers, $this->redisCacheServer, $this->cachePrefix);
    }

    /**
     * @return Db
     */
    protected function getDb(): Db
    {
        return new Db($this, $this->dbPdo);
    }

    /**
     * @return bool
     */
    protected function handleViewRequest(): bool
    {
        if (empty($_GET[$this->secretRequest])) {
            return false;
        }

        try {
            $view = new View($this, $this->db, $this->cache, $this->secretRequest);
            $view->dispatch(); // This will exit
        } catch (Throwable $e) {
            $this->log(LogLevel::CRITICAL, $e);
            exit('PHPWall: View has encountered a critical error.');
        }
        return true;
    }

    /**
     * @return void
     */
    protected function setUserData(): void
    {
        // Order of checks is important. HTTP_X_FORWARDED_FOR can be spoofed.
        // Consider making the trusted proxy headers configurable.
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REMOTE_ADDR', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $this->userIp = (string) $_SERVER[$header];
                break;
            }
        }
        // Take the first IP if a list is provided (e.g., in X-Forwarded-For)
        $this->userIp = explode(',', $this->userIp)[0];

        $this->userAgent = !empty($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }

    /**
     * @return void
     */
    protected function setLang(): void
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
     * @return string
     */
    public function getLang(): string
    {
        return $this->lang ?? '';
    }

    /**
     * @return void
     */
    protected function init(): void
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
    protected function checkIp(): bool
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
    public function checkUrl(string $str): bool
    {
        foreach ($this->getMatchRules($this->checkUrlKeyword, $str) as $rule) {
            if ($this->hasMatchRules($this->checkUrlKeywordExclude, $str)) {
                continue;
            }
            if ($this->debug === 2) {
                exit('Block by URL: ' . $rule . ': ' . $str);
            }
            return $this->ruleApply(self::RULE_URL, $rule . ': ' . $str);
        }
        return true;
    }

    /**
     * @param string $str
     * @return bool
     */
    public function checkUa(string $str): bool
    {
        if (empty($str) && $this->checkUaEmpty) {
            return $this->ruleApply(self::RULE_UA, '*empty*');
        }
        if (mb_strlen($str) > 500) {
            return $this->ruleApply(self::RULE_UA, 'Too long > 500');
        }

        foreach ($this->getMatchRules($this->checkUaKeyword, $str) as $rule) {
            if ($this->hasMatchRules($this->checkUaKeywordExclude, $str)) {
                continue;
            }
            if ($this->debug === 2) {
                exit('Block by UA: ' . $rule . ': ' . $str);
            }
            return $this->ruleApply(self::RULE_UA, $rule . ': ' . $str);
        }

        return true;
    }

    /**
     * @param mixed $postData
     * @return bool
     */
    public function checkPost(mixed $postData): bool
    {
        if (is_array($postData)) {
            foreach ($postData as $value) {
                if (!$this->checkPost($value)) {
                    return false;
                }
            }
        } else {
            $strValue = (string) $postData;
            foreach ($this->getMatchRules($this->checkPostKeyword, $strValue) as $rule) {
                if ($this->hasMatchRules($this->checkPostKeywordExclude, $strValue)) {
                    continue;
                }
                if ($this->debug === 2) {
                    exit('Block by POST: ' . $rule . ': ' . $strValue);
                }
                return $this->ruleApply(self::RULE_POST, $rule . ': ' . $strValue);
            }
        }
        return true;
    }

    /**
     * @param (string|callable)[] $rules
     * @param string $str
     * @return \Generator<string>
     */
    protected function getMatchRules(array $rules, string $str): \Generator
    {
        foreach ($rules as $k => $rule) {
            if (is_callable($rule) && call_user_func($rule, $str)) {
                yield 'call:' . $k;
            } elseif (is_string($rule)) {
                $res = preg_match($rule, $str);
                if ($res > 0) {
                    yield $rule;
                } elseif ($res === false) {
                    $this->log(LogLevel::WARNING, 'BAD regexp: ' . $rule);
                }
            }
        }
    }

    /**
     * @param (string|callable)[] $rules
     * @param string $str
     * @return bool
     */
    protected function hasMatchRules(array $rules, string $str): bool
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
    protected function ruleApply(int $rule, string $word): bool
    {
        // Allow trusted IPs (e.g., search engine bots) to bypass certain rules.
        $trust = $this->cache->getIpCacheTrust($this->userIp);
        if ($trust === self::TRUST_WHITE_LIST || $trust === self::TRUST_CONTROL) {
            return true;
        }

        $this->log(LogLevel::INFO, 'Trigger by ' . View::RULE_TYPE_MAP[$rule] . ': ' . $word);
        $this->incrementBadIp($this->userIp);

        if ($this->allowLogRequest) {
            $this->db->addLog($this->userIp, $rule, $word, $this->ipFrc);
        }

        // If the number of attempts is still within the allowed limit, do not block yet.
        return $this->ipFrc <= $this->try;
    }

    /**
     * @param int $byRule
     * @return void
     */
    protected function wallAlarmAction(int $byRule): void
    {
        $this->log(LogLevel::NOTICE, 'wallAlarm: ' . View::RULE_TYPE_MAP[$byRule]);

        $redirectType = ($byRule === self::RULE_IP) ? $this->redirectByIp : $this->redirectByCheck;

        switch ($redirectType) {
            case EnumRedirectType::REDIRECT_TYPE_SELF:
                self::redirect('//' . $this->userIp);
                break;
            case EnumRedirectType::REDIRECT_TYPE_INFO:
                if (!empty($_POST[self::POST_WALL_NAME]) && $this->unBunByCaptcha()) {
                    self::redirect('//' . (string) ($_SERVER['HTTP_HOST'] ?? ''));
                }
                $phpWall = $this;
                include $this->wallTpl;
                exit();
            default:
                // @phpstan-ignore-next-line
                self::redirect($redirectType);
                break;
        }
        exit('No rule');
    }

    /**
     * @param string $url
     * @return void
     */
    protected static function redirect(string $url): void
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
    protected function unBunByCaptcha(): bool
    {
        $captchaResponse = $_POST['g-recaptcha-response'] ?? null;
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
    private function verifyCaptcha(string $response): bool
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
     * @param MainData|array{} $data
     * @return int
     */
    protected function getBunTimeout(array $data): int
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
    protected function incrementBadIp(string $ip): void
    {
        $this->ipFrc++;

        $saveToDb = false;
        if ($this->ipFrc === $this->try) {
            $saveToDb = true; // First time we hit the limit, always save.
        } elseif ($this->ipFrc > $this->try && $this->ipFrc < $this->evilFr) {
            // For moderate attacks, save every 3rd bad request to reduce DB load.
            $saveToDb = ($this->ipFrc % 3) === 0;
        } elseif ($this->ipFrc >= $this->evilFr) {
            // For heavy attacks (DDOS), save only every 100th request.
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
                    $trust = self::TRUST_DEFAULT;
                    if ($this->isTrustIp($ip)) {
                        $trust = self::TRUST_WHITE_LIST;
                    }
                    $data = $this->db->insertBadIp($ip, $this->userAgent, $this->ipFrc, $bunTimeout, $this->getHostnameByIp($ip), $trust);
                }
                $this->db->commit();

                $this->cache->setIpCache($ip, $bunTimeout, $data['trust']);
            } catch (Throwable $e) {
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
    protected function getHostnameByIp(string $ip): string
    {
        $hostname = $this->cache->get('hostname:' . $ip);
        if (!is_string($hostname)) {
            $hostname = gethostbyaddr($ip) ?: '';
            $this->cache->set('hostname:' . $ip, $hostname, 86400);
        }
        return $hostname;
    }

    /*****************************************************/

    /**
     * @param string $level
     * @param string|\Throwable $message
     * @param array<mixed> $context
     * @return void
     */
    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        $context['tag'] = 'PHPWall';
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
    public function setIpIsTrust(string $ip, int $trust): void
    {
        $this->cache->setIpIsTrust($ip, $trust, $this->trustControlTimeout);
        $this->db->setIpIsTrust($ip, $trust, $this->trustControlTimeout);
    }

    /**
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * @return int
     */
    public function getDosFr(): int
    {
        return $this->ddosFr;
    }

    /**
     * @return string
     */
    public function getGoogleCaptchaSiteKey(): string
    {
        return $this->googleCaptchaSiteKey;
    }

    /**
     * @return string
     */
    public function getUserIp(): string
    {
        return $this->userIp;
    }

    /**
     * @param string $ip
     * @return bool
     */
    public function isTrustIp(string $ip): bool
    {
        $hostname = $this->getHostnameByIp($ip);
        $ipLong = ip2long($ip);

        foreach ($this->trustHosts as $r) {
            if ($r === $ip) {
                return true;
            }
            // находим совпадение домена
            if (is_string($r) && substr($hostname, -(strlen($r))) === $r) {
                return true;
            }
            if (is_callable($r) && call_user_func($r, $hostname)) {
                return true;
            }
            if (is_string($r) && $ipLong !== false && self::ipMaskCheck($r, $ipLong)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Если соответствует маске
     */
    public static function ipMaskCheck(string $ipMask, int $ipLong): bool
    {
        if (str_contains($ipMask, '/')) {
            // CIDR subnet
            list($subnet, $mask) = explode('/', $ipMask);
            $subnet = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            if (!$subnet || !$mask) {
                trigger_error("Invalid IP address: $ipMask");
                return false;
            }
            $mask = (int) $mask;
            $subnetLong = ip2long($subnet);
            if ($subnetLong === false || $mask < 0 || $mask > 32) {
                return false; // Invalid entry
            }
            $netMask = ~((1 << (32 - $mask)) - 1);
            if (($ipLong & $netMask) == ($subnetLong & $netMask)) {
                return true;
            }
        } else {
            // Single IP
            if (ip2long($ipMask) == $ipLong) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $message
     * @param array<string|int, mixed> $params
     * @return string
     */
    public function locale(string $message, array $params = []): string
    {
        $translated = $this->locale[$this->lang][$message] ?? $message;
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
    public function restoreCache(): void
    {
        if ($this->cache->get(self::KEY_CACHE_INIT)) {
            return;
        }

        $checkVal = microtime() . '-' . random_int(0, 100000);
        $this->cache->set(self::KEY_CACHE_INIT, $checkVal, 60);
        usleep(10000); // 10ms

        // Prevent race condition where multiple processes try to restore at once
        // @phpstan-ignore-next-line
        if ((string) $this->cache->get(self::KEY_CACHE_INIT) !== $checkVal) {
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
