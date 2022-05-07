# phpwall
Protect site from scanners on PHP
Get googleCaptha keys from https://www.google.com/recaptcha/admin/

```
CREATE DATABASE IF NOT EXISTS phpwall;
CREATE USER IF NOT EXISTS 'phpwall'@'%' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL PRIVILEGES ON phpwall.* TO 'phpwall'@'%';"
```

```php
$phpWallConf = [
    'secretRequest' => 'CHANGE_ME',
    'secretRequestRemove' => 'CHANGE_ME',
    'banTimeOut' => 86400,
    'checkPost' => false,
    'checkUa' => false,
    'checkUrlKeywordExclude' => ['/admin/index.php', '/user/admin'],
    'dbPdo' => [
        'password' => 'passwor to databse'
    ],
    'googleCaptcha' => [
        'sitekey' => '...',
        'sicretkey' => '...',
    ]
];
new \Xakki\PHPWall\PHPWall($phpWallConf);
```