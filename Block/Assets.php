<?php

declare(strict_types=1);

namespace BA\Svelte\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use BA\Svelte\Model\SvelteTranslationsProvider;

class Assets extends Template
{
    public function __construct(
        Context $context,
        private readonly SvelteTranslationsProvider $svelteTranslationsProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getStoreCode(): string
    {
        return (string) $this->_storeManager->getStore()->getCode();
    }

    public function getSvelteTranslationsJson(): string
    {
        $translations = $this->svelteTranslationsProvider->getTranslationsForCurrentStore();
        $json = json_encode(
            $translations,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return is_string($json) ? $json : '{}';
    }
}
