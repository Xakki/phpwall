# phpwall
Protect site from scanners on PHP


```php
$phpWallConf = [
    'currentHost' => 'example.com',
    'secretRequest' => 'change me',
    'bunTimeout' => 86400,
    'check_post' => false,
    'check_ua' => false,
    'check_url_keyword_exclude' => ['/admin/index.php', '/user/admin'],
    'dbPdo' => [
        'password' => 'passwor to databse'
    ],
    'googleCaptha' => [
        'domain' => 'example.com',
        'sitekey' => '...',
        'sicretkey' => '...',
    ]
];
new PhpWall($phpWallConf);
```