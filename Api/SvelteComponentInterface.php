<?php
declare(strict_types=1);

namespace BA\Svelte\Api;

interface SvelteComponentInterface
{
    public function getComponentConfig(bool $includePropTypes = false): \BA\Svelte\Model\Dto\SvelteComponentConfig;
}