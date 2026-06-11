<?php

declare(strict_types=1);

namespace BA\Svelte\Test\Unit\Block;

use BA\Svelte\Block\AbstractSvelteBlock;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;

class AbstractSvelteBlockTest extends TestCase
{
    private function createTestBlock(array $data = [], $mocks = [])
    {
        $context = $this->createMock(Context::class);

        $json = $mocks['json'] ?? $this->createMock(Json::class);
        $propResolverPool = $mocks['propResolverPool'] ?? $this->createMock(\BA\Svelte\Model\PropResolverPool::class);
        $dataNormalizer = $mocks['dataNormalizer'] ?? $this->createMock(\BA\Svelte\Model\StructuredDataNormalizer::class);
        $methodsMap = $mocks['methodsMap'] ?? $this->createMock(\Magento\Framework\Reflection\MethodsMap::class);
        $fieldNamer = $mocks['fieldNamer'] ?? $this->createMock(\Magento\Framework\Reflection\FieldNamer::class);

        $block = new class($context, $json, $propResolverPool, $dataNormalizer, $methodsMap, $fieldNamer, $data) extends AbstractSvelteBlock {
            public function publicSerializeJson(mixed $value): string
            {
                return $this->serializeJson($value);
            }

            public function publicResolveStructuredData(string $explicitKey, string $computedKey, array $reserved = []): array
            {
                return $this->resolveStructuredData($explicitKey, $computedKey, $reserved);
            }

            public function publicResolveViewModelPropTypes(): array
            {
                return $this->resolveViewModelPropTypes();
            }
        };

        return $block;
    }

    public function testSerializeJson()
    {
        $json = $this->createMock(Json::class);
        $json->expects($this->once())->method('serialize')->with(['a' => 1])->willReturn('{"a":1}');

        $block = $this->createTestBlock([], ['json' => $json]);

        $this->assertSame('{"a":1}', $block->publicSerializeJson(['a' => 1]));
    }

    public function testResolveStructuredDataIncludesImplicitAndComputedAndIgnoresReserved()
    {
        $dataNormalizer = $this->createMock(\BA\Svelte\Model\StructuredDataNormalizer::class);
        $dataNormalizer->method('normalizeValue')->willReturnMap([
            [100, 'norm100'],
            ['resolved', 'normResolved'],
        ]);
        $dataNormalizer->method('normalizeAssociativeData')->willReturn([]);

        $resolver = $this->createMock(\BA\Svelte\Api\PropResolverInterface::class);
        $resolver->method('resolve')->willReturn('resolved');

        $propResolverPool = $this->createMock(\BA\Svelte\Model\PropResolverPool::class);
        $propResolverPool->method('get')->with('r')->willReturn($resolver);

        $mocks = [
            'dataNormalizer' => $dataNormalizer,
            'propResolverPool' => $propResolverPool,
        ];

        $data = [
            'computed' => [
                'entry' => ['resolver' => 'r'],
            ],
            'x' => 100,
            'as' => 'reserved',
        ];

        $block = $this->createTestBlock($data, $mocks);

        $result = $block->publicResolveStructuredData('explicit', 'computed', []);

        $this->assertArrayHasKey('x', $result);
        $this->assertSame('norm100', $result['x']);
        $this->assertArrayHasKey('entry', $result);
        $this->assertSame('normResolved', $result['entry']);
    }

    public function testResolveViewModelDataAndPropTypes()
    {
        $methodsMap = $this->createMock(\Magento\Framework\Reflection\MethodsMap::class);
        $fieldNamer = $this->createMock(\Magento\Framework\Reflection\FieldNamer::class);

        $vm = new class implements \Magento\Framework\View\Element\Block\ArgumentInterface {
            public function getFoo()
            {
                return 'v';
            }

            public function getBar()
            {
                return null;
            }
        };

        $vmClass = $vm::class;

        $methodsMap->method('getMethodsMap')->with($vmClass)->willReturn(['getFoo' => null, 'getBar' => null]);
        $methodsMap->method('isMethodValidForDataField')->willReturnMap([
            [$vmClass, 'getFoo', true],
            [$vmClass, 'getBar', true],
        ]);
        $methodsMap->method('isMethodReturnValueRequired')->willReturnMap([
            [$vmClass, 'getFoo', true],
            [$vmClass, 'getBar', false],
        ]);
        $methodsMap->method('getMethodReturnType')->willReturnMap([
            [$vmClass, 'getFoo', 'string'],
            [$vmClass, 'getBar', 'string'],
        ]);

        $fieldNamer->method('getFieldNameForMethodName')->willReturnMap([
            ['getFoo', 'foo'],
            ['getBar', 'bar'],
        ]);

        $dataNormalizer = $this->createMock(\BA\Svelte\Model\StructuredDataNormalizer::class);
        $dataNormalizer->expects($this->once())->method('normalizeValue')->with('v')->willReturn('nv');

        $mocks = [
            'methodsMap' => $methodsMap,
            'fieldNamer' => $fieldNamer,
            'dataNormalizer' => $dataNormalizer,
        ];

        $data = ['view_model' => $vm];

        $block = $this->createTestBlock($data, $mocks);

        $result = $block->publicResolveStructuredData('explicit', 'computed', []);
        $this->assertArrayHasKey('foo', $result);
        $this->assertSame('nv', $result['foo']);

        $propTypes = $block->publicResolveViewModelPropTypes();
        $this->assertArrayHasKey('foo', $propTypes);
        $this->assertSame('string', $propTypes['foo']);
    }
}
