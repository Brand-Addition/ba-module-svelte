<?php

declare(strict_types=1);

namespace BA\Svelte\Test\Unit\Model;

use BA\Svelte\Model\SvelteDependencyConfig;
use Magento\Framework\Config\ReaderInterface;
use PHPUnit\Framework\TestCase;

class SvelteDependencyConfigTest extends TestCase
{
    public function testAreaDependenciesExtendAndOverrideGlobalDependencies(): void
    {
        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects(self::exactly(4))
            ->method('read')
            ->willReturnMap([
                ['global', ['dependencies' => ['@vendor/global' => '^1.0', '@vendor/shared' => '^1.0']]],
                ['frontend', ['dependencies' => ['@vendor/frontend' => '^2.0', '@vendor/shared' => '^2.0']]],
                ['global', ['dependencies' => ['@vendor/global' => '^1.0', '@vendor/shared' => '^1.0']]],
                ['adminhtml', []],
            ]);

        $config = new SvelteDependencyConfig($reader);

        self::assertSame(
            [
                '@vendor/global' => '^1.0',
                '@vendor/shared' => '^2.0',
                '@vendor/frontend' => '^2.0',
            ],
            $config->getDependencies('frontend')
        );
        self::assertSame(
            ['@vendor/global' => '^1.0', '@vendor/shared' => '^1.0'],
            $config->getDependencies('adminhtml')
        );
    }

    public function testAreaWithoutDependenciesUsesGlobalDependencies(): void
    {
        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects(self::exactly(2))
            ->method('read')
            ->willReturnMap([
                ['global', ['dependencies' => ['vite-plugin-example' => '^1.0']]],
                ['frontend', []],
            ]);

        self::assertSame(
            ['vite-plugin-example' => '^1.0'],
            (new SvelteDependencyConfig($reader))->getDependencies('frontend')
        );
    }
}
