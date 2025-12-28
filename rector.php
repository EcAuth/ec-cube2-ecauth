<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/data/class',
    ]);

    $rectorConfig->skip([
        __DIR__ . '/vendor',
    ]);

    // PHP 5.6 互換を維持
    $rectorConfig->phpVersion(\Rector\ValueObject\PhpVersion::PHP_56);

    // コード品質向上ルール
    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
    ]);
};
