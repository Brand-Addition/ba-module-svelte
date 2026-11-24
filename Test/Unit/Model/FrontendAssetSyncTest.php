<?php

declare(strict_types=1);

namespace BA\Svelte\Test\Unit\Model;

use BA\Svelte\Model\FrontendAssetSync;
use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

class FrontendAssetSyncTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ba-frontend-asset-sync-'
            . bin2hex(random_bytes(6));
        mkdir($this->tmpPath, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tmpPath);

        parent::tearDown();
    }

    public function testSyncCopiesBaseAndRequestedAreaAssetsAndOverwritesExistingFiles(): void
    {
        $modulePath = $this->tmpPath . DIRECTORY_SEPARATOR . 'module';
        $scdRoot = $this->tmpPath . DIRECTORY_SEPARATOR . 'static'
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'Vendor'
            . DIRECTORY_SEPARATOR . 'theme'
            . DIRECTORY_SEPARATOR . 'en_US';
        $targetModulePath = $scdRoot . DIRECTORY_SEPARATOR . 'Vendor_Module';

        $this->writeFile(
            $modulePath . '/view/base/web/svelte-src/src/Changed.svelte',
            'updated source'
        );
        $this->writeFile(
            $modulePath . '/view/frontend/web/svelte-src/src/Frontend.svelte',
            'frontend source'
        );
        $this->writeFile(
            $modulePath . '/view/adminhtml/web/svelte-src/src/Admin.svelte',
            'admin source'
        );
        $this->writeFile(
            $targetModulePath . '/svelte-src/src/Changed.svelte',
            'stale deployed source'
        );

        $registrar = $this->getMockBuilder(ComponentRegistrar::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPaths'])
            ->getMock();
        $registrar->method('getPaths')->with(ComponentRegistrar::MODULE)->willReturn([
            'Vendor_Module' => $modulePath,
        ]);

        (new FrontendAssetSync($registrar))->sync($scdRoot, 'frontend');

        self::assertSame(
            'updated source',
            file_get_contents($targetModulePath . '/svelte-src/src/Changed.svelte')
        );
        self::assertSame(
            'frontend source',
            file_get_contents($targetModulePath . '/svelte-src/src/Frontend.svelte')
        );
        self::assertFileDoesNotExist($targetModulePath . '/svelte-src/src/Admin.svelte');
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }
}
