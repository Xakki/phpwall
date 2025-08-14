<?php
if ($_SERVER['REQUEST_URI'] == '/favicon.ico') {
    exit();
}

date_default_timezone_set(getenv('TZ'));

require __DIR__ . '/../vendor/autoload.php';

use Xakki\PHPWall\PHPWall;
use Psr\Log\NullLogger;

$_SERVER['HTTP_X_REMOTE_ADDR'] = '192.168.128.1';
if (isset($_GET['changeUA'])) {
    $_SERVER['HTTP_USER_AGENT'] = $_GET['changeUA'];
}

$logger = new NullLogger();

$cache = !empty($_GET['cache']) ? $_GET['cache'] : (!empty($_COOKIE['cache']) ? $_COOKIE['cache'] : 'redis');

new PHPWall(
    secretRequest: getenv('SECRET_KEY'),
    googleCaptchaSiteKey: getenv('GOOGLE_CAPTCHA_KEY'),
    googleCaptchaSecretKey: getenv('GOOGLE_CAPTCHA_SECRET'),
    logger: $logger,
    //debug: true,
    debug: 1,
    dbPdo: [
        'host' => getenv('MARIADB_HOST'),
        'dbname' => getenv('MARIADB_DATABASE'),
        'username' => getenv('MARIADB_USER'),
        'password' => getenv('MARIADB_PASSWORD')
    ],
    memCacheServers: ($cache !== 'redis' ? ['memcached:11211'] : []),
    redisCacheServer: [
        'host' => getenv('REDIS_HOST'),
        'database' => 1,
    ],
    checkUrlKeywordExclude: [
        '#/admin/index.php#',
        '#/ping#',
        '#/.well-known#',
        static  function ($str) {
            return strpos($str, 'myadminpage') !== false;
        },
    ],
);

if ($cache == 'redis') {
    setcookie('cache', 'redis');
} else {
    setcookie('cache', 'memc');
}

?>

<h1><a href="/">Home page</a></h1>
<p><a href="?<?=getenv('SECRET_KEY')?>=1" target="_blank">PhpWall panel</a></p>
<ul>
    <li><a href="/myadmin">Блокировка по урл (checkUrlKeyword - Черный список, checkUrlKeywordExclude - белый список)</a> </li>
    <li><form method="post"><input type="hidden" name="name" value="eval();"/><button>Блокировка по POST</button> </form> </li>
    <li><a href="/?changeUA=curl">Блокировка по UserAgent</a></li>
</ul>

Выбрать кэш сервер:
<a href="?cache=redis" style="<?=($cache == 'redis' ? 'font-weight: bold;': '')?>">Redis</a>
<a href="?cache=memc" style="<?=($cache != 'redis' ? 'font-weight: bold;': '')?>">Memcached</a>