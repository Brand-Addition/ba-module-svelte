<?php
declare(strict_types=1);

namespace BA\Svelte\Model;

class SvelteBuilder
{
    private const MODULE_NAME = 'BA_Svelte';
    private const RELATIVE_SVELTE_PATH = 'view/frontend/web/svelte-src';

    private bool $buildAttempted = false;

    public function __construct(
        private readonly \Magento\Framework\Component\ComponentRegistrarInterface $componentRegistrar,
        private readonly \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        private readonly \Magento\Framework\Shell $shell,
        private readonly \BA\Svelte\Model\FrontendAssetSync $frontendAssetSync,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {}

    public function configure(
        bool $buildAttempted = false,
    ): void
    {
        $this->buildAttempted = $buildAttempted;
    }

    /**
     * @param array<int, string> $scdRoots
     */
    public function buildSvelteAssets(array $scdRoots, ?\Symfony\Component\Console\Output\OutputInterface $output = null): void
    {
        if ($this->buildAttempted) {
            return;
        }
        $this->buildAttempted = true;

        $svelteSourcePath = $this->getSvelteSourcePath();
        if ($svelteSourcePath === null) {
            $this->outputOrLog($output, 'BA Svelte: Skipping build (svelte-src directory not found).');
            return;
        }

        if (!is_file($svelteSourcePath . DIRECTORY_SEPARATOR . 'package.json')) {
            $this->outputOrLog($output, 'BA Svelte: Skipping build (package.json not found).');
            return;
        }

        if ($scdRoots === []) {
            $this->outputOrLog($output, 'BA Svelte: Skipping build (no deployed frontend packages found).');
            return;
        }

        $this->assertNpmIsAvailable();
        $this->ensureNodeModules($svelteSourcePath, $output);

        foreach ($scdRoots as $scdRoot) {
            $this->frontendAssetSync->sync($scdRoot);
            $this->outputOrLog($output, sprintf('BA Svelte: Building bundle for %s', $scdRoot));
            $this->runShellCommand(
                label: 'npm run build',
                command: 'cd %s && env SCD_ROOT=%s npm run build',
                arguments: [$svelteSourcePath, $scdRoot],
                svelteSourcePath: $svelteSourcePath,
                scdRoot: $scdRoot
            );
        }
    }

    /**
     * @param array<int, string> $areas
     * @param array<int, string> $excludedAreas
     * @param array<int, string> $themes
     * @param array<int, string> $excludedThemes
     * @param array<int, string> $languages
     * @param array<int, string> $excludedLanguages
     * @return array<int, string>
     */
    public function getScdRoots(
        array $areas,
        array $excludedAreas,
        array $themes,
        array $excludedThemes,
        array $languages,
        array $excludedLanguages
    ): array
    {
        $staticViewPath = $this->directoryList->getPath(
            \Magento\Framework\App\Filesystem\DirectoryList::STATIC_VIEW
        );
        $areaPatterns = $areas === ['all'] ? ['*'] : $areas;

        $themePatterns = $themes === ['all']
            ? ['*' . DIRECTORY_SEPARATOR . '*']
            : array_map(
                static fn (string $theme): string => str_replace('/', DIRECTORY_SEPARATOR, $theme),
                $themes
            );
        $languagePatterns = $languages === ['all'] ? ['*'] : $languages;

        $roots = [];
        foreach ($areaPatterns as $areaPattern) {
            foreach ($themePatterns as $themePattern) {
                foreach ($languagePatterns as $languagePattern) {
                    $matches = glob(
                        $staticViewPath
                        . DIRECTORY_SEPARATOR
                        . $areaPattern
                        . DIRECTORY_SEPARATOR
                        . $themePattern
                        . DIRECTORY_SEPARATOR
                        . $languagePattern
                    );

                    if (!is_array($matches)) {
                        continue;
                    }

                    array_push($roots, ...$matches);
                }
            }
        }

        if ($roots === []) {
            return [];
        }

        $roots = array_values(array_filter(
            $roots,
            function (string $path) use ($excludedAreas, $excludedThemes, $excludedLanguages, $staticViewPath): bool {
                if (!is_dir($path) || !$this->hasScdEntryPoint($path)) {
                    return false;
                }

                $relativePath = substr(
                    $path,
                    strlen($staticViewPath . DIRECTORY_SEPARATOR)
                );
                if ($relativePath === '') {
                    return false;
                }

                $segments = explode(DIRECTORY_SEPARATOR, $relativePath);
                if (count($segments) < 4) {
                    return false;
                }

                $area = $segments[0];
                $theme = $segments[1] . '/' . $segments[2];
                $language = $segments[3];

                return !in_array($area, $excludedAreas, true)
                    && !in_array($theme, $excludedThemes, true)
                    && !in_array($language, $excludedLanguages, true);
            }
        ));

        $roots = array_values(array_unique($roots));
        sort($roots);

        return $roots;
    }

    private function getSvelteSourcePath(): ?string
    {
        $modulePath = $this->componentRegistrar->getPath(
            \Magento\Framework\Component\ComponentRegistrar::MODULE,
            self::MODULE_NAME
        );
        if (!is_string($modulePath) || $modulePath === '') {
            return null;
        }

        $sveltePath = $modulePath
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_SVELTE_PATH);

        return is_dir($sveltePath) ? $sveltePath : null;
    }

    private function ensureNodeModules(
        string $svelteSourcePath,
        \Symfony\Component\Console\Output\OutputInterface $output,
    ): void
    {
        if ($this->hasBuildDependencies($svelteSourcePath)) {
            return;
        }

        if (is_file($svelteSourcePath . DIRECTORY_SEPARATOR . 'package-lock.json')) {
            $this->outputOrLog($output, 'BA Svelte: Installing dependencies with npm ci...');
            $this->runShellCommand(
                label: 'npm ci',
                command: 'cd %s && npm ci',
                arguments: [$svelteSourcePath],
                svelteSourcePath: $svelteSourcePath
            );
            return;
        }

        $this->outputOrLog($output, 'BA Svelte: Installing dependencies with npm install...');
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

    private function hasScdEntryPoint(string $path): bool
    {
        return is_file(
            $path
            . DIRECTORY_SEPARATOR
            . self::MODULE_NAME
            . DIRECTORY_SEPARATOR
            . 'svelte-src'
            . DIRECTORY_SEPARATOR
            . 'src'
            . DIRECTORY_SEPARATOR
            . 'main.js'
        );
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
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
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

            throw new \Magento\Framework\Exception\LocalizedException(
                new \Magento\Framework\Phrase(implode(PHP_EOL, $details)),
                $exception
            );
        }
    }

    private function extractShellOutput(\Magento\Framework\Exception\LocalizedException $exception): string
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

    private function outputOrLog(?\Symfony\Component\Console\Output\OutputInterface $output, string $message): void
    {
        if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutput) {
            $output->writeln($message);
        } else {
            $this->logger->info($message);
        }
    }
}
