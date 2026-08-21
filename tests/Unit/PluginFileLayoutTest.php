<?php

namespace EcAuthLogin2\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #30: クラスファイルを EC-CUBE 本体の data/class/ 配下へコピーする実装をやめ、
 * PLUGIN_UPLOAD_REALDIR に置いたまま直接 require_once するようにした。
 *
 * この構成は「配置表に載っているか」と「require 先のパスが実在するか」の
 * 2 つが噛み合って初めて成立し、どちらかがずれると実行時に fatal error になる。
 * 静的に検証できる範囲を押さえておく。
 */
class PluginFileLayoutTest extends TestCase
{
    /** @return string プラグインディレクトリ（末尾スラッシュ付き） */
    private static function pluginDir()
    {
        return dirname(dirname(__DIR__)).'/plugin/EcAuthLogin2/';
    }

    public function testFileMapDoesNotDeployClassFiles()
    {
        $fileMap = require self::pluginDir().'filemap.php';

        foreach ($fileMap as $src => $destSpec) {
            self::assertStringStartsNotWith(
                'CLASS_REALDIR:',
                $destSpec,
                $src.' が EC-CUBE 本体の data/class/ へ配置されようとしている'
            );
        }
    }

    public function testFileMapSourcesExist()
    {
        $fileMap = require self::pluginDir().'filemap.php';
        self::assertNotEmpty($fileMap);

        foreach ($fileMap as $src => $destSpec) {
            self::assertFileExists(self::pluginDir().$src, '配置表の src が存在しない');
        }
    }

    public function testLegacyFileMapListsOnlyClassFiles()
    {
        $legacy = require self::pluginDir().'filemap_legacy.php';

        // 1.0.4 以前が data/class/ 配下へ配置していた 10 件。
        // 減らすと残骸が消えなくなるため、件数ごと固定する。
        self::assertCount(10, $legacy);
        foreach ($legacy as $destSpec) {
            self::assertStringStartsWith('CLASS_REALDIR:', $destSpec);
        }
    }

    /**
     * エントリポイントは PLUGIN_UPLOAD_REALDIR 経由でプラグイン内のクラスを読む。
     * 実行時にしか解決されないパスなので、綴りが実在するかをここで確かめる。
     *
     * @dataProvider entryPointFiles
     *
     * @param string $relativePath
     */
    public function testEntryPointRequiresResolveToExistingFiles($relativePath)
    {
        $source = file_get_contents(self::pluginDir().$relativePath);
        self::assertNotFalse($source);

        $matched = preg_match_all(
            "/require_once PLUGIN_UPLOAD_REALDIR \. 'EcAuthLogin2\/([^']+)'/",
            $source,
            $matches
        );
        self::assertGreaterThan(0, $matched, $relativePath.' がプラグイン内クラスを読み込んでいない');

        foreach ($matches[1] as $target) {
            self::assertFileExists(self::pluginDir().$target, $relativePath.' の require 先が存在しない');
        }
    }

    /**
     * @return array
     */
    public function entryPointFiles()
    {
        return [
            ['html/ecauth/authorize.php'],
            ['html/ecauth/callback.php'],
            ['html/ecauth/passkey/authenticate-options.php'],
            ['html/ecauth/passkey/authenticate-verify.php'],
            ['html/admin/ecauth/passkey.php'],
            ['html/admin/ecauth/api/verify-password.php'],
            ['html/admin/ecauth/api/register-options.php'],
            ['html/admin/ecauth/api/register-verify.php'],
        ];
    }

    /**
     * data/class/ 配下とプラグインルートの config.php は __DIR__ 相対で読む。
     * 定数に依存しないぶん壊れにくいが、相対の段数を間違えやすいので確認する。
     */
    public function testDirRelativeRequiresResolveToExistingFiles()
    {
        $targets = array_merge(
            [self::pluginDir().'config.php'],
            self::globRecursive(self::pluginDir().'data/class', '*.php')
        );
        self::assertNotEmpty($targets);

        $checked = 0;
        foreach ($targets as $file) {
            $source = file_get_contents($file);
            self::assertNotFalse($source);

            if (!preg_match_all("/require_once __DIR__ \. '([^']+)'/", $source, $matches)) {
                continue;
            }
            foreach ($matches[1] as $relative) {
                $resolved = realpath(dirname($file).$relative);
                self::assertNotFalse(
                    $resolved,
                    $file.' の require 先が解決できない: '.$relative
                );
                ++$checked;
            }
        }

        // 相互参照が全部消えていたら検証になっていないので、件数も見る。
        self::assertGreaterThanOrEqual(10, $checked);
    }

    /**
     * @param string $dir
     * @param string $pattern
     *
     * @return array
     */
    private static function globRecursive($dir, $pattern)
    {
        $files = glob($dir.'/'.$pattern) ?: [];
        foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $subDir) {
            $files = array_merge($files, self::globRecursive($subDir, $pattern));
        }

        return $files;
    }
}
