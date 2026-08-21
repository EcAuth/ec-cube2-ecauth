<?php

namespace EcAuthLogin2\Tests\Unit;

use PHPUnit\Framework\TestCase;

// EcAuthLogin2 本体は composer の classmap（plugin/EcAuthLogin2/data/class）の
// 対象外なので明示的に読み込む。
require_once __DIR__.'/../../plugin/EcAuthLogin2/EcAuthLogin2.php';

// insertB2CLoginButton() が参照する定数のみスタブする。DB / セッションには
// 触れないため、phpunit.xml.dist の「純粋なユニットテスト」の方針は保たれる。
defined('HTTPS_URL') || define('HTTPS_URL', 'https://shop.example.jp/');

/**
 * #29: B2B パスキーだけを使う構成でも、フロントに未提供の B2C ログインボタンが
 * 表示されていた。表示条件が `client_id` の有無だけで、その client_id は B2B
 * パスキーにも必須（設定画面で EXIST_CHECK）だったため、事実上ほぼ常時表示だった。
 *
 * B2C を正式提供するまでは `enable_b2c_login` が明示的に true でない限り
 * 描画されないことを保証する。
 */
class B2CLoginButtonTest extends TestCase
{
    /** mypage/login.tpl の該当箇所を模した最小のテンプレートソース */
    const SOURCE = '<div class="btn_area"><ul><li><input type="image" alt="ログイン" src="login.jpg" /></li></ul></div>';

    public function testNotRenderedWhenFlagIsAbsent()
    {
        // 既存インストールではキー自体が存在しない。既定で無効であること。
        $source = self::SOURCE;
        $this->applyTo($source, ['client_id' => 'ec-example-0123456789abcdef']);

        self::assertSame(self::SOURCE, $source);
    }

    public function testNotRenderedWhenFlagIsFalse()
    {
        $source = self::SOURCE;
        $this->applyTo($source, [
            'client_id' => 'ec-example-0123456789abcdef',
            'enable_b2c_login' => false,
        ]);

        self::assertSame(self::SOURCE, $source);
    }

    public function testNotRenderedWhenClientIdIsEmpty()
    {
        $source = self::SOURCE;
        $this->applyTo($source, ['client_id' => '', 'enable_b2c_login' => true]);

        self::assertSame(self::SOURCE, $source);
    }

    public function testRenderedWhenExplicitlyEnabled()
    {
        $source = self::SOURCE;
        $this->applyTo($source, [
            'client_id' => 'ec-example-0123456789abcdef',
            'enable_b2c_login' => true,
        ]);

        self::assertStringContainsString('ecauth-login-button', $source);
        self::assertStringContainsString('https://shop.example.jp/ecauth/authorize.php', $source);
        // ログインボタンの直後に挿入される
        self::assertMatchesRegularExpression('/alt="ログイン"[^>]*\/>\s*<div class="ecauth-login-button"/u', $source);
    }

    public function testProviderNameIsEscaped()
    {
        $source = self::SOURCE;
        $this->applyTo($source, [
            'client_id' => 'ec-example-0123456789abcdef',
            'enable_b2c_login' => true,
            'provider_name' => '<script>alert(1)</script>',
        ]);

        self::assertStringNotContainsString('<script>', $source);
        self::assertStringContainsString('&lt;script&gt;', $source);
    }

    /**
     * @param string $source
     * @param array  $config
     */
    private function applyTo(&$source, array $config)
    {
        $plugin = new ConfigurableEcAuthLogin2($config);
        $plugin->applyB2CLoginButton($source, 'mypage/login.tpl');
    }
}

/**
 * loadConfig() は dtb_plugin を SELECT するため、テストでは差し替える。
 */
class ConfigurableEcAuthLogin2 extends \EcAuthLogin2
{
    /** @var array */
    private $stubConfig;

    public function __construct(array $config)
    {
        parent::__construct([]);
        $this->stubConfig = $config;
    }

    /**
     * @param string $source
     * @param string $filename
     */
    public function applyB2CLoginButton(&$source, $filename)
    {
        $this->insertB2CLoginButton($source, $filename);
    }

    protected function loadConfig()
    {
        return $this->stubConfig;
    }
}
