<?php
declare(strict_types=1);

namespace BA\Svelte\Block;

use BA\Svelte\Model\PropResolverPool;
use BA\Svelte\Model\SvelteComponentConfig;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Reflection\FieldNamer;
use Magento\Framework\Reflection\MethodsMap;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Element\Block\ArgumentInterface;

abstract class AbstractSvelteBlock extends Template
{
    private const COMMON_RESERVED_DATA_KEYS = [
        'as',
        'cache_lifetime',
        'cache_tags',
        'class',
        'data',
        'js_layout',
        'jsLayout',
        'layout',
        'module_name',
        'name',
        'name_in_layout',
        'template',
        'type',
        'view_model',
    ];

    public function __construct(
        Context $context,
        private readonly Json $jsonSerializer,
        private readonly PropResolverPool $propResolverPool,
        private readonly MethodsMap $methodsMap,
        private readonly FieldNamer $fieldNamer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function serializeJson(mixed $value): string
    {
        return $this->jsonSerializer->serialize($value);
    }

    /**
     * @param array<int, string> $reservedDataKeys
     * @return array<string, mixed>
     */
    protected function resolveStructuredData(
        string $explicitDataKey,
        string $computedDataKey,
        array $reservedDataKeys = []
    ): array {
        $resolvedData = [];
        $reservedDataKeys = array_values(array_unique(array_merge(
            self::COMMON_RESERVED_DATA_KEYS,
            $reservedDataKeys,
            [$explicitDataKey, $computedDataKey]
        )));

        $explicitData = $this->getData($explicitDataKey);
        if (is_array($explicitData)) {
            foreach ($explicitData as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $resolvedData[$key] = $this->normalizeValue($value);
            }
        }

        foreach ($this->getData() as $key => $value) {
            if (!is_string($key) || in_array($key, $reservedDataKeys, true)) {
                continue;
            }

            $resolvedData[$key] = $this->normalizeValue($value);
        }

        foreach ($this->resolveViewModelData() as $key => $value) {
            if (array_key_exists($key, $resolvedData)) {
                continue;
            }

            $resolvedData[$key] = $value;
        }

        foreach ($this->resolveComputedData($computedDataKey) as $key => $value) {
            $resolvedData[$key] = $value;
        }

        return $resolvedData;
    }

    /**
     * @return array<string, array<int, SvelteComponentConfig>>
     */
    protected function getComponentContainers(): array
    {
        $containers = [];

        foreach ($this->getChildElementNames($this->getNameInLayout()) as $childName) {
            if ($this->getLayout()->isContainer($childName)) {
                $containerName = $this->getLayout()->getElementAlias($childName) ?: 'default';
                $containerItems = $this->collectContainerComponents($childName);

                if ($containerItems === []) {
                    continue;
                }

                $containers[$containerName] = array_merge($containers[$containerName] ?? [], $containerItems);
                continue;
            }

            $childBlock = $this->getLayout()->getBlock($childName);
            if (!$childBlock instanceof ComponentBlock) {
                continue;
            }

            $containers['default'][] = $childBlock->getComponentConfig();
        }

        return $containers;
    }

    /**
     * @return array<int, SvelteComponentConfig>
     */
    private function collectContainerComponents(string $parentElementName): array
    {
        $components = [];

        foreach ($this->getChildElementNames($parentElementName) as $childName) {
            if ($this->getLayout()->isContainer($childName)) {
                $components = array_merge($components, $this->collectContainerComponents($childName));
                continue;
            }

            $childBlock = $this->getLayout()->getBlock($childName);
            if (!$childBlock instanceof ComponentBlock) {
                continue;
            }

            $components[] = $childBlock->getComponentConfig();
        }

        return $components;
    }

    /**
     * @return array<int, string>
     */
    private function getChildElementNames(string $parentElementName): array
    {
        try {
            $childNames = $this->getLayout()->getChildNames($parentElementName);
        } catch (LocalizedException) {
            return [];
        }

        return array_values(array_filter(
            $childNames,
            static fn (mixed $childName): bool => is_string($childName) && $childName !== ''
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveComputedData(string $computedDataKey): array
    {
        $computedData = [];
        $definitions = $this->getData($computedDataKey);
        if (!is_array($definitions)) {
            return $computedData;
        }

        foreach ($definitions as $entryName => $definition) {
            if (!is_string($entryName) || !is_array($definition)) {
                continue;
            }

            $resolverCode = $definition['resolver'] ?? null;
            if (!is_string($resolverCode) || $resolverCode === '') {
                continue;
            }

            $resolver = $this->propResolverPool->get($resolverCode);
            if ($resolver === null) {
                continue;
            }

            $computedData[$entryName] = $this->normalizeValue(
                $resolver->resolve($entryName, $definition, $this)
            );
        }

        return $computedData;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveViewModelData(): array
    {
        $viewModel = $this->getData('view_model');
        if (!$viewModel instanceof ArgumentInterface) {
            return [];
        }

        $viewModelClass = $viewModel::class;
        $resolvedData = [];

        foreach (array_keys($this->methodsMap->getMethodsMap($viewModelClass)) as $methodName) {
            if (!$this->methodsMap->isMethodValidForDataField($viewModelClass, $methodName)) {
                continue;
            }

            $fieldName = $this->fieldNamer->getFieldNameForMethodName($methodName);
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            $value = $viewModel->{$methodName}();
            if ($value === null && !$this->methodsMap->isMethodReturnValueRequired($viewModelClass, $methodName)) {
                continue;
            }

            $resolvedData[$fieldName] = $this->normalizeValue($value);
        }

        return $resolvedData;
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeValue($item);
            }

            return $value;
        }

        if ($value instanceof Phrase) {
            return (string)$value;
        }

        if ($value instanceof DataObject) {
            return $this->normalizeValue($value->getData());
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize());
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            /** @var mixed $arrayValue */
            $arrayValue = $value->toArray();
            return $this->normalizeValue($arrayValue);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return null;
    }
}
