# phpwall
Protect site from scanners on PHP
Get googleCaptha keys from https://www.google.com/recaptcha/admin/

```php
$phpWallConf = [
    'secretRequest' => 'CHANGE_ME',
    'secretRequestRemove' => 'CHANGE_ME',
    'bunTimeout' => 86400,
    'check_post' => false,
    'check_ua' => false,
    'check_url_keyword_exclude' => ['/admin/index.php', '/user/admin'],
    'dbPdo' => [
        'password' => 'passwor to databse'
    ],
    'googleCaptha' => [
        'sitekey' => '...',
        'sicretkey' => '...',
    ]
];
new \xakki\phpwall\PhpWall($phpWallConf);
```