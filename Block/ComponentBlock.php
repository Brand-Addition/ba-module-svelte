<?php

declare(strict_types=1);

namespace BA\Svelte\Block;

use BA\Svelte\Model\SvelteComponentConfig;

class ComponentBlock extends AbstractSvelteBlock
{
    private const RESERVED_DATA_KEYS = [
        'props',
        'svelte_component',
    ];

    public function getComponentConfig(bool $includePropTypes = false): SvelteComponentConfig
    {
        return new SvelteComponentConfig(
            name: $this->getNameInLayout(),
            component: (string)$this->getData('svelte_component'),
            props: $this->resolveStructuredData(
                explicitDataKey: 'props',
                computedDataKey: 'computed_props',
                reservedDataKeys: self::RESERVED_DATA_KEYS
            ),
            propTypes: $includePropTypes ? $this->resolveViewModelPropTypes() : [],
            containers: $this->getComponentContainers()
        );
    }
}
