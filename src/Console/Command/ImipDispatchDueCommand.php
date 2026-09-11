<?php

declare(strict_types=1);

namespace AgenDAV\Console\Command;

use AgenDAV\Davyro\Outbox\ImipDispatchOutbox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImipDispatchDueCommand extends Command
{
    public function __construct(private readonly ImipDispatchOutbox $outbox)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('imip:dispatch-due')
            ->setDescription('Deliver durable pending calendar e-mail messages to Davyro Mail')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum messages per run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if ($limit === false) {
            $output->writeln('<error>--limit must be an integer between 1 and 1000</error>');

            return Command::INVALID;
        }
        $result = $this->outbox->dispatchDue((int) $limit);
        $output->writeln(sprintf(
            'iMIP dispatch: processed=%d sent=%d pending=%d failed=%d',
            $result['processed'],
            $result['sent'],
            $result['pending'],
            $result['failed'],
        ));

        return Command::SUCCESS;
    }
}
