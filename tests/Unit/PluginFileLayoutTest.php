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

    public function testRequiredFilesExist()
    {
        $required = require self::pluginDir().'required_files.php';
        self::assertNotEmpty($required);

        foreach ($required as $relative) {
            self::assertFileExists(self::pluginDir().$relative, '必須ファイルが存在しない');
        }
    }

    /**
     * 配置表から外したクラスファイルは、必須ファイル一覧で検証される。
     * 片方から漏れると「不完全なアーカイブでもインストール成功」に戻ってしまう。
     */
    public function testEveryPluginLocalClassIsListedAsRequired()
    {
        $required = require self::pluginDir().'required_files.php';
        $classFiles = self::globRecursive(self::pluginDir().'data/class', '*.php');
        self::assertNotEmpty($classFiles);

        $prefixLength = strlen(self::pluginDir());
        foreach ($classFiles as $file) {
            $relative = substr($file, $prefixLength);
            self::assertContains(
                $relative,
                $required,
                $relative.' が required_files.php に載っていない'
            );
        }
    }

    /**
     * プラグインルート直下のファイルも PLUGIN_UPLOAD_REALDIR から直接読まれる。
     * とくに EcAuthLogin2.php は SC_Helper_Plugin::load() が読むプラグイン本体で、
     * アップデート時に欠けると旧クラスが残ったまま新旧混在になる。
     *
     * 除外しているものは、いずれも別経路で存在が保証される。
     */
    public function testEveryRootLevelFileIsListedAsRequired()
    {
        $required = require self::pluginDir().'required_files.php';

        $verifiedElsewhere = [
            // 本体の updatePlugin() が requirePluginFile() で読む
            'plugin_info.php',
            'plugin_update.php',
            // plugin_update::update() が検証フェーズで明示的に is_file() する
            'filemap.php',
            'required_files.php',
        ];

        $rootFiles = glob(self::pluginDir().'*.php') ?: [];
        self::assertNotEmpty($rootFiles);

        $prefixLength = strlen(self::pluginDir());
        foreach ($rootFiles as $file) {
            $relative = substr($file, $prefixLength);
            if (in_array($relative, $verifiedElsewhere, true)) {
                continue;
            }
            self::assertContains(
                $relative,
                $required,
                $relative.' が required_files.php に載っていない'
            );
        }
    }

    /**
     * 管理画面テンプレートも PLUGIN_UPLOAD_REALDIR から直接読まれる。
     * 欠けると is_file() で握り潰され、無言で機能が落ちる。
     */
    public function testEveryAdminTemplateIsListedAsRequired()
    {
        $required = require self::pluginDir().'required_files.php';
        $templates = self::globRecursive(self::pluginDir().'templates', '*.tpl');
        self::assertNotEmpty($templates);

        $prefixLength = strlen(self::pluginDir());
        foreach ($templates as $file) {
            $relative = substr($file, $prefixLength);
            self::assertContains(
                $relative,
                $required,
                $relative.' が required_files.php に載っていない'
            );
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
