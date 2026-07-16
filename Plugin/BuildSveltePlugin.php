<?php

declare(strict_types=1);

namespace BA\Svelte\Plugin;

use BA\Svelte\Model\FrontendAssetSync;
use Magento\Deploy\Service\DeployStaticContent;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Shell;
use Magento\Setup\Console\Command\DeployStaticContentCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class BuildSveltePlugin
{
    private const MODULE_NAME = 'BA_Svelte';
    private const RELATIVE_SVELTE_PATH = 'view/frontend/web/svelte-src';

    private bool $buildAttempted = false;

    public function __construct(
        private readonly \BA\Svelte\Model\SvelteBuilder $svelteBuilder
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function afterDeploy(
        DeployStaticContent $subject,
        mixed $result,
        array $options
    ): mixed {
        $this->svelteBuilder->buildSvelteAssets(
            output: new ConsoleOutput(),
            scdRoots: $this->svelteBuilder->getScdRoots(
                areas: is_array($options['area'] ?? null) ? $options['area'] : ['all'],
                excludedAreas: is_array($options['exclude-area'] ?? null) ? $options['exclude-area'] : ['none'],
                themes: is_array($options['theme'] ?? null) ? $options['theme'] : ['all'],
                excludedThemes: is_array($options['exclude-theme'] ?? null) ? $options['exclude-theme'] : ['none'],
                languages: is_array($options['language'] ?? null) ? $options['language'] : ['all'],
                excludedLanguages: is_array($options['exclude-language'] ?? null) ? $options['exclude-language'] : ['none']
            )
        );

        return $result;
    }

}
