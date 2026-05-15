<?php

declare(strict_types=1);

namespace BA\Svelte\Model\PropResolver;

use BA\Svelte\Api\PropResolverInterface;
use Magento\Framework\View\Element\Template;

class TranslatePropResolver implements PropResolverInterface
{
    public function resolve(string $propName, array $definition, Template $block): mixed
    {
        $text = $definition['text'] ?? null;
        if (!is_string($text) || $text === '') {
            return null;
        }

        return (string)__($text);
    }
}
