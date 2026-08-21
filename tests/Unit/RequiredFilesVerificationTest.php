<?php

namespace EcAuthLogin2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../plugin/EcAuthLogin2/EcAuthLogin2.php';

// verifyRequiredFiles() は PLUGIN_UPLOAD_REALDIR/EcAuthLogin2/ 配下を探す。
// 実環境を見に行かせないよう一時ディレクトリへ向ける。
if (!defined('PLUGIN_UPLOAD_REALDIR')) {
    define('PLUGIN_UPLOAD_REALDIR', sys_get_temp_dir().'/ecauth-required-test-'.getmypid().'/');
}

/**
 * #30: 配置表に載らないファイルの存在検証。
 *
 * ここが素通りすると「インストール成功」と表示されたまま、実行時に
 * require_once で fatal error になる。検証が実際に効いていることを確かめる。
 */
class RequiredFilesVerificationTest extends TestCase
{
    /** @var string */
    private $baseDir;

    /** @var string|false */
    private $originalErrorLog;

    /** @var string */
    private $errorLogFile;

    protected function setUp(): void
    {
        $this->baseDir = PLUGIN_UPLOAD_REALDIR.'EcAuthLogin2/';

        $this->originalErrorLog = ini_get('error_log');
        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'ecauth-errorlog-');
        ini_set('error_log', $this->errorLogFile);

        $this->removeDirRecursive(PLUGIN_UPLOAD_REALDIR);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog === false ? '' : $this->originalErrorLog);
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }

        $this->removeDirRecursive(PLUGIN_UPLOAD_REALDIR);
    }

    public function testPassesWhenEveryRequiredFileIsPresent()
    {
        $this->createAllRequiredFiles();

        $plugin = new VerifiableEcAuthLogin2();
        $plugin->applyVerifyRequiredFiles();

        // 例外が出なければ成功
        self::assertDirectoryExists($this->baseDir);
    }

    public function testThrowsWhenAFileIsMissing()
    {
        $this->createAllRequiredFiles();
        unlink($this->baseDir.'EcAuthLogin2.php');

        $plugin = new VerifiableEcAuthLogin2();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Required files missing.*EcAuthLogin2\.php/');
        $plugin->applyVerifyRequiredFiles();
    }

    public function testReportsEveryMissingFile()
    {
        $this->createAllRequiredFiles();
        unlink($this->baseDir.'config.php');
        unlink($this->baseDir.'data/class/helper/SC_Helper_EcAuthLogin2.php');

        $plugin = new VerifiableEcAuthLogin2();

        try {
            $plugin->applyVerifyRequiredFiles();
            self::fail('RuntimeException が送出されなかった');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('config.php', $e->getMessage());
            self::assertStringContainsString('SC_Helper_EcAuthLogin2.php', $e->getMessage());
        }
    }

    /**
     * 一覧そのものが壊れていると検証が素通りする。空配列は foreach が 0 回で終わり、
     * 途中で切れたファイルは require が 1 を返して警告だけで済んでしまう。
     *
     * @dataProvider invalidManifests
     *
     * @param mixed $manifest
     */
    public function testThrowsWhenManifestIsInvalid($manifest)
    {
        $this->createAllRequiredFiles();

        $plugin = new VerifiableEcAuthLogin2();
        $plugin->useStub = true;
        $plugin->stubManifest = $manifest;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/manifest is invalid/');
        $plugin->applyVerifyRequiredFiles();
    }

    /**
     * @return array
     */
    public function invalidManifests()
    {
        return [
            'empty array' => [[]],
            // return 文まで届かず切れたファイルは require が int(1) を返す
            'truncated file returns 1' => [1],
            'null' => [null],
            'string' => ['data/class/helper/SC_Helper_EcAuthLogin2.php'],
        ];
    }

    private function createAllRequiredFiles()
    {
        foreach (\EcAuthLogin2::getRequiredFiles() as $relative) {
            $path = $this->baseDir.$relative;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, '<?php // stub');
        }
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
 * verifyRequiredFiles() は protected なので、テストから呼べるようにする。
 * 一覧は static::getRequiredFiles() 経由で読まれるため、ここで差し替えられる。
 */
class VerifiableEcAuthLogin2 extends \EcAuthLogin2
{
    /** @var bool 一覧を差し替えるか。null や [] も検証対象なのでフラグで制御する */
    public $useStub = false;

    /** @var mixed 差し替える一覧 */
    public $stubManifest;

    /** @var bool getRequiredFiles() は static なので、呼び出し中だけ静的に橋渡しする */
    private static $stubActive = false;

    /** @var mixed */
    private static $currentStub;

    public function __construct()
    {
        parent::__construct([]);
    }

    public function applyVerifyRequiredFiles()
    {
        self::$stubActive = $this->useStub;
        self::$currentStub = $this->stubManifest;
        try {
            $this->verifyRequiredFiles();
        } finally {
            self::$stubActive = false;
            self::$currentStub = null;
        }
    }

    public static function getRequiredFiles()
    {
        if (self::$stubActive) {
            return self::$currentStub;
        }

        return parent::getRequiredFiles();
    }
}
