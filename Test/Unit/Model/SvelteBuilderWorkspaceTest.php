<?php

declare(strict_types=1);

namespace BA\Svelte\Test\Unit\Model;

use BA\Svelte\Model\SvelteBuilder\Workspace;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

class SvelteBuilderWorkspaceTest extends TestCase
{
    private string $tmpPath;
    private string $sourcePath;
    private ?string $workspacePath = null;
    private ?string $scdRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ba-svelte-workspace-'
            . bin2hex(random_bytes(6));
        $this->sourcePath = $this->tmpPath . DIRECTORY_SEPARATOR . 'source';
        mkdir($this->sourcePath, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->workspacePath !== null && is_dir($this->workspacePath)) {
            (new Workspace())->cleanup(
                $this->workspacePath,
                $this->scdRoot === null ? [] : [$this->scdRoot]
            );
        }

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

    public function testCreateComposesManifestWithoutChangingSourceAndCleanupRemovesWorkspaceLink(): void
    {
        $manifest = [
            'name' => 'ba-svelte',
            'private' => true,
            'scripts' => ['build' => 'vite build'],
        ];
        $this->writeFile(
            $this->sourcePath . DIRECTORY_SEPARATOR . 'package.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
        $this->writeFile($this->sourcePath . DIRECTORY_SEPARATOR . 'package-lock.json', '{"lockfileVersion":3}');
        $this->writeFile($this->sourcePath . DIRECTORY_SEPARATOR . 'src/main.js', 'export {};');
        $this->writeFile($this->sourcePath . DIRECTORY_SEPARATOR . 'node_modules/vite/index.js', 'module');
        $this->writeFile($this->sourcePath . DIRECTORY_SEPARATOR . 'dist/bundle.js', 'bundle');
        $this->writeFile($this->sourcePath . DIRECTORY_SEPARATOR . '.git/config', 'git');

        $workspace = new Workspace();
        $this->workspacePath = $workspace->create($this->sourcePath, ['vite' => '^8.3', 'svelte' => '^5.56']);
        $workspaceManifest = json_decode(
            file_get_contents($this->workspacePath . DIRECTORY_SEPARATOR . 'package.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(['vite' => '^8.3', 'svelte' => '^5.56'], $workspaceManifest['devDependencies']);
        self::assertSame(
            $manifest,
            json_decode(
                file_get_contents($this->sourcePath . DIRECTORY_SEPARATOR . 'package.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
        self::assertFileExists($this->workspacePath . DIRECTORY_SEPARATOR . 'src/main.js');
        self::assertFileExists($this->workspacePath . DIRECTORY_SEPARATOR . 'package-lock.json');
        self::assertDirectoryDoesNotExist($this->workspacePath . DIRECTORY_SEPARATOR . 'node_modules');
        self::assertDirectoryDoesNotExist($this->workspacePath . DIRECTORY_SEPARATOR . 'dist');
        self::assertDirectoryDoesNotExist($this->workspacePath . DIRECTORY_SEPARATOR . '.git');

        $this->scdRoot = $this->tmpPath . DIRECTORY_SEPARATOR . 'scd';
        mkdir($this->scdRoot);
        mkdir($this->workspacePath . DIRECTORY_SEPARATOR . 'node_modules');
        symlink(
            $this->workspacePath . DIRECTORY_SEPARATOR . 'node_modules',
            $this->scdRoot . DIRECTORY_SEPARATOR . 'node_modules'
        );

        $completedWorkspacePath = $this->workspacePath;
        $workspace->cleanup($completedWorkspacePath, [$this->scdRoot]);
        $this->workspacePath = null;

        self::assertDirectoryDoesNotExist($completedWorkspacePath);
        self::assertFalse(is_link($this->scdRoot . DIRECTORY_SEPARATOR . 'node_modules'));
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
