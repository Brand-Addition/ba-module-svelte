<?php

declare(strict_types=1);

namespace BA\Svelte\Block;

use BA\Svelte\Model\PropResolverPool;
use BA\Svelte\Model\StructuredDataNormalizer;
use BA\Svelte\Model\SvelteComponentConfig;
use Magento\Framework\Exception\LocalizedException;
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

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Json $jsonSerializer,
        private readonly PropResolverPool $propResolverPool,
        private readonly StructuredDataNormalizer $dataNormalizer,
        private readonly MethodsMap $methodsMap,
        private readonly FieldNamer $fieldNamer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function serializeJson(mixed $value): string
    {
        return (string) $this->jsonSerializer->serialize($value);
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
        $reservedDataKeys = $this->resolveReservedDataKeys(
            $explicitDataKey,
            $computedDataKey,
            $reservedDataKeys
        );

        $this->mergeData($resolvedData, $this->getExplicitStructuredData($explicitDataKey));
        $this->mergeData($resolvedData, $this->getImplicitStructuredData($reservedDataKeys));
        $this->mergeMissingData($resolvedData, $this->resolveViewModelData());
        $this->mergeData($resolvedData, $this->resolveComputedData($computedDataKey));

        return $resolvedData;
    }

    /**
     * @param array<int, string> $reservedDataKeys
     * @return array<int, string>
     */
    private function resolveReservedDataKeys(
        string $explicitDataKey,
        string $computedDataKey,
        array $reservedDataKeys
    ): array {
        return array_values(array_unique(array_merge(
            self::COMMON_RESERVED_DATA_KEYS,
            $reservedDataKeys,
            [$explicitDataKey, $computedDataKey]
        )));
    }

    /**
     * @return array<string, array<int, SvelteComponentConfig>>
     */
    protected function getComponentContainers(): array
    {
        /** @var array<string, array<int, SvelteComponentConfig>> $containers */
        $containers = [];

        foreach ($this->getChildElementNames($this->getNameInLayout()) as $childName) {
            if ($this->getLayout()->isContainer($childName)) {
                $containerAlias = $this->getLayout()->getElementAlias($childName);
                $containerName = is_string($containerAlias) && $containerAlias !== '' ? $containerAlias : 'default';
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

            $containers['default'] ??= [];
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
     * @return array<string, mixed>
     */
    private function getExplicitStructuredData(string $explicitDataKey): array
    {
        $explicitData = $this->getData($explicitDataKey);
        if (!is_array($explicitData)) {
            return [];
        }

        return $this->dataNormalizer->normalizeAssociativeData($explicitData);
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
     * @param array<int, string> $reservedDataKeys
     * @return array<string, mixed>
     */
    private function getImplicitStructuredData(array $reservedDataKeys): array
    {
        $resolvedData = [];

        foreach ($this->getData() as $key => $value) {
            if (!is_string($key) || in_array($key, $reservedDataKeys, true)) {
                continue;
            }

            $resolvedData[$key] = $this->dataNormalizer->normalizeValue($value);
        }

        return $resolvedData;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private function mergeData(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            $target[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private function mergeMissingData(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            if (array_key_exists($key, $target)) {
                continue;
            }

            $target[$key] = $value;
        }
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

            $computedData[$entryName] = $this->dataNormalizer->normalizeValue(
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

            $resolvedData[$fieldName] = $this->dataNormalizer->normalizeValue($value);
        }

        return $resolvedData;
    }

    /**
     * @return array<string, string>
     */
    protected function resolveViewModelPropTypes(): array
    {
        $viewModel = $this->getData('view_model');
        if (!$viewModel instanceof ArgumentInterface) {
            return [];
        }

        $viewModelClass = $viewModel::class;
        $propTypes = [];

        foreach (array_keys($this->methodsMap->getMethodsMap($viewModelClass)) as $methodName) {
            if (!$this->methodsMap->isMethodValidForDataField($viewModelClass, $methodName)) {
                continue;
            }

            $fieldName = $this->fieldNamer->getFieldNameForMethodName($methodName);
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            $propType = $this->methodsMap->getMethodReturnType($viewModelClass, $methodName);
            if (!is_string($propType) || $propType === '') {
                continue;
            }

            $propTypes[$fieldName] = $propType;
        }

        return $propTypes;
    }
}
