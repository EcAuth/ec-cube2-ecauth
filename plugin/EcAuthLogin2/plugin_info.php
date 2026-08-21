<?php

/**
 * EcAuthLogin2 プラグイン情報クラス
 *
 * @package EcAuthLogin2
 * @version 1.0.3
 */
class plugin_info
{
    /** プラグインコード（必須）：プラグインを識別するキー */
    public static $PLUGIN_CODE = 'EcAuthLogin2';

    /** プラグイン名（必須）：EC-CUBE上で表示されるプラグイン名 */
    public static $PLUGIN_NAME = 'EcAuth Login (パスキー)';

    /** クラス名（必須）：プラグインのクラス（拡張子は含まない） */
    public static $CLASS_NAME = 'EcAuthLogin2';

    /** プラグインバージョン（必須）：プラグインのバージョン */
    public static $PLUGIN_VERSION = '1.0.3';

    /**
     * 対応バージョン（必須）：対応するEC-CUBEバージョン
     *
     * 本体側では互換性判定に使われず、プラグイン管理画面の
     * 「対応EC-CUBEバージョン」表示に出るだけの自由記述
     * （@see data/Smarty/templates/admin/ownersstore/plugin.tpl）。
     *
     * 2.13 を含めないのは、本プラグインが PHP 7.1 以降でしか動かないため。
     * ヘルパー各クラスがクラス定数の可視性修飾子（`public const` /
     * `private const`、PHP 7.1 で導入）を使っており、2.13 がサポートする
     * PHP 5.3〜5.6 では読み込み時に Parse error になる。
     */
    public static $COMPLIANT_VERSION = '2.17 / 2.25';

    /** 作者（必須）：プラグイン作者 */
    public static $AUTHOR = 'EcAuth';

    /** 説明（必須）：プラグインの説明 */
    public static $DESCRIPTION = 'EcAuth IdP と連携し、管理画面の B2B パスキーログインを提供します。';

    /**
     * プラグインURL：プラグインの説明ページなど
     *
     * プラグイン管理画面ではプラグイン名のリンク先になる。ショップ運営者が
     * 開くリンクなので、開発者向けの GitHub ではなくサービスサイトへ向ける。
     */
    public static $PLUGIN_SITE_URL = 'https://ec-auth.io/';

    /** プラグイン作者URL：作者のサイトURL */
    public static $AUTHOR_SITE_URL = 'https://ec-auth.io/';

    /** フックポイント：フックポイントとコールバック関数を定義 */
    public static $HOOK_POINTS = array(
        array('prefilterTransform', 'prefilterTransform'),
    );
}
