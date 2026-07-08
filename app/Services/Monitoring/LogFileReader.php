<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class LogFileReader
{
    private const MAX_ENTRIES = 10000;
    private const MAX_SCAN_BYTES = 104857600; // 100 MB
    private const CHUNK_SIZE = 65536; // 64 KB

    protected string $path;

    public function __construct()
    {
        $this->path = storage_path('logs/laravel.log');
    }

    /**
     * Compatibility method untuk mengambil log terbaru.
     */
    public function tail(int $limit = 100): array
    {
        return $this->read(
            limit: $limit,
            stopBefore: null,
        );
    }

    /**
     * Membaca log dari bagian paling akhir file.
     *
     * Jika $stopBefore diberikan, pembacaan dihentikan ketika log yang
     * ditemukan sudah lebih lama daripada tanggal tersebut.
     */
    public function read(
        int $limit = self::MAX_ENTRIES,
        ?DateTimeInterface $stopBefore = null,
    ): array {
        $limit = max(
            1,
            min($limit, self::MAX_ENTRIES),
        );

        if (
            !file_exists($this->path)
            || !is_readable($this->path)
        ) {
            return [];
        }

        $fp = fopen($this->path, 'rb');

        if (!$fp) {
            return [];
        }

        fseek($fp, 0, SEEK_END);

        $fileSize = ftell($fp);

        if (!$fileSize) {
            fclose($fp);

            return [];
        }

        $stopBeforeTimestamp = $stopBefore?->getTimestamp();

        $entries = [];
        $currentEntry = [];
        $tail = '';
        $position = $fileSize;
        $scannedBytes = 0;
        $stopReading = false;

        while (
            $position > 0
            && count($entries) < $limit
            && $scannedBytes < self::MAX_SCAN_BYTES
        ) {
            $readSize = min(
                self::CHUNK_SIZE,
                $position,
                self::MAX_SCAN_BYTES - $scannedBytes,
            );

            if ($readSize <= 0) {
                break;
            }

            $position -= $readSize;
            $scannedBytes += $readSize;

            fseek($fp, $position);

            $text = fread($fp, $readSize) . $tail;
            $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];

            /*
            |------------------------------------------------------------------
            | Elemen pertama dapat terpotong pada batas kiri chunk.
            |------------------------------------------------------------------
            */
            $tail = (string) array_shift($lines);

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = rtrim((string) $lines[$i]);

                array_unshift($currentEntry, $line);

                if (!$this->isLogHeader($line)) {
                    continue;
                }

                $entry = trim(
                    implode("\n", $currentEntry),
                );

                $currentEntry = [];

                if ($entry === '') {
                    continue;
                }

                $entryTimestamp = $this->extractTimestamp(
                    $entry,
                );

                if (
                    $stopBeforeTimestamp !== null
                    && $entryTimestamp !== null
                    && $entryTimestamp < $stopBeforeTimestamp
                ) {
                    $stopReading = true;

                    break 2;
                }

                $entries[] = $entry;

                if (count($entries) >= $limit) {
                    break 2;
                }
            }
        }

        /*
        |----------------------------------------------------------------------
        | Flush entry paling awal apabila pembacaan mencapai awal file.
        |----------------------------------------------------------------------
        */
        if (
            !$stopReading
            && count($entries) < $limit
        ) {
            if ($tail !== '') {
                array_unshift(
                    $currentEntry,
                    rtrim($tail),
                );
            }

            if (!empty($currentEntry)) {
                $entry = trim(
                    implode("\n", $currentEntry),
                );

                if ($entry !== '') {
                    $entryTimestamp = $this->extractTimestamp(
                        $entry,
                    );

                    if (
                        $stopBeforeTimestamp === null
                        || $entryTimestamp === null
                        || $entryTimestamp >= $stopBeforeTimestamp
                    ) {
                        $entries[] = $entry;
                    }
                }
            }
        }

        fclose($fp);

        /*
        |----------------------------------------------------------------------
        | Urutan sudah terbaru ke terlama karena file dibaca dari belakang.
        |----------------------------------------------------------------------
        */
        return $entries;
    }

    public function getFileName(): string
    {
        return basename($this->path);
    }

    private function isLogHeader(string $line): bool
    {
        return preg_match(
            '/^\[\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\]/',
            $line,
        ) === 1;
    }

    private function extractTimestamp(
        string $entry,
    ): ?int {
        if (!preg_match(
            '/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/',
            $entry,
            $matches,
        )) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $matches[1],
                config('app.timezone'),
            )->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
