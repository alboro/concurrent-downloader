<?php

namespace App\Service;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\File;
use Amp\File\FilesystemException;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\File\Driver\ParallelFilesystemDriver;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use function Amp\File\filesystem;
use function Amp\Future\await;
use function Amp\async;
use function Amp\delay;

final class FileDownloader
{
    private const MAX_ATTEMPTS = 20; // many-many attempts
    private HttpClient $httpClient;

    public function __construct(
        private LoggerInterface $logger,
        private readonly string $tempDir,
        private readonly string $completedDir,
    ) {
        // Initialize parallel file system
        filesystem(new ParallelFilesystemDriver());

        $this->httpClient = (new HttpClientBuilder())
            ->followRedirects()
            ->build();
    }

    public function concurrentDownloadWithRetry(array $urls): void
    {
        $tasks = [];
        foreach ($urls as $url) {
            $tasks[] = async(fn () => $this->processUrlWithRetry($url));
        }

        // Wait for all downloads to complete
        await($tasks);
    }

    private function tempFileName(string $url): string
    {
        $fileName = $this->filename($url);

        return "{$this->tempDir}/{$fileName}.part";
    }

    private function completedFileName(string $url): string
    {
        $fileName = $this->filename($url);

        return "{$this->completedDir}/{$fileName}";
    }

    private function filename(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    private function processUrlWithRetry(string $url): void
    {
        $completedFile = $this->completedFileName($url);

        if (file_exists($completedFile)) {
            $tempFile = $this->tempFileName($url);
            if (file_exists($tempFile)) {
                File\deleteFile($tempFile);
            }
            $this->logger->info(
                sprintf('No need to download: %s. %s already exists.', $url, $completedFile)
            );
            return;
        }

        for ($currentAttemptNumber = 0; $currentAttemptNumber < self::MAX_ATTEMPTS; ++$currentAttemptNumber) {
            try {
                $this->logger->info(
                    sprintf('Attempting to download: %s (Attempt %d).', $url, $currentAttemptNumber)
                );
                $this->processUrl($url);
            } catch (Throwable $throwable) {
                $this->logger->error(
                    sprintf(
                        'Error downloading %s: exception of class %s occurred with code %d and message %s.',
                        $url,
                        get_class($throwable),
                        $throwable->getCode(),
                        $throwable->getMessage()
                    )
                );
                if ($throwable instanceof FilesystemException) {
                    $this->logger->error(
                        sprintf('Unexpected error with %s. Aborting.', $url)
                    );
                    return;
                }
                if ($currentAttemptNumber >= self::MAX_ATTEMPTS) {
                    $this->logger->error(
                        sprintf('Max retries reached for %s. Aborting.', $url)
                    );
                    return;
                }
                // Wait before retrying
                delay(2);
            }
        }
    }

    private function createRequest(string $url): Request
    {
        $tempFile = $this->tempFileName($url);
        $offset = is_file($tempFile) ? filesize($tempFile) : 0;

        $request = new Request($url);
        $request->setBodySizeLimit(0); // Remove body size limit
        $request->setTransferTimeout(15);
        if ($offset > 0) {
            $request->setHeader('Range', "bytes={$offset}-");
        }

        return $request;
    }

    private function moveToCompleted(string $url): void
    {
        File\move(
            $this->tempFileName($url),
            $this->completedFileName($url)
        );
    }

    /**
     * @throws FilesystemException|HttpException|StreamException|ClosedException|RuntimeException
     */
    private function processUrl(string $url): void
    {
        $response = $this->httpClient->request(
            $this->createRequest($url)
        );
        $tempFile = $this->tempFileName($url);

        if ($response->getStatus() === 416 && is_file($tempFile)) {
            $this->logger->info(
                sprintf('File already fully downloaded: %s', $url)
            );
            $this->moveToCompleted($url);

            return;
        }
        if ($response->getStatus() === 200 || $response->getStatus() === 206) {
            // Open file for appending
            $file = File\openFile($tempFile, 'a');
            $downloadedSize = 0;

            while (($chunk = $response->getBody()->read()) !== null) {
                $downloadedSize += strlen($chunk);
                $file->write($chunk); // Write chunk to file
            }
            $this->logger->info(
                sprintf(
                    'File downloaded: %s from %s, size: %s bytes',
                    $this->completedFileName($url),
                    $url,
                    $downloadedSize
                )
            );
            $file->close();
            $file->onClose(fn () => $this->moveToCompleted($url));

            return;
        }
        throw new RuntimeException(
            sprintf('HTTP error: %s, body: %s', $response->getStatus(), $response->getBody())
        );
    }
}
