<?php
declare(strict_types=1);

namespace BA\Svelte\Model\Config;

use Magento\Framework\Config\SchemaLocatorInterface;

class SchemaLocator implements SchemaLocatorInterface
{
    public function getSchema(): string
    {
        return __DIR__ . '/../../etc/svelte.xsd';
    }

    public function getPerFileSchema(): string
    {
        return __DIR__ . '/../../etc/svelte.xsd';
    }
}
