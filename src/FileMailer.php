<?php

declare(strict_types=1);

namespace DineReserve;

use RuntimeException;

/**
 * Development / CI mailer: appends every message to a file so the delivery
 * path can be inspected without SMTP credentials (REQ-1.4).
 */
final class FileMailer implements Mailer
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function send(string $to, string $subject, string $body): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the mail log directory: ' . $directory);
        }

        $entry = sprintf(
            "=== %s ===\nTo: %s\nSubject: %s\n\n%s\n\n",
            date('Y-m-d\TH:i:sP'),
            $to,
            $subject,
            $body
        );

        if (file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Cannot append to the mail log: ' . $this->path);
        }
    }
}
