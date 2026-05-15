<?php
declare(strict_types=1);

namespace BA\Svelte\Block;

class SvelteBlock extends ComponentBlock
{
    public function getComponentConfigJson(): string
    {
        return $this->serializeJson($this->getComponentConfig());
    }
}
