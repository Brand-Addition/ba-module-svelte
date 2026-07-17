<?php

declare(strict_types=1);

namespace BA\Svelte\Model;

use FilesystemIterator;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FrontendAssetSync
{
    public function __construct(
        private readonly ComponentRegistrar $registrar
    ) {
    }

    public function sync(string $scdRoot): void
    {
        foreach ($this->registrar->getPaths(ComponentRegistrar::MODULE) as $moduleName => $modulePath) {
            if (!is_string($moduleName) || !is_string($modulePath) || $moduleName === '' || $modulePath === '') {
                continue;
            }

            $sourcePath = $this->buildFrontendAssetPath($modulePath);
            if (!$this->shouldSyncModuleAssets($sourcePath)) {
                continue;
            }

            $targetPath = $scdRoot . DIRECTORY_SEPARATOR . $moduleName;
            $this->copyDirectoryContents($sourcePath, $targetPath);
        }
    }

    private function shouldSyncModuleAssets(string $sourcePath): bool
    {
        if (!is_dir($sourcePath)) {
            return false;
        }

        return is_dir($sourcePath . DIRECTORY_SEPARATOR . 'svelte')
            || is_dir($sourcePath . DIRECTORY_SEPARATOR . 'svelte-src');
    }

    private function buildFrontendAssetPath(string $modulePath): string
    {
        return $modulePath
            . DIRECTORY_SEPARATOR
            . 'view'
            . DIRECTORY_SEPARATOR
            . 'frontend'
            . DIRECTORY_SEPARATOR
            . 'web';
    }

    private function copyDirectoryContents(string $sourcePath, string $targetPath): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathName();
            if ($this->shouldSkipSyncedPath($relativePath)) {
                continue;
            }

            $destination = $targetPath . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                $this->ensureDirectoryExists($destination);
                continue;
            }

            $destinationDirectory = dirname($destination);
            $this->ensureDirectoryExists($destinationDirectory);
            $this->copyFile($item, $destination);
        }
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new LocalizedException(
                new Phrase('Unable to create directory: %1', [$path])
            );
        }
    }

    private function copyFile(SplFileInfo $item, string $destination): void
    {
        if (is_link($destination)) {
            return;
        }

        if (!copy($item->getPathname(), $destination)) {
            $reason = match (true) {
                !file_exists($item->getPathname()) =>
                    'Source file does not exist.',
                !is_readable($item->getPathname()) =>
                    'Source file is not readable.',
                !is_dir(dirname($destination)) =>
                    'Destination directory does not exist.',
                !is_writable(dirname($destination)) =>
                    'Destination directory is not writable.',
                default =>
                    'The filesystem rejected the copy operation for an unknown reason.',
            };

            throw new LocalizedException(
                new Phrase(
                    'Unable to copy static asset from %1 to %2. %3',
                    [
                        $item->getPathname(),
                        $destination,
                        $reason,
                    ]
                )
            );
        }
    }

    private function shouldSkipSyncedPath(string $relativePath): bool
    {
        $segments = preg_split('#[\\\\/]#', $relativePath) ?: [];

        return in_array('node_modules', $segments, true)
            || in_array('dist', $segments, true);
    }
}
