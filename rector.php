<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/plugin/EcAuthLogin2',
    ]);

    $rectorConfig->skip([
        __DIR__ . '/vendor',
    ]);

    // Rector が生成してよい構文の上限。実際の動作下限は PHP 7.1
    // （ヘルパー各クラスがクラス定数の可視性修飾子を使うため）だが、
    // 上限を低く抑えておく分には安全なのでこの値のままにしている。
    $rectorConfig->phpVersion(\Rector\ValueObject\PhpVersion::PHP_56);

    // コード品質向上ルール
    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
    ]);
};
