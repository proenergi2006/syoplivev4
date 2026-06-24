<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Database\Seeder;

class ApprovalFlowSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | PURCHASE ORDER FLOW
        |--------------------------------------------------------------------------
        */

        ApprovalFlow::updateOrCreate(
            [
                'module_name' => 'PURCHASE_REQUEST',
                'name' => 'Approval Purchase Request',
                'is_active' => true,
            ],

            [
                'module_name' => 'PURCHASE_ORDER',
                'name' => 'Approval Purchase Order',
                'is_active' => true,
            ],

            [
                'module_name' => 'MASTER_VENDOR',
                'name' => 'Approval Master Vendor',
                'is_active' => true,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | STEP 1 - CEO
        |--------------------------------------------------------------------------
        |
        | sementara hardcode dulu approver_id = 1
        | nanti bisa diganti lewat setting master approval
        |
        */

        // ApprovalFlowStep::updateOrCreate(
        //     [
        //         'approval_flow_id' => $flow->id,
        //         'step_order' => 1,
        //     ],
        //     [
        //         'approver_type' => 'USER',
        //         'approver_id' => 1,
        //         'label' => 'CEO Approval',
        //         'is_required' => true,
        //     ]
        // );
    }
}
