<?php

namespace App\Command;

use App\Repository\UrlRepositoryInterface;
use App\Service\FileDownloader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadCommand extends Command
{
    protected static $defaultName = 'app:download';

    public function __construct(
        private readonly UrlRepositoryInterface $urlRepository,
        private readonly FileDownloader $downloader,
    ){
        parent::__construct(static::$defaultName);
    }

    protected function configure(): void
    {
        $this->setDescription('Concurrent downloading of files from configured URLs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Files download process was started...');
        $this->downloader->concurrentDownloadWithRetry(
            $this->urlRepository->getUrls()
        );
        $output->writeln('Files download process has finished.');

        return Command::SUCCESS;
    }
}
