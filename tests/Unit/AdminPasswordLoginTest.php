<?php

namespace EcAuthLogin2\Tests\Unit;

use EcAuthLogin2;
use PHPUnit\Framework\TestCase;
use stdClass;

// EcAuthLogin2 本体は composer の classmap（plugin/EcAuthLogin2/data/class）の
// 対象外なので明示的に読み込む。DB / セッション / EC-CUBE の定数には触れない
// メソッドだけを対象にしており、phpunit.xml.dist の「純粋なユニットテスト」の
// 方針は保たれる。
require_once __DIR__.'/../../plugin/EcAuthLogin2/EcAuthLogin2.php';

/**
 * protected static なヘルパーを外から呼ぶためのプローブ。
 *
 * これらは実装の内部だが、判断の中身そのものなので固定しておきたい。
 * 公開 API を増やさずに検証するため、テスト側でだけ露出させる。
 */
class AdminPasswordLoginProbe extends EcAuthLogin2
{
    public static function probeIsPasswordLoginAttempt()
    {
        return self::isPasswordLoginAttempt();
    }

    public static function probeSanitizeForLog($value)
    {
        return self::sanitizeForLog($value);
    }

    public function markRejected()
    {
        $this->adminPasswordLoginRejected = true;
    }
}

/**
 * 管理画面のパスワード認証を無効化する機能の判定ロジック。
 *
 * 実際の拒否は LC_Page_Admin_Index_action_before フックで行うため、
 * 画面越しの挙動は tests/admin-password-disabled.spec.ts が担保する。
 * ここでは「どの値を無効化と見なすか」「どの POST をログイン試行と見なすか」
 * という、間違えても画面上は正常に見えてしまう部分を固定する。
 */
class AdminPasswordLoginTest extends TestCase
{
    /** @var array */
    private $savedRequest;
    /** @var array */
    private $savedPost;
    /** @var array */
    private $savedGet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedRequest = $_REQUEST;
        $this->savedPost = $_POST;
        $this->savedGet = $_GET;
        $_REQUEST = array();
        $_POST = array();
        $_GET = array();
    }

    protected function tearDown(): void
    {
        $_REQUEST = $this->savedRequest;
        $_POST = $this->savedPost;
        $_GET = $this->savedGet;
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 定数値の解釈
    // ------------------------------------------------------------------

    /**
     * @dataProvider disablingValues
     *
     * @param mixed $value
     */
    public function testValuesThatDisablePasswordLogin($value)
    {
        self::assertTrue(EcAuthLogin2::normalizeDisableFlag($value));
    }

    public function disablingValues()
    {
        // README / 設定画面が案内するのは define(..., true) だが、
        // 手で書く設定なので FILTER_VALIDATE_BOOLEAN が受け付ける表記は通す。
        return array(
            'boolean true' => array(true),
            'int 1' => array(1),
            'string 1' => array('1'),
            'string true' => array('true'),
            'string TRUE' => array('TRUE'),
            'string on' => array('on'),
            'string yes' => array('yes'),
        );
    }

    /**
     * @dataProvider nonDisablingValues
     *
     * @param mixed $value
     */
    public function testValuesThatKeepPasswordLoginEnabled($value)
    {
        self::assertFalse(EcAuthLogin2::normalizeDisableFlag($value));
    }

    public function nonDisablingValues()
    {
        return array(
            'boolean false' => array(false),
            'int 0' => array(0),
            'string 0' => array('0'),
            'string false' => array('false'),
            'string off' => array('off'),
            'string no' => array('no'),
            'empty string' => array(''),
            'null' => array(null),
            // 書き間違いは「無効化しない」に倒れる。気付けるように、
            // 設定画面へ解決後の状態を出している。
            'typo' => array('ture'),
            'arbitrary string' => array('disabled'),
            // filter_var に配列やオブジェクトを渡すと警告が出るため、
            // 手前で弾いていることを固定する（failOnWarning=true なので
            // 弾き漏れていればこのテストが落ちる）。
            'array' => array(array()),
            'object' => array(new stdClass()),
        );
    }

    // ------------------------------------------------------------------
    // 定数そのものの読み取り
    // ------------------------------------------------------------------

    public function testDisabledIsFalseWhenConstantIsNotDefined()
    {
        // 既定はパスワードログインが使える状態。プラグインを入れただけで
        // 締め出しが起きてはいけない。
        self::assertFalse(defined(EcAuthLogin2::DISABLE_ADMIN_PASSWORD_LOGIN));
        self::assertFalse(EcAuthLogin2::isAdminPasswordLoginDisabled());
    }

    /**
     * 定数名と読み取り経路が実際に繋がっていることの確認。
     * define() はプロセス全体に効いてしまうので別プロセスで実行する。
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testDisabledIsTrueWhenConstantIsDefined()
    {
        define('ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN', true);

        self::assertTrue(EcAuthLogin2::isAdminPasswordLoginDisabled());
    }

    public function testConstantNameMatchesTheOneDocumented()
    {
        // README / 設定画面 / docker-entrypoint.sh / 4 系プラグインの環境変数と
        // 同じ名前であること。ここがずれると、設定しても何も起きない。
        self::assertSame(
            'ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN',
            EcAuthLogin2::DISABLE_ADMIN_PASSWORD_LOGIN
        );
    }

    // ------------------------------------------------------------------
    // ログイン試行の判定
    // ------------------------------------------------------------------

    public function testDetectsLoginAttemptFromRequest()
    {
        // LC_Page::getMode() が見るのは $_REQUEST。
        $_REQUEST['mode'] = 'login';

        self::assertTrue(AdminPasswordLoginProbe::probeIsPasswordLoginAttempt());
    }

    public function testDetectsLoginAttemptFromPost()
    {
        // variables_order から R が外れている構成でも取りこぼさない。
        $_POST['mode'] = 'login';

        self::assertTrue(AdminPasswordLoginProbe::probeIsPasswordLoginAttempt());
    }

    public function testDetectsLoginAttemptFromGet()
    {
        $_GET['mode'] = 'login';

        self::assertTrue(AdminPasswordLoginProbe::probeIsPasswordLoginAttempt());
    }

    public function testNoModeIsNotALoginAttempt()
    {
        // ログイン画面の初回表示。ここで拒否扱いにすると案内が常時出てしまう。
        self::assertFalse(AdminPasswordLoginProbe::probeIsPasswordLoginAttempt());
    }

    public function testOtherModeIsNotALoginAttempt()
    {
        $_REQUEST['mode'] = 'logout';

        self::assertFalse(AdminPasswordLoginProbe::probeIsPasswordLoginAttempt());
    }

    public function testModeIsComparedCaseSensitively()
    {
        // 本体は switch ($this->getMode()) { case 'login': } で分岐するため、
        // 'Login' では認証処理に入らない。こちらだけ拒否すると、
        // 実際には何も起きない POST を拒否したことにしてログを汚す。
        $_REQUEST['mode'] = 'Login';

        self::assertFalse(AdminPasswordLoginProbe::probeIsPasswordLoginAttempt());
    }

    public function testArrayModeIsNotALoginAttempt()
    {
        // mode[]=login のような送り方。本体の getMode() も
        // preg_match が配列を受け取れないため login とは扱わない。
        $_POST['mode'] = array('login');

        self::assertFalse(AdminPasswordLoginProbe::probeIsPasswordLoginAttempt());
    }

    // ------------------------------------------------------------------
    // ログ出力前の正規化
    // ------------------------------------------------------------------

    public function testLogValueDropsControlCharacters()
    {
        // login_id は利用者入力。改行を通すとログの 1 行を偽装できる。
        self::assertSame(
            'adminrogue',
            AdminPasswordLoginProbe::probeSanitizeForLog("admin\r\nrogue")
        );
        self::assertSame(
            'admin',
            AdminPasswordLoginProbe::probeSanitizeForLog("ad\tmin")
        );
    }

    public function testLogValueIsTruncated()
    {
        $value = str_repeat('a', 200);

        self::assertSame(50, strlen(AdminPasswordLoginProbe::probeSanitizeForLog($value)));
    }

    public function testLogValueTruncationIsMultibyteSafe()
    {
        // mb_substr でないと途中で切れたバイト列がログに出る。
        $value = str_repeat('あ', 200);
        $sanitized = AdminPasswordLoginProbe::probeSanitizeForLog($value);

        self::assertSame(str_repeat('あ', 50), $sanitized);
    }

    public function testLogValueForNonString()
    {
        self::assertSame('', AdminPasswordLoginProbe::probeSanitizeForLog(null));
        self::assertSame('', AdminPasswordLoginProbe::probeSanitizeForLog(array('admin')));
    }

    // ------------------------------------------------------------------
    // 出力フィルタによるプレースホルダ置換
    // ------------------------------------------------------------------

    public function testOutputFilterLeavesUnrelatedPagesUntouched()
    {
        // 出力フィルタはサイト全体の全ページで走る。無関係な HTML を
        // 書き換えないこと（そして strpos 1 回で抜けること）。
        $plugin = new AdminPasswordLoginProbe(array());
        $source = '<html><body><p>商品一覧</p></body></html>';
        $original = $source;

        $plugin->outputfilterTransform($source, null, 'index.tpl');

        self::assertSame($original, $source);
    }

    public function testOutputFilterReplacesPlaceholdersWithDisabledState()
    {
        // 定数が未定義なので「無効化されていない」。
        $plugin = new AdminPasswordLoginProbe(array());
        $source = "var A = ('".EcAuthLogin2::PLACEHOLDER_PASSWORD_LOGIN_DISABLED."' === '1');"
            ."var B = ('".EcAuthLogin2::PLACEHOLDER_PASSWORD_LOGIN_REJECTED."' === '1');";

        $plugin->outputfilterTransform($source, null, 'login_frame.tpl');

        self::assertSame("var A = ('0' === '1');var B = ('0' === '1');", $source);
    }

    public function testOutputFilterReflectsRejectedFlag()
    {
        // onAdminLoginActionBefore() が拒否したリクエストでは、同じ
        // プラグインインスタンスの出力フィルタがそれを拾う。
        //
        // 2 つのプレースホルダは常にセットで差し込まれる。出力フィルタの
        // 早期 return は DISABLED 側の有無だけを見る（毎ページ走るので
        // strpos は 1 回に留める）ため、片方だけの HTML は対象にならない。
        $plugin = new AdminPasswordLoginProbe(array());
        $plugin->markRejected();
        $source = EcAuthLogin2::PLACEHOLDER_PASSWORD_LOGIN_DISABLED
            ."|('".EcAuthLogin2::PLACEHOLDER_PASSWORD_LOGIN_REJECTED."' === '1')";

        $plugin->outputfilterTransform($source, null, 'login_frame.tpl');

        self::assertSame("0|('1' === '1')", $source);
    }

    public function testOutputFilterLeavesNoPlaceholderBehind()
    {
        // 置換漏れがあると JS のリテラルに %%...%% が残る。
        // 見た目には気付きにくいので固定しておく。
        $plugin = new AdminPasswordLoginProbe(array());
        $source = EcAuthLogin2::PLACEHOLDER_PASSWORD_LOGIN_DISABLED
            .EcAuthLogin2::PLACEHOLDER_PASSWORD_LOGIN_REJECTED;

        $plugin->outputfilterTransform($source, null, 'login_frame.tpl');

        self::assertStringNotContainsString('%%', $source);
    }
}
