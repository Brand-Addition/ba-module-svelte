<?php

declare(strict_types=1);

namespace BA\Svelte\Model\PropResolver;

use BA\Svelte\Api\PropResolverInterface;
use Magento\Framework\View\Element\Template;

class AssetPropResolver implements PropResolverInterface
{
    public function resolve(string $propName, array $definition, Template $block): mixed
    {
        $file = $definition['file'] ?? null;
        if (!is_string($file) || $file === '') {
            return null;
        }

        return $block->getViewFileUrl($file);
    }
}
