<?php

namespace EcAuthLogin2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SC_Helper_EcAuthLogin2_Jwks;

/**
 * JWKS の取得とファイルキャッシュの挙動を検証する。
 *
 * 4 系プラグインの Tests/Unit/CachedJwksProviderTest.php に対応する。
 */
class JwksTest extends TestCase
{
    const BASE_URL = 'https://tenant.ec-auth.io';

    /** @var string */
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ecauth-jwks-test-' . bin2hex(random_bytes(8));
        mkdir($this->cacheDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    public function testFetchesAndCachesKeys()
    {
        $urls = array();
        $helper = $this->createHelper($urls, array(
            array('status' => 200, 'body' => $this->jwksBody('kid-1')),
        ));

        $first = $helper->getJwks(self::BASE_URL);
        $second = $helper->getJwks(self::BASE_URL);

        self::assertIsArray($first);
        self::assertSame('kid-1', $first[0]['kid']);
        // 2 回目はキャッシュから返るため HTTP リクエストは 1 回だけ
        self::assertCount(1, $urls);
        self::assertSame($first, $second);
        self::assertCount(1, glob($this->cacheDir . '/*'));
    }

    public function testRequestsTheJwksEndpointOfTheGivenBaseUrl()
    {
        $urls = array();
        $helper = $this->createHelper($urls, array(
            array('status' => 200, 'body' => $this->jwksBody('kid-1')),
        ));

        $helper->getJwks(self::BASE_URL . '/');

        self::assertSame(self::BASE_URL . '/.well-known/jwks.json', $urls[0]);
    }

    public function testForceRefreshBypassesCache()
    {
        $urls = array();
        $helper = $this->createHelper($urls, array(
            array('status' => 200, 'body' => $this->jwksBody('kid-1')),
            array('status' => 200, 'body' => $this->jwksBody('kid-2')),
        ));

        $helper->getJwks(self::BASE_URL);
        $refreshed = $helper->getJwks(self::BASE_URL, true);

        self::assertIsArray($refreshed);
        self::assertSame('kid-2', $refreshed[0]['kid']);
        self::assertCount(2, $urls);
    }

    public function testCacheIsSeparatedPerBaseUrl()
    {
        $urls = array();
        $helper = $this->createHelper($urls, array(
            array('status' => 200, 'body' => $this->jwksBody('kid-a')),
            array('status' => 200, 'body' => $this->jwksBody('kid-b')),
        ));

        $a = $helper->getJwks('https://tenant-a.ec-auth.io');
        $b = $helper->getJwks('https://tenant-b.ec-auth.io');

        // テナントごとに鍵が異なるため、キャッシュが混ざってはいけない
        self::assertSame('kid-a', $a[0]['kid']);
        self::assertSame('kid-b', $b[0]['kid']);
        self::assertCount(2, glob($this->cacheDir . '/*'));
    }

    public function testExpiredCacheIsRefetched()
    {
        $urls = array();
        $helper = $this->createHelper($urls, array(
            array('status' => 200, 'body' => $this->jwksBody('kid-1')),
            array('status' => 200, 'body' => $this->jwksBody('kid-2')),
        ));

        $helper->getJwks(self::BASE_URL);
        $helper->advanceClock(SC_Helper_EcAuthLogin2_Jwks::CACHE_TTL + 1);
        $refreshed = $helper->getJwks(self::BASE_URL);

        self::assertSame('kid-2', $refreshed[0]['kid']);
        self::assertCount(2, $urls);
    }

    public function testHttpErrorReturnsNull()
    {
        $urls = array();
        $helper = $this->createHelper($urls, array(array('status' => 500, 'body' => '')));

        self::assertNull($helper->getJwks(self::BASE_URL));
        self::assertCount(0, glob($this->cacheDir . '/*'));
    }

    public function testTransportErrorReturnsNull()
    {
        $urls = array();
        // httpRequest は失敗時に body = false を返す
        $helper = $this->createHelper($urls, array(array('status' => 0, 'body' => false)));

        self::assertNull($helper->getJwks(self::BASE_URL));
    }

    public function testMalformedResponseReturnsNull()
    {
        foreach (array('not json', '{"keys":[]}', '{"foo":1}') as $body) {
            $urls = array();
            $helper = $this->createHelper($urls, array(array('status' => 200, 'body' => $body)));

            self::assertNull($helper->getJwks(self::BASE_URL), 'body: ' . $body);
        }

        // 取得失敗をキャッシュしてはいけない
        self::assertCount(0, glob($this->cacheDir . '/*'));
    }

    public function testEmptyBaseUrlReturnsNullWithoutRequest()
    {
        $urls = array();
        $helper = $this->createHelper($urls, array());

        self::assertNull($helper->getJwks(''));
        self::assertCount(0, $urls);
    }

    /**
     * @param array $urls out 送信された URL の記録
     * @param array $queue 返す応答のキュー
     * @return TestableJwks
     */
    private function createHelper(array &$urls, array $queue)
    {
        $fetcher = function ($url) use (&$urls, &$queue) {
            $urls[] = $url;
            $next = array_shift($queue);
            if ($next === null) {
                throw new \RuntimeException('Unexpected JWKS request: ' . $url);
            }

            return $next;
        };

        return new TestableJwks($fetcher, $this->cacheDir);
    }

    /**
     * @param string $kid
     * @return string
     */
    private function jwksBody($kid)
    {
        return json_encode(array(
            'keys' => array(
                array(
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => $kid,
                    'n' => 'dGVzdC1tb2R1bHVz',
                    'e' => 'AQAB',
                ),
            ),
        ));
    }
}

/**
 * 時刻を進められるようにしたサブクラス。TTL 切れの挙動を検証するために使う。
 */
class TestableJwks extends SC_Helper_EcAuthLogin2_Jwks
{
    /** @var int */
    private $offset = 0;

    /**
     * @param int $seconds
     * @return void
     */
    public function advanceClock($seconds)
    {
        $this->offset += $seconds;
    }

    protected function now()
    {
        return 1700000000 + $this->offset;
    }
}
