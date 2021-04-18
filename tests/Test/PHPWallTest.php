<?php

declare(strict_types=1);

namespace Xakki\PHPWallTest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xakki\PHPWall\PHPWall;

final class PHPWallTest extends TestCase
{
    public const URL_KEY1 = 'someblockurl';
    public const URL_KEY_EXCLUDE = 'myCustomUrl';
    public const URL_KEY_EXCLUDE2 = 'excludeUrl2';

    #[DataProvider('dataProviderUrls')]
    public function testCheckUrl(string $url, bool $expected): void
    {
        $mock = $this->getMockedPhpWall();
        $this->assertEquals($expected, $mock->checkUrl($url), 'Failed url: ' . $url);
    }

    public static function dataProviderUrls(): array
    {
        return [
            ['/landing', true],
            ['/page?eq=1', true],
            ['/qwe/admin', false],
            ['/wp-admin/', false],
            ['/page?q=eval(phpinfo())', false],
            ['/admin/' . self::URL_KEY_EXCLUDE2 . '/test', true],
            ['/admin/' . self::URL_KEY_EXCLUDE . '?q=eval(', true],
            ['/' . self::URL_KEY1, false],
        ];
    }

    #[DataProvider('dataProviderUa')]
    public function testCheckUa(string $ua, bool $expected): void
    {
        $mock = $this->getMockedPhpWall();

        $this->assertEquals($expected, $mock->checkUa($ua), 'Failed UA: ' . $ua);
    }

    public static function dataProviderUa(): array
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

    #[DataProvider('dataProviderPost')]
    public function testCheckPost(array $post, bool $expected): void
    {
        $mock = $this->getMockedPhpWall();

        $this->assertEquals($expected, $mock->checkPost($post), 'Failed UA: ' . json_encode($post));
    }

    public static function dataProviderPost(): array
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
        $mock = $this->getMockBuilder(PHPWall::class)
            ->setConstructorArgs([
                'debug' => true,
                'secretRequest' => 'REQUEST_CHANGE_ME',
                'googleCaptchaSiteKey' => 'CHANGE_ME',
                'googleCaptchaSecretKey' => 'CHANGE_ME',
                'checkUrlKeyword' => [
                    "/" . self::URL_KEY1 . "/ui",
                ],
                'checkUrlKeywordExclude' => [
                    "/" . self::URL_KEY_EXCLUDE2 . "/ui",
                    function (mixed $str) {
                        return str_contains($str, self::URL_KEY_EXCLUDE);
                    },
                ],
                'checkUaKeyword' => [
                    "/" . self::URL_KEY1 . "/ui",
                ],
                'checkUaKeywordExclude' => [
                    "/" . self::URL_KEY_EXCLUDE2 . "/ui",
                    function (mixed $str) {
                        return str_contains($str, self::URL_KEY_EXCLUDE);
                    },
                ],
                'checkPostKeyword' => [
                    "/" . self::URL_KEY1 . "/ui",
                ],
                'checkPostKeywordExclude' => [
                    "/" . self::URL_KEY_EXCLUDE2 . "/ui",
                    function (mixed $str) {
                        return str_contains($str, self::URL_KEY_EXCLUDE);
                    },
                ],
            ])
            ->enableOriginalConstructor()
            ->onlyMethods(['ruleApply', 'getCache', 'getDb', 'initView'])
            ->getMock();

        $mock->method('ruleApply')
            ->willReturn(false);
        return $mock;
    }
//    protected function callProtectedMethod(string $method, array $args = []): mixed
//    {
//        $mock= $this->getMockBuilder(PHPWall::class)
//            ->disableOriginalConstructor()    // you may need the constructor on integration tests only
//            ->getMock();
//        $reflection = new \ReflectionClass($mock);
//        $method = $reflection->getMethod($method);
//        $method->setAccessible(true);
//
//        return $method->invokeArgs($mock, $args);
//    }
//
//    protected function getProtectedProperty(string $prop): mixed
//    {
//        $mock= $this->getMockBuilder(PHPWall::class)
//            ->disableOriginalConstructor()    // you may need the constructor on integration tests only
//            ->getMock();
//        $reflection = new \ReflectionClass($mock);
//        $method = $reflection->getProperty($prop);
//        $method->setAccessible(true);
//
//        return $method->getValue($mock);
//    }
}
