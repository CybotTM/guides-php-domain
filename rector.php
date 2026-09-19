<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\FuncCall\SimplifyRegexPatternRector;
use Rector\Config\RectorConfig;
use Rector\Core\ValueObject\PhpVersion;
use Rector\EarlyReturn\Rector\If_\ChangeAndIfToEarlyReturnRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\PHPUnit\Set\PHPUnitLevelSetList;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $config): void {
    $config->phpVersion(PhpVersion::PHP_82);

    $config->import(LevelSetList::UP_TO_PHP_82);
    $config->import(SetList::CODE_QUALITY);
    $config->import(SetList::DEAD_CODE);
    $config->import(SetList::EARLY_RETURN);
    $config->import(SetList::TYPE_DECLARATION);
    $config->import(PHPUnitLevelSetList::UP_TO_PHPUNIT_100);
    $config->import(PHPUnitSetList::PHPUNIT_CODE_QUALITY);

    $config->autoloadPaths([
        __DIR__ . '/vendor/autoload.php',
    ]);
    $config->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);
    $config->skip([
        PreferPHPUnitThisCallRector::class,
        ChangeAndIfToEarlyReturnRector::class,
        SimplifyRegexPatternRector::class
    ]);
};
