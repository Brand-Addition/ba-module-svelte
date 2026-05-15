<?php

declare(strict_types=1);

namespace BA\Svelte\Api;

use Magento\Framework\View\Element\Template;

interface PropResolverInterface
{
    /**
     * @param array<string, mixed> $definition
     */
    public function resolve(string $propName, array $definition, Template $block): mixed;
}
