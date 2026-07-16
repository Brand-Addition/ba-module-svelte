<?php

declare(strict_types=1);

namespace BA\Svelte\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WatchCommand extends Command
{
    public function __construct(
        private readonly \BA\Svelte\Model\SvelteBuilder $svelteBuilder
    )
    {}

    protected function configure(): void
    {
        $this->setName('ba:svelte:watch');
        $this->setDescription('Watches file changes in app/code & app/design and recompiles when needed.');
        // place option/argument setup here

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = Command::SUCCESS;

        // 1. check if static-content has been compiled
        // if not, tell user to compile it first
        // 2. Watch for .js, .ts and .svelte file changes
        // 3. see if its a symlink in preprocessed and processed folders,
        // if not, delete files in those directories to create symlinks
        // 4. re-run svelte build and show all output to console.
        // goto 2.

        try {
            if (rand(0, 1)) {
               throw new LocalizedException(__('An error occurred.'));
            }
        } catch (LocalizedException $e) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                $e->getMessage()
            ));
            $exitCode = Command::FAILURE;
        }

        return $exitCode;
    }

    // private function
}
