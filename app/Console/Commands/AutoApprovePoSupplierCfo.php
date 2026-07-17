<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PurchaseOrderService;
use App\Services\Trade\PurchaseOrder\PurchaseOrderInventoryService;

class AutoApprovePoSupplierCfo extends Command
{
    protected $signature = 'po-supplier:auto-approve-cfo';

    protected $description = 'Auto approve PO Supplier CFO jika pending lebih dari 20 menit';

   public function handle(PurchaseOrderInventoryService $service): int
    {
        $result = $service->autoApproveCfo();

        if (!($result['success'] ?? false)) {
            $this->error($result['message'] ?? 'Auto approve CFO gagal');

            return self::FAILURE;
        }

        $this->info($result['message'] ?? 'Auto approve CFO berhasil');
        $this->info('Total PO auto approved: ' . ($result['total'] ?? 0));

        return self::SUCCESS;
    }
}