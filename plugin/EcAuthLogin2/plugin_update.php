<?php

/*
 * EcAuthLogin2 アップデート処理
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * 管理画面 オーナーズストア > プラグイン管理 > 「アップデート」から
 * LC_Page_Admin_OwnersStore::updatePlugin() 経由で呼ばれる。
 *
 * 本体側の処理順（EC-CUBE 2.25 時点）:
 *   1. tar.gz を DOWNLOADS_TEMP_PLUGIN_UPDATE_DIR に展開
 *   2. plugin_info.php を読み込み PLUGIN_CODE 一致を確認
 *   3. plugin_update.php（このファイル）を require
 *   4. plugin_update::update() を実行          ← ここ
 *   5. registerData() で dtb_plugin を更新
 *   6. DOWNLOADS_TEMP_PLUGIN_UPDATE_DIR を削除
 *
 * 重要な前提が 3 つある。
 *
 * (a) インストール経路と違い、本体はファイルを配置してくれない。
 *     installPlugin() は SC_Utils_Ex::copyDirectory() で
 *     PLUGIN_UPLOAD_REALDIR へ展開してくれるが、updatePlugin() には
 *     相当する処理が無い。展開先から先はこのファイルの責任。
 *
 * (b) 4 が失敗しても 5 は実行される。本体は execPlugin() の戻り値を見ずに
 *     registerData() を呼ぶため、途中で失敗すると「dtb_plugin のバージョンだけ
 *     上がって中身は古い」状態になる。そのため配置前に全ソースの存在を検証し、
 *     部分適用が起きる窓をできるだけ塞ぐ。
 *
 * (c) EcAuthLogin2 クラスは旧バージョンがロード済みで、読み直せない。
 *     プラグインが有効な場合、SC_Helper_Plugin::load() がリクエスト冒頭で
 *     PLUGIN_UPLOAD_REALDIR/EcAuthLogin2/EcAuthLogin2.php を require_once
 *     しているため、アップデート中に新しい定義へ差し替えることはできない。
 *     よって EcAuthLogin2::install() に配置を委ねると「旧バージョンの配置表」
 *     で処理され、このバージョンで追加されたファイルを取りこぼす。
 *     配置表は新バージョンの filemap.php を直接 require して使う。
 */
class plugin_update
{
    /**
     * アップデート処理.
     *
     * static である必要がある。本体は
     * `call_user_func_array(['plugin_update', 'update'], ...)` と静的呼び出しを
     * するため、非 static だと PHP 8 で Error になる
     * （本体同梱の他プラグインの実装例は非 static だが、それは PHP 5 時代の名残）。
     *
     * @param array $arrPlugin dtb_plugin の行
     * @param SC_Plugin_Installer|null $installer
     *
     * @return string|true エラーメッセージ（本体が画面に表示する）、成功時は true
     */
    public static function update($arrPlugin, $installer = null)
    {
        $pluginCode = isset($arrPlugin['plugin_code']) ? $arrPlugin['plugin_code'] : 'EcAuthLogin2';
        $srcDir = DOWNLOADS_TEMP_PLUGIN_UPDATE_DIR;
        $pluginDir = PLUGIN_UPLOAD_REALDIR.$pluginCode.'/';

        // --- 検証フェーズ（ここでは何も書き換えない） ---
        $fileMapPath = $srcDir.'filemap.php';
        if (!is_file($fileMapPath)) {
            return self::fail('配置表 (filemap.php) がアーカイブに含まれていません。');
        }
        $fileMap = require $fileMapPath;
        if (!is_array($fileMap) || $fileMap === array()) {
            return self::fail('配置表 (filemap.php) を読み込めませんでした。');
        }

        $missing = array();
        foreach ($fileMap as $relativeSrc => $destSpec) {
            if (!is_file($srcDir.$relativeSrc)) {
                $missing[] = $relativeSrc;
            }
        }
        if ($missing !== array()) {
            return self::fail('アーカイブに必要なファイルがありません: '.implode(', ', $missing));
        }

        // --- 適用フェーズ ---
        // (1) プラグイン保存ディレクトリを最新化する。
        //     templates/ や config.php は PLUGIN_UPLOAD_REALDIR から直接読まれるため、
        //     このコピーだけで反映される（fileMap には載っていない）。
        SC_Utils_Ex::copyDirectory($srcDir, $pluginDir);

        // (2) EC-CUBE のディレクトリツリーへ再配置する。
        foreach ($fileMap as $relativeSrc => $destSpec) {
            $src = $pluginDir.$relativeSrc;
            $dest = self::expandDestSpec($destSpec);
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                return self::fail('ディレクトリを作成できませんでした: '.$destDir);
            }
            if (!copy($src, $dest)) {
                return self::fail('ファイルを配置できませんでした: '.$src.' -> '.$dest);
            }
        }

        // (3) 1.0.4 以前が data/class/ 配下へ配置した残骸を削除する (#30)。
        //     配置（2）の後に実行する。先に消すと、途中で失敗したときに
        //     旧ファイルも新ファイルも無い状態になりうるため。
        self::removeLegacyClassFiles($srcDir);

        // (4) Smarty のコンパイル済みテンプレートを破棄する。
        //     prefilterTransform はテンプレートのコンパイル時にしか走らないため、
        //     これを消さないと管理画面ログインのパスキーボタンなど、
        //     テンプレートに対する変更が反映されないまま残る。
        SC_Utils_Ex::clearCompliedTemplate();

        error_log('[EcAuthLogin2] Updated to '.plugin_info::$PLUGIN_VERSION);

        return true;
    }

    /**
     * 1.0.4 以前が data/class/ 配下へ配置したクラスファイルを削除する.
     *
     * 1.0.5 以降はクラスファイルをコピーせず PLUGIN_UPLOAD_REALDIR から直接
     * require_once するため、これらは読まれない残骸になる (#30)。
     *
     * 一覧は新バージョンの filemap_legacy.php から読む。旧バージョンの
     * EcAuthLogin2 クラスはロード済みで読み直せないため、そちらの
     * getLegacyFileMap() は呼べない（冒頭の前提 (c) を参照）。
     *
     * 削除に失敗してもアップデート自体は成功扱いにする。残骸が残っても新しい
     * 配置は完了しており、ここで失敗を返すと本体が「アップデート失敗」と表示して
     * しまうため。
     *
     * @param string $srcDir 展開済みアーカイブのディレクトリ
     */
    protected static function removeLegacyClassFiles($srcDir)
    {
        $legacyMapPath = $srcDir.'filemap_legacy.php';
        if (!is_file($legacyMapPath)) {
            return;
        }
        $legacyMap = require $legacyMapPath;
        if (!is_array($legacyMap)) {
            return;
        }

        foreach ($legacyMap as $destSpec) {
            $dest = self::expandDestSpec($destSpec);
            if (!is_file($dest)) {
                continue;
            }
            if (@unlink($dest)) {
                error_log('[EcAuthLogin2] Removed legacy file: '.$dest);
            } else {
                error_log('[EcAuthLogin2] Failed to remove legacy file: '.$dest);
            }
        }

        foreach (array('pages/ecauth', 'pages/admin/ecauth') as $relative) {
            $dir = CLASS_REALDIR.$relative;
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                @rmdir($dir);
            }
        }
    }

    /**
     * "PLACEHOLDER:relative/path" 形式を絶対パスに展開する.
     *
     * EcAuthLogin2::expandDestSpec() と同じ処理だが、上記 (c) の理由で
     * そちらを呼べないため複製している。片方だけ直すと配置先がずれるので、
     * 変更時は両方を揃えること。
     *
     * @param string $destSpec
     *
     * @return string
     */
    protected static function expandDestSpec($destSpec)
    {
        list($placeholder, $relative) = explode(':', $destSpec, 2);
        switch ($placeholder) {
            case 'CLASS_REALDIR':
                return CLASS_REALDIR.$relative;
            case 'HTML_REALDIR':
                return HTML_REALDIR.$relative;
            case 'ADMIN_HTML_REALDIR':
                return HTML_REALDIR.ADMIN_DIR.$relative;
            case 'PLUGIN_HTML_REALDIR':
                return PLUGIN_HTML_REALDIR.$relative;
            default:
                return $destSpec;
        }
    }

    /**
     * エラーをログに残しつつ、本体が画面表示するメッセージを返す.
     *
     * @param string $message
     *
     * @return string
     */
    protected static function fail($message)
    {
        error_log('[EcAuthLogin2] Update failed: '.$message);

        return '※ EcAuthLogin2 のアップデートに失敗しました。'.$message.'<br/>';
    }
}
