<!--{*
 * EcAuthLogin2 基本情報管理サブナビへ追加する「パスキー管理」項目
 * Copyright (C) 2026 EcAuth
 *
 * 本体の basis/subnavi.tpl の <ul class="level1"> 末尾へ appendChild される。
 * 書式（class="on" の出し分け、id の命名、ROOT_URLPATH + ADMIN_DIR の組み立て）は
 * 本体の既存項目に合わせている。
 *
 * SC_Helper_Transform は挿入する断片をプレースホルダに退避して getHTML() で
 * 復元するため、この中の Smarty タグはパースされない（属性位置に書いても安全）。
 * @see EcAuthLogin2::insertBasisSubnaviMenu()
*}-->
<li<!--{if $tpl_subno == 'ecauth_login2_passkey'}--> class="on"<!--{/if}--> id="navi-basis-ecauth-passkey"><a href="<!--{$smarty.const.ROOT_URLPATH}--><!--{$smarty.const.ADMIN_DIR}-->ecauth/passkey.php"><span>パスキー管理</span></a></li>
