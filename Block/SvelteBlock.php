<?php
declare(strict_types=1);
namespace BA\Svelte\Block;

class SvelteBlock extends AbstractSvelteBlock implements \BA\Svelte\Api\SvelteComponentInterface
{
    public function getComponentConfigJson(): string
    {
        return $this->serializeJson($this->getComponentConfig());
    }
}
