<?php
declare(strict_types=1);

namespace BA\Svelte\Plugin;

use Magento\Deploy\Service\DeployStaticContent;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Shell;
use Magento\Setup\Console\Command\DeployStaticContentCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildSveltePlugin
{
    private const MODULE_NAME = 'BA_Svelte';
    private const RELATIVE_SVELTE_PATH = 'view/frontend/web/svelte-src';

    private bool $buildAttempted = false;

    public function __construct(
        private readonly ComponentRegistrar $registrar,
        private readonly DirectoryList $directoryList,
        private readonly Shell $shell
    ) {
    }

    public function afterRun(
        DeployStaticContentCommand $subject,
        int $result,
        InputInterface $input,
        OutputInterface $output
    ): int {
        $this->buildSvelteAssets(
            static function (string $message) use ($output): void {
                $output->writeln($message);
            }
        );

        return $result;
    }

    public function afterDeploy(
        DeployStaticContent $subject,
        mixed $result,
        array $options
    ): mixed {
        $this->buildSvelteAssets([$this, 'writeToStdout']);

        return $result;
    }

    /**
     * @param callable(string):void $writeLine
     */
    private function buildSvelteAssets(callable $writeLine): void
    {
        if ($this->buildAttempted) {
            return;
        }
        $this->buildAttempted = true;

        $svelteSourcePath = $this->getSvelteSourcePath();
        if ($svelteSourcePath === null) {
            $writeLine('BA Svelte: Skipping build (svelte-src directory not found).');
            return;
        }

        if (!is_file($svelteSourcePath . DIRECTORY_SEPARATOR . 'package.json')) {
            $writeLine('BA Svelte: Skipping build (package.json not found).');
            return;
        }

        $scdRoots = $this->getScdRoots();
        if ($scdRoots === []) {
            $writeLine('BA Svelte: Skipping build (no deployed frontend packages found).');
            return;
        }

        $this->assertNpmIsAvailable();
        $this->ensureNodeModules($svelteSourcePath, $writeLine);

        foreach ($scdRoots as $scdRoot) {
            $this->syncFrontendAssets($scdRoot);
            $writeLine(sprintf('BA Svelte: Building bundle for %s', $scdRoot));
            $this->runShellCommand(
                label: 'npm run build',
                command: 'cd %s && env SCD_ROOT=%s npm run build',
                arguments: [$svelteSourcePath, $scdRoot],
                svelteSourcePath: $svelteSourcePath,
                scdRoot: $scdRoot
            );
        }
    }

    private function getSvelteSourcePath(): ?string
    {
        $modulePath = $this->registrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
        if (!is_string($modulePath) || $modulePath === '') {
            return null;
        }

        $sveltePath = $modulePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_SVELTE_PATH);

        return is_dir($sveltePath) ? $sveltePath : null;
    }

    /**
     * @return array<int, string>
     */
    private function getScdRoots(): array
    {
        $frontendStaticPath = $this->directoryList->getPath(DirectoryList::STATIC_VIEW)
            . DIRECTORY_SEPARATOR
            . 'frontend';
        $roots = glob($frontendStaticPath . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*');
        if (!is_array($roots)) {
            return [];
        }

        $roots = array_values(array_filter(
            $roots,
            fn (string $path): bool => is_dir($path)
                && is_file($path . DIRECTORY_SEPARATOR . self::MODULE_NAME . DIRECTORY_SEPARATOR . 'svelte-src' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'main.js')
        ));

        sort($roots);

        return $roots;
    }

    /**
     * @param callable(string):void $writeLine
     */
    private function ensureNodeModules(string $svelteSourcePath, callable $writeLine): void
    {
        if ($this->hasBuildDependencies($svelteSourcePath)) {
            return;
        }

        if (is_file($svelteSourcePath . DIRECTORY_SEPARATOR . 'package-lock.json')) {
            $writeLine('BA Svelte: Installing dependencies with npm ci...');
            $this->runShellCommand(
                label: 'npm ci',
                command: 'cd %s && npm ci',
                arguments: [$svelteSourcePath],
                svelteSourcePath: $svelteSourcePath
            );
            return;
        }

        $writeLine('BA Svelte: Installing dependencies with npm install...');
        $this->runShellCommand(
            label: 'npm install',
            command: 'cd %s && npm install',
            arguments: [$svelteSourcePath],
            svelteSourcePath: $svelteSourcePath
        );
    }

    private function hasBuildDependencies(string $svelteSourcePath): bool
    {
        $nodeModulesPath = $svelteSourcePath . DIRECTORY_SEPARATOR . 'node_modules';

        return is_dir($nodeModulesPath)
            && is_file($nodeModulesPath . DIRECTORY_SEPARATOR . '.bin' . DIRECTORY_SEPARATOR . 'vite')
            && is_dir($nodeModulesPath . DIRECTORY_SEPARATOR . 'svelte');
    }

    private function assertNpmIsAvailable(): void
    {
        $this->runShellCommand(
            label: 'npm availability check',
            command: 'command -v npm >/dev/null 2>&1',
            arguments: [],
            svelteSourcePath: $this->getSvelteSourcePath()
        );
    }

    private function writeToStdout(string $message): void
    {
        if (!defined('STDOUT')) {
            return;
        }

        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function syncFrontendAssets(string $scdRoot): void
    {
        foreach ($this->registrar->getPaths(ComponentRegistrar::MODULE) as $moduleName => $modulePath) {
            if (!is_string($moduleName) || !is_string($modulePath) || $moduleName === '' || $modulePath === '') {
                continue;
            }

            $sourcePath = $modulePath . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'web';
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

    private function copyDirectoryContents(string $sourcePath, string $targetPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathName();
            if ($this->shouldSkipSyncedPath($relativePath)) {
                continue;
            }

            $destination = $targetPath . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0775, true);
                }
                continue;
            }

            $destinationDirectory = dirname($destination);
            if (!is_dir($destinationDirectory)) {
                mkdir($destinationDirectory, 0775, true);
            }

            copy($item->getPathname(), $destination);
        }
    }

    private function shouldSkipSyncedPath(string $relativePath): bool
    {
        $segments = preg_split('#[\\\\/]#', $relativePath) ?: [];

        return in_array('node_modules', $segments, true)
            || in_array('dist', $segments, true);
    }

    /**
     * @param array<int, string> $arguments
     */
    private function runShellCommand(
        string $label,
        string $command,
        array $arguments,
        ?string $svelteSourcePath = null,
        ?string $scdRoot = null
    ): string {
        try {
            return $this->shell->execute($command, $arguments);
        } catch (LocalizedException $exception) {
            $details = [
                sprintf('BA Svelte build step failed: %s', $label),
            ];

            if (is_string($svelteSourcePath) && $svelteSourcePath !== '') {
                $details[] = sprintf('Svelte source: %s', $svelteSourcePath);
            }

            if (is_string($scdRoot) && $scdRoot !== '') {
                $details[] = sprintf('SCD root: %s', $scdRoot);
            }

            $output = $this->extractShellOutput($exception);
            if ($output !== '') {
                $details[] = 'Command output:';
                $details[] = $output;
            }

            throw new LocalizedException(
                new Phrase(implode(PHP_EOL, $details)),
                $exception
            );
        }
    }

    private function extractShellOutput(LocalizedException $exception): string
    {
        $output = '';
        $previous = $exception->getPrevious();
        if ($previous instanceof \Throwable) {
            $output = trim($previous->getMessage());
        }

        if ($output === '') {
            $output = trim($exception->getMessage());
        }

        if ($output === '') {
            return '';
        }

        $lines = preg_split('/\R/', $output) ?: [];
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => rtrim($line), $lines),
            static fn (string $line): bool => $line !== ''
        ));

        if (count($lines) > 40) {
            $lines = array_slice($lines, -40);
            array_unshift($lines, '... truncated to last 40 lines ...');
        }

        $output = implode(PHP_EOL, $lines);

        if (strlen($output) > 8000) {
            $output = '... truncated to last 8000 characters ...' . PHP_EOL . substr($output, -8000);
        }

        return $output;
    }
}
