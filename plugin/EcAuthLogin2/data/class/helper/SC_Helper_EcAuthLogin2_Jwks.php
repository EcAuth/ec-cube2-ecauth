<?php

/*
 * EcAuthLogin2 JWKS 取得・キャッシュ
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

/**
 * EcAuth の JWKS エンドポイント（{base_url}/.well-known/jwks.json）から
 * 公開鍵を取得し、ファイルキャッシュに一定時間保持する。
 *
 * JWKS は Organization（テナント）ごとに異なるため、キャッシュファイル名は
 * Base URL から導出する。保持するのは公開鍵のみで秘密情報は含まれない。
 *
 * 4 系プラグイン (ec-cube4-ecauth) の Service/CachedJwksProvider.php に対応する。
 * あちらは EC-CUBE 本体の PSR-6 キャッシュを使うが、2 系にはキャッシュ抽象が
 * 無いため CACHE_REALDIR 配下のファイルを直接使う。
 *
 * @package EcAuthLogin2
 */
class SC_Helper_EcAuthLogin2_Jwks
{
    /** @var string JWKS エンドポイントのパス */
    public const JWKS_PATH = '/.well-known/jwks.json';

    /**
     * JWKS のキャッシュ保持秒数。
     *
     * 短すぎるとログインのたびに EcAuth へ HTTP リクエストが飛び、長すぎると
     * 鍵ローテーション直後の追従が遅れる。kid 不一致時は forceRefresh で
     * 即時再取得されるため、通常運用ではこの TTL が追従性の上限にはならない。
     *
     * @var int
     */
    public const CACHE_TTL = 300;

    /**
     * 強制再取得（kid 不一致時）を許可する最短間隔（秒）。
     *
     * kid は id_token のヘッダから来るため、kid を変え続けるトークンを渡されると
     * キャッシュを迂回して JWKS エンドポイントへのリクエストを増幅できてしまう。
     * EcAuth 側の設定ミスで「JWKS に無い kid」が発行され続ける状況でも、
     * ログインのたびに 2 回 HTTP を叩くことになる。クールダウンで上限を設ける。
     *
     * @var int
     */
    public const FORCED_REFRESH_COOLDOWN = 60;

    /** @var string キャッシュファイル名の接頭辞 */
    private const CACHE_PREFIX = 'ecauth_jwks_';

    /** @var string 強制再取得のクールダウンマーカーの接頭辞 */
    private const COOLDOWN_PREFIX = 'ecauth_jwks_forced_';

    /**
     * HTTP GET を行う callable。引数は string $url、
     * 戻り値は array{status: int, body: string|false}。
     *
     * @var callable
     */
    private $fetcher;

    /** @var string|null キャッシュディレクトリ。null ならキャッシュしない */
    private $cacheDir;

    /** @var callable|null */
    private $logger;

    /**
     * @param callable $fetcher function (string $url): array{status: int, body: string|false}
     * @param string|null $cacheDir 未指定時は CACHE_REALDIR を使う
     * @param callable|null $logger function (string $message, array $context): void
     */
    public function __construct($fetcher, $cacheDir = null, $logger = null)
    {
        if ($cacheDir === null && defined('CACHE_REALDIR')) {
            $cacheDir = CACHE_REALDIR;
        }

        $this->fetcher = $fetcher;
        $this->cacheDir = $cacheDir;
        $this->logger = $logger;
    }

    /**
     * JWKS を取得する。
     *
     * @param string $baseUrl EcAuth の Base URL（末尾スラッシュなし）
     * @param bool $forceRefresh キャッシュを無視して再取得する
     * @return array|null JWK の配列。取得失敗時は null
     */
    public function getJwks($baseUrl, $forceRefresh = false)
    {
        $baseUrl = rtrim((string) $baseUrl, '/');
        if ($baseUrl === '') {
            return null;
        }

        $cached = $this->readCache($baseUrl);

        if (!$forceRefresh && $cached !== null) {
            return $cached;
        }

        // 直近に強制再取得したばかりなら、キャッシュを返して外部リクエストを抑える。
        // キャッシュが空のときは返せるものが無いので取得を許可する。
        if ($forceRefresh && $cached !== null && !$this->tryConsumeForcedRefresh($baseUrl)) {
            return $cached;
        }

        $keys = $this->fetch($baseUrl);
        if ($keys === null) {
            return null;
        }

        $this->writeCache($baseUrl, $keys);

        return $keys;
    }

    /**
     * 強制再取得のクールダウンを消費する。
     *
     * まだクールダウン中なら false を返し、呼び出し側は再取得を見送る。
     * 許可した場合はマーカーを立てて、次の呼び出しから一定時間ブロックする。
     *
     * @param string $baseUrl
     * @return bool
     */
    private function tryConsumeForcedRefresh($baseUrl)
    {
        $path = $this->cachePath($baseUrl, self::COOLDOWN_PREFIX);
        if ($path === null) {
            // クールダウンを管理できない場合は従来どおり再取得を許可する
            return true;
        }

        if (is_readable($path)) {
            $raw = file_get_contents($path);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['expires']) && (int) $decoded['expires'] > $this->now()) {
                $this->log('Skipping the forced EcAuth JWKS refresh; still in cooldown', array());

                return false;
            }
        }

        $payload = json_encode(array('expires' => $this->now() + self::FORCED_REFRESH_COOLDOWN));
        if ($payload !== false) {
            @file_put_contents($path, $payload, LOCK_EX);
        }

        return true;
    }

    /**
     * @param string $baseUrl
     * @return array|null
     */
    private function fetch($baseUrl)
    {
        $result = call_user_func($this->fetcher, $baseUrl . self::JWKS_PATH);
        if (!is_array($result)) {
            return null;
        }

        $status = isset($result['status']) ? (int) $result['status'] : 0;
        $body = isset($result['body']) ? $result['body'] : false;

        if ($status !== 200 || !is_string($body)) {
            $this->log('EcAuth JWKS request failed', array('status' => $status));

            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            $this->log('EcAuth JWKS response is malformed', array());

            return null;
        }

        $keys = array();
        foreach ($decoded['keys'] as $key) {
            if (is_array($key)) {
                $keys[] = $key;
            }
        }

        if ($keys === array()) {
            $this->log('EcAuth JWKS response contains no usable key', array());

            return null;
        }

        return $keys;
    }

    /**
     * @param string $baseUrl
     * @return array|null
     */
    private function readCache($baseUrl)
    {
        $path = $this->cachePath($baseUrl);
        if ($path === null || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['expires']) || !isset($decoded['keys'])) {
            return null;
        }
        if (!is_array($decoded['keys']) || $decoded['keys'] === array()) {
            return null;
        }
        if ((int) $decoded['expires'] <= $this->now()) {
            return null;
        }

        return $decoded['keys'];
    }

    /**
     * @param string $baseUrl
     * @return void
     */
    private function writeCache($baseUrl, array $keys)
    {
        $path = $this->cachePath($baseUrl);
        if ($path === null) {
            return;
        }

        $payload = json_encode(array(
            'expires' => $this->now() + self::CACHE_TTL,
            'keys' => $keys,
        ));
        if ($payload === false) {
            return;
        }

        // 書き込みに失敗しても取得自体は成立しているので、警告を残して続行する
        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            $this->log('Failed to write the EcAuth JWKS cache', array('path' => $path));
        }
    }

    /**
     * @param string $baseUrl
     * @param string|null $prefix 既定は JWKS 本体のキャッシュ
     * @return string|null
     */
    private function cachePath($baseUrl, $prefix = null)
    {
        if ($this->cacheDir === null || !is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
            return null;
        }

        if ($prefix === null) {
            $prefix = self::CACHE_PREFIX;
        }

        return rtrim($this->cacheDir, '/') . '/' . $prefix . hash('sha256', $baseUrl) . '.json';
    }

    /**
     * 現在時刻。テストから差し替えられるように分離している。
     *
     * @return int
     */
    protected function now()
    {
        return time();
    }

    /**
     * @param string $message
     * @return void
     */
    private function log($message, array $context)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message, $context);
        }
    }
}
