<?php

namespace App\Factory;

use Amp\ByteStream\WritableResourceStream;
use function Amp\ByteStream\getStdout;

final class ByteStreamFactory
{
    public static function getStdout(): WritableResourceStream
    {
        return getStdout();
    }
}
