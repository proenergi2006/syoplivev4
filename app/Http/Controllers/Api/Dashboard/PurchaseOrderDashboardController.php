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

        if (
            !$user->hasPermission(
                'dashboard.po.view',
            )
        ) {
            abort(
                403,
                'Anda tidak memiliki akses ke dashboard Purchase Order.',
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

            'cabang_id' => [
                'nullable',
                'integer',
                'exists:cabang,id',
            ],

            'department_id' => [
                'nullable',
                'integer',
                'exists:departments,id',
            ],
        ]);

        /*
         * Menentukan filter efektif berdasarkan scope.
         *
         * OWN_CABANG dan OWN_DEPARTMENT akan menimpa
         * filter tertentu dengan data user login.
         */
        $resolvedAccess
            = $this->dashboardService
            ->resolveAccessAndFilters(
                user: $user,
                filters: $validated,
            );

        $dashboard
            = $this->dashboardService
            ->getDashboard(
                $resolvedAccess['filters'],
            );

        return response()->json([
            'message'
            => 'Purchase Order dashboard retrieved successfully.',

            'data' => [
                'access'
                => $resolvedAccess['access'],

                'filters'
                => $dashboard['filters'],

                'summary'
                => $dashboard['summary'],

                'trend'
                => $dashboard['trend'] ?? [],

                'statuses'
                => $dashboard['statuses'] ?? [],

                'attention_items'
                => $dashboard['attention_items'] ?? [],

                /*
                 * Akan diisi pada tahap chart berikutnya.
                 */
                'breakdown' => $dashboard['breakdown'] ?? [
                    'by_cabang' => [],
                    'by_department' => [],
                ],
            ],
        ]);
    }
}
