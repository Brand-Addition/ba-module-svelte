<?php

declare(strict_types=1);

namespace BA\Svelte\Block;

class Assets extends \Magento\Framework\View\Element\Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \BA\Svelte\Model\SvelteTranslationsProvider $svelteTranslationsProvider,
        private readonly \Magento\Framework\Locale\ResolverInterface $localeResolver,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getStoreCode(): string
    {
        return (string) $this->_storeManager->getStore()->getCode();
    }

    public function getCurrentLocale(): string
    {
        return (string) $this->localeResolver->getLocale();
    }

    public function getCurrentCurrency(): string
    {
        return (string) $this->_storeManager
            ->getStore()
            ->getCurrentCurrencyCode(); // @phpstan-ignore-line
    }

    public function getSvelteTranslationsJson(): string
    {
        $translations = $this->svelteTranslationsProvider->getTranslationsForCurrentStore();
        $json = json_encode(
            $translations,
            JSON_HEX_TAG |
                JSON_HEX_AMP |
                JSON_HEX_APOS |
                JSON_HEX_QUOT |
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE,
        );

        return is_string($json) ? $json : "{}";
    }
}
