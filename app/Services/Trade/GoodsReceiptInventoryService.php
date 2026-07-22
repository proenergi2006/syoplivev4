<?php

namespace App\Services\Trade;

use App\Models\Cabang;
use App\Models\InventoryDepot;
use App\Models\InventoryVendorPo;
use App\Models\InventoryVendorReceive;
use App\Models\InventoryVendorReceiveHistory;
use App\Models\MasterVendor;
use App\Services\AccurateApiService;
use App\Services\Inventory\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoodsReceiptInventoryService
{
    public function create(array $form, $file, $user)
    {
            try {
                Log::info('START GR CREATE', [
                    'form' => $form,
                    'hasFile' => request()->hasFile('file'),
                ]);

                return DB::transaction(function () use ($form, $file, $user) {

                    $id = now()->format('Ym') . str_pad(
                        DB::table('inventory_vendor_receive')->count() + 1,
                        9,
                        '0',
                        STR_PAD_LEFT
                    );

                    Log::info('GENERATED ID', ['id' => $id]);

                    $path = $file ? $file->store('good-receipt', 'public') : null;

                    Log::info('FILE STORED', ['path' => $path]);

                    DB::table('inventory_vendor_receive')->insert([
                        'id_po_receive' => $id,
                        'id_po_supplier' => $form['id_po_supplier'],
                        'no_terima' => $form['no_terima'],
                        'nama_pic' => $form['nama_pic'],
                        'tgl_terima' => $form['tgl_terima'],
                        'volume_bol' => $form['volume_bol'],
                        'volume_terima' => $form['volume_terima'],
                        'harga_tebus' => $form['harga_tebus'],
                        'file_upload' => $path,
                        'created_time' => now(),
                        'created_by' => $user->name,
                        'created_ip' => request()->ip(),
                    ]);

                    Log::info('INSERT DONE');

                    app(InventoryService::class)->postGR($form, $id, $user);

                    $this->accurateGR($form, $id);

                    return [
                        'success' => true,
                        'message' => 'GR berhasil disimpan',
                        'id' => $id
                    ];
                });

            } catch (\Throwable $e) {
                Log::error('GR ERROR', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'message' => 'Gagal mengirim Goods.',
                    'error'   => $e->getMessage()
                ], 500);

            }
    }


    public function accurateGR($form, $id)
    {
        // $po = DB::table('inventory_vendor_po')
        //     ->where('id_master', $form['id_po_supplier'])
        //     ->first();
        $po = InventoryVendorPo::with('terminal.cabang')
        ->where('id_master', $form['id_po_supplier'])
        ->first();
        
        $nomor_po = ($form['tipe'] ?? null) === 'gain'
        ? $form['nomor_po']
        : $po->nomor_po;

        $terminal = $po->terminal;
        $cabang = Cabang::where('id', $po->id_vendor)
        ->first();
        // $cabang = $po->terminal->cabang;

        if (!$po) {
            throw new \Exception("PO tidak ditemukan");
        }
          $query_params = [
            'id' => $po->id_accurate,
        ];

        $poDetail = app(AccurateApiService::class)->get(
             config('services.accurate.base_url') . '/accurate/api/purchase-order/detail.do?' . http_build_query($query_params)
        );

        $kode_item_accurate = $poDetail['d']['detailItem'][0]['item']['no'];
        $unitPrice = $poDetail['d']['detailItem'][0]['unitPrice'];

        if (!$poDetail['s']) {
            throw new \Exception("PO Accurate tidak ditemukan");
        }
        $namaCabang = $po->terminal->cabang->nama_cabang;
        $tgl = Carbon::parse($form['tgl_terima'])->format('d/m/Y');
        $payload = [
            "receiveNumber" => $form['no_terima'],
            "number" => $form['no_terima'],
            "transDate" => $tgl,
            "vendorNo" => $poDetail['d']['vendor']['vendorNo'],
            'branchName' =>  $namaCabang === 'Kantor Pusat'
                    ? 'Head Office'
                    :   $namaCabang,
            "description" => "GR PO " . $nomor_po,
            "toAddress" => $poDetail['d']['toAddress'],
            "detailItem" => [[
                'itemNo'       => $kode_item_accurate,
                'quantity' => $form['volume_bol'],
                'unitPrice'    => $unitPrice,
                'purchaseOrderNumber' =>$nomor_po,
                'departmentName' => $cabang,
            ]]
        ];

        $res = app(AccurateApiService::class)->post(
            config('services.accurate.base_url') . '/accurate/api/receive-item/save.do',
            $payload
        );

        if (!$res['s']) {
            Log::info('Accurate response ', $res);
            throw new \Exception(json_encode($res['d'])."- Response Accurate");
        }
        

        DB::table('inventory_vendor_receive')
            ->where('id_po_receive', $id)
            ->update([
                'id_accurate' => $res['r']['id'] ?? null
            ]);
    }

     public function update(array $form, $file, $id, $user)
    {
        
        return DB::transaction(function () use ($form, $file,$id, $user) {

            $receive = InventoryVendorReceive::where('id_po_receive', $id)
                ->firstOrFail();
            $count_resubmit=(int)$receive->updated_count;
            if ($count_resubmit< 3) {
                $count_resubmit++;
            }

            // =========================
            // 1. SIMPAN HISTORY
            // =========================
            InventoryVendorReceiveHistory::create([
                'id_po_receive'   => $receive->id_po_receive,
                'id_po_supplier'  => $receive->id_po_supplier,
                'id_accurate'     => $receive->id_accurate,
                'no_terima'       => $receive->no_terima,
                'nama_pic'        => $receive->nama_pic,
                'tgl_terima'      => $receive->tgl_terima,
                'volume_bol'      => $receive->volume_bol,
                'volume_terima'   => $receive->volume_terima,
                'harga_tebus'     => $receive->harga_tebus,
                'file_upload'     => $receive->file_upload,
                'created_time'    => $receive->created_time,
                'created_by'      => $receive->created_by,
                'created_ip'      => $receive->created_ip,
                'is_updated'      => $receive->id_updated??0,
                'updated_count'   => $receive->updated_count,
                'keterangan_updated' => $form['keterangan'],
            ]);

            // =========================
            // 2. HANDLE FILE (optional)
            // =========================
            if ($file) {
                $path = $file->store('good-receipt', 'public');
                $receive->file_upload = $path;
            }

            // =========================
            // 3. UPDATE LOCAL DATA
            // =========================
            $receive->update([
                'no_terima'      => $form['no_terima'],
                'nama_pic'       => $form['nama_pic'],
                'tgl_terima'     => $form['tgl_terima'],
                'volume_bol'     => $form['volume_bol'],
                'volume_terima'  => $form['volume_terima'],
                'harga_tebus'    => $form['harga_tebus'],
                'file_upload'    => $receive->file_upload,
                'lastupdate_time'   => now(),
                'lastupdate_by'     => $user->name,
                'lastupdate_ip' => request()->ip(),
                'is_updated'     => 1,
                'updated_count'  => $count_resubmit,
            ]);
            


            // =========================
            // 4. SYNC KE ACCURATE
            // =========================
            $this->accurateUpdate($receive, $form);

            return [
                'success' => true,
                'message' => 'GR berhasil diupdate',
                'id' => $receive->id_po_receive
            ];
        });
    }

    private function accurateUpdate($receive, $form)
    {
        $po = InventoryVendorPo::with('terminal.cabang')
            ->where('id_master', $receive->id_po_supplier)
            ->firstOrFail();

        $cabang = Cabang::find($po->id_vendor);

        $poDetail = app(AccurateApiService::class)->get(
            config('services.accurate.base_url') . '/accurate/api/receive-item/detail.do?id=' . $receive->id_accurate
        );

        if (!$poDetail['s']) {
            throw new \Exception('PO Accurate tidak ditemukan');
        }

        $item = $poDetail['d']['detailItem'][0];

        $payload = [
            "id" => $receive->id_accurate, // IMPORTANT: update mode
            "receiveNumber" => $form['no_terima'],
            "number" => $form['no_terima'],
            "transDate" => Carbon::parse($form['tgl_terima'])->format('d/m/Y'),
            "vendorNo" => $poDetail['d']['vendor']['vendorNo'],
            "branchName" => $cabang->nama === 'Kantor Pusat' ? 'Head Office' : $cabang->nama,
            "description" => "UPDATE GR PO " . $po->nomor_po,
            "toAddress" => $poDetail['d']['toAddress'],
            "detailItem" => [
                [
                    "id" => $item['id'],
                    "itemNo" => $item['item']['no'],
                    "quantity" => $form['volume_bol'],
                    "unitPrice" => $item['unitPrice'],
                    "purchaseOrderNumber" => $po->nomor_po,
                    "departmentName" => $cabang->nama
                ]
            ]
        ];


        $res = app(AccurateApiService::class)->post(
            config('services.accurate.base_url') . '/accurate/api/receive-item/save.do',
            $payload
        );

        if (!$res['s']) {
            Log::error('Accurate Update Failed', $res);
            throw new \Exception('Gagal update Accurate');
        }

        $receive->update([
            'id_accurate' => $res['r']['id'] ?? $receive->id_accurate
        ]);
    }
     public function delete($poReceiveId)
    {
        return DB::transaction(function () use ($poReceiveId) {

            // $usedStock = ProPrDetail::where('id_po_supplier', $poSupplierId)
            //     ->exists();

            // if ($usedStock) {
            //     throw new \Exception(
            //         'Maaf, data tidak dapat dihapus karena stok sudah terpakai'
            //     );
            // }

            $receive = InventoryVendorReceive::where('id_po_receive', $poReceiveId)
                ->firstOrFail();

            InventoryVendorReceiveHistory::create([
                'id_po_receive'      => $receive->id_po_receive,
                'id_po_supplier'     => $receive->id_po_supplier,
                'id_accurate'     => $receive->id_accurate,
                'no_terima' => $receive->no_terima,
                'nama_pic' => $receive->nama_pic,
                'tgl_terima' => $receive->tgl_terima,
                'volume_bol' => $receive->volume_bol,
                'volume_terima' => $receive->volume_terima,
                'harga_tebus' => $receive->harga_tebus,
                'created_time' =>  $receive->created_time,
                'created_by' => $receive->created_by,
                'created_ip' =>  $receive->created_ip,
                // 'file_upload' => $file ? $file->store('gr') : null,
            ]);

            $data_receive = array(
				'id' => $receive->id_accurate,
			);

           if($receive->id_accurate){

               $deleteGR = app(AccurateApiService::class)->delete(
                   config('services.accurate.base_url') . '/accurate/api/receive-item/delete.do',$data_receive);
   
               if (!$deleteGR['s']) {
                   throw new \Exception(
                       ($deleteGR['d'][0] ?? 'Delete Accurate gagal')
                       . ' - Response dari Accurate'.json_encode($data_receive)
                   );
               }
           }


            $file = $receive->file_upload;

            if ($file) {
                Storage::disk('public')->delete(
                    $file
                );
            }
            app(InventoryService::class)->deleteGR($receive->id_po_supplier,$poReceiveId );
            $receive->delete();


            return true;
        });
    }
}