<?php

/*
 * EcAuthLogin2 プラグインメインクラス
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * 設計メモ:
 * - SC_Plugin_Base を継承すると EC-CUBE 2.17.2+ で
 *   `Fatal error: Cannot make static method SC_Plugin_Base::install() non static`
 *   となるため継承しない。回避策としてマジックメソッド経由で
 *   install/uninstall/enable/disable を実装する
 *   (@see https://github.com/EC-CUBE/ec-cube2/issues/551)。
 * - 一方、Web インストール経路（LC_Page_Admin_OwnersStore::execPlugin）は
 *   `method_exists($class_name, $exec_func)` で install 等の存在を確認する。
 *   `method_exists()` は __call/__callStatic のマジックメソッドには
 *   反応しない（PHP 仕様）ため、マジックメソッドだけだと「install が
 *   見つかりません」エラーで Web インストールが失敗する。
 * - 上記を両立するため、**マジックメソッドと実メソッドを併設** する。
 *   実メソッドが優先的に呼ばれ、マジックメソッドは保険として残す。
 */
class EcAuthLogin2
{
    /**
     * 管理画面のパスワード認証を無効化する定数の名前。
     *
     * data/config/config.php で定義する。プラグイン設定 (dtb_plugin.free_field1)
     * ではなくファイル側に置くのは、管理画面を奪われた攻撃者に無効化を
     * 解除させないため。EC-CUBE 4 系プラグイン (EcAuthLogin43) の
     * 環境変数 ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN と同じ名前・同じ役割。
     *
     * @see isAdminPasswordLoginDisabled()
     */
    public const DISABLE_ADMIN_PASSWORD_LOGIN = 'ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN';

    /**
     * ログイン画面へ差し込むスクリプトに埋めるプレースホルダ。
     *
     * prefilterTransform はコンパイル時にしか走らないため、ここで状態を
     * 焼き込むと templates_c が残る限り古いままになる。プレースホルダのまま
     * コンパイルさせ、毎リクエスト走る outputfilterTransform で置換する。
     *
     * @see outputfilterTransform()
     */
    public const PLACEHOLDER_PASSWORD_LOGIN_DISABLED = '%%ECAUTH_PASSWORD_LOGIN_DISABLED%%';
    public const PLACEHOLDER_PASSWORD_LOGIN_REJECTED = '%%ECAUTH_PASSWORD_LOGIN_REJECTED%%';

    /** @var array プラグイン情報 */
    protected $arrSelfInfo;

    /**
     * このリクエストでパスワードログインを拒否したか。
     *
     * onAdminLoginActionBefore() が立て、outputfilterTransform() が読む。
     * どちらも SC_Helper_Plugin::load() が生成した同一インスタンスに
     * バインドされるため、インスタンスプロパティで受け渡せる。
     *
     * @var bool
     */
    protected $adminPasswordLoginRejected = false;

    /**
     * ファイル配置表を取得する。
     *
     * 定義の実体は filemap.php にある。static プロパティに直接持たせないのは、
     * アップデート経路（plugin_update.php）が「新バージョンの配置表」を
     * 読めるようにするため。有効なプラグインのクラスファイルは
     * SC_Helper_Plugin::load() がリクエスト冒頭で require_once 済みで、
     * アップデート中に新しい EcAuthLogin2.php を読み直すことはできない。
     *
     * @return array<string,string> [プラグイン内パス => コピー先指定]
     */
    public static function getFileMap()
    {
        return require __DIR__.'/filemap.php';
    }

    /**
     * PLUGIN_UPLOAD_REALDIR 配下に存在しなければならないファイルの一覧を取得する。
     *
     * @see getFileMap() 配列を返すファイルに分離している理由
     *
     * @return array<int,string> プラグインディレクトリからの相対パス
     */
    public static function getRequiredFiles()
    {
        return require __DIR__.'/required_files.php';
    }

    /**
     * 1.0.4 以前が data/class/ 配下へ配置していたクラスファイルの一覧を取得する。
     *
     * @see getFileMap() 配列を返すファイルに分離している理由
     *
     * @return array<int,string> コピー先指定の配列
     */
    public static function getLegacyFileMap()
    {
        return require __DIR__.'/filemap_legacy.php';
    }

    public function __construct(array $arrSelfInfo)
    {
        $this->arrSelfInfo = $arrSelfInfo;
    }

    /**
     * EC-CUBE 2 標準のプラグインライフサイクル（Web インストール経路で
     * `method_exists` 経由で検出される）の実メソッド定義。
     * 第二引数 $installer は SC_Plugin_Installer もしくは未指定（CLI 経由）。
     *
     * @param array $plugin dtb_plugin の行
     */
    public static function install($plugin, $installer = null)
    {
        $instance = new self($plugin);
        $instance->doInstall($plugin);
    }

    public static function uninstall($plugin, $installer = null)
    {
        $instance = new self($plugin);
        $instance->doUninstall($plugin);
    }

    public static function enable($plugin, $installer = null)
    {
        $instance = new self($plugin);
        $instance->doEnable($plugin);
    }

    public static function disable($plugin, $installer = null)
    {
        $instance = new self($plugin);
        $instance->doDisable($plugin);
    }

    /**
     * issue #551 回避用のマジックメソッド。
     * SC_Plugin_Base 継承を避けて install/uninstall/enable/disable を実装する
     * 公式回避パターン (@see https://gist.github.com/nanasess/6447bfd9e3cd26815c9ce7d0e8b1cb71)。
     *
     * 上の static 実メソッドが定義されている経路では実メソッドが優先されるため
     * このマジックメソッドは到達しないが、将来 EC-CUBE 本体側のチェック方式が
     * 変更された場合の保険として残す。
     */
    public static function __callStatic($name, $arguments)
    {
        switch ($name) {
            case 'install':
            case 'uninstall':
            case 'enable':
            case 'disable':
                $plugin = isset($arguments[0]) ? $arguments[0] : array();

                return self::$name($plugin, isset($arguments[1]) ? $arguments[1] : null);
        }

        return null;
    }

    public function __call($name, $arguments)
    {
        switch ($name) {
            case 'install':
            case 'uninstall':
            case 'enable':
            case 'disable':
                $plugin = isset($arguments[0]) ? $arguments[0] : $this->arrSelfInfo;

                return self::$name($plugin, isset($arguments[1]) ? $arguments[1] : null);
        }

        return null;
    }

    /**
     * インストール処理。冪等。
     * - dtb_customer / dtb_member に ecauth_subject カラムを追加
     * - dtb_plugin.free_field1 が空なら空 JSON を初期投入
     * - PLUGIN_UPLOAD_REALDIR/EcAuthLogin2/ 配下のファイルを EC-CUBE のディレクトリツリーへコピー
     */
    protected function doInstall($arrPlugin)
    {
        // 検証は DB スキーマ変更より前に済ませる。後段で失敗すると、追加済みの
        // カラムと初期設定だけが残った中途半端な状態になるため。
        $this->verifyRequiredFiles();

        $this->ensureEcAuthSubjectColumn('dtb_customer');
        $this->ensureEcAuthSubjectColumn('dtb_member', true);
        $this->initializeDefaultConfig();
        $this->copyPluginFiles();
        // 1.0.4 以前が入っていた環境に上書きインストールした場合の残骸を掃除する。
        $this->removeLegacyClassFiles();
    }

    /**
     * アンインストール処理。
     * - 配置したファイルを削除する
     * - DB のカラムは残す（データ保持のため）
     */
    protected function doUninstall($arrPlugin)
    {
        $this->removePluginFiles();
        $this->removeLegacyClassFiles();
    }

    protected function doEnable($arrPlugin)
    {
        // 必要時のみキャッシュクリア等
    }

    protected function doDisable($arrPlugin)
    {
        // 必要時のみキャッシュクリア等
    }

    /**
     * 処理の介入箇所とコールバック関数を設定
     *
     * @param int $priority
     */
    public function register(SC_Helper_Plugin $objHelperPlugin, $priority)
    {
        if (!isset($this->arrSelfInfo['plugin_hook_point'])) {
            return;
        }
        foreach ($this->arrSelfInfo['plugin_hook_point'] as $hookPoint) {
            if (!isset($hookPoint['callback'])) {
                continue;
            }
            $objHelperPlugin->addAction(
                $hookPoint['hook_point'],
                array($this, $hookPoint['callback']),
                $priority
            );
        }
    }

    public function getPluginInfo()
    {
        return $this->arrSelfInfo;
    }

    // ========================================================================
    // フックポイント
    // ========================================================================
    /**
     * 管理画面ログインのローカルフック (LC_Page_Admin_Index_action_before)。
     *
     * 無効化されている場合に、本体の認証処理へ入る前にログインを取り止める。
     *
     * ここを差し込み位置に選んだ理由:
     *  - LC_Page_Admin::init() は doValidToken(true) で CSRF を検証した**後**に
     *    このフックを発火し、その後 process() が action() を呼ぶ。つまり
     *    「CSRF 検証済み・パスワード照合前」に割り込める。
     *  - LC_Page_Admin_Index::lfIsLoginMember() より前で止まるため、
     *    dtb_member を引かない。拒否時の応答時間や挙動から
     *    「その login_id が存在するか」を推測されない。
     *  - 管理画面ログインのパスワード認証は本体では
     *    LC_Page_Admin_Index::action() の mode=login 経路の 1 箇所だけなので、
     *    ここを塞げば管理画面ログインは覆える。
     *
     * テンプレートを差し替えないのは、LC_Page_Admin_Index::init() が
     * parent::init()（このフックの発火元）の**後**に tpl_mainpage を
     * 'login.tpl' で上書きするため。フックから変更しても効かない。
     * そこで mode を落として action() のログイン処理へ入らせず、
     * ログイン画面をそのまま再描画させる（4 系が
     * CustomUserMessageAuthenticationException でログイン画面へ戻すのと同じ挙動）。
     *
     * 表示用の状態を $objPage に持たせない（＝ Smarty へ渡さない）のは、
     * 本体のページクラスが宣言していないプロパティを足すことになり、
     * PHP 8.2 以降で「Creation of dynamic property ... is deprecated」が出るため。
     * 本体は error_reporting(E_ALL & ~E_NOTICE & ~E_USER_NOTICE) で
     * E_DEPRECATED を拾って data/logs/error.log へ書くので、管理ログイン画面を
     * 開くたびにログが積まれることになる（#27 と同種）。
     * 表示制御は outputfilterTransform() のプレースホルダ置換で行う。
     *
     * @param LC_Page_Admin_Index $objPage
     *
     * @return void
     */
    public function onAdminLoginActionBefore($objPage)
    {
        if (!self::isAdminPasswordLoginDisabled() || !self::isPasswordLoginAttempt()) {
            return;
        }

        // 本体も lfSetIncorrectData() で失敗時の login_id を記録している
        // （'<login_id> password incorrect.'）ので、ここで残しても新たな
        // 情報露出にはならない。無効化後も試行が続いているかの判断に要る。
        GC_Utils_Ex::gfPrintLog(
            self::sanitizeForLog(isset($_POST['login_id']) ? $_POST['login_id'] : '')
            .' rejected: admin password login is disabled by '.self::DISABLE_ADMIN_PASSWORD_LOGIN
        );

        // LC_Page::getMode() は $_REQUEST['mode'] しか見ないが、
        // variables_order の設定に依らず確実に落とすため 3 つとも消す。
        unset($_REQUEST['mode'], $_POST['mode'], $_GET['mode']);

        $this->adminPasswordLoginRejected = true;
    }

    /**
     * Smarty テンプレートの出力フィルタ。
     *
     * ログイン画面へ差し込んだスクリプトのプレースホルダを、このリクエストの
     * 状態で置き換える。prefilterTransform と違い**毎リクエスト走る**ため、
     * templates_c にコンパイル結果が残っていても現在の設定が反映される。
     * 「config.php の定数を消してパスワードログインへ戻す」緊急復旧で
     * フォームが隠れたままにならないために、この性質が要る。
     *
     * $filename では判定しない。出力フィルタが受け取るのは描画された最終 HTML で、
     * ファイル名はフレーム側（login_frame.tpl）になる。フレーム名はエラー画面とも
     * 共通で、本体の LOGIN_FRAME 定数にも依存する。プレースホルダ自体の有無を見る方が
     * 対象を取り違えず、無関係なページでは strpos 1 回で抜けられる。
     *
     * 置換されなかった場合（このフックが未登録の古いインストール等）、
     * JS 側の比較は false になり「無効化されていない」と扱われる。UI は
     * フェイルオープンだが、拒否そのものは onAdminLoginActionBefore() が
     * 独立して行うので認証は緩まない。
     *
     * @param string $source 描画済みの HTML
     * @param LC_Page_Ex $objPage
     * @param string $filename
     *
     * @return void
     */
    public function outputfilterTransform(&$source, $objPage, $filename)
    {
        if (strpos($source, self::PLACEHOLDER_PASSWORD_LOGIN_DISABLED) === false) {
            return;
        }

        $source = str_replace(
            array(
                self::PLACEHOLDER_PASSWORD_LOGIN_DISABLED,
                self::PLACEHOLDER_PASSWORD_LOGIN_REJECTED,
            ),
            array(
                self::isAdminPasswordLoginDisabled() ? '1' : '0',
                $this->adminPasswordLoginRejected ? '1' : '0',
            ),
            $source
        );
    }

    /**
     * 管理画面のパスワード認証が無効化されているか。
     *
     * 未定義なら false（＝従来どおりパスワードでログインできる）。
     * 定数を書いていないだけの環境で管理者が締め出されてはいけないので、
     * 既定は「無効化しない」に倒す。無効化は明示的な意思表示に限る。
     *
     * @return bool
     */
    public static function isAdminPasswordLoginDisabled()
    {
        if (!defined(self::DISABLE_ADMIN_PASSWORD_LOGIN)) {
            return false;
        }

        return self::normalizeDisableFlag(constant(self::DISABLE_ADMIN_PASSWORD_LOGIN));
    }

    /**
     * 定数値を真偽値へ正規化する。
     *
     * 有効値は true / 1 / '1' / 'true' / 'on' / 'yes'（FILTER_VALIDATE_BOOLEAN の
     * 受け付ける表記）。それ以外はすべて「無効化しない」に倒す。
     *
     * 書き間違えると無効化したつもりで有効のまま、という失敗があり得るため、
     * 設定画面 (LC_Page_Admin_EcAuthLogin2_Config) に解決後の状態を表示して
     * 目視で確認できるようにしてある。
     *
     * @param mixed $value
     *
     * @return bool
     */
    public static function normalizeDisableFlag($value)
    {
        if (is_array($value) || is_object($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 現在のリクエストが管理画面ログインの送信か。
     *
     * @return bool
     */
    protected static function isPasswordLoginAttempt()
    {
        foreach (array($_REQUEST, $_POST, $_GET) as $params) {
            if (isset($params['mode']) && $params['mode'] === 'login') {
                return true;
            }
        }

        return false;
    }

    /**
     * ログへ書く前に制御文字を落として長さを切り詰める。
     *
     * login_id は利用者入力なので、改行を含んだ値をそのまま書くと
     * ログの 1 行を偽装できてしまう。
     *
     * @param mixed $value
     *
     * @return string
     */
    protected static function sanitizeForLog($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $sanitized = preg_replace('/[\x00-\x1f\x7f]/', '', $value);

        return mb_substr((string) $sanitized, 0, 50, 'UTF-8');
    }

    /**
     * Smarty テンプレートのプレフィルタ。
     * - フロントの mypage/login.tpl と shopping/index.tpl に B2C ログインボタンを差し込む
     *   （既定では enable_b2c_login が false のため差し込まれない）
     * - 管理画面の admin/login.tpl にパスキーログインスクリプトを差し込む（Phase B-3 で実装）
     * - 管理画面の basis/subnavi.tpl に「パスキー管理」メニューを差し込む
     *
     * @param string $source テンプレートソース
     * @param string $filename
     */
    public function prefilterTransform(&$source, LC_Page_Ex $objPage, $filename)
    {
        // B2C ログインボタンは設定値 enable_b2c_login（既定 false）で抑止される。
        // @see insertB2CLoginButton()
        if ($filename === 'mypage/login.tpl' || $filename === 'shopping/index.tpl') {
            $this->insertB2CLoginButton($source, $filename);

            return;
        }

        // EC-CUBE 2 の admin ログイン画面のテンプレートファイル名は "login.tpl"
        // (admin/ プレフィックスは付かない)。フロントの mypage/login.tpl とは
        // 上のブロックで分岐済みなので、ここに来た login.tpl は admin と扱う。
        if ($filename === 'login.tpl') {
            $this->insertAdminPasskeyScript($source);

            return;
        }

        if ($filename === 'basis/subnavi.tpl') {
            $this->insertBasisSubnaviMenu($source);

            return;
        }
    }

    /**
     * 「基本情報管理」のサブナビへ「パスキー管理」を追加する。
     *
     * パスキー管理画面（admin/ecauth/passkey.php）への導線が、これまでプラグイン設定画面の
     * ボタンしか無かった。設定画面は「オーナーズストア」配下にあり、本体の
     * html/user_data/packages/admin/css/admin.css が `.authority_1` で #navi-ownersstore ごと
     * 非表示にするため、店舗オーナー権限の管理者は自分のパスキーを登録できなかった。
     *
     * パスキーはログイン中の管理者ごとに登録するもの（verify-password → ensureB2BUser が
     * dtb_member 単位で subject を発行する）なので、全権限から見える基本情報管理に置く。
     * なお mtb_permission に /ecauth/passkey.php の登録は無く、SC_Session::IsSuccess() は
     * 未登録パスを素通しするため、アクセス制御上は元から店舗オーナーも利用できる。
     */
    protected function insertBasisSubnaviMenu(&$source)
    {
        $tplFile = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/templates/admin/plg_EcAuthLogin2_admin_basis_subnavi.tpl';
        if (!is_file($tplFile)) {
            return;
        }

        $snippet = file_get_contents($tplFile);
        if ($snippet === false || trim($snippet) === '') {
            return;
        }

        // 挿入は SC_Helper_Transform を使う（プラグイン開発マニュアル 3-11 / 4-1 の推奨手法）。
        // 文字列置換と違いセレクタ指定なので、本体の subnavi.tpl の項目が増減しても壊れない。
        // Transform は挿入する断片をプレースホルダに退避して getHTML() で復元するため、
        // 断片内の Smarty タグ（属性位置の <!--{if}--> を含む）はパースされず安全。
        //
        // select() の第 3 引数 $require に false を渡すのが重要。既定の true のままだと
        // セレクタ不一致が致命的エラーとして積まれ、getHTML() が SC_Utils_Ex::sfDispSiteError()
        // を呼ぶため、メニュー 1 項目のせいで基本情報管理の画面全体がエラー表示になる。
        // false ならセレクタが無いとき appendChild が何もせず、getHTML() は元ソースを返す。
        $objTransform = new SC_Helper_Transform($source);
        $objTransform->select('ul.level1', null, false)->appendChild($snippet);
        $transformed = $objTransform->getHTML();

        // 念のため。null が返るのはエラー経路のみだが、その場合も元ソースを維持する
        // （導線はプラグイン設定画面側に残っているので、メニューが出ないだけで済む）。
        if (!is_string($transformed) || $transformed === '') {
            return;
        }

        $source = $transformed;
    }

    /**
     * B2C ログインボタンを挿入する。
     *
     * 注意: B2C OIDC フェデレーションは後続リリースで正式提供予定であり、本機能は
     * 現段階では実運用での使用を想定していない。表示は設定値 `enable_b2c_login`
     * （既定 false）で抑止する。
     *
     * かつては「`client_id` を保存しない運用にすればボタンは出ない」という前提で
     * 条件が `client_id` の有無だけになっていたが、B2B パスキーが同じ `client_id` を
     * 使う（かつ設定画面で必須入力の）ため、この前提は成立しない。結果として B2B
     * だけを使う構成でもフロントに未提供機能への導線が露出していた (#29)。
     *
     * `enable_b2c_login` は設定画面に入力欄を設けていない。B2C は未提供であり、
     * 管理者に選択肢として見せる段階にないため。動作確認が必要な場合は
     * dtb_plugin.free_field1 の JSON を直接編集して有効化する。
     */
    protected function insertB2CLoginButton(&$source, $filename)
    {
        $config = $this->loadConfig();
        if (empty($config['client_id'])) {
            return;
        }

        // 既存インストールでは当該キー自体が存在しないため、未設定は無効として扱う
        // （設定のマイグレーションは不要）。
        //
        // 有効値は boolean の true のみに限定する。この設定は画面から入力せず
        // free_field1 の JSON を直接編集して与えるため、empty() 判定だと
        // {"enable_b2c_login":"false"} のような文字列を「有効」と解釈してしまう。
        // 未提供機能のゲートなので、曖昧な値はすべて無効側に倒す。
        if (!isset($config['enable_b2c_login']) || $config['enable_b2c_login'] !== true) {
            return;
        }

        $providerName = empty($config['provider_name']) ? 'EcAuth' : $config['provider_name'];
        $authorizeUrl = HTTPS_URL . 'ecauth/authorize.php';

        $providerNameHtml = htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8');
        $button = '<div class="ecauth-login-button" style="margin-top: 15px; text-align: center;">'
            . '<a href="' . htmlspecialchars($authorizeUrl, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="btn btn-primary" style="background-color: #4285f4; border-color: #4285f4; color: #fff; padding: 10px 20px; text-decoration: none; display: inline-block; border-radius: 4px;">'
            . $providerNameHtml . ' でログイン'
            . '</a></div>';

        $pattern = '/(<input[^>]*alt=["\']ログイン["\'][^>]*\/>)/iu';
        if (preg_match($pattern, $source)) {
            $source = preg_replace($pattern, '$1' . "\n" . $button, $source, 1);

            return;
        }

        // </ul> の直前に <li> を挿入する。$1（btn_area の中身）と $2（</ul>）を分離キャプチャして
        // <li> をリスト内側に配置することで、無効な HTML（<ul>...</ul><li>...</li>）になるのを防ぐ。
        // $button にメタ文字（$0 等）が含まれた場合の preg_replace 衝突も avoid するため callback 形式。
        $fallbackPattern = '/(<div class="btn_area">.*?)(<\/ul>)/is';
        if (preg_match($fallbackPattern, $source)) {
            $source = preg_replace_callback(
                $fallbackPattern,
                function ($matches) use ($button) {
                    return $matches[1] . "\n" . '<li>' . $button . '</li>' . $matches[2];
                },
                $source,
                1
            );
        }
    }

    /**
     * 管理画面ログイン画面にパスキーログインスクリプトを挿入する。
     * 実装は Phase B-3。ここではテンプレートが存在すれば読み込んで </body> 直前に挿入するだけ。
     */
    protected function insertAdminPasskeyScript(&$source)
    {
        $tplFile = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/templates/admin/plg_EcAuthLogin2_admin_login_passkey.tpl';
        if (!is_file($tplFile)) {
            return;
        }

        // client_id 未設定時もスクリプトは常に注入する。
        // Smarty は prefilterTransform をテンプレートコンパイル時にしか実行しないため、
        // 「設定保存前にコンパイル → 後から設定保存」の場合キャッシュが効いて
        // 再コンパイルされず、条件付き注入だと永続的にボタンが出なくなる。
        // 設定未保存時はクリック後の API fetch が失敗してアラート表示する仕掛けで握り潰す。
        $script = file_get_contents($tplFile);
        if ($script === false || $script === '') {
            return;
        }

        // テンプレートは file_get_contents で読み込まれるため Smarty 経路を通らない。
        // {$smarty.const.HTTPS_URL} の展開も効かないので、サブディレクトリインストール
        // (ROOT_URLPATH=/shop/ 等) でも正しく解決される絶対 URL をプレースホルダ置換で埋め込む。
        $httpsUrl = rtrim(HTTPS_URL, '/');
        $script = str_replace(
            array('%%ECAUTH_OPTIONS_URL%%', '%%ECAUTH_VERIFY_URL%%'),
            array(
                $httpsUrl . '/ecauth/passkey/authenticate-options.php',
                $httpsUrl . '/ecauth/passkey/authenticate-verify.php',
            ),
            $script
        );

        // </body> 直前に挿入（無い場合は末尾追加）
        // 置換は callback 形式にする。$script は JS なので `$0` `$1` のような
        // メタ文字を含みうるが、preg_replace の置換文字列ではそれが後方参照として
        // 展開され、スクリプトが静かに壊れる（`$0` がマッチした </body> に化ける）。
        // @see insertB2CLoginButton() の同じ対処
        if (stripos($source, '</body>') !== false) {
            $source = preg_replace_callback(
                '/<\/body>/i',
                function ($matches) use ($script) {
                    return $script . "\n" . $matches[0];
                },
                $source,
                1
            );

            return;
        }
        $source .= $script;
    }

    // ========================================================================
    // Internal: install ヘルパー
    // ========================================================================

    /**
     * 列とインデックスを冪等に確保する。
     * 列だけ存在しインデックスが欠損している環境（途中失敗・手動追加・
     * 旧バージョンからのアップグレード等）でもインデックスを補修する。
     *
     * @param string $table テーブル名
     * @param bool $unique UNIQUE 制約を付けるか（dtb_member は UNIQUE 必須）
     */
    protected function ensureEcAuthSubjectColumn($table, $unique = false)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();

        // (1) 列の確保
        $columns = $objQuery->listTableFields($table);
        if (!in_array('ecauth_subject', $columns)) {
            $objQuery->query('ALTER TABLE ' . $table . ' ADD ecauth_subject VARCHAR(255)');
            error_log('[EcAuthLogin2] Added ecauth_subject column to ' . $table);
        }

        // (2) インデックスの確保（列が既存でもインデックスだけ無い状態を補修）
        $indexName = 'idx_' . $table . '_ecauth_subject';
        if (!$this->indexExists($table, $indexName)) {
            if ($unique) {
                $objQuery->query('CREATE UNIQUE INDEX ' . $indexName . ' ON ' . $table . '(ecauth_subject)');
            } else {
                $objQuery->query('CREATE INDEX ' . $indexName . ' ON ' . $table . '(ecauth_subject)');
            }
            error_log('[EcAuthLogin2] Created index ' . $indexName . ' on ' . $table);
        }
    }

    /**
     * インデックス存在チェック（PostgreSQL / MySQL 両対応）。
     * 確認失敗時は false を返し、後続の CREATE INDEX で例外を再表面化させる。
     *
     * @param string $table
     * @param string $indexName
     * @return bool
     */
    protected function indexExists($table, $indexName)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        try {
            if (defined('DB_TYPE') && DB_TYPE === 'pgsql') {
                $row = $objQuery->getRow(
                    'indexname',
                    'pg_indexes',
                    'tablename = ? AND indexname = ?',
                    array($table, $indexName)
                );
            } else {
                $row = $objQuery->getRow(
                    'INDEX_NAME',
                    'INFORMATION_SCHEMA.STATISTICS',
                    'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    array($table, $indexName)
                );
            }
        } catch (Exception $e) {
            return false;
        }

        return !empty($row);
    }

    protected function initializeDefaultConfig()
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $row = $objQuery->getRow('free_field1', 'dtb_plugin', 'plugin_code = ?', array('EcAuthLogin2'));
        if (!empty($row['free_field1'])) {
            return;
        }
        $objQuery->update(
            'dtb_plugin',
            array(
                'free_field1' => json_encode(new stdClass(), JSON_UNESCAPED_UNICODE),
                'update_date' => 'CURRENT_TIMESTAMP',
            ),
            'plugin_code = ?',
            array('EcAuthLogin2')
        );
    }

    /**
     * 本体のディレクトリツリーへは配置しないが、PLUGIN_UPLOAD_REALDIR から直接
     * 読まれるファイルが揃っているか検証する。
     *
     * 配置表 (filemap.php) に載っているファイルは copyPluginFiles() が存在を
     * 確認するが、載っていないものは検証されない。1.0.5 でクラスファイルを配置表から
     * 外したため、不完全なアーカイブでも「インストール成功」と表示され、実行時に
     * require_once で fatal error になる穴が空いた。ここで塞ぐ (#30)。
     *
     * @throws RuntimeException
     */
    protected function verifyRequiredFiles()
    {
        // 一覧そのものが壊れていると検証が素通りする。空配列なら foreach が
        // 0 回で終わり、途中で切れたファイルは require が 1 を返して警告だけ出る。
        // どちらも「検証した」ことにはならないので、先に一覧の体裁を確かめる。
        // plugin_update::update() 側と同じ扱いに揃えている。
        $manifestPath = __DIR__ . '/required_files.php';
        if (!is_file($manifestPath)) {
            $message = '[EcAuthLogin2] Required file manifest missing: ' . $manifestPath;
            error_log($message);
            throw new RuntimeException($message);
        }

        $requiredFiles = static::getRequiredFiles();
        if (!is_array($requiredFiles) || $requiredFiles === array()) {
            $message = '[EcAuthLogin2] Required file manifest is invalid: ' . $manifestPath;
            error_log($message);
            throw new RuntimeException($message);
        }

        $base = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/';

        $missing = array();
        foreach ($requiredFiles as $relative) {
            if (!is_file($base . $relative)) {
                $missing[] = $relative;
            }
        }
        if ($missing !== array()) {
            $message = '[EcAuthLogin2] Required files missing: ' . implode(', ', $missing);
            error_log($message);
            throw new RuntimeException($message);
        }
    }

    /**
     * fileMap に従いファイルを配置する。
     *
     * 重要: ソース未検出・mkdir 失敗・copy 失敗はいずれも RuntimeException を
     * 送出し、install を失敗扱いにする。`error_log` だけで握りつぶすと
     * 「インストール成功表示だが実行時にクラス未発見/404」というワースト
     * ケースになるため、明示的に abort する。
     *
     * @throws RuntimeException
     */
    protected function copyPluginFiles()
    {
        $base = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/';

        foreach (self::getFileMap() as $relativeSrc => $destSpec) {
            $src = $base . $relativeSrc;
            $dest = $this->expandDestSpec($destSpec);
            if (!is_file($src)) {
                $message = '[EcAuthLogin2] Source file missing: ' . $src;
                error_log($message);
                throw new RuntimeException($message);
            }
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $message = '[EcAuthLogin2] mkdir failed: ' . $destDir;
                error_log($message);
                throw new RuntimeException($message);
            }
            if (!copy($src, $dest)) {
                $message = '[EcAuthLogin2] Copy failed: ' . $src . ' -> ' . $dest;
                error_log($message);
                throw new RuntimeException($message);
            }
        }
    }

    protected function removePluginFiles()
    {
        foreach (self::getFileMap() as $destSpec) {
            $dest = $this->expandDestSpec($destSpec);
            if (is_file($dest)) {
                @unlink($dest);
            }
        }
        $this->cleanupEmptyDir(HTML_REALDIR . 'ecauth/passkey');
        $this->cleanupEmptyDir(HTML_REALDIR . 'ecauth');
        if (defined('ADMIN_DIR')) {
            $this->cleanupEmptyDir(HTML_REALDIR . ADMIN_DIR . 'ecauth/api');
            $this->cleanupEmptyDir(HTML_REALDIR . ADMIN_DIR . 'ecauth');
        }
    }

    /**
     * 1.0.4 以前が data/class/ 配下へ配置したクラスファイルを削除する。
     *
     * 1.0.5 以降はクラスファイルをコピーせず PLUGIN_UPLOAD_REALDIR から直接
     * require_once するため、これらは読まれない残骸になる (#30)。実害は無いが、
     * コアのディレクトリツリーにプラグイン由来のファイルが混ざったままになるので
     * 掃除する。
     *
     * 削除に失敗しても処理は続行する。ここで中断すると、インストール／
     * アンインストール本体が残骸のせいで失敗することになり、本末転倒なため。
     */
    protected function removeLegacyClassFiles()
    {
        foreach (self::getLegacyFileMap() as $destSpec) {
            $dest = $this->expandDestSpec($destSpec);
            if (!is_file($dest)) {
                continue;
            }
            if (@unlink($dest)) {
                error_log('[EcAuthLogin2] Removed legacy file: ' . $dest);
            } else {
                error_log('[EcAuthLogin2] Failed to remove legacy file: ' . $dest);
            }
        }
        $this->cleanupEmptyDir(CLASS_REALDIR . 'pages/ecauth');
        $this->cleanupEmptyDir(CLASS_REALDIR . 'pages/admin/ecauth');
    }

    protected function cleanupEmptyDir($dir)
    {
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            @rmdir($dir);
        }
    }

    /**
     * "PLACEHOLDER:relative/path" 形式を絶対パスに展開する。
     */
    protected function expandDestSpec($destSpec)
    {
        list($placeholder, $relative) = explode(':', $destSpec, 2);
        switch ($placeholder) {
            case 'CLASS_REALDIR':
                return CLASS_REALDIR . $relative;
            case 'HTML_REALDIR':
                return HTML_REALDIR . $relative;
            case 'ADMIN_HTML_REALDIR':
                return HTML_REALDIR . ADMIN_DIR . $relative;
            case 'PLUGIN_HTML_REALDIR':
                return PLUGIN_HTML_REALDIR . $relative;
            default:
                return $destSpec;
        }
    }

    protected function loadConfig()
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $row = $objQuery->getRow('free_field1', 'dtb_plugin', 'plugin_code = ?', array('EcAuthLogin2'));
        if (empty($row['free_field1'])) {
            return array();
        }
        $config = json_decode($row['free_field1'], true);

        return is_array($config) ? $config : array();
    }
}
