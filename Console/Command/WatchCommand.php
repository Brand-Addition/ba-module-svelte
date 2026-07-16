<?php

declare(strict_types=1);

namespace BA\Svelte\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WatchCommand extends Command
{
    private const WATCH_EXTENSIONS = [
        'js',
        'ts',
        'svelte',
    ];
    private const DEBOUNCE_SECONDS = 0.5;

    public function __construct(
        private readonly \BA\Svelte\Model\SvelteBuilder $svelteBuilder,
        private readonly \Magento\Framework\App\Filesystem\DirectoryList $directoryList
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ba:svelte:watch');
        $this->setDescription(
            'Watches file changes in app/code and app/design and recompiles Svelte bundles.'
        );

        parent::configure();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        // 1. check if static-content has been compiled
        // if not, tell user to compile it first
        // 2. Watch for .js, .ts and .svelte file changes
        // 3. see if its a symlink in preprocessed and processed folders,
        // if not, delete files in those directories to create symlinks
        // 4. re-run svelte build and show all output to console.
        // goto 2.
        try {
            $scdRoots = $this->svelteBuilder->getScdRoots(
                areas: ['frontend'],
                excludedAreas: [],
                themes: ['all'],
                excludedThemes: [],
                languages: ['all'],
                excludedLanguages: []
            );

            if ($scdRoots === []) {
                throw new LocalizedException(
                    __(
                        'No deployed frontend static content was found. Run setup:static-content:deploy first.'
                    )
                );
            }

            $output->writeln('<info>BA Svelte Watcher Starting...</info>');

            foreach ($scdRoots as $root) {
                $output->writeln(sprintf('  %s', $root));
            }

            if (extension_loaded('inotify')) {
                $output->writeln(
                    '<info>Using inotify filesystem watching.</info>'
                );

                $this->watchWithInotify($scdRoots, $output);

                return Command::SUCCESS;
            }

            $output->writeln(
                '<comment>ext-inotify not installed. Falling back to polling.</comment>'
            );

            $this->watchWithPolling($scdRoots, $output);

            return Command::SUCCESS;
        } catch (LocalizedException $e) {
            $output->writeln(
                sprintf(
                    '<error>%s</error>',
                    $e->getMessage()
                )
            );

            return Command::FAILURE;
        }
    }

    /**
     * @param string[] $scdRoots
     */
    private function watchWithPolling(
        array $scdRoots,
        OutputInterface $output
    ): void {
        $state = $this->buildFileState();

        while (true) { // @phpstan-ignore-line
            clearstatcache();

            $newState = $this->buildFileState();

            if ($newState !== $state) {
                $this->handleChange(
                    $scdRoots,
                    $output
                );

                $state = $newState;
            }

            sleep(1);
        }
    }

    /**
     * @param string[] $scdRoots
     */
    private function watchWithInotify(
        array $scdRoots,
        OutputInterface $output
    ): void {
        $fd = inotify_init();

        stream_set_blocking($fd, true);

        $watchDirectories = $this->getWatchRoots();

        $watchDescriptors = [];

        foreach ($watchDirectories as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $directories = $this->collectDirectories($root);

            foreach ($directories as $directory) {
                $watch = inotify_add_watch(
                    $fd,
                    $directory,
                    IN_CREATE
                    | IN_MODIFY
                    | IN_DELETE
                    | IN_MOVED_TO
                    | IN_MOVED_FROM
                );

                if ($watch !== false) {
                    $watchDescriptors[] = $watch;
                }
            }
        }

        $lastBuildTime = 0.0;

        while (true) { // @phpstan-ignore-line
            $events = inotify_read($fd);

            if ($events === false) {
                continue;
            }

            $shouldBuild = false;

            foreach ($events as $event) {
                $name = (string)($event['name']);

                if ($name === '') {
                    continue;
                }

                $extension = strtolower(
                    pathinfo($name, PATHINFO_EXTENSION)
                );

                if (
                    in_array(
                        $extension,
                        self::WATCH_EXTENSIONS,
                        true
                    )
                ) {
                    $shouldBuild = true;
                    break;
                }
            }

            if (!$shouldBuild) {
                continue;
            }

            $now = microtime(true);

            if (
                ($now - $lastBuildTime)
                < self::DEBOUNCE_SECONDS
            ) {
                continue;
            }

            $lastBuildTime = $now;

            $this->handleChange(
                $scdRoots,
                $output
            );
        }
    }

    /**
     * @param string[] $scdRoots
     */
    private function handleChange(
        array $scdRoots,
        OutputInterface $output
    ): void {
        $output->writeln('');
        $output->writeln(
            sprintf(
                '<comment>[%s] Change detected</comment>',
                date('H:i:s')
            )
        );

        foreach ($scdRoots as $scdRoot) {
            $this->ensureSymlinkedAssets(
                $scdRoot,
                $output
            );
        }

        $this->svelteBuilder->configure(
            buildAttempted: false
        );

        $this->svelteBuilder->buildSvelteAssets(
            scdRoots: $scdRoots,
            output: $output
        );

        $output->writeln(
            sprintf(
                '<info>[%s] Build complete.</info>',
                date('H:i:s')
            )
        );
    }

    /**
     * @return array<string,int>
     */
    private function buildFileState(): array
    {
        $state = [];

        foreach ([
            $this->directoryList->getRoot() . '/app/code',
            $this->directoryList->getRoot() . '/app/design',
        ] as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $extension = strtolower(
                    $file->getExtension()
                );

                if (!in_array(
                    $extension,
                    self::WATCH_EXTENSIONS,
                    true
                )) {
                    continue;
                }

                $state[$file->getPathname()] = $file->getMTime();
            }
        }

        ksort($state);

        return $state;
    }

    /**
     * @return string[]
     */
    private function collectDirectories(
        string $root
    ): array {
        $directories = [$root];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $directories[] = $item->getPathname();
            }
        }

        return $directories;
    }

    private function ensureSymlinkedAssets(
        string $scdRoot,
        OutputInterface $output
    ): void {
        $paths = [
            $this->directoryList->getPath(
                DirectoryList::TMP_MATERIALIZATION_DIR
            ),
            $scdRoot,
        ];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $this->removeNonSymlinkFiles(
                $path,
                $output
            );
        }
    }

    private function removeNonSymlinkFiles(
        string $path,
        OutputInterface $output
    ): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $pathname = $item->getPathname();

            $extension = strtolower(
                pathinfo($pathname, PATHINFO_EXTENSION)
            );

            if (!in_array(
                $extension,
                self::WATCH_EXTENSIONS,
                true
            )) {
                continue;
            }

            if (is_link($pathname)) {
                continue;
            }

            if ($item->isFile()) {
                @unlink($pathname);

                $output->writeln(
                    sprintf(
                        'Removed generated file: %s',
                        $pathname
                    )
                );
            }
        }
    }

    private function getWatchRoots(): array
    {
        $roots = [];

        foreach ([
            $this->directoryList->getRoot() . '/app/code',
            $this->directoryList->getRoot() . '/app/design',
        ] as $basePath) {
            if (!is_dir($basePath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $basePath,
                    \FilesystemIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if (!$item->isDir()) {
                    continue;
                }

                if (
                    str_ends_with(
                        str_replace('\\', '/', $item->getPathname()),
                        '/view/frontend/web'
                    )
                ) {
                    $roots[] = $item->getPathname();
                }
            }
        }

        sort($roots);

        return array_unique($roots);
    }
}
