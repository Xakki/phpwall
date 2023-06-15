# Simple Web Application Firewall on PHP

Protect site from scanners on PHP

# How run example

* cp .env.dist .env
* change GOOGLE_CAPTCHA_KEY & GOOGLE_CAPTCHA_SECRET
* run bash `make test-ui`
* Open http://localhost:89


# Steps
1) Prepare code
 - Get googleCaptha keys from https://www.google.com/recaptcha/admin/
 - use example/index.php for you project

2) Create table (dont foget change pass `CHANGE_ME`)
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
