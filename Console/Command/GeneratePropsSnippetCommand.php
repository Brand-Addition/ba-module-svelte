<?php

declare(strict_types=1);

namespace BA\Svelte\Console\Command;

use BA\Svelte\Model\LayoutBlockInspector;
use BA\Svelte\Model\PropsSnippetFormatter;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GeneratePropsSnippetCommand extends Command
{
    private const NAME = 'ba:svelte:props';

    private const ARGUMENT_LAYOUT_HANDLES = 'layout-handles';

    private const ARGUMENT_BLOCK_NAME = 'block-name';

    private const OPTION_STORE = 'store';

    public function __construct(
        private readonly LayoutBlockInspector $layoutBlockInspector,
        private readonly PropsSnippetFormatter $propsSnippetFormatter,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Print a typed Svelte $props() snippet for a BA_Svelte block.')
            ->addArgument(
                self::ARGUMENT_LAYOUT_HANDLES,
                InputArgument::REQUIRED,
                'Frontend layout handle, or a comma-separated list of handles to load.'
            )
            ->addArgument(
                self::ARGUMENT_BLOCK_NAME,
                InputArgument::REQUIRED,
                'Block name in layout.'
            )
            ->addOption(
                self::OPTION_STORE,
                's',
                InputOption::VALUE_REQUIRED,
                'Store code or ID to emulate while loading the layout.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $layoutHandles = $this->splitLayoutHandles((string) $input->getArgument(self::ARGUMENT_LAYOUT_HANDLES));
        $blockName = trim((string) $input->getArgument(self::ARGUMENT_BLOCK_NAME));
        $store = $input->getOption(self::OPTION_STORE);

        if ($layoutHandles === []) {
            $output->writeln('<error>You must provide at least one layout handle.</error>');

            return Cli::RETURN_FAILURE;
        }

        if ($blockName === '') {
            $output->writeln('<error>You must provide a block name.</error>');

            return Cli::RETURN_FAILURE;
        }

        try {
            $componentConfig = $this->layoutBlockInspector->getComponentConfig(
                $layoutHandles,
                $blockName,
                is_scalar($store) ? (string) $store : null
            );
            $snippet = $this->propsSnippetFormatter->format($componentConfig);
        } catch (LocalizedException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Cli::RETURN_FAILURE;
        } catch (\Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Cli::RETURN_FAILURE;
        }

        $output->writeln(OutputFormatter::escape($snippet));

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function splitLayoutHandles(string $layoutHandles): array
    {
        return array_values(array_filter(array_map(
            static fn (string $handle): string => trim($handle),
            preg_split('/[\s,]+/', $layoutHandles) ?: []
        )));
    }
}
