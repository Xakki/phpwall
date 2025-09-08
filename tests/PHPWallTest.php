<?php

namespace Xakki\PHPWall\Tests;

use PHPUnit\Framework\TestCase;
use Xakki\PHPWall\PHPWall;

final class PHPWallTest extends TestCase
{
    const URL_KEY1 = 'someblockurl';
    const URL_KEY_EXCLUDE = 'myCustomUrl';
    const URL_KEY_EXCLUDE2 = 'excludeUrl2';

    public function testTrustIp()
    {
        $mock = $this->getMockedPhpWall();
        $this->assertEquals(true, $mock->isTrustIp('126.0.0.1'), 'Failed trust ip');
        $this->assertEquals(true, $mock->isTrustIp('125.0.127.1'), 'Failed trust ip');
        $this->assertEquals(false, $mock->isTrustIp('127.0.0.1'), 'Failed trust ip');
    }

    /**
     * @dataProvider dataProviderUrls
     */
    public function testCheckUrl($url, $expected)
    {
        $mock = $this->getMockedPhpWall();
        $this->assertEquals($expected, $mock->checkUrl($url), 'Failed url: ' . $url);
    }

    public static function dataProviderUrls()
    {
        return [
            ['/landing', true],
            ['/page?eq=1', true],
//            ['/qwe/admin', false],
//            ['/wp-admin/', false],
//            ['/page?q=eval(phpinfo())', false],
            ['/admin/' . self::URL_KEY_EXCLUDE2 . '/test', true],
            ['/admin/' . self::URL_KEY_EXCLUDE . '?q=eval(', true],
            ['/' . self::URL_KEY1, false],
        ];
    }

    /**
     * @dataProvider dataProviderUa
     */
    public function testCheckUa($ua, $expected)
    {
        $mock = $this->getMockedPhpWall();

        $this->assertEquals($expected, $mock->checkUa($ua), 'Failed UA: ' . $ua);
    }

    public static function dataProviderUa()
    {
        return [
            ['CHROME', true],
            ['GuzzleHttp', false],
            ['<script>alert(1)</script>', false],
            ['GuzzleHttp ' . self::URL_KEY_EXCLUDE2, true],
            [self::URL_KEY1 . ' ' . self::URL_KEY_EXCLUDE, true],
            [self::URL_KEY1, false],
            [str_repeat('qazwsxedcrfv', 100), false],
        ];
    }

    /**
     * @dataProvider dataProviderPost
     */
    public function testCheckPost($post, $expected)
    {
        $mock = $this->getMockedPhpWall();

        $this->assertEquals($expected, $mock->checkPost($post), 'Failed UA: ' . json_encode($post));
    }

    public static function dataProviderPost()
    {
        return [
            [['a' => 'OK', 'b' => 123], true],
            [['a' => 'OK' . self::URL_KEY_EXCLUDE2, 'b' => 111], true],
            [['a' => [self::URL_KEY1, 'OK'], 'b' => self::URL_KEY1], false],
        ];
    }

    protected function getMockedPhpWall()
    {
        static $mock;
        if ($mock) {
            return $mock;
        }
        $config = [
            //'debug' => 2,
            'secretRequest' => 'fh6ktjf',
            'googleCaptchaSiteKey' => 'hfrhfhfgh',
            'googleCaptchaSecretKey' => 'fdgdfghfdgdfg',
            'dbPdo' => [
                'password' => 'sddsfdf',
            ],
            'checkUrlKeyword' => [
                "/" . self::URL_KEY1 . "/ui",
            ],
            'checkUrlKeywordExclude' => [
                "/" . self::URL_KEY_EXCLUDE2 . "/ui",
                static function ($str) {
                    return strpos($str, self::URL_KEY_EXCLUDE) !== false;
                },
            ],
            'checkUaKeyword' => [
                "/" . self::URL_KEY1 . "/ui",
            ],
            'checkUaKeywordExclude' => [
                "/" . self::URL_KEY_EXCLUDE2 . "/ui",
                static function ($str) {
                    return strpos($str, self::URL_KEY_EXCLUDE) !== false;
                },
            ],
            'checkPostKeyword' => [
                "/" . self::URL_KEY1 . "/ui",
            ],
            'checkPostKeywordExclude' => [
                "/" . self::URL_KEY_EXCLUDE2 . "/ui",
                static  function ($str) {
                    return strpos($str, self::URL_KEY_EXCLUDE) !== false;
                },
            ],
            'trustHosts' => function() {
                return [
                    '126.0.0.1',
                    '125.0.0.1/8',
                ];
            }
        ];
        $_SERVER['HTTP_X_REAL_IP'] = '127.0.0.1';
        $mock = $this->getMockBuilder(PHPWall::class)
            ->enableOriginalConstructor()
            ->setConstructorArgs([$config])
            ->setMethods(['ruleApply', 'getCache', 'getDb', 'initView', 'init', 'getHostnameByIp'])
            ->getMock();

        $mock->method('ruleApply')
            ->willReturn(false);
        $mock->method('getHostnameByIp')
            ->willReturn('localhost');
        $mock->method('init')
            ->willReturn(false);
        return $mock;
    }
}
