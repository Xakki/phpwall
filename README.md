# phpwall
Protect site from scanners on PHP
Get googleCaptha keys from https://www.google.com/recaptcha/admin/
PhpWall- scan protect

1) Create table (dont foget change pass)
   ```mysql
   CREATE DATABASE `phpwall` CHARACTER SET 'utf8';
   CREATE USER 'phpwall'@'%' IDENTIFIED BY 'DB_PASS_CHANGE_ME';
   GRANT ALL PRIVILEGES ON phpwall.* TO 'phpwall'@'%';
   FLUSH PRIVILEGES;
   ```

If encoded password
```
set password for 'phpwall' = PASSWORD('*****');
```

2) get googleCaptcha from https://www.google.com/recaptcha/admin/

3) Add code of begin

```php

if (!isset($_SERVER['argv'])) {
   new \Xakki\PHPWall\PHPWall(
       secretRequest: 'REQUEST_CHANGE_ME',
       googleCaptchaSiteKey: 'CHANGE_ME',
       googleCaptchaSecretKey: 'CHANGE_ME',
   //    debug: true,
   //    checkPost: false,
   //    checkUa: false,
   //    checkUrlKeywordExclude: ['/admin/index.php', '/user/admin'],
   //    dbPdo: [
   //        'password' => 'DB_PASS_CHANGE_ME'
   //    ],
   );
}
```

## View mode 
URL  ?REQUEST_CHANGE_ME=1