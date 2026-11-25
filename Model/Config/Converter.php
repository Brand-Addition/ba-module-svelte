<?php
declare(strict_types=1);

namespace BA\Svelte\Model\Config;

use Magento\Framework\Config\ConverterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class Converter implements ConverterInterface
{
    /**
     * @return array{dependencies: array<string, string>}
     */
    public function convert($source): array
    {
        $dependencies = [];
        foreach ($source->getElementsByTagName('dependency') as $dependency) {
            $name = trim($dependency->getAttribute('name'));
            $spec = trim($dependency->getAttribute('spec'));
            if (
                preg_match('/^(?:@[a-z0-9._~-]+\/)?[a-z0-9._~-]+$/', $name) !== 1
                || $spec === ''
            ) {
                throw new LocalizedException(
                    new Phrase('Invalid Svelte dependency. Each entry needs a valid "name" and non-empty "spec".')
                );
            }

            $dependencies[$name] = $spec;
        }

        return ['dependencies' => $dependencies];
    }
}
