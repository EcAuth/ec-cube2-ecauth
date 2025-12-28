<?php
/**
 * EcAuthLogin2 プラグイン情報クラス
 *
 * @package EcAuthLogin2
 * @version 1.0.0
 */
class plugin_info
{
    /** プラグインコード（必須）：プラグインを識別するキー */
    public static $PLUGIN_CODE = 'EcAuthLogin2';

    /** プラグイン名（必須）：EC-CUBE上で表示されるプラグイン名 */
    public static $PLUGIN_NAME = 'EcAuth ソーシャルログイン';

    /** クラス名（必須）：プラグインのクラス（拡張子は含まない） */
    public static $CLASS_NAME = 'EcAuthLogin2';

    /** プラグインバージョン（必須）：プラグインのバージョン */
    public static $PLUGIN_VERSION = '1.0.0';

    /** 対応バージョン（必須）：対応するEC-CUBEバージョン */
    public static $COMPLIANT_VERSION = '2.13.0';

    /** 作者（必須）：プラグイン作者 */
    public static $AUTHOR = 'EcAuth';

    /** 説明（必須）：プラグインの説明 */
    public static $DESCRIPTION = 'EcAuth IdP と連携し、ソーシャルログイン機能を提供します。Google、LINE、Facebook 等の外部 IdP でログインできるようになります。';

    /** プラグインURL：プラグインの説明ページなど */
    public static $PLUGIN_SITE_URL = 'https://github.com/EcAuth/ec-cube2-ecauth';

    /** プラグイン作者URL：作者のサイトURL */
    public static $AUTHOR_SITE_URL = 'https://github.com/EcAuth';

    /** フックポイント：フックポイントとコールバック関数を定義 */
    public static $HOOK_POINTS = array(
        array('prefilterTransform', 'prefilterTransform'),
    );
}
