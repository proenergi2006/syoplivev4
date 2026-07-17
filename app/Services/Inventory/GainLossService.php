<?php

namespace App\Services\Inventory;

use App\Mail\POTradingMail;
use App\Models\InventoryGainLoss;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\AccurateApiService;
use App\Services\Trade\GoodsReceiptInventoryService;
use App\Services\Trade\PurchaseOrder\PurchaseOrderInventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class GainLossService {
    
    public function __construct(
    private AccurateApiService $accurateService,
    private PurchaseOrderInventoryService $poService,
    private GoodsReceiptInventoryService $grService
    ) { 
        $this->accurateService = $accurateService;
        $this->poService = $poService;
        $this->grService = $grService;
    }

public function createGainLoss(array $form, array $user)
{
    return DB::transaction(function () use ($form, $user) {

        $po = DB::table('inventory_vendor_po')
            ->where('id_master', $form['id_po'])
            ->first();

        if (!$po) {
            throw new Exception('PO tidak ditemukan');
        }

        if ($form['jenis'] == 1) {
            $this->updatePoGain(
                $po,
                $form,
                $user
            );
        }

        $this->saveGainLoss(
            $po,
            $form,
            $user
        );

        if ($po->id_accurate) {

            if ($form['jenis'] == 1) {
                $this->processGain(
                    $po,
                    $form
                );
            } else {
                $this->processLoss(
                    $po,
                    $form
                );
            }
        }

        return [
            'success' => true,
            'msg' => 'Gain/Loss berhasil disimpan'
        ];
    });
}
private function processLoss(
    object $po,
    array $form
): void {

    $this->poService->closeAccurate(
        $po->id_accurate,
        $po->kode_vendor,
        $po->nomor_po,
        'Close PO - loss'
    );
}

private function processGain(
    object $po,
    array $form
): void {

     $receive = DB::table('inventory_vendor_receive')
        ->where('id_po_supplier', $po->id_master)
        ->first();

        $this->poService->closeAccurate(
            $po->id_accurate,
               [
                    'vendor' => $po->id_vendor,
                ],
        );

        $data_receive = array(
            'id' => $receive->id_accurate,
        );

        // if($receive->id_accurate){

        //     $deleteGR = app(AccurateApiService::class)->delete(
        //         config('services.accurate.base_url') . '/accurate/api/receive-item/delete.do',$data_receive);

        //     if (!$deleteGR['s']) {
        //         throw new \Exception(
        //             ($deleteGR['d'][0] ?? 'Delete Accurate gagal')
        //             . ' - Response dari Accurate'.json_encode($data_receive)
        //         );
        //     }
        // }

    $query_params = [
        'id' => $po->id_accurate,
    ];

    $poDetail = app(AccurateApiService::class)->get(
            config('services.accurate.base_url') . '/accurate/api/purchase-order/detail.do?' . http_build_query($query_params)
    );

$items = $poDetail['d']['detailItem'] ?? [];
$expenses = $poDetail['d']['detailExpense'] ?? [];

$detailItems = [];
$detailExpenses = [];

foreach ($items as $item) {

    $unitPriceLossGain = $item['item']['itemType'] === 'INVENTORY'
        ? $form['harga_tebus']
        : $item['unitPrice'];

    $quantity = $item['item']['itemType'] === 'INVENTORY'
        ? $receive->volume_bol
        : $item['quantity'];

    if ($item['item']['itemType'] === 'INVENTORY') {

        $detailItems[] = [
            'itemNo' => $item['item']['no'],
            'quantity' => $quantity,
            'unitPrice' => $unitPriceLossGain,
            'useTax1' => $item['useTax1'],
            'warehouseName' =>  $item['warehouse']['name'] == null ? '' : $item['warehouse']['name']
            // 'departmentName' => $alamat['nama_cabang'],
        ];

        $detailItems[] = [
            'itemNo' => $item['item']['no'],
            'quantity' => $form['volume'],
            'unitPrice' => $form['harga_tebus'],
            'useTax1' => $item['useTax1'],
            'warehouseName' => $item['warehouse']['name'] == null ? '' : $item['warehouse']['name'],
            // 'departmentName' => $alamat['nama_cabang'],
        ];

    } else {

        $detailItems[] = [
            'itemNo' => $item['item']['no'],
            'quantity' => $form['volume'],
            'unitPrice' => $form['harga_tebus'],
            'useTax1' => $item['useTax1'],
            // 'departmentName' => $alamat['nama_cabang'],
        ];
    }
}

// foreach ($expenses as $expense) {

//     if ($expense['expenseName'] === 'PBBKB') {

//         $detailExpenses[] = [
//             'accountNo' => $expense['account']['no'],
//             'expenseAmount' => $pbbkb,
//             'allocateToItemCost' => $expense['allocateToItemCost'],
//             // 'departmentName' => $alamat['nama_cabang'],
//         ];

//     } elseif (str_contains($expense['expenseName'], '22')) {

//         $detailExpenses[] = [
//             'accountNo' => $expense['account']['no'],
//             'expenseAmount' => $pph_22,
//             'allocateToItemCost' => $expense['allocateToItemCost'],
//             // 'departmentName' => $alamat['nama_cabang'],
//         ];

//     } else {

//         $detailExpenses[] = [
//             'accountNo' => $expense['account']['no'],
//             'expenseAmount' => $expense['expenseAmount'],
//             'allocateToItemCost' => $expense['allocateToItemCost'],
//             // 'departmentName' => $alamat['nama_cabang'],
//         ];
//     }
// }

    $newPo = $this->poService->sendToAccurate(
        [
            'terminal' => $po->id_terminal,
            'vendor' => $po->id_vendor,
            'tanggal_inven' => $po->tanggal_inven,
            'terms' => $po->terms,
            'catatan_po' => $po->keterangan,
        ],
        $detailItems,
        $detailExpenses,
        '.'.$po->nomor_po,
    );
     $idAccurate = $newPo['r']['id'] ?? null;

    $newReceive = $this->grService
        ->accurateGR(
            [
                'id_po_supplier' => $po->id_master,
                'tgl_terima' => $receive->tgl_terima,
                'no_terima' => $receive->no_terima,
                'volume_bol' => $receive->volume_bol,
                'tipe' =>'gain',
                'nomor_po' =>'.'.$po->nomor_po,
            ],
            $receive->id_po_receive
        );

   $this->poService->closeAccurate(
        $idAccurate,
        [
            'vendor' => $po->id_vendor,
        ],
    );

    DB::table('inventory_vendor_po')
        ->where('id_master', $po->id_master)
        ->update([
            'id_accurate' => $idAccurate
        ]);
}
private function updatePoGain(
    object $po,
    array $form,
    array $user
): void {

    DB::table('inventory_vendor_po')
    ->where('id_master', $po->id_master)
    ->update([
        'harga_tebus' => $form['harga_tebus'],
        'harga_po' => $form['harga_po'],
        'volume_ri' => $form['volume_ri'],
        'subtotal' => $form['subtotal'],
        'ppn_12' => $form['ppn_12'],
        'pph_22' => $form['pph_22'],
        'pbbkb' => $form['pbbkb'],
        'total_order' => $form['total_order'],
        'keterangan' => $form['keterangan'],
        'lastupdate_time' => now(),
        'lastupdate_by' => $user['name'],
        'lastupdate_ip' => request()->ip()
    ]);
}
private function saveGainLoss(
    object $po,
    array $form,
    array $user
): void {

    $fileName = null;

    if (!empty($form['file'])) {
        $fileName = $form['file']->store(
            'gain-loss',
            'public'
        );
    }

    DB::table('inventory_gain_loss')
        ->insert([
            'id_po_supplier' => $po->id_master,
            'volume_po' => $form['volume_po'],
            'volume_terima' => $form['volume_ri'],
            'jenis' => $form['jenis'],
            'volume' => $form['volume'],
            'ket' => $form['keterangan'],
            'file_upload' => $fileName,
            'disposisi_gain_loss' => 1,
            'created_time' => now(),
            'created_by' => $user['name'],
            'created_ip' => request()->ip(),
        ]);
}

public function approvalCEO($idMaster, $revert, $catatan = null)
{
    $po =  InventoryGainLoss::with('po')
        ->where('id_master', $idMaster)
        ->first();
    DB::beginTransaction();

    try {

        $data = [
            'ceo_pic' => Auth::id(),
            'ceo_tanggal' => now(),
            'ceo_summary' => $catatan,
        ];

        if ($revert == 1) {

            $data['ceo_result'] = 1;
            $data['disposisi_gain_loss'] = 2;

            // $subject = 'Persetujuan Gain & Loss';
            // $message = Auth::user()->name . ' telah melakukan verifikasi Gain & Loss';

        } elseif ($revert == 2) {

            $data['ceo_result'] = 2;
            $data['disposisi_gain_loss'] = 3;

            // $subject = 'Penolakan Gain & Loss';
            // $message = Auth::user()->name . ' melakukan penolakan Gain & Loss';
        }
        

          $res= $this->poService->openAccurate($po->po->id_accurate,  $po->po->id_vendor);
                 
            if (!$res || ($res['s'] ?? false) == false) {

                Log::info('Accurate response ', $res);

                $message = is_array($res['d'])
                    ? ($res['d']['message'] ?? json_encode($res['d']))
                    : $res['d'];

                throw new \Exception($message.'- Response dari Accurrate 1');
            }

            $type = $revert == 1 ? 'approved_gain' : 'rejected_gain';

            Mail::to('gary.salsabilla@proenergi.com')->send(
                new POTradingMail(
                    $po->nomor_po,
                    [
                        'vendor' => $po->id_vendor,
                        'produk' => $po->id_produk,
                        'volume_po' => $po->volume_po,
                        'harga_tebus' => $po->harga_tebus,
                    ],
                    [
                        'name' => auth()->user()->name,
                    ],
                    $type
                )
            );
        DB::table('inventory_gain_loss')
            ->where('id_master', $idMaster)
            ->update($data);

        DB::commit();

        return true;

    } catch (\Throwable $e) {

        DB::rollBack();

        throw $e;
    }
}
}