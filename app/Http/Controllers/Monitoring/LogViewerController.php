<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\LogFileReader;
use App\Services\Monitoring\LogParser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LogViewerController extends Controller
{
    private const MAX_SCAN_ENTRIES = 10000;

    private const LEVELS = [
        'EMERGENCY',
        'ALERT',
        'CRITICAL',
        'ERROR',
        'WARNING',
        'NOTICE',
        'INFO',
        'DEBUG',
    ];

    private const DEFAULT_LEVELS = [
        'ERROR',
        'WARNING',
        'INFO',
    ];

    public function __construct(
        protected LogFileReader $fileReader,
        protected LogParser $parser,
    ) {}

    public function index(
        Request $request,
    ): JsonResponse {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        [$period, $startAt, $endAt] = $this->resolvePeriod(
            $request,
        );

        $levels = $this->resolveLevels(
            $request,
        );

        $environment = trim(
            (string) $request->input('environment', ''),
        );

        $source = trim(
            (string) $request->input('source', ''),
        );

        $module = trim(
            (string) $request->input('module', ''),
        );

        $search = trim(
            (string) $request->input('search', ''),
        );

        $hasTrace = $this->resolveNullableBoolean(
            $request,
            'has_trace',
        );

        $page = max(
            1,
            $request->integer('page', 1),
        );

        $perPage = max(
            10,
            min(
                $request->integer(
                    'per_page',
                    $request->integer('limit', 50),
                ),
                100,
            ),
        );

        $entries = $this->fileReader->read(
            limit: self::MAX_SCAN_ENTRIES,
            stopBefore: $startAt,
        );

        $parsedLogs = collect(
            $this->parser->parseMany($entries),
        );

        /*
        |----------------------------------------------------------------------
        | Batasi sesuai periode terlebih dahulu.
        |----------------------------------------------------------------------
        */
        $periodLogs = $parsedLogs
            ->filter(function (array $item) use (
                $startAt,
                $endAt,
            ): bool {
                $timestamp = $this->parseTimestamp(
                    $item['timestamp'] ?? null,
                );

                if (!$timestamp) {
                    return false;
                }

                return $timestamp->betweenIncluded(
                    $startAt,
                    $endAt,
                );
            })
            ->values();

        $filterOptions = $this->buildFilterOptions(
            $periodLogs,
        );

        $filteredLogs = $periodLogs
            ->filter(function (array $item) use ($levels): bool {
                return in_array(
                    strtoupper((string) ($item['level'] ?? '')),
                    $levels,
                    true,
                );
            })
            ->when(
                $environment !== '',
                fn(Collection $logs): Collection => $logs
                    ->filter(function (array $item) use ($environment): bool {
                        return strcasecmp(
                            (string) ($item['environment'] ?? ''),
                            $environment,
                        ) === 0;
                    }),
            )
            ->when(
                $source !== '',
                fn(Collection $logs): Collection => $logs
                    ->filter(function (array $item) use ($source): bool {
                        return strcasecmp(
                            (string) ($item['source'] ?? ''),
                            $source,
                        ) === 0;
                    }),
            )
            ->when(
                $module !== '',
                fn(Collection $logs): Collection => $logs
                    ->filter(function (array $item) use ($module): bool {
                        return str_contains(
                            mb_strtolower(
                                (string) ($item['module'] ?? ''),
                            ),
                            mb_strtolower($module),
                        );
                    }),
            )
            ->when(
                $hasTrace !== null,
                fn(Collection $logs): Collection => $logs
                    ->filter(function (array $item) use ($hasTrace): bool {
                        return (bool) ($item['has_trace'] ?? false)
                            === $hasTrace;
                    }),
            )
            ->when(
                $search !== '',
                fn(Collection $logs): Collection => $logs
                    ->filter(function (array $item) use ($search): bool {
                        $needle = mb_strtolower($search);

                        $haystack = mb_strtolower(
                            implode(' ', [
                                (string) ($item['message'] ?? ''),
                                (string) ($item['module'] ?? ''),
                                (string) ($item['source'] ?? ''),
                                (string) ($item['exception_class'] ?? ''),
                                (string) ($item['file'] ?? ''),
                                (string) ($item['trace'] ?? ''),
                                json_encode(
                                    $item['context'] ?? [],
                                    JSON_UNESCAPED_UNICODE
                                        | JSON_UNESCAPED_SLASHES,
                                ) ?: '',
                            ]),
                        );

                        return str_contains(
                            $haystack,
                            $needle,
                        );
                    }),
            )
            ->sortByDesc('timestamp')
            ->values();

        $total = $filteredLogs->count();
        $lastPage = max(
            1,
            (int) ceil($total / $perPage),
        );

        $page = min($page, $lastPage);

        $items = $filteredLogs
            ->slice(
                ($page - 1) * $perPage,
                $perPage,
            )
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Data system log berhasil dimuat.',
            'data' => $items,
            'summary' => $this->buildSummary(
                $filteredLogs,
            ),
            'filters' => [
                'period' => $period,
                'start_at' => $startAt->toDateTimeString(),
                'end_at' => $endAt->toDateTimeString(),
                'levels' => $levels,
                'environment' => $environment ?: null,
                'source' => $source ?: null,
                'module' => $module ?: null,
                'search' => $search ?: null,
                'has_trace' => $hasTrace,
            ],
            'filter_options' => $filterOptions,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'count' => $items->count(),
                'scanned_count' => count($entries),
                'scan_limit' => self::MAX_SCAN_ENTRIES,
                'scan_limited'
                => count($entries) >= self::MAX_SCAN_ENTRIES,
                'source_file' => $this->fileReader->getFileName(),
            ],
        ], 200);
    }

    private function resolvePeriod(
        Request $request,
    ): array {
        $timezone = config('app.timezone');
        $now = CarbonImmutable::now($timezone);

        $period = strtolower(
            trim(
                (string) $request->input(
                    'period',
                    'last_7_days',
                ),
            ),
        );

        return match ($period) {
            'today' => [
                'today',
                $now->startOfDay(),
                $now->endOfDay(),
            ],

            'last_24_hours', '24_hours', '24h' => [
                'last_24_hours',
                $now->subHours(24),
                $now,
            ],

            'this_week', 'week' => [
                'this_week',
                $now->startOfWeek(CarbonInterface::MONDAY),
                $now,
            ],

            'last_week' => [
                'last_week',
                $now
                    ->subWeek()
                    ->startOfWeek(CarbonInterface::MONDAY),
                $now
                    ->subWeek()
                    ->endOfWeek(CarbonInterface::SUNDAY),
            ],

            'last_30_days', '30_days', '30d' => [
                'last_30_days',
                $now->subDays(29)->startOfDay(),
                $now,
            ],

            'custom' => $this->resolveCustomPeriod(
                $request,
                $timezone,
            ),

            default => [
                'last_7_days',
                $now->subDays(6)->startOfDay(),
                $now,
            ],
        };
    }

    private function resolveCustomPeriod(
        Request $request,
        string $timezone,
    ): array {
        $startDate = trim(
            (string) $request->input('start_date', ''),
        );

        $endDate = trim(
            (string) $request->input('end_date', ''),
        );

        if ($startDate === '' || $endDate === '') {
            throw ValidationException::withMessages([
                'period' => [
                    'Tanggal awal dan tanggal akhir wajib diisi untuk periode custom.',
                ],
            ]);
        }

        try {
            $startAt = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $startDate,
                $timezone,
            )->startOfDay();

            $endAt = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $endDate,
                $timezone,
            )->endOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'period' => [
                    'Format tanggal harus menggunakan YYYY-MM-DD.',
                ],
            ]);
        }

        if ($startAt->greaterThan($endAt)) {
            throw ValidationException::withMessages([
                'period' => [
                    'Tanggal awal tidak boleh lebih besar dari tanggal akhir.',
                ],
            ]);
        }

        return [
            'custom',
            $startAt,
            $endAt,
        ];
    }

    private function resolveLevels(
        Request $request,
    ): array {
        $rawLevels = $request->input(
            'levels',
            $request->input('level', []),
        );

        if (is_string($rawLevels)) {
            $rawLevels = preg_split(
                '/[,\s]+/',
                $rawLevels,
            ) ?: [];
        }

        if (!is_array($rawLevels)) {
            $rawLevels = [$rawLevels];
        }

        $levels = collect($rawLevels)
            ->map(
                fn($level) => strtoupper(
                    trim((string) $level),
                ),
            )
            ->filter()
            ->unique()
            ->values();

        if ($levels->contains('ALL')) {
            return self::LEVELS;
        }

        $levels = $levels
            ->filter(
                fn(string $level): bool => in_array(
                    $level,
                    self::LEVELS,
                    true,
                ),
            )
            ->values()
            ->all();

        return !empty($levels)
            ? $levels
            : self::DEFAULT_LEVELS;
    }

    private function resolveNullableBoolean(
        Request $request,
        string $key,
    ): ?bool {
        if (!$request->has($key)) {
            return null;
        }

        return filter_var(
            $request->input($key),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );
    }

    private function parseTimestamp(
        mixed $timestamp,
    ): ?CarbonImmutable {
        if (!$timestamp) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $timestamp,
                config('app.timezone'),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildSummary(
        Collection $logs,
    ): array {
        $counts = $logs->countBy(
            fn(array $item): string => strtoupper(
                (string) ($item['level'] ?? 'UNKNOWN'),
            ),
        );

        return [
            'total' => $logs->count(),
            'emergency' => (int) ($counts['EMERGENCY'] ?? 0),
            'alert' => (int) ($counts['ALERT'] ?? 0),
            'critical' => (int) ($counts['CRITICAL'] ?? 0),
            'error' => (int) ($counts['ERROR'] ?? 0),
            'warning' => (int) ($counts['WARNING'] ?? 0),
            'notice' => (int) ($counts['NOTICE'] ?? 0),
            'info' => (int) ($counts['INFO'] ?? 0),
            'debug' => (int) ($counts['DEBUG'] ?? 0),
        ];
    }

    private function buildFilterOptions(
        Collection $logs,
    ): array {
        $levelCounts = $logs->countBy(
            fn(array $item): string => strtoupper(
                (string) ($item['level'] ?? 'UNKNOWN'),
            ),
        );

        return [
            'periods' => [
                ['title' => 'Hari Ini', 'value' => 'today'],
                ['title' => '24 Jam Terakhir', 'value' => 'last_24_hours'],
                ['title' => '7 Hari Terakhir', 'value' => 'last_7_days'],
                ['title' => 'Minggu Ini', 'value' => 'this_week'],
                ['title' => 'Minggu Lalu', 'value' => 'last_week'],
                ['title' => '30 Hari Terakhir', 'value' => 'last_30_days'],
                ['title' => 'Rentang Tanggal', 'value' => 'custom'],
            ],
            'levels' => collect(self::LEVELS)
                ->map(function (string $level) use ($levelCounts): array {
                    return [
                        'title' => ucfirst(strtolower($level)),
                        'value' => $level,
                        'count' => (int) ($levelCounts[$level] ?? 0),
                    ];
                })
                ->values()
                ->all(),
            'environments' => $logs
                ->pluck('environment')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'sources' => $logs
                ->pluck('source')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'modules' => $logs
                ->pluck('module')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
