<?php
declare(strict_types=1);

namespace BA\Svelte\Model;

use Magento\Framework\Config\ReaderInterface;

class SvelteDependencyConfig
{
    public function __construct(
        private readonly ReaderInterface $reader
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getDependencies(string $area): array
    {
        $globalConfig = $this->reader->read('global');
        $areaConfig = $this->reader->read($area);
        $globalDependencies = $globalConfig['dependencies'] ?? [];
        $areaDependencies = $areaConfig['dependencies'] ?? [];

        return array_replace($globalDependencies, $areaDependencies);
    }
}
