<?php

/*
 * EcAuthLogin2 id_token 検証
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

/**
 * EcAuth が発行した id_token (JWT) を検証する。
 *
 * 本クラスは「管理者セッションを確立してよいか」の判断の起点になるため、
 * 検証に少しでも失敗した場合は必ず null を返す（fail-closed）。
 *
 * 検証内容:
 *   1. JWT ヘッダの alg が RS256 であること（alg=none / HS256 への差し替え拒否）
 *   2. JWKS ({base_url}/.well-known/jwks.json) の公開鍵による RS256 署名検証
 *   3. iss が設定済み Base URL と一致すること
 *   4. aud が自身の client_id と一致すること
 *   5. exp が存在し、有効期限内であること（exp 欠落トークンは拒否）
 *   6. nbf / iat が存在する場合、それらが未来でないこと
 *
 * 以前は OIDC Core 3.1.3.7.6 の "direct communication via TLS" 例外を根拠に
 * 署名検証を省いていたが、その前提（トークンエンドポイントが信頼できること）は
 * Base URL が無検証で採用される限り成立しない。Base URL の許可リスト
 * (SC_Helper_EcAuthLogin2_BaseUrl) と本クラスの署名検証は対で機能する。
 *
 * 4 系プラグイン (ec-cube4-ecauth) の Service/IdTokenVerifier.php と同一仕様。
 * 片方だけ変更しないこと。
 *
 * @package EcAuthLogin2
 */
class SC_Helper_EcAuthLogin2_IdToken
{
    /**
     * 許容する署名アルゴリズム。EcAuth は RS256 固定で発行する。
     * ここを緩めると alg confusion 攻撃の余地が生まれるため、他の値は受け付けない。
     *
     * @var string
     */
    public const REQUIRED_ALG = 'RS256';

    /**
     * 時刻クレーム比較時に許容するずれ（秒）。
     *
     * @var int
     */
    public const CLOCK_SKEW = 60;

    /**
     * OID 1.2.840.113549.1.1.1 (rsaEncryption) の DER エンコード。
     *
     * @var string
     */
    private const OID_RSA_ENCRYPTION = "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

    /**
     * JWKS を返す callable。引数は bool $forceRefresh、戻り値は JWK の配列または null。
     *
     * @var callable
     */
    private $jwksProvider;

    /**
     * ログ出力の callable（省略可）。引数は string $message, array $context。
     *
     * @var callable|null
     */
    private $logger;

    /**
     * @param callable $jwksProvider function (bool $forceRefresh): ?array
     * @param callable|null $logger function (string $message, array $context): void
     */
    public function __construct($jwksProvider, $logger = null)
    {
        $this->jwksProvider = $jwksProvider;
        $this->logger = $logger;
    }

    /**
     * id_token を検証し、検証済みのペイロード（クレーム）を返す。
     *
     * @param string $idToken 検証対象の id_token (JWT)
     * @param string $expectedIssuer 期待する iss（＝ EcAuth Base URL、末尾スラッシュなし）
     * @param string $expectedAudience 期待する aud（＝ 自身の client_id）
     * @return array|null 検証成功時はペイロード、失敗時は null
     */
    public function verify($idToken, $expectedIssuer, $expectedAudience)
    {
        $expectedIssuer = rtrim((string) $expectedIssuer, '/');
        $expectedAudience = (string) $expectedAudience;
        if ($expectedIssuer === '' || $expectedAudience === '') {
            $this->log('ID token verification skipped: issuer or audience is not configured', array());

            return null;
        }

        $parts = explode('.', (string) $idToken);
        if (count($parts) !== 3) {
            $this->log('ID token is not a well-formed JWT', array());

            return null;
        }

        $header = $this->decodeJsonSegment($parts[0]);
        $payload = $this->decodeJsonSegment($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);

        if ($header === null || $payload === null || $signature === null || $signature === '') {
            $this->log('ID token segments could not be decoded', array());

            return null;
        }

        $alg = isset($header['alg']) && is_string($header['alg']) ? $header['alg'] : '';
        if (!hash_equals(self::REQUIRED_ALG, $alg)) {
            // alg=none や HS256 への差し替えはここで落とす
            $this->log('ID token uses an unsupported signing algorithm', array('alg' => $alg));

            return null;
        }

        $kid = null;
        if (isset($header['kid']) && is_string($header['kid']) && $header['kid'] !== '') {
            $kid = $header['kid'];
        }

        if (!$this->verifySignature($parts[0] . '.' . $parts[1], $signature, $kid)) {
            return null;
        }

        if (!$this->validateClaims($payload, $expectedIssuer, $expectedAudience)) {
            return null;
        }

        if (!isset($payload['sub']) || !is_string($payload['sub']) || $payload['sub'] === '') {
            $this->log('ID token has no usable sub claim', array());

            return null;
        }

        return $payload;
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
     * JWKS の公開鍵で署名を検証する。
     *
     * @param string $signingInput
     * @param string $signature
     * @param string|null $kid
     * @return bool
     */
    private function verifySignature($signingInput, $signature, $kid)
    {
        $jwks = call_user_func($this->jwksProvider, false);
        $key = is_array($jwks) ? $this->selectKey($jwks, $kid) : null;

        if ($key === null) {
            // 鍵が見つからないのはキャッシュが古い（鍵ローテーション直後）可能性がある。
            // 署名検証の失敗ではないため、ここでだけ強制再取得を 1 度試みる。
            $jwks = call_user_func($this->jwksProvider, true);
            $key = is_array($jwks) ? $this->selectKey($jwks, $kid) : null;
        }

        if ($key === null) {
            $this->log('No matching JWK found for ID token', array('kid' => $kid));

            return false;
        }

        $modulus = $this->base64UrlDecode((string) $key['n']);
        $exponent = $this->base64UrlDecode((string) $key['e']);
        if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
            $this->log('JWK contains a malformed RSA public key', array());

            return false;
        }

        $publicKey = openssl_pkey_get_public($this->rsaPublicKeyToPem($modulus, $exponent));
        if ($publicKey === false) {
            $this->log('Failed to build an OpenSSL public key from JWK', array());

            return false;
        }

        $result = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            $this->log('ID token signature verification failed', array('openssl_verify' => $result));

            return false;
        }

        return true;
    }

    /**
     * 署名検証に使える JWK を選択する。
     *
     * kid がトークンヘッダにある場合は kid 一致を必須とする。kid が無い場合に限り、
     * 候補が 1 件だけならそれを使う（EcAuth 側の鍵ローテーション未実装時の互換措置）。
     *
     * @param string|null $kid
     * @return array|null
     */
    private function selectKey(array $jwks, $kid)
    {
        $candidates = array();
        foreach ($jwks as $key) {
            if (!is_array($key)) {
                continue;
            }
            if (!isset($key['kty']) || $key['kty'] !== 'RSA') {
                continue;
            }
            // use / alg は省略可。指定がある場合のみ厳格に一致を要求する。
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            if (isset($key['alg']) && $key['alg'] !== self::REQUIRED_ALG) {
                continue;
            }
            if (!isset($key['n']) || !isset($key['e']) || !is_string($key['n']) || !is_string($key['e'])) {
                continue;
            }
            $candidates[] = $key;
        }

        if ($kid !== null) {
            foreach ($candidates as $key) {
                if (isset($key['kid']) && is_string($key['kid']) && hash_equals($key['kid'], $kid)) {
                    return $key;
                }
            }

            return null;
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * iss / aud / exp / nbf / iat を検証する。
     *
     * @param string $expectedIssuer
     * @param string $expectedAudience
     * @return bool
     */
    private function validateClaims(array $payload, $expectedIssuer, $expectedAudience)
    {
        $issuer = isset($payload['iss']) && is_string($payload['iss']) ? rtrim($payload['iss'], '/') : '';
        if (!hash_equals($expectedIssuer, $issuer)) {
            $this->log('ID token issuer mismatch', array());

            return false;
        }

        $aud = isset($payload['aud']) ? $payload['aud'] : null;
        if (!$this->audienceMatches($aud, $expectedAudience)) {
            $this->log('ID token audience mismatch', array());

            return false;
        }

        $now = $this->now();

        // exp は必須。欠落トークンを通すと期限切れトークンの再利用を許してしまう。
        if (!isset($payload['exp']) || !$this->isTimestamp($payload['exp'])) {
            $this->log('ID token has no valid exp claim', array());

            return false;
        }
        if ((int) $payload['exp'] <= $now - self::CLOCK_SKEW) {
            $this->log('ID token is expired', array());

            return false;
        }

        if (isset($payload['nbf'])
            && (!$this->isTimestamp($payload['nbf']) || (int) $payload['nbf'] > $now + self::CLOCK_SKEW)) {
            $this->log('ID token is not yet valid', array());

            return false;
        }

        if (isset($payload['iat'])
            && (!$this->isTimestamp($payload['iat']) || (int) $payload['iat'] > $now + self::CLOCK_SKEW)) {
            $this->log('ID token was issued in the future', array());

            return false;
        }

        return true;
    }

    /**
     * aud クレームを検証する。単一文字列と配列のどちらの表現にも対応する。
     *
     * @param mixed $aud
     * @param string $expectedAudience
     * @return bool
     */
    private function audienceMatches($aud, $expectedAudience)
    {
        if (is_string($aud)) {
            return hash_equals($expectedAudience, $aud);
        }

        if (is_array($aud)) {
            foreach ($aud as $value) {
                if (is_string($value) && hash_equals($expectedAudience, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isTimestamp($value)
    {
        return is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * @param string $segment
     * @return array|null
     */
    private function decodeJsonSegment($segment)
    {
        $decoded = $this->base64UrlDecode($segment);
        if ($decoded === null) {
            return null;
        }

        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param string $input
     * @return string|null
     */
    private function base64UrlDecode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * RSA の modulus (n) と exponent (e) から PEM 形式の公開鍵を組み立てる。
     *
     * PHP には JWK → PEM の標準関数が無いため、X.509 SubjectPublicKeyInfo の
     * DER を手で組み立てる。構造は RFC 5280 / RFC 8017 に従う:
     *
     *   SEQUENCE {
     *     SEQUENCE { OBJECT IDENTIFIER rsaEncryption, NULL }
     *     BIT STRING { SEQUENCE { INTEGER n, INTEGER e } }
     *   }
     *
     * @param string $modulus
     * @param string $exponent
     * @return string
     */
    private function rsaPublicKeyToPem($modulus, $exponent)
    {
        $rsaPublicKey = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));

        $algorithmIdentifier = $this->derSequence(
            "\x06" . $this->derLength(strlen(self::OID_RSA_ENCRYPTION)) . self::OID_RSA_ENCRYPTION . "\x05\x00"
        );

        // BIT STRING の先頭 1 バイトは「未使用ビット数」。バイト境界なので常に 0。
        $bitString = "\x03" . $this->derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $der = $this->derSequence($algorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * @param string $content
     * @return string
     */
    private function derSequence($content)
    {
        return "\x30" . $this->derLength(strlen($content)) . $content;
    }

    /**
     * DER の INTEGER を組み立てる。DER の INTEGER は符号付きなので、最上位ビットが
     * 立っている場合は正の数であることを示す 0x00 を前置する。
     *
     * @param string $raw
     * @return string
     */
    private function derInteger($raw)
    {
        $raw = ltrim($raw, "\x00");
        if ($raw === '') {
            $raw = "\x00";
        }
        if ((ord($raw[0]) & 0x80) !== 0) {
            $raw = "\x00" . $raw;
        }

        return "\x02" . $this->derLength(strlen($raw)) . $raw;
    }

    /**
     * DER の長さフィールドを組み立てる（127 バイト以下は短形式、それ以上は長形式）。
     *
     * @param int $length
     * @return string
     */
    private function derLength($length)
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
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
