<?php
declare(strict_types=1);

namespace BA\Svelte\Model\PropResolver;

use BA\Svelte\Api\PropResolverInterface;
use Magento\Framework\View\Element\Template;

class UrlPropResolver implements PropResolverInterface
{
    public function resolve(string $propName, array $definition, Template $block): mixed
    {
        $path = $definition['path'] ?? $definition['route'] ?? null;
        if (!is_string($path) || $path === '') {
            return null;
        }

        return $block->getUrl($path);
    }
}
