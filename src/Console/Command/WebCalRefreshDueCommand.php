<?php

declare(strict_types=1);

namespace AgenDAV\Console\Command;

use AgenDAV\Davyro\WebCal\WebCalRefreshWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class WebCalRefreshDueCommand extends Command
{
    public function __construct(private readonly WebCalRefreshWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('webcal:refresh-due')
            ->setDescription('Refresh due, active WebCal subscriptions')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum feeds per run', '100');
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

        $result = $this->worker->refreshDue(limit: (int) $limit);
        $output->writeln(sprintf(
            'WebCal refresh: processed=%d current=%d stale=%d error=%d',
            $result->processed,
            $result->current,
            $result->stale,
            $result->error,
        ));

        return Command::SUCCESS;
    }
}
