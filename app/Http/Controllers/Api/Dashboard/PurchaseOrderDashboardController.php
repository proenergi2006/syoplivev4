<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\PurchaseOrderDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderDashboardController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderDashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
         * Project menggunakan permission custom, bukan Spatie.
         */
        if (!$user->hasPermission('dashboard.po.view')) {
            abort(
                403,
                'Anda tidak memiliki akses ke dashboard Purchase Order.',
            );
        }

        $scope = $user->getPermissionScope(
            'dashboard.po.view',
        );

        /*
         * Dashboard management saat ini ditujukan untuk scope ALL.
         * Scope lain dapat kita implementasikan setelah struktur User
         * dan field cabang/departemen user dikonfirmasi.
         */
        if ($scope !== 'ALL') {
            abort(
                403,
                'Dashboard Purchase Order management memerlukan scope ALL.',
            );
        }

        $validated = $request->validate([
            'period' => [
                'required',
                Rule::in([
                    'day',
                    'week',
                    'month',
                    'year',
                    'range',
                ]),
            ],

            'date' => [
                'nullable',
                'required_if:period,day',
                'date_format:Y-m-d',
            ],

            'week' => [
                'nullable',
                'required_if:period,week',
                'regex:/^\d{4}-W\d{2}$/',
            ],

            'month' => [
                'nullable',
                'required_if:period,month',
                'date_format:Y-m',
            ],

            'year' => [
                'nullable',
                'required_if:period,year',
                'integer',
                'min:2000',
                'max:2100',
            ],

            'start_date' => [
                'nullable',
                'required_if:period,range',
                'date_format:Y-m-d',
            ],

            'end_date' => [
                'nullable',
                'required_if:period,range',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],

            /*
             * Untuk sementara divalidasi sebagai integer.
             * Validasi exists dapat ditambahkan setelah nama tabel
             * Cabang dan Department dipastikan.
             */
            'cabang_id' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'department_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $dashboard = $this->dashboardService->getDashboard(
            $validated,
        );

        return response()->json([
            'message' => 'Purchase Order dashboard retrieved successfully.',

            'data' => [
                'access' => [
                    'scope_view' => $scope,
                    'can_filter_cabang' => true,
                    'can_filter_department' => true,
                ],

                'filters' => $dashboard['filters'],

                'summary' => $dashboard['summary'],

                'trend' => $dashboard['trend'] ?? [],

                'statuses' => $dashboard['statuses'] ?? [],

                'attention_items'
                => $dashboard['attention_items'] ?? [],
            ],
        ]);
    }
}
