<?php

declare(strict_types=1);

namespace BA\Svelte\Plugin;

use BA\Svelte\Model\FrontendAssetSync;
use Magento\Deploy\Service\DeployStaticContent;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Shell;
use Magento\Setup\Console\Command\DeployStaticContentCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class BuildSveltePlugin
{
    private const MODULE_NAME = 'BA_Svelte';
    private const RELATIVE_SVELTE_PATH = 'view/frontend/web/svelte-src';

    private bool $buildAttempted = false;

    public function __construct(
        private readonly ComponentRegistrar $registrar,
        private readonly DirectoryList $directoryList,
        private readonly Shell $shell,
        private readonly FrontendAssetSync $frontendAssetSync
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function afterDeploy(
        DeployStaticContent $subject,
        mixed $result,
        array $options
    ): mixed {
        $this->buildSvelteAssets(
            new ConsoleOutput(),
            $this->getScdRoots(
                is_array($options['area'] ?? null) ? $options['area'] : ['all'],
                is_array($options['exclude-area'] ?? null) ? $options['exclude-area'] : ['none'],
                is_array($options['theme'] ?? null) ? $options['theme'] : ['all'],
                is_array($options['exclude-theme'] ?? null) ? $options['exclude-theme'] : ['none'],
                is_array($options['language'] ?? null) ? $options['language'] : ['all'],
                is_array($options['exclude-language'] ?? null) ? $options['exclude-language'] : ['none']
            )
        );

        return $result;
    }

    /**
     * @param array<int, string> $scdRoots
     */
    private function buildSvelteAssets(OutputInterface $output, array $scdRoots): void
    {
        if ($this->buildAttempted) {
            return;
        }
        $this->buildAttempted = true;

        $svelteSourcePath = $this->getSvelteSourcePath();
        if ($svelteSourcePath === null) {
            $output->writeln('BA Svelte: Skipping build (svelte-src directory not found).');
            return;
        }

        if (!is_file($svelteSourcePath . DIRECTORY_SEPARATOR . 'package.json')) {
            $output->writeln('BA Svelte: Skipping build (package.json not found).');
            return;
        }

        if ($scdRoots === []) {
            $output->writeln('BA Svelte: Skipping build (no deployed frontend packages found).');
            return;
        }

        $this->assertNpmIsAvailable();
        $this->ensureNodeModules($svelteSourcePath, $output);

        foreach ($scdRoots as $scdRoot) {
            $this->frontendAssetSync->sync($scdRoot);
            $output->writeln(sprintf('BA Svelte: Building bundle for %s', $scdRoot));
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

        $sveltePath = $modulePath
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_SVELTE_PATH);

        return is_dir($sveltePath) ? $sveltePath : null;
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
    private function getScdRoots(
        array $areas,
        array $excludedAreas,
        array $themes,
        array $excludedThemes,
        array $languages,
        array $excludedLanguages
    ): array
    {
        $staticViewPath = $this->directoryList->getPath(DirectoryList::STATIC_VIEW);
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
                if (!is_string($relativePath) || $relativePath === '') {
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

    private function ensureNodeModules(string $svelteSourcePath, OutputInterface $output): void
    {
        if ($this->hasBuildDependencies($svelteSourcePath)) {
            return;
        }

        if (is_file($svelteSourcePath . DIRECTORY_SEPARATOR . 'package-lock.json')) {
            $output->writeln('BA Svelte: Installing dependencies with npm ci...');
            $this->runShellCommand(
                label: 'npm ci',
                command: 'cd %s && npm ci',
                arguments: [$svelteSourcePath],
                svelteSourcePath: $svelteSourcePath
            );
            return;
        }

        $output->writeln('BA Svelte: Installing dependencies with npm install...');
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
