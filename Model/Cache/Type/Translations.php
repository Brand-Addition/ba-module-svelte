<?php

declare(strict_types=1);

namespace BA\Svelte\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

class Translations extends TagScope
{
    public const TYPE_IDENTIFIER = 'ba_svelte_translations';
    public const CACHE_TAG = 'ba_svelte_translations';

    public function __construct(FrontendPool $frontendPool)
    {
        parent::__construct(
            $frontendPool->get(self::TYPE_IDENTIFIER),
            self::CACHE_TAG
        );
    }
}
