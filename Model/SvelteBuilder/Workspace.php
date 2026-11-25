<?php
declare(strict_types=1);

namespace BA\Svelte\Model\SvelteBuilder;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class Workspace
{
    /**
     * @param array<string, string> $dependencies
     */
    public function create(string $svelteSourcePath, array $dependencies): string
    {
        $workspacePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ba-svelte-build-'
            . bin2hex(random_bytes(8));

        if (!mkdir($workspacePath, 0775)) {
            throw new LocalizedException(
                new Phrase('Unable to create Svelte build workspace: %1', [$workspacePath])
            );
        }

        try {
            $this->copyDirectory($svelteSourcePath, $workspacePath);
            $packageJsonPath = $workspacePath . DIRECTORY_SEPARATOR . 'package.json';
            $packageJsonContents = file_get_contents($packageJsonPath);
            if ($packageJsonContents === false) {
                throw new LocalizedException(
                    new Phrase('Unable to read Svelte package manifest: %1', [$packageJsonPath])
                );
            }

            try {
                $packageJson = json_decode($packageJsonContents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new LocalizedException(
                    new Phrase('Invalid Svelte package manifest: %1', [$packageJsonPath]),
                    $exception
                );
            }

            if (!is_array($packageJson) || !is_array($packageJson['devDependencies'] ?? [])) {
                throw new LocalizedException(
                    new Phrase(
                        'Svelte package manifest must contain an object-valued "devDependencies" field: %1',
                        [$packageJsonPath]
                    )
                );
            }

            $devDependencies = $packageJson['devDependencies'] ?? [];
            foreach ($dependencies as $name => $spec) {
                if (isset($devDependencies[$name]) && $devDependencies[$name] !== $spec) {
                    throw new LocalizedException(
                        new Phrase(
                            'Configured Svelte dependency "%1" with spec "%2" conflicts with package.json spec "%3".',
                            [$name, $spec, $devDependencies[$name]]
                        )
                    );
                }
                $devDependencies[$name] = $spec;
            }
            $packageJson['devDependencies'] = (object)$devDependencies;

            try {
                $updatedManifest = json_encode(
                    $packageJson,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $exception) {
                throw new LocalizedException(
                    new Phrase('Unable to encode Svelte package manifest: %1', [$packageJsonPath]),
                    $exception
                );
            }

            if (file_put_contents($packageJsonPath, $updatedManifest . PHP_EOL) === false) {
                throw new LocalizedException(
                    new Phrase('Unable to write Svelte package manifest: %1', [$packageJsonPath])
                );
            }

            return $workspacePath;
        } catch (\Throwable $exception) {
            $this->removeDirectory($workspacePath);
            throw $exception;
        }
    }

    /**
     * @param array<int, string> $scdRoots
     */
    public function cleanup(string $workspacePath, array $scdRoots): void
    {
        foreach ($scdRoots as $scdRoot) {
            $this->removeNodeModulesLink(
                $scdRoot,
                $workspacePath . DIRECTORY_SEPARATOR . 'node_modules'
            );
        }

        $this->removeDirectory($workspacePath);
    }

    private function copyDirectory(string $sourcePath, string $targetPath): void
    {
        $entries = scandir($sourcePath);
        if ($entries === false) {
            throw new LocalizedException(
                new Phrase('Unable to read Svelte source directory: %1', [$sourcePath])
            );
        }

        foreach ($entries as $entry) {
            if (in_array($entry, ['.', '..', 'node_modules', 'dist', '.git'], true)) {
                continue;
            }

            $source = $sourcePath . DIRECTORY_SEPARATOR . $entry;
            $target = $targetPath . DIRECTORY_SEPARATOR . $entry;
            if (is_link($source)) {
                $linkTarget = readlink($source);
                if ($linkTarget === false || !symlink($linkTarget, $target)) {
                    throw new LocalizedException(
                        new Phrase('Unable to copy Svelte source link: %1', [$source])
                    );
                }
                continue;
            }

            if (is_dir($source)) {
                if (!mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new LocalizedException(
                        new Phrase('Unable to create Svelte workspace directory: %1', [$target])
                    );
                }
                $this->copyDirectory($source, $target);
                continue;
            }

            if (!copy($source, $target)) {
                throw new LocalizedException(
                    new Phrase('Unable to copy Svelte source file: %1', [$source])
                );
            }
        }
    }

    private function removeNodeModulesLink(string $scdRoot, string $workspaceNodeModules): void
    {
        $linkPath = $scdRoot . DIRECTORY_SEPARATOR . 'node_modules';
        $linkTarget = realpath($linkPath);
        $workspaceTarget = realpath($workspaceNodeModules);
        if (
            is_link($linkPath)
            && $linkTarget !== false
            && $workspaceTarget !== false
            && $linkTarget === $workspaceTarget
            && !unlink($linkPath)
        ) {
            throw new LocalizedException(
                new Phrase('Unable to remove temporary Svelte dependency link: %1', [$linkPath])
            );
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $removed = rmdir($item->getPathname());
            } else {
                $removed = unlink($item->getPathname());
            }
            if (!$removed) {
                throw new LocalizedException(
                    new Phrase('Unable to remove temporary Svelte build path: %1', [$item->getPathname()])
                );
            }
        }

        if (!rmdir($directory)) {
            throw new LocalizedException(
                new Phrase('Unable to remove Svelte build workspace: %1', [$directory])
            );
        }
    }
}
