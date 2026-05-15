<?php

declare(strict_types=1);

namespace BA\Svelte\Block;

use Magento\Framework\View\Element\Template;

class Assets extends Template
{
    public function getStoreCode(): string
    {
        return (string) $this->_storeManager->getStore()->getCode();
    }
}
