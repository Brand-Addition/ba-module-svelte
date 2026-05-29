<?php

declare(strict_types=1);

namespace BA\Svelte\Test\Unit\Model;

use BA\Svelte\Model\Cache\Type\Translations;
use BA\Svelte\Model\SvelteTranslationsProvider;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SvelteTranslationsProviderTest extends TestCase
{
    private Translations $cache;
    private Json $serializer;
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->getMockBuilder(Translations::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'save'])
            ->getMock();
        $this->serializer = new Json();
        $this->tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ba-svelte-translations-' . uniqid('', true);
        mkdir($this->tmpPath, 0777, true);
        $this->resetProviderCaches();
    }

    protected function tearDown(): void
    {
        $this->resetProviderCaches();
        $this->removeDirectory($this->tmpPath);

        parent::tearDown();
    }

    public function testGetPhrasesScansCurrentStaticStorefrontAndModuleRoots(): void
    {
        $storefrontRoot = $this->createStorefrontRoot('BA/theme', 'en_GB');

        $this->writeFile($storefrontRoot . '/js/theme.js', "_('Theme phrase');");
        $this->writeFile($storefrontRoot . '/Vendor_Module/js/component.js', "window.baTranslate(\"Vendor phrase\");");
        $this->writeFile($storefrontRoot . '/Magento_Catalog/svelte/card.svelte', "{_('Catalog phrase')}");
        $this->writeFile($storefrontRoot . '/jquery/widget.js', "_('Ignored root lib phrase');");
        $this->writeFile($storefrontRoot . '/Vendor_Module/node_modules/ignored.js', "_('Ignored node_modules phrase');");

        $provider = $this->createProvider('BA/theme', 'en_GB');

        $phrases = $this->invokePrivateMethod($provider, 'getPhrases');

        $this->assertSame(
            ['Catalog phrase', 'Theme phrase', 'Vendor phrase'],
            $phrases
        );
    }

    public function testPhraseCacheIsScopedToStaticStorefrontRoots(): void
    {
        $firstRoot = $this->createStorefrontRoot('BA/theme', 'en_GB');
        $secondRoot = $this->createStorefrontRoot('BA/theme', 'en_US');

        $this->writeFile($firstRoot . '/Vendor_Module/js/component.js', "_('English GB phrase');");
        $this->writeFile($secondRoot . '/Vendor_Module/js/component.js', "_('English US phrase');");

        $firstProvider = $this->createProvider('BA/theme', 'en_GB');
        $secondProvider = $this->createProvider('BA/theme', 'en_US');

        $this->assertSame(
            ['English GB phrase'],
            $this->invokePrivateMethod($firstProvider, 'getPhrases')
        );
        $this->assertSame(
            ['English US phrase'],
            $this->invokePrivateMethod($secondProvider, 'getPhrases')
        );
    }

    public function testGetPhrasesLoadsFromMagentoCacheUsingStaticScanRootKey(): void
    {
        $storefrontRoot = $this->createStorefrontRoot('BA/theme', 'en_GB');
        mkdir($storefrontRoot . '/js', 0777, true);
        mkdir($storefrontRoot . '/Vendor_Module/js', 0777, true);

        $cacheKey = implode('|', [
            realpath($storefrontRoot . '/Vendor_Module/js'),
            realpath($storefrontRoot . '/js'),
        ]);
        $cachedPhrases = ['Cached phrase'];

        $this->cache->expects($this->once())
            ->method('load')
            ->with($cacheKey)
            ->willReturn($this->serializer->serialize($cachedPhrases));
        $this->cache->expects($this->never())
            ->method('save');

        $provider = $this->createProvider('BA/theme', 'en_GB');

        $this->assertSame(
            $cachedPhrases,
            $this->invokePrivateMethod($provider, 'getPhrases')
        );
    }

    public function testGetTranslationsForCurrentStoreUsesMagentoCacheWithoutExpiry(): void
    {
        $cachedTranslations = ['View' => 'Voir'];

        $this->cache->expects($this->once())
            ->method('load')
            ->with('1|default|en_GB')
            ->willReturn(false);
        $this->cache->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (string $data, string $key, array $tags, ?int $lifetime): bool {
                $this->assertSame($this->serializer->serialize([]), $data);
                $this->assertSame('1|default|en_GB', $key);
                $this->assertSame([], $tags);
                $this->assertNull($lifetime);

                return true;
            });

        $provider = $this->createProvider('BA/theme', 'en_GB');

        $this->assertSame([], $provider->getTranslationsForCurrentStore());
        $this->resetProviderCaches();

        $this->cache = $this->getMockBuilder(Translations::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'save'])
            ->getMock();
        $this->cache->expects($this->once())
            ->method('load')
            ->with('1|default|en_GB')
            ->willReturn($this->serializer->serialize($cachedTranslations));
        $this->cache->expects($this->never())
            ->method('save');

        $provider = $this->createProvider('BA/theme', 'en_GB');

        $this->assertSame($cachedTranslations, $provider->getTranslationsForCurrentStore());
    }

    private function createProvider(string $themePath, string $locale): SvelteTranslationsProvider
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')
            ->with(DirectoryList::STATIC_VIEW)
            ->willReturn($this->tmpPath);

        $theme = $this->createMock(ThemeInterface::class);
        $theme->method('getThemePath')->willReturn($themePath);
        $theme->method('getCode')->willReturn($themePath);

        $design = $this->createMock(DesignInterface::class);
        $design->method('getDesignTheme')->willReturn($theme);

        $localeResolver = $this->createMock(ResolverInterface::class);
        $localeResolver->method('getLocale')->willReturn($locale);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('default');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new SvelteTranslationsProvider(
            $this->cache,
            $directoryList,
            $design,
            $localeResolver,
            $this->serializer,
            $storeManager
        );
    }

    private function createStorefrontRoot(string $themePath, string $locale): string
    {
        $storefrontRoot = $this->tmpPath
            . DIRECTORY_SEPARATOR
            . 'frontend'
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $themePath)
            . DIRECTORY_SEPARATOR
            . $locale;

        mkdir($storefrontRoot, 0777, true);

        return $storefrontRoot;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @return mixed
     */
    private function invokePrivateMethod(object $object, string $method): mixed
    {
        $reflection = new ReflectionClass($object);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);

        return $methodReflection->invoke($object);
    }

    private function resetProviderCaches(): void
    {
        $reflection = new ReflectionClass(SvelteTranslationsProvider::class);

        foreach (['phraseCache', 'translationsCache'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, []);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->removeDirectory($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
