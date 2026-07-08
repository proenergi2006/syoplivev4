<?php

namespace App\Services\Monitoring;

class LogParser
{
    public function parseMany(array $entries): array
    {
        return array_map(
            fn(string $entry): array => $this->parse($entry),
            $entries,
        );
    }

    public function parse(string $entry): array
    {
        $lines = preg_split(
            "/\r\n|\n|\r/",
            $entry,
        ) ?: [];

        $header = (string) array_shift($lines);

        $pattern = '/^\[(.*?)\]\s+([^.]+)\.(\w+):\s?(.*)$/';

        $timestamp = null;
        $environment = null;
        $level = null;
        $message = $header;

        if (preg_match($pattern, $header, $matches)) {
            $timestamp = $matches[1];
            $environment = trim($matches[2]);
            $level = strtoupper(trim($matches[3]));
            $message = trim($matches[4]);
        }

        [$message, $context] = $this->extractContext(
            $message,
        );

        $trace = trim(
            implode("\n", $lines),
        );

        $module = $this->resolveModule(
            $message,
            $context,
        );

        $source = $this->resolveSource(
            $context,
        );

        $exceptionClass = $this->extractExceptionClass(
            $context,
            $entry,
        );

        [$filePath, $fileLine] = $this->extractErrorLocation(
            $context,
            $trace,
        );

        return [
            'id' => sha1($entry),
            'timestamp' => $timestamp,
            'environment' => $environment,
            'level' => $level,
            'source' => $source,
            'module' => $module,
            'message' => $message,
            'context' => $context,
            'exception_class' => $exceptionClass,
            'file' => $filePath,
            'line' => $fileLine,
            'has_trace' => $trace !== '',
            'trace' => $trace,
            'raw' => $entry,
        ];
    }

    /**
     * Mengambil JSON context yang berada di bagian akhir header log.
     */
    private function extractContext(
        string $message,
    ): array {
        $offset = strlen($message);

        while ($offset > 0) {
            $prefix = substr(
                $message,
                0,
                $offset,
            );

            $position = strrpos($prefix, '{');

            if ($position === false) {
                break;
            }

            $candidate = trim(
                substr($message, $position),
            );

            $decoded = json_decode(
                $candidate,
                true,
            );

            if (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
            ) {
                return [
                    trim(substr($message, 0, $position)),
                    $decoded,
                ];
            }

            $offset = $position;
        }

        return [
            trim($message),
            [],
        ];
    }

    private function resolveSource(
        array $context,
    ): string {
        $source = $context['source']
            ?? $context['channel']
            ?? 'application';

        $source = trim((string) $source);

        return $source !== ''
            ? $source
            : 'application';
    }

    private function resolveModule(
        string $message,
        array $context,
    ): ?string {
        $contextModule = trim(
            (string) ($context['module'] ?? ''),
        );

        if ($contextModule !== '') {
            return $contextModule;
        }

        if (preg_match(
            '/^\[([^\]]+)\]/',
            $message,
            $matches,
        )) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractExceptionClass(
        array $context,
        string $entry,
    ): ?string {
        $exception = (string) (
            $context['exception']
            ?? $entry
        );

        if (preg_match(
            '/\[object\]\s+\(([^(:]+)(?:\([^)]*\))?:/',
            $exception,
            $matches,
        )) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractErrorLocation(
        array $context,
        string $trace,
    ): array {
        $exception = (string) (
            $context['exception']
            ?? ''
        );

        if (preg_match(
            '/\sat\s(.+?\.php):(\d+)\)?/',
            $exception,
            $matches,
        )) {
            return [
                $matches[1],
                (int) $matches[2],
            ];
        }

        if (preg_match(
            '/#\d+\s+(.+?\.php)\((\d+)\):/',
            $trace,
            $matches,
        )) {
            return [
                $matches[1],
                (int) $matches[2],
            ];
        }

        return [null, null];
    }
}
