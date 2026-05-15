<?php

declare(strict_types=1);

namespace BA\Svelte\Model;

use BA\Svelte\Api\PropResolverInterface;

class PropResolverPool
{
    /**
     * @param array<string, PropResolverInterface> $resolvers
     */
    public function __construct(
        private readonly array $resolvers = []
    ) {
    }

    public function get(string $code): ?PropResolverInterface
    {
        $resolver = $this->resolvers[$code] ?? null;

        return $resolver instanceof PropResolverInterface ? $resolver : null;
    }
}
