<?php

declare(strict_types=1);

namespace AgenDAV\Console\Command;

use AgenDAV\Davyro\WebCal\LegacyWebCalEncryptor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class WebCalEncryptLegacyUrlsCommand extends Command
{
    public function __construct(private readonly LegacyWebCalEncryptor $encryptor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('webcal:encrypt-legacy-urls')
            ->setDescription('Replace legacy clear-text WebCal URLs with encrypted state and opaque references');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->encryptor->migrate();
        $output->writeln(sprintf(
            'Legacy WebCal URL migration: migrated=%d unresolved=%d',
            $result->migrated,
            $result->unresolved,
        ));

        return $result->unresolved === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
