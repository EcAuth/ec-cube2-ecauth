<?php

namespace EcAuthLogin2\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../plugin/EcAuthLogin2/EcAuthLogin2.php';

// removeLegacyClassFiles() は filemap_legacy.php の "CLASS_REALDIR:..." を
// expandDestSpec() で展開して unlink する。本体の data/class/ を触らせないよう、
// 定数を一時ディレクトリへ向ける。
if (!defined('CLASS_REALDIR')) {
    define('CLASS_REALDIR', sys_get_temp_dir().'/ecauth-legacy-test-'.getmypid().'/');
}

/**
 * #30: 1.0.4 以前が data/class/ 配下へ配置したクラスファイルの削除。
 *
 * 消し漏れると本体のディレクトリツリーにプラグイン由来のファイルが残り、
 * 逆にパスの展開を間違えると無関係なファイルを消しかねない。
 */
class LegacyClassFileRemovalTest extends TestCase
{
    /** @var string|false */
    private $originalErrorLog;

    /** @var string */
    private $errorLogFile;

    protected function setUp(): void
    {
        // removeLegacyClassFiles() は削除のたびに error_log() を呼ぶ。既定では
        // stderr へ出て CI のログを汚すため、テスト中は一時ファイルへ逃がす。
        // ついでに、期待したログが出ているかの検証にも使う。
        $this->originalErrorLog = ini_get('error_log');
        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'ecauth-errorlog-');
        ini_set('error_log', $this->errorLogFile);

        $this->removeDirRecursive(CLASS_REALDIR);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog === false ? '' : $this->originalErrorLog);
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }

        $this->removeDirRecursive(CLASS_REALDIR);
    }

    public function testRemovesEveryLegacyFile()
    {
        $paths = $this->createLegacyFiles();
        self::assertNotEmpty($paths);

        $plugin = new LegacyRemovableEcAuthLogin2();
        $plugin->applyRemoveLegacyClassFiles();

        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testLogsEveryRemoval()
    {
        $paths = $this->createLegacyFiles();

        $plugin = new LegacyRemovableEcAuthLogin2();
        $plugin->applyRemoveLegacyClassFiles();

        $log = file_get_contents($this->errorLogFile);
        self::assertNotFalse($log);
        foreach ($paths as $path) {
            self::assertStringContainsString('Removed legacy file: '.$path, $log);
        }
    }

    public function testRemovesDirectoriesLeftEmpty()
    {
        $this->createLegacyFiles();

        $plugin = new LegacyRemovableEcAuthLogin2();
        $plugin->applyRemoveLegacyClassFiles();

        self::assertDirectoryDoesNotExist(CLASS_REALDIR.'pages/ecauth');
        self::assertDirectoryDoesNotExist(CLASS_REALDIR.'pages/admin/ecauth');
    }

    public function testKeepsUnrelatedFiles()
    {
        $this->createLegacyFiles();
        // 本体や他プラグインのファイルを巻き込まないこと
        $unrelated = CLASS_REALDIR.'helper/SC_Helper_Session.php';
        file_put_contents($unrelated, '<?php // core');
        $sibling = CLASS_REALDIR.'pages/ecauth/LC_Page_Other_Plugin.php';
        file_put_contents($sibling, '<?php // other plugin');

        $plugin = new LegacyRemovableEcAuthLogin2();
        $plugin->applyRemoveLegacyClassFiles();

        self::assertFileExists($unrelated);
        self::assertFileExists($sibling);
        // 中身が残っているディレクトリは消さない
        self::assertDirectoryExists(CLASS_REALDIR.'pages/ecauth');
    }

    public function testIsIdempotentWhenNothingToRemove()
    {
        $plugin = new LegacyRemovableEcAuthLogin2();
        $plugin->applyRemoveLegacyClassFiles();
        $plugin->applyRemoveLegacyClassFiles();

        // 例外も警告も出さずに完了すればよい
        self::assertDirectoryDoesNotExist(CLASS_REALDIR.'pages/ecauth');
    }

    /**
     * filemap_legacy.php の各エントリに対応するダミーファイルを作る。
     *
     * @return array<int,string> 作成した絶対パス
     */
    private function createLegacyFiles()
    {
        $paths = [];
        foreach (\EcAuthLogin2::getLegacyFileMap() as $destSpec) {
            list($placeholder, $relative) = explode(':', $destSpec, 2);
            self::assertSame('CLASS_REALDIR', $placeholder);

            $path = CLASS_REALDIR.$relative;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, '<?php // legacy');
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @param string $dir
     */
    private function removeDirRecursive($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

/**
 * removeLegacyClassFiles() は protected なので、テストから呼べるようにする。
 */
class LegacyRemovableEcAuthLogin2 extends \EcAuthLogin2
{
    public function __construct()
    {
        parent::__construct([]);
    }

    public function applyRemoveLegacyClassFiles()
    {
        $this->removeLegacyClassFiles();
    }
}
