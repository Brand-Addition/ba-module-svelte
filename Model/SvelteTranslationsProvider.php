<?php

declare(strict_types=1);

namespace BA\Svelte\Model;

use BA\Svelte\Model\Cache\Type\Translations;
use FilesystemIterator;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\StoreManagerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class SvelteTranslationsProvider
{
    private const FRONTEND_AREA = 'frontend';
    private const SUPPORTED_EXTENSIONS = ['js', 'svelte', 'ts'];
    private const STATIC_SCAN_PATH_SUFFIXES = [
        'js',
        'svelte',
        'svelte-src/src',
    ];
    private const SKIPPED_DIRECTORY_NAMES = ['dist', 'node_modules'];
    private const TRANSLATION_PATTERNS = [
        '/(?:^|[^\w$.])(?:_|window\.baTranslate)\(\s*\'((?:\\\\.|[^\'\\\\])*)\'(?=\s*(?:,|\)))/m',
        '/(?:^|[^\w$.])(?:_|window\.baTranslate)\(\s*"((?:\\\\.|[^"\\\\])*)"(?=\s*(?:,|\)))/m',
    ];

    /**
     * @var array<string, list<string>>
     */
    private static array $phraseCache = [];

    /**
     * @var array<string, array<string, string>>
     */
    private static array $translationsCache = [];

    public function __construct(
        private readonly Translations $cache,
        private readonly DirectoryList $directoryList,
        private readonly DesignInterface $design,
        private readonly ResolverInterface $localeResolver,
        private readonly Json $serializer,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getTranslationsForCurrentStore(): array
    {
        $store = $this->storeManager->getStore();
        $cacheKey = implode('|', [
            (string) $store->getId(),
            (string) $store->getCode(),
            (string) $this->localeResolver->getLocale(),
        ]);

        if (isset(self::$translationsCache[$cacheKey])) {
            return self::$translationsCache[$cacheKey];
        }

        if ($cacheKey !== '') {
            $cachedTranslations = $this->loadCachedArray($cacheKey);
            if ($cachedTranslations !== null) {
                /** @var array<string, string> $cachedTranslations */
                self::$translationsCache[$cacheKey] = $cachedTranslations;

                return $cachedTranslations;
            }
        }

        $translations = [];
        foreach ($this->getPhrases() as $phrase) {
            $translatedPhrase = (string) __($phrase);
            if ($translatedPhrase === $phrase) {
                continue;
            }

            $translations[$phrase] = $translatedPhrase;
        }

        self::$translationsCache[$cacheKey] = $translations;
        if ($cacheKey !== '') {
            $this->saveCachedArray($cacheKey, $translations);
        }

        return $translations;
    }

    /**
     * @return list<string>
     */
    private function getPhrases(): array
    {
        $scanRoots = $this->getStaticContentScanRoots();
        $cacheKey = implode('|', $scanRoots);

        if (isset(self::$phraseCache[$cacheKey])) {
            return self::$phraseCache[$cacheKey];
        }

        if ($cacheKey !== '') {
            $cachedPhrases = $this->loadCachedArray($cacheKey);
            if ($cachedPhrases !== null) {
                $values = [];
                foreach ($cachedPhrases as $item) {
                    $values[] = (string) $item;
                }
                self::$phraseCache[$cacheKey] = $values;

                return self::$phraseCache[$cacheKey];
            }
        }

        $phrases = [];
        foreach ($scanRoots as $scanRoot) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $scanRoot,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
                )
            );

            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $pathname = $item->getPathname();
                if ($this->shouldSkipPath($pathname) || !$this->shouldScanFile($pathname)) {
                    continue;
                }

                $source = file_get_contents($pathname);
                if (!is_string($source) || $source === '') {
                    continue;
                }

                foreach ($this->extractPhrasesFromSource($source) as $phrase) {
                    $phrases[$phrase] = true;
                }
            }
        }

        $phraseList = array_keys($phrases);
        sort($phraseList);
        self::$phraseCache[$cacheKey] = $phraseList;
        if ($cacheKey !== '') {
            $this->saveCachedArray($cacheKey, self::$phraseCache[$cacheKey]);
        }

        return self::$phraseCache[$cacheKey];
    }

    /**
     * @return array<string|int, mixed>|null
     */
    private function loadCachedArray(string $cacheKey): ?array
    {
        $cachedValue = $this->cache->load($cacheKey);
        if (!is_string($cachedValue)) {
            return null;
        }

        try {
            $decodedValue = $this->serializer->unserialize($cachedValue);
        } catch (Throwable) {
            return null;
        }

        return is_array($decodedValue) ? $decodedValue : null;
    }

    /**
     * @param array<string|int, mixed> $value
     */
    private function saveCachedArray(string $cacheKey, array $value): void
    {
        $value = $this->serializer->serialize($value);
        if (is_bool($value)) {
            return;
        }

        $this->cache->save(
            $value,
            $cacheKey,
            [],
            null
        );
    }

    /**
     * @return list<string>
     */
    private function getStaticContentScanRoots(): array
    {
        $storefrontRoot = $this->getCurrentStorefrontStaticRoot();
        if ($storefrontRoot === null) {
            return [];
        }

        $scanRoots = [];
        foreach ($this->buildScanRootsForBasePath($storefrontRoot) as $scanRoot) {
            $scanRoots[$scanRoot] = true;
        }

        $moduleRoots = glob($storefrontRoot . DIRECTORY_SEPARATOR . '*_*', GLOB_ONLYDIR);
        foreach (is_array($moduleRoots) ? $moduleRoots : [] as $moduleRoot) {
            foreach ($this->buildScanRootsForBasePath($moduleRoot) as $scanRoot) {
                $scanRoots[$scanRoot] = true;
            }
        }

        $scanRootList = array_keys($scanRoots);
        sort($scanRootList);

        return $scanRootList;
    }

    private function getCurrentStorefrontStaticRoot(): ?string
    {
        $theme = $this->design->getDesignTheme();
        $themePath = trim($theme->getThemePath());

        if ($themePath === '') {
            $themePath = trim($theme->getCode());
        }

        if ($themePath === '') {
            return null;
        }

        $storefrontRoot = $this->directoryList->getPath(DirectoryList::STATIC_VIEW)
            . DIRECTORY_SEPARATOR
            . self::FRONTEND_AREA
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $themePath)
            . DIRECTORY_SEPARATOR
            . $this->localeResolver->getLocale();

        return $this->normaliseDirectoryPath($storefrontRoot);
    }

    /**
     * @return list<string>
     */
    private function buildScanRootsForBasePath(string $basePath): array
    {
        $scanRoots = [];

        foreach (self::STATIC_SCAN_PATH_SUFFIXES as $suffix) {
            $scanRoot = $this->normaliseDirectoryPath(
                $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $suffix)
            );

            if ($scanRoot !== null) {
                $scanRoots[] = $scanRoot;
            }
        }

        return $scanRoots;
    }

    private function normaliseDirectoryPath(string $path): ?string
    {
        if (!is_dir($path)) {
            return null;
        }

        $resolvedPath = realpath($path);

        return is_string($resolvedPath) ? $resolvedPath : null;
    }

    private function shouldScanFile(string $pathname): bool
    {
        $extension = pathinfo($pathname, PATHINFO_EXTENSION);

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    private function shouldSkipPath(string $pathname): bool
    {
        $segments = preg_split('#[\\\\/]#', $pathname) ?: [];

        foreach (self::SKIPPED_DIRECTORY_NAMES as $directoryName) {
            if (in_array($directoryName, $segments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractPhrasesFromSource(string $source): array
    {
        $phrases = [];

        foreach (self::TRANSLATION_PATTERNS as $pattern) {
            $matches = [];
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $rawPhrase) {
                $phrase = stripcslashes($rawPhrase);
                if ($phrase === '') {
                    continue;
                }

                $phrases[$phrase] = true;
            }
        }

        return array_keys($phrases);
    }
}
