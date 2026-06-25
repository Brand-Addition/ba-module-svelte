<?php

declare(strict_types=1);

namespace BA\Svelte\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResolverListCommand extends Command
{
    private const NAME = "ba:svelte:resolver:list";

    public function __construct(
        private readonly \BA\Svelte\Model\PropResolverPool $resolverPool,
        ?string $name = null,
    ) {
        parent::__construct($name ?? self::NAME);
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)->setDescription(
            "List all registered BA_Svelte prop resolvers",
        );

        parent::configure();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $resolvers = $this->resolverPool->getResolvers();

            if (empty($resolvers)) {
                $output->writeln("<comment>No resolvers registered.</comment>");
                return Cli::RETURN_SUCCESS;
            }

            $output->writeln(
                "<info>Registered BA_Svelte Prop Resolvers:</info>",
            );
            $output->writeln(""); // spacing

            foreach ($resolvers as $name => $resolver) {
                $class = is_object($resolver)
                    ? $resolver::class
                    : (string) $resolver;

                $output->writeln(
                    sprintf(
                        "  <comment>%s</comment> => <info>%s</info>",
                        $name,
                        $class,
                    ),
                );
            }

            return Cli::RETURN_SUCCESS;
        } catch (LocalizedException $exception) {
            $output->writeln(
                sprintf(
                    "<error>%s</error>",
                    OutputFormatter::escape($exception->getMessage()),
                ),
            );

            return Cli::RETURN_FAILURE;
        } catch (\Throwable $exception) {
            $output->writeln(
                sprintf(
                    "<error>%s</error>",
                    OutputFormatter::escape($exception->getMessage()),
                ),
            );

            return Cli::RETURN_FAILURE;
        }
    }
}
