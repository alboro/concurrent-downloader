<?php

namespace App\Logger;

use Amp\File;
use Amp\File\Driver\ParallelFilesystemDriver;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use function Amp\async;
use function Amp\File\filesystem;

class AsyncFileHandler extends AbstractProcessingHandler
{
    private string $logFile;

    public function __construct(string $logFile, int $level = Level::Debug->value, bool $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->logFile = $logFile;

        filesystem(new ParallelFilesystemDriver());

    }

    protected function write(array|LogRecord $record): void
    {
        async(function () use ($record) {
            $formatted = $this->formatRecord($record);

            // Open the file in append mode
            $file = File\openFile($this->logFile, 'a');
            $file->write($formatted . PHP_EOL);
            $file->close();
        });
    }

    private function formatRecord(array|LogRecord $record): string
    {
        if ($record instanceof LogRecord) {
            $datetime = $record->datetime->format('Y-m-d H:i:s');
            $level = strtoupper($record->level->getName());
            $message = $record->message;
        } else {
            $datetime = $record['datetime']->format('Y-m-d H:i:s');
            $level = strtoupper($record['level_name']);
            $message = $record['message'];
        }

        return sprintf("[%s] %s: %s", $datetime, $level, $message);
    }
}
