<?php

/*
 * EcAuthLogin2 Base URL 許可リスト
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

/**
 * EcAuth Base URL が許可されたホストを指しているかを検証する。
 *
 * Base URL はトークン交換先および JWKS 取得先になるため、設定汚染や
 * client-resolve 応答の改竄によって攻撃者のホストに向けられると、
 * id_token の署名検証ごと攻撃者の鍵で成立してしまう。信頼の起点として
 * ホストを許可リストで縛る（EcAuthDocs #101）。
 *
 * 許可リストは環境変数 ECAUTH_ALLOWED_HOSTS（カンマ区切り）で上書きできる。
 * 各エントリの書式:
 *
 *   .example.com          … example.com のサブドメインすべて（apex は含まない）
 *   example.com           … ホスト完全一致
 *   example.com:8081      … ホストとポートの完全一致
 *   http://localhost:8080 … 既定では https のみ許可するため、http を許す場合はスキームを明示する
 *
 * 許可リストが空の場合はすべて拒否する（fail-closed）。
 *
 * 4 系プラグイン (ec-cube4-ecauth) の Service/BaseUrlValidator.php と同一仕様。
 * 片方だけ変更しないこと。
 *
 * @package EcAuthLogin2
 */
class SC_Helper_EcAuthLogin2_BaseUrl
{
    /** @var string 許可ホストのデフォルト（カンマ区切り） */
    public const DEFAULT_ALLOWED_HOSTS = '.ec-auth.io';

    /** @var array<int, array{scheme: string|null, host: string, port: int|null, suffix: bool}> */
    private $allowedHosts;

    /**
     * @param string|null $allowedHosts 未指定時は環境変数 ECAUTH_ALLOWED_HOSTS → 既定値の順で解決する
     */
    public function __construct($allowedHosts = null)
    {
        if ($allowedHosts === null) {
            $fromEnv = getenv('ECAUTH_ALLOWED_HOSTS');
            $allowedHosts = ($fromEnv === false || $fromEnv === '') ? self::DEFAULT_ALLOWED_HOSTS : $fromEnv;
        }

        $this->allowedHosts = $this->parseAllowedHosts((string) $allowedHosts);
    }

    /**
     * Base URL を検証し、正規化した URL を返す。許可されない場合は null。
     *
     * @param string|null $baseUrl
     * @return string|null
     */
    public function normalize($baseUrl)
    {
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return null;
        }

        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
            return null;
        }

        // 認証情報付き URL (https://evil@example.com) やパス・クエリ付きは受け付けない。
        // EcAuth の Base URL は "{scheme}://{host}" の形に限られる。
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '') {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? $parts['port'] : null;

        if (!$this->matchesAllowedHost($scheme, $host, $port)) {
            return null;
        }

        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }

    /**
     * @param string|null $baseUrl
     * @return bool
     */
    public function isAllowed($baseUrl)
    {
        return $this->normalize($baseUrl) !== null;
    }

    /**
     * @param string $scheme
     * @param string $host
     * @param int|null $port
     * @return bool
     */
    private function matchesAllowedHost($scheme, $host, $port)
    {
        foreach ($this->allowedHosts as $allowed) {
            // スキームを明示していないエントリは https のみ許可する。
            $expectedScheme = $allowed['scheme'] === null ? 'https' : $allowed['scheme'];
            if ($scheme !== $expectedScheme) {
                continue;
            }

            if ($allowed['port'] !== null && $allowed['port'] !== $port) {
                continue;
            }

            if ($allowed['suffix']) {
                $suffix = $allowed['host'];
                if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
                    return true;
                }
                continue;
            }

            if ($host === $allowed['host']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $allowedHosts
     * @return array<int, array{scheme: string|null, host: string, port: int|null, suffix: bool}>
     */
    private function parseAllowedHosts($allowedHosts)
    {
        $parsed = array();
        foreach (explode(',', $allowedHosts) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }

            $scheme = null;
            if (strpos($entry, '://') !== false) {
                list($scheme, $entry) = explode('://', $entry, 2);
                $entry = trim($entry);
                if ($entry === '' || ($scheme !== 'http' && $scheme !== 'https')) {
                    continue;
                }
            }

            $suffix = strpos($entry, '.') === 0;

            $port = null;
            // IPv6 リテラルは扱わない。ポート指定は "host:port" のみ。
            if (preg_match('/^(.+):(\d+)$/', $entry, $matches) === 1) {
                $entry = $matches[1];
                $port = (int) $matches[2];
            }

            if ($entry === '.') {
                continue;
            }

            $parsed[] = array(
                'scheme' => $scheme,
                'host' => $entry,
                'port' => $port,
                'suffix' => $suffix,
            );
        }

        return $parsed;
    }
}
