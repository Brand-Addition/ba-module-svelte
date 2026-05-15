<?php

declare(strict_types=1);

namespace BA\Svelte\Block;

use Magento\Customer\Block\Account\SortLinkInterface;

class SvelteLink extends SvelteBlock implements SortLinkInterface
{
    /**
     * @return int|string|null
     */
    public function getSortOrder()
    {
        return $this->getData(self::SORT_ORDER);
    }
}
