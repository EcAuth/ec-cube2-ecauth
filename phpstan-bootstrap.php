<?php
/**
 * PHPStan 用 ブートストラップ
 *
 * EC-CUBE 2 のコア定数を解析時に解決するためのスタブ定義。
 * 実行時には app_initial.php / data/include/* で定義されるが、
 * 静的解析時にはそれらの初期化処理を経ないため、ここで宣言する。
 * 値はダミー (空文字 / フォールバック) で良い。
 */

defined('HTML_REALDIR') || define('HTML_REALDIR', '');
defined('DATA_REALDIR') || define('DATA_REALDIR', '');
defined('CLASS_REALDIR') || define('CLASS_REALDIR', '');
defined('CLASS_EX_REALDIR') || define('CLASS_EX_REALDIR', '');
defined('PLUGIN_UPLOAD_REALDIR') || define('PLUGIN_UPLOAD_REALDIR', '');
defined('PLUGIN_HTML_REALDIR') || define('PLUGIN_HTML_REALDIR', '');
defined('PLUGIN_HTML_URLPATH') || define('PLUGIN_HTML_URLPATH', '');
defined('TEMPLATE_REALDIR') || define('TEMPLATE_REALDIR', '');
defined('SMARTY_TEMPLATES_REALDIR') || define('SMARTY_TEMPLATES_REALDIR', '');

defined('HTTP_URL') || define('HTTP_URL', '');
defined('HTTPS_URL') || define('HTTPS_URL', '');
defined('ROOT_URLPATH') || define('ROOT_URLPATH', '');
defined('ADMIN_DIR') || define('ADMIN_DIR', 'admin/');
defined('ADMIN_HOME_URLPATH') || define('ADMIN_HOME_URLPATH', '');

defined('AUTH_MAGIC') || define('AUTH_MAGIC', '');
defined('AUTH_TYPE') || define('AUTH_TYPE', '');
defined('PASSWORD_HASH_ALGOS') || define('PASSWORD_HASH_ALGOS', 'sha256');
defined('CERT_STRING') || define('CERT_STRING', '');
defined('TRANSACTION_ID_NAME') || define('TRANSACTION_ID_NAME', 'transactionid');

// 文字長の定数だけは EC-CUBE 2 の実値 (data/mtb_constants_init.php) を写す。
// 他の定数と違い「何文字まで入るか」がプラグインの正しさに直結するため、
// ダミー値を置くと実環境との差に気付けない。
defined('STEXT_LEN') || define('STEXT_LEN', 50);
defined('MTEXT_LEN') || define('MTEXT_LEN', 200);
defined('URL_LEN') || define('URL_LEN', 1024);
defined('LOGIN_RETRY_INTERVAL') || define('LOGIN_RETRY_INTERVAL', 3);
defined('SAFE') || define('SAFE', false);
