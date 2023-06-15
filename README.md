# phpwall
Protect site from scanners on PHP
Get googleCaptha keys from https://www.google.com/recaptcha/admin/
PhpWall- scan protect

1) Create table (dont foget change pass `CHANGE_ME`)
   ```mysql
   CREATE DATABASE `phpwall` CHARACTER SET 'utf8';
   CREATE USER 'phpwall'@'%' IDENTIFIED BY 'CHANGE_ME';
   GRANT ALL PRIVILEGES ON phpwall.* TO 'phpwall'@'%';
   FLUSH PRIVILEGES;
   ```

If encoded password
```
set password for 'phpwall' = PASSWORD('*****');
```

2) Add code to index.php
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
     // get key on https://www.google.com/recaptcha/admin/
     'googleCaptchaSiteKey' => 'CHANGE_ME',
     'googleCaptchaSecretKey' => 'CHANGE_ME',

];
new \Xakki\PHPWall\PHPWall($phpWallConf);
```