<?php

namespace EcAuthLogin2\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ファイル配置表 (filemap.php) の健全性を検証する。
 *
 * 配置表の誤りは「インストールは成功したのに実行時にクラス未発見 / 404」という
 * 最悪の形で表面化するため、静的に検出できるものはここで潰しておく。
 *
 * さらに、配置先を展開する expandDestSpec() は EcAuthLogin2（インストール用）と
 * plugin_update（アップデート用）に複製されている。アップデート経路では
 * SC_Helper_Plugin::load() が旧 EcAuthLogin2 を require_once 済みで新しい定義を
 * 読めないため、やむを得ず複製している。片方だけ直すと「インストールでは正しい
 * 位置に、アップデートでは別の位置に」置かれる事故になるので、両者が同じ結果を
 * 返すことをテストで縛る。
 */
class FileMapTest extends TestCase
{
    /** @var string */
    private static $pluginDir;

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = realpath(__DIR__.'/../../plugin/EcAuthLogin2').'/';

        // expandDestSpec() が参照する EC-CUBE 2 のコア定数。実値である必要はなく、
        // 2 つの実装が同じ入力から同じ出力を返すことが検証できればよい。
        // 値の取り違えに気付けるよう、プレースホルダごとに異なる文字列を割り当てる。
        defined('CLASS_REALDIR') || define('CLASS_REALDIR', '/dummy/data/class/');
        defined('HTML_REALDIR') || define('HTML_REALDIR', '/dummy/html/');
        defined('PLUGIN_HTML_REALDIR') || define('PLUGIN_HTML_REALDIR', '/dummy/html/plugin/');
        defined('ADMIN_DIR') || define('ADMIN_DIR', 'admin/');

        require_once self::$pluginDir.'EcAuthLogin2.php';
        require_once self::$pluginDir.'plugin_update.php';
    }

    /**
     * 配置表に書かれたソースがすべて実在すること。
     * 実在しないと install が RuntimeException で中断する。
     */
    public function testAllMappedSourceFilesExist()
    {
        $fileMap = require self::$pluginDir.'filemap.php';

        self::assertNotEmpty($fileMap, 'filemap.php が空です');

        foreach ($fileMap as $relativeSrc => $destSpec) {
            self::assertFileExists(
                self::$pluginDir.$relativeSrc,
                '配置表に載っているソースが存在しません: '.$relativeSrc
            );
        }
    }

    /**
     * EcAuthLogin2::getFileMap() が filemap.php と同一の内容を返すこと。
     */
    public function testGetFileMapReturnsTheSharedDefinition()
    {
        $fileMap = require self::$pluginDir.'filemap.php';

        self::assertSame($fileMap, \EcAuthLogin2::getFileMap());
    }

    /**
     * インストール用とアップデート用の配置先展開が完全に一致すること。
     */
    public function testExpandDestSpecIsConsistentBetweenInstallAndUpdate()
    {
        $fileMap = require self::$pluginDir.'filemap.php';

        $installMethod = new \ReflectionMethod('EcAuthLogin2', 'expandDestSpec');
        self::makeAccessible($installMethod);
        $installTarget = new \EcAuthLogin2(array());

        $updateMethod = new \ReflectionMethod('plugin_update', 'expandDestSpec');
        self::makeAccessible($updateMethod);

        foreach ($fileMap as $relativeSrc => $destSpec) {
            $installDest = $installMethod->invoke($installTarget, $destSpec);
            $updateDest = $updateMethod->invoke(null, $destSpec);

            self::assertSame(
                $installDest,
                $updateDest,
                'インストールとアップデートで配置先が食い違っています: '.$relativeSrc
            );
        }
    }

    /**
     * protected メソッドを invoke できる状態にする。
     *
     * PHP 8.1 以降は protected/private でも invoke でき、setAccessible() は
     * 不要になった。8.5 では deprecated 警告まで出て failOnRisky に引っかかる。
     * 一方 CI は 7.4 も対象で、7.4 では呼ばないと ReflectionException になる。
     *
     * @param \ReflectionMethod $method
     */
    private static function makeAccessible(\ReflectionMethod $method)
    {
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
    }

    /**
     * 配置先のプレースホルダが未知の値になっていないこと。
     *
     * expandDestSpec() は未知のプレースホルダを default 節でそのまま返すため、
     * 綴りを間違えると "CLASS_REALDER:helper/..." のような文字列が
     * そのままファイルパスとして使われ、copy が静かに失敗する。
     */
    public function testAllDestSpecsUseKnownPlaceholders()
    {
        $fileMap = require self::$pluginDir.'filemap.php';
        $known = array('CLASS_REALDIR', 'HTML_REALDIR', 'ADMIN_HTML_REALDIR', 'PLUGIN_HTML_REALDIR');

        foreach ($fileMap as $relativeSrc => $destSpec) {
            $placeholder = explode(':', $destSpec, 2)[0];
            self::assertContains(
                $placeholder,
                $known,
                '未知の配置先プレースホルダです: '.$destSpec.' ('.$relativeSrc.')'
            );
        }
    }
}
