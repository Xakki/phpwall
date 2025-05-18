# About

Protect site from scanners on PHP

Get googleCaptha keys from https://www.google.com/recaptcha/admin/


# Install

`composer require xakki/phpwall`

# Configure

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
       debug: true,
       checkUrlKeywordExclude: ['/admin/index.php', '/user/admin'],
       dbPdo: [
           'host' => getenv('MYSQL_HOST'),
           'password' => 'DB_PASS_CHANGE_ME',
           'options' => [
               PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
               PDO::ATTR_PERSISTENT => true,
           ]
       ],
       redisCacheServer: [
           'host' => getenv('REDIS_HOST'),
           'database' => 1,
       ]
   );
}
```

## View mode

View URL  `?REQUEST_CHANGE_ME=1`

# CONTACTS

<p>
 <a href="https://t.me/ProfMatrix" target="_blank">
  <img src="https://img.shields.io/badge/Telegram-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white" target="_blank">
 </a>  

<a href="https://www.linkedin.com/in/xakki/" target="_blank">
 <img src="https://img.shields.io/badge/-LinkedIn-%230077B5?style=for-the-badge&logo=linkedin&logoColor=white" target="_blank">
</a>  
</p>