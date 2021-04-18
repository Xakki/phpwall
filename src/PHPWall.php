<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * @phpstan-import-type MainData from DB
 * @phpstan-import-type DbConfig from DB
 */
class PHPWall
{
    public const VERSION = '0.8.1';

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

    /** @var array<string, array<string, string>> */
    protected array $locale = [
        'ru' => [
            'Home' => 'На главную',
            'Attention' => 'Внимание',
            'Your IP [{$0}] has been blocked for suspicious activity.' => 'Ваш IP [{$0}] был заблокирован за подозрительную активность.',
            'If you want to remove the lock, then pass the check out.' => 'Если вы хотите снять блокировку, то пройдите проверку.',
            'Unbun' => 'Разблокировать',
            'Captcha not valid! Try again.' => 'Проверка не пройдена. Попробуйте еще.',
        ],
        'en' => [],
    ];

    /** @var string[] */
    protected array $trustHosts = [
        'ya.ru',
        'yandex.ru',
        'yandex.com',
        'google.com',
        'bing.com',
        'yahoo.com',
    ];

    /** @var DbConfig */
    protected array $dbPdo = [
        'engine' => 'mysql',
        'port' => 3306,
        'host' => '127.0.0.1',
        'dbname' => 'phpwall',
        'username' => 'phpwall',
        'password' => 'CHANGE_ME',
        'options' => [],
    ];

    /** @var string[] */
    protected array $memCacheServers = [
        //'127.0.0.1:11211',
    ];

    /** @var array<string, string|numeric|bool> */
    protected array $redisCacheServer = [
        'host'           => '127.0.0.1',
        'port'           => 6379,
        'readTimeout'    => 2.5,
        'connectTimeout' => 2.5,
        'persistent'     => true,
        'database'       => 0, // use default (first) DB
    ];

    /** @var array<int, string|callable> */
    protected array $checkUrlKeyword = [];
    /** @var array<int, string|callable> */
    protected array $checkUrlKeywordExclude = [];
    /** @var array<int, string|callable> */
    protected array $checkUaKeyword = [];
    /** @var array<int, string|callable> */
    protected array $checkUaKeywordExclude = [];
    /** @var array<int, string|callable> */
    protected array $checkPostKeyword = [];
    /** @var array<int, string|callable> */
    protected array $checkPostKeywordExclude = [];

    /////////////////////////////////////////////////

    private string $userIp;
    // Cached IP request frequency
    private int $ipFrc = 0;
    private string $errorMessage = '';
    private Cache $cache;
    private Db $db;

    public function __construct(
        protected readonly string $secretRequest,
        protected readonly string $googleCaptchaSiteKey,
        protected readonly string $googleCaptchaSecretKey,
        protected readonly ?LoggerInterface $logger = null,
        protected readonly bool $debug = false, // set debug mode
        protected readonly int $try = 2, // Allowed try bad request before get bun
        protected readonly bool $allowLogRequest = true, // save request log
        protected readonly string $cachePrefix = 'phpwall',
        protected readonly string $wallTpl = 'ban-view.php',
        protected readonly int $banTimeOut = 86400 * 3,
        protected readonly int $banTimeOutEachDay = 43200,
        protected readonly int $banTimeOutEachRequest = 3600,
        protected readonly int $evilFr = 20, // If session bad request more that evilFr, then less work with DB
        protected readonly int $ddosFr = 100, // Force self redirect
        protected readonly bool $checkUrl = true, // if need check by url
        protected readonly bool $checkUa = true, // if need check by user_agent
        protected readonly bool $checkUaEmpty = false, // if need check user_agent for empty
        protected readonly bool $checkPost = true, // if need check by POST
        protected string|EnumRedirectType $redirectByIp = EnumRedirectType::REDIRECT_TYPE_INFO, // `self` | `info` | custom url  // action if ban by IP
        protected string|EnumRedirectType $redirectByCheck = EnumRedirectType::REDIRECT_TYPE_INFO, // `self` | `info` | custom url // action if bun by over check
        protected bool $checkIp = true, // if need check by IP
        protected ?string $lang = null, // Default locale en
        /** @var ?array<string, string|numeric|bool> */
        ?array $dbPdo = null,
        /** @var ?array<int, string> */
        ?array $memCacheServers = null,
        /** @var ?array<string, string|numeric|bool> */
        ?array $redisCacheServer = null,
        /** @var ?array<int, string> */
        ?array $trustHosts = null,
        /** @var ?array<string, array<string, string>> */
        ?array $locale = null,
        /** @var ?array<int, string|callable> */
        ?array $checkUrlKeyword = null,
        /** @var ?array<int, string|callable> */
        ?array $checkUrlKeywordExclude = null,
        /** @var ?array<int, string|callable> */
        ?array $checkUaKeyword = null,
        /** @var ?array<int, string|callable> */
        ?array $checkUaKeywordExclude = null,
        /** @var ?array<int, string|callable> */
        ?array $checkPostKeyword = null,
        /** @var ?array<int, string|callable> */
        ?array $checkPostKeywordExclude = null,
    ) {
        try {
            foreach (
                [
                    'checkUrlKeyword',
                    'checkUrlKeywordExclude',
                    'checkUaKeyword',
                    'checkUaKeywordExclude',
                    'checkPostKeyword',
                    'checkPostKeywordExclude',
                ] as $prop
            ) {
                $method = 'getRule' . ucfirst($prop);
                if (method_exists($this, $method)) {
                    $this->$prop = call_user_func([$this, $method]);
                }
            }

            foreach (
                [
                    'dbPdo',
                    'memCacheServers',
                    'redisCacheServer',
                    'trustHosts',
                    'locale',
                    'checkUrlKeyword',
                    'checkUrlKeywordExclude',
                    'checkUaKeyword',
                    'checkUaKeywordExclude',
                    'checkPostKeyword',
                    'checkPostKeywordExclude',
                ] as $prop
            ) {
                // @phpstan-ignore argument.type
                if (is_array($$prop) && count($$prop)) {
                    // @phpstan-ignore-next-line
                    $this->$prop = array_merge($this->$prop, $$prop);
                }
            }

            if (!count($this->memCacheServers) && !count($this->redisCacheServer)) {
                throw new \Error('PHPWall: memCacheServers or redisCacheServer is require.');
            }

            $this->setUserIp();
            $this->setLang();

            $this->cache = $this->getCache();
            $this->db = $this->getDb();

            $this->initView();
            $this->init();
        } catch (\Throwable $e) {
            $this->log(LogLevel::ERROR, $e);
        }
    }

    protected function getCache(): Cache
    {
        return new Cache($this, $this->memCacheServers, $this->redisCacheServer, $this->cachePrefix);
    }

    protected function getDb(): Db
    {
        return new Db($this, $this->dbPdo);
    }

    protected function initView(): void
    {
        if (!empty($_GET[$this->secretRequest])) {
            if ($this->secretRequest == 'CHANGE_ME') {
                exit('CHANGE the secretReques');
            }

            try {
                new View($this, $this->db, $this->cache, $this->secretRequest);
            } catch (\Throwable $e) {
                $this->log(LogLevel::CRITICAL, $e);
                exit('View has error');
            }
        }
    }

    protected function setUserIp(): void
    {
        if (isset($_SERVER['HTTP_X_REMOTE_ADDR'])) {
            $this->userIp = (string) $_SERVER['HTTP_X_REMOTE_ADDR'];
        } else {
            $this->userIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        }
    }

    protected function setLang(): void
    {
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $this->lang = 'en';
            return;
        }
        $k = array_keys($this->locale);
        $v = preg_match('/(' . implode('|', $k) . ')/u', (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'], $m);
        if ($v) {
            $this->lang = $m[1];
        }
    }

    protected function init(): bool
    {
        if ($this->checkIp) {
            if (!$this->checkIp()) {
                $this->wallAlarmAction(self::RULE_IP);
            }
        }

        if ($this->checkUrl) {
            if (!$this->checkUrl((string) ($_SERVER['REQUEST_URI'] ?? ''))) {
                $this->wallAlarmAction(self::RULE_URL);
            }
        }

        if ($this->checkUa) {
            if (!$this->checkUa((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
                $this->wallAlarmAction(self::RULE_UA);
            }
        }

        if ($this->checkPost && !empty($_POST)) {
            if (!$this->checkPost($_POST)) {
                $this->wallAlarmAction(self::RULE_POST);
            }
        }

        return true;
    }

    protected function checkIp(): bool
    {
        if (empty($this->userIp)) {
            // skip
            $this->checkIp = false;
            return true;
        }

        $this->ipFrc = $this->cache->getIpCacheFrequency($this->userIp);

        if ($this->ipFrc) {
            if ($this->ipFrc <= $this->try) {
                // one more try
                return true;
            } else {
                if ($this->ipFrc > $this->ddosFr) {
                    $this->redirectByIp = EnumRedirectType::REDIRECT_TYPE_SELF;
                }
                return false;
            }
        }

        return true;
    }

    public function checkUrl(string $str): bool
    {
        foreach ($this->getMatchRules($this->checkUrlKeyword, $str) as $item) {
            if ($this->hasMatchRules($this->checkUrlKeywordExclude, $str)) {
                continue;
            }
            return $this->ruleApply(self::RULE_URL, is_string($item) ? $item : $str);
        }
        return true;
    }

    public function checkUa(string $str): bool
    {
        if (!$str && $this->checkUaEmpty) {
            return $this->ruleApply(self::RULE_UA, '*empty*');
        } else {
            foreach ($this->getMatchRules($this->checkUaKeyword, $str) as $item) {
                if ($this->hasMatchRules($this->checkUaKeywordExclude, $str)) {
                    continue;
                }
                return $this->ruleApply(self::RULE_UA, is_string($item) ? $item : $str);
            }
        }
        return true;
    }

    public function checkPost(mixed $post): bool
    {
        if (is_array($post)) {
            foreach ($post as $value) {
                if (!$this->checkPost($value)) {
                    return false;
                }
            }
        } else {
            $post = (string) $post;
            foreach ($this->getMatchRules($this->checkPostKeyword, $post) as $item) {
                if ($this->hasMatchRules($this->checkPostKeywordExclude, $post)) {
                    continue;
                }
                return $this->ruleApply(self::RULE_POST, is_string($item) ? $item : $post);
            }
        }
        return true;
    }

    /**
     * @param array<int, string|callable>  $rules
     */
    protected function getMatchRules(array $rules, string $str): \Generator
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                if (preg_match($rule, $str) > 0) {
                    yield $rule;
                }
            } elseif (is_callable($rule)) {
                if (call_user_func($rule, $str)) {
                    yield $rule;
                }
            }
        }
    }

    /**
     * @param array<int, string|callable>  $rules
     */
    protected function hasMatchRules(array $rules, string $str): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                if (preg_match($rule, $str)) {
                    return true;
                }
            } elseif (is_callable($rule)) {
                if (call_user_func($rule, $str)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function ruleApply(int $rule, string $word): bool
    {
        $this->incrementBadIp();

        if ($this->allowLogRequest) {
            $this->db->addLog($this->userIp, $rule, $word, $this->ipFrc);
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

    protected function wallAlarmAction(int $byRule): void
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
            if ($rule === EnumRedirectType::REDIRECT_TYPE_SELF) {
                self::redirect('//' . $this->userIp);
            } elseif ($rule === EnumRedirectType::REDIRECT_TYPE_INFO) {
                if (!empty($_POST[self::POST_WALL_NAME])) {
                    if ($this->unBunByCaptcha()) {
                        self::redirect('//' . (string) $_SERVER['HTTP_HOST']);
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

    protected function unBunByCaptcha(): bool
    {
        if (!empty($_POST['g-recaptcha-response'])) {
            $flag = false;
            $myCurl = curl_init();
            curl_setopt_array($myCurl, [
                CURLOPT_URL => 'https://www.google.com/recaptcha/api/siteverify',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'secret' => $this->googleCaptchaSecretKey,
                    'response' => $_POST['g-recaptcha-response'],
                    'remoteip' => $this->userIp,
                ]),
            ]);
            $response = curl_exec($myCurl);
            curl_close($myCurl);
            if (is_string($response)) {
                $response = json_decode($response, true);
                if (is_array($response) && !empty($response['success'])) {
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
     * @param MainData|array{} $data
     * @return int
     */
    protected function getBunTimeout(array $data): int
    {
        if ($data) {
            return $this->banTimeOut +
                ($data['request_bad_days'] - 1) * $this->banTimeOutEachDay +
                ($data['request_bad'] * $this->banTimeOutEachRequest);
        }
        return $this->banTimeOut;
    }

    protected function incrementBadIp(): void
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
            $this->db->beginTransaction();

            $data = $this->db->getMainByIp($ip);

            $bunTimeout = $this->getBunTimeout($data);

            if ($data) {
                $data = $this->db->updateBadIp($data, $this->ipFrc, $bunTimeout);
            } else {
                $data = $this->db->insertBadIp($ip, $this->ipFrc, $bunTimeout);
            }

            $this->db->commit();

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

    /**
     * @param array<string, scalar>     $context
     */
    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        if ($this->debug || $level == LogLevel::ERROR || $level == LogLevel::CRITICAL || $level == LogLevel::EMERGENCY) {
            fwrite(\STDERR, 'PHPWall [' . $level . '] ' . $message . ' | ' . json_encode($context) . PHP_EOL);
        }
        if (!$this->logger) {
            return;
        }
        $this->logger->log($level, $message, $context);
    }

    public function setIpIsTrust(string $ip, int $trust): void
    {
        $this->cache->setIpIsTrust($ip, $trust);
        $this->db->setIpIsTrust($ip, $trust);
    }

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
        return $this->googleCaptchaSiteKey;
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

    /**
     * @param string $message
     * @param array<string|int, mixed>  $params
     * @return string
     */
    public function locale(string $message, array $params = []): string
    {
        if (isset($this->locale[$this->lang][$message])) {
            $message = $this->locale[$this->lang][$message];
        }
        if (count($params)) {
            foreach ($params as $k => $p) {
                $message = str_replace('{$' . $k . '}', (string) $p, $message);
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

        $data = $this->db->getDataForRestore();
        if (!$data) {
            return;
        }
        foreach ($data as $r) {
            $ip = (string) Tools::convertIp2String($r['ip']);
            if ($ip) {
                $this->cache->setIpCache($ip, $this->getBunTimeout($r), $r['trust']);
            }
        }
    }

    /**
     * @return string[]|callable[]
     */
    public static function getRuleCheckUrlKeyword(): array
    {
        return [
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
    }

    /**
     * @return string[]|callable[]
     */
    public static function getRuleCheckUrlKeywordExclude(): array
    {
        return [];
    }

    /**
     * @return string[]|callable[]
     */
    public static function getRuleCheckUaKeyword(): array
    {
        return [
            '#GuzzleHttp#',
            '#eval\(#',
            '#curl#',
            '#<script>#',
            '#select #ui',
            function ($str) {
                return strlen($str) > 255;
            },
        ];
    }

    /**
     * @return string[]|callable[]
     */
    public static function getRuleCheckUaKeywordExclude(): array
    {
        return [];
    }

    /**
     * @return string[]|callable[]
     */
    public static function getRuleCheckPostKeyword(): array
    {
        return [
//            '#eval\(#',
//            '#curl#',
        ];
    }

    /**
     * @return string[]|callable[]
     */
    public static function getRuleCheckPostKeywordExclude(): array
    {
        return [];
    }
}
