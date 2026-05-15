<?php

declare(strict_types=1);

namespace BA\Svelte\Model;

final class SvelteComponentConfig implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $props
     * @param array<string, string> $propTypes
     * @param array<string, array<int, self>> $containers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $component,
        public readonly array $props = [],
        public readonly array $propTypes = [],
        public readonly array $containers = []
    ) {
    }

    /**
     * @return array{
     *     name:string,
     *     component:string,
     *     props:array<string, mixed>,
     *     containers:array<string, array<int, array<string, mixed>>>
     * }
     */
    public function jsonSerialize(): array
    {
        $serializedContainers = [];

        foreach ($this->containers as $containerName => $components) {
            $serializedContainers[$containerName] = array_map(
                static fn (self $component): array => $component->jsonSerialize(),
                $components
            );
        }

        return [
            'name' => $this->name,
            'component' => $this->component,
            'props' => $this->props,
            'containers' => $serializedContainers,
        ];
    }
}
