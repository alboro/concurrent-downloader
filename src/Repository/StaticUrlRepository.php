<?php

namespace App\Repository;

final class StaticUrlRepository implements UrlRepositoryInterface
{
    public function __construct(private array $urls = [
        'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_60sec.mp4',
    ]) {
    }

    public function getUrls(): array
    {
        return $this->urls;
    }
}
