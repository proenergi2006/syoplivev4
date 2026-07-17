<?php
namespace App\Services\Trade\PurchaseOrder;

use App\Models\Cabang;
use App\Models\InventoryVendorPo;
use App\Models\MasterVendor;
use App\Models\Terminal;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Mail\POTradingMail;
use App\Models\InventoryVendorPoOld;
use App\Models\User;
use App\Services\AccurateApiService;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PurchaseOrderInventoryService
{
    public function create(array $form, array $user)
    {
       return DB::transaction(function () use ($form, $user) {

        // $last = DB::table('inventory_vendor_po')->count();

        // $idMaster = now()->format('Ym') . str_pad(
        //     ($last + 1),
        //     9,
        //     '0',
        //     STR_PAD_LEFT
        // );
        $lastOld = DB::connection('mysql_old')
            ->table('new_pro_inventory_vendor_po')
            ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(id_master, 7, 9) AS UNSIGNED)), 0) as last_id")
            ->first();

        $next = $lastOld->last_id + 1;

        $idMaster = now()->format('Ym') . str_pad($next, 9, '0', STR_PAD_LEFT);

        $nomorPO = $this->generateNomorPO($form);

        $subtotal = $form['volume_po'] * $form['harga_tebus'];

        $ppn = $form['ppn12'] ?? 0;
        $dpp = $form['dpp'] ?? 0;
        $pph22 = $form['pph22'] ?? 0;

        // $total = $subtotal + $ppn - $pph22;

        $dataPo = [
            'id_master' => $idMaster,
            'id_vendor' => $form['vendor'],
            'id_produk' => $form['produk'],
            'id_terminal' => $form['terminal'],
            'nomor_po' => $nomorPO,
            'tanggal_inven' => $form['tanggal_inven'],
            'volume_po' => $form['volume_po'],
            'harga_tebus' => $form['harga_tebus'],
            'kd_tax' => $form['kd_tax'],
            'subtotal' => $subtotal,
            'ppn_12' => $ppn,
            'dpp_11_12' => $dpp,
            'pph_22' => $pph22,
            'total_order' => $form['total_order'],
            'terms' => $form['terms'],
            'terms_day' => $form['terms_day'],
            'jenis_harga' => $form['jenis_harga'],
            'jenis_kirim' => $form['jenis_kirim'],
            'kategori_plat' => $form['kategori_plat'],
            'kategori_oa' => $form['kategori_oa'],
            'ongkos_angkut' => $form['ongkos_angkut'],
            'is_biaya' => $form['jenis_oa'],
            'keterangan' => $form['catatan_po'],
            'internal_notes' => $form['internal_notes'],
            'iuran_migas' => $form['iuran_migas'],
            'nominal_migas' => $form['nominal_migas'],
            'disposisi_po' => 1,
            'created_time' => now(),
            'created_by' => $user['name'],
            'created_ip' => request()->ip(),
        ];
        DB::table('inventory_vendor_po')->insert($dataPo);

        DB::connection('mysql_old')
        ->table('new_pro_inventory_vendor_po')
        ->insert($dataPo);

        $detailItems = $this->buildDetailItems($form);
        $detailExpenses = $this->buildExpenses($form);

        $res = $this->sendToAccurate($form, $detailItems, $detailExpenses, $nomorPO);
        

        if (!$res || ($res['s'] ?? false) == false) {

            Log::info('Accurate response', $res);

            $message = is_array($res['d'])
                ? ($res['d']['message'] ?? json_encode($res['d']))
                : $res['d'];

            throw new \Exception($message.'- Response Accurate');
        }

        $idAccurate = $res['r']['id'] ?? null;
        $res2=$this->closeAccurate($idAccurate, $form, 'Menunggu Approve');
        
        if (!$res2 || ($res2['s'] ?? false) == false) {

            Log::info('Accurate response 2', $res2);

            $message = is_array($res2['d'])
                ? ($res2['d']['message'] ?? json_encode($res2['d']))
                : $res2['d'];

            throw new \Exception($message);
        }

        DB::table('inventory_vendor_po')
        ->where('id_master', $idMaster)
        ->update([
            'id_accurate' => $idAccurate
        ]);
        DB::connection('mysql_old')
        ->table('new_pro_inventory_vendor_po')
        ->where('id_master', $idMaster)
        ->update([
            'id_accurate' => $idAccurate
        ]);

        
        DB::afterCommit(function () use ($nomorPO, $form, $user) {
            Mail::to('gary.salsabilla@proenergi.com')
                ->send(new POTradingMail(
                    $nomorPO,
                    $form,
                    $user,
                    'need_cfo'
                ));
        });

        return [
            'success' => true,
            'msg' => 'PO berhasil disimpan',
        ];
        });
    }

    public function buildDetailItems(array $form)
    {
        $data = [];
        $detailItems = [];

        if (!empty($form['kode_item'])) {
            $data[] = ['kode' => $form['kode_item'], 'keterangan' => 'kode_item'];
        }

        if (!empty($form['kode_item_oa'])) {
            $data[] = ['kode' => $form['kode_item_oa'], 'keterangan' => 'kode_oa'];
        }

        $terminal = Terminal::where('id', $form['terminal'])
        ->first();
        $cabang = Cabang::where('id', $terminal->id_cabang)
        ->first();

        foreach ($data as $item) {

            $quantity = 0;
            $unitprice = 0;
            $ppninclude = false;
            $jenis = null;
            $detailNotes = '';

            if ($item['keterangan'] === 'kode_item') {
                $quantity = (int) $form['volume_po'];
                $unitprice = $form['harga_tebus'];
                $ppninclude = true;
                $jenis = 'kode_item';
                $detailNotes = $form['keterangan_item1'] ?? '';
            }

            if ($item['keterangan'] === 'kode_oa') {
                $quantity = (int) $form['volume_po'];
                $unitprice = $form['ongkos_angkut'] ?? 0;
                $jenis = 'kode_oa';
                $detailNotes = $form['keterangan_item2'] ?? '';

                $ppninclude = ($form['kategori_plat'] === 'Hitam');
            }

            $detailItems[] = [
                'itemNo' => $item['kode'],
                'quantity' => $quantity,
                'unitPrice' => $unitprice,
                'useTax1' => $ppninclude,
                'warehouseName' => $cabang->inisial_cabang,
                'detailNotes' => $detailNotes,
                'departmentName' => $cabang->nama_cabang,
            ];
        }

        return $detailItems;
    }
    public function buildExpenses(array $form)
    {
        $detailExpenses = [];

        if (!empty($form['biaya_oa'])) {
            $detailExpenses[] = [
                'accountNo' => $form['biaya_oa'],
                'expenseAmount' => $form['total_biaya_oa'] ?? 0,
                'allocateToItemCost' => (bool) $form['alokasi_biaya_oa'],
                'expenseNotes' => $form['keterangan_biaya_oa'] ?? '',
            ];
        }

        if (!empty($form['biaya_lain_oa'])) {
            $detailExpenses[] = [
                'accountNo' => $form['biaya_lain_oa'],
                'expenseAmount' => $form['jumlah_biaya'] ?? 0,
                'allocateToItemCost' => (bool) $form['alokasi_biaya_oa_lain'],
                'expenseNotes' => $form['ket_biaya_lain_oa'] ?? '',
            ];
        }

        if (!empty($form['biaya_pph22'])) {
            $detailExpenses[] = [
                'accountNo' => $form['biaya_pph22'],
                'expenseAmount' => $form['pph22'] ?? 0,
                'allocateToItemCost' => (bool) $form['alokasi_pph22'],
                'expenseNotes' => $form['keterangan_pph22'] ?? '',
            ];
        }

        if (!empty($form['biaya_pbbkb'])) {
            $detailExpenses[] = [
                'accountNo' => $form['biaya_pbbkb'],
                'expenseAmount' => $form['pbbkb'] ?? 0,
                'allocateToItemCost' => (bool) $form['alokasi_pbbkb'],
                'expenseNotes' => $form['keterangan_pbbkb'] ?? '',
            ];
        }

        if (!empty($form['biaya_migas'])) {
            $detailExpenses[] = [
                'accountNo' => $form['biaya_migas'],
                'expenseAmount' => $form['nominal_migas'] ?? 0,
                'allocateToItemCost' => (bool) $form['alokasi_migas'],
                'expenseNotes' => $form['keterangan_migas'] ?? '',
            ];
        }


        return $detailExpenses;
    }
    // private function generateNomorPO($form)
    // {
    //     $count = DB::table('inventory_vendor_po')->count();

    //     return str_pad($count + 1, 3, '0', STR_PAD_LEFT)
    //         . '/PO/' . date('Y');
    // }

    private function generateNomorPO($form)
    {
        $terminal = DB::table('terminal')
            ->where('id', $form['terminal'])
            ->first();

        $vendor = DB::table('master_vendor')
            ->where('id', $form['vendor'])
            ->first();

        $cabang = DB::table('cabang')
            ->where('id', $terminal->id_cabang)
            ->first();

        $year = date('Y', strtotime($form['tanggal'] ?? now()));

        // ambil nomor terakhir
        $lastNumber = DB::table('inventory_vendor_po')
            ->where('id_vendor', $form['vendor'])
            ->whereYear('tanggal_inven', $year)
            ->max(DB::raw("CAST(SUBSTRING(nomor_po,1,3) AS INTEGER)"));

        $next = ($lastNumber ?? 0) + 1;

        $romawi = [
            1=>'I','II','III','IV','V','VI',
            'VII','VIII','IX','X','XI','XII'
        ];

        $bulan = $romawi[(int)date('m', strtotime($form['tanggal'] ?? now()))];
        $tahun = date('y', strtotime($form['tanggal'] ?? now()));

        return str_pad($next, 3, '0', STR_PAD_LEFT)
            . '/' . strtoupper($vendor->inisial_vendor)
            . '/' . strtoupper($cabang->inisial_cabang)
            . '/' . $bulan
            . '/' . $tahun;
    }

    public function sendToAccurate($form, $items, $expenses, $nomorPO)
    {
  
        $terminal = Terminal::where('id', $form['terminal'])
        ->first();
        $vendor = MasterVendor::where('id', $form['vendor'])
        ->first();
        $terminal = $terminal->terminal;
        $tgl = Carbon::parse($form['tanggal_inven'])->format('d/m/Y');

        $user = auth()->user()->load('cabang');

        
        if ($form['terms'] == 'NET') {
            $payment = $form['terms'] . ' ' . $form['terms_day'];
        } else {
            $payment = 'C.O.D';
        }

        $data = [
                'transDate' => $tgl,
                'vendorNo' => $vendor->kode_vendor,
                'number' => $nomorPO,
                'branchName' => $user->cabang->nama_cabang === 'Kantor Pusat'
                    ? 'Head Office'
                    : $user->cabang->nama_cabang,

                'paymentTermName' =>$payment ,
                'description' => $form['catatan_po'],

                'toAddress' => 'Graha Irama Lt 6 Jl. HR Rasuna Said Kota Administrasi Jakarta Selatan DKI Jakarta Indonesia',

                'detailItem' => $items,
                'detailExpense' => $expenses,
            ];

        return app(AccurateApiService::class)->post(
            config('services.accurate.base_url') . '/accurate/api/purchase-order/save.do',
            $data
        );
        
    }
    public function closeAccurate($id, $form ,$reason)
    {
        $terminal = Terminal::where('id', $form['terminal'])
        ->first();
        $cabang = Cabang::where('id', $terminal->id_cabang)
        ->first();

        return app(AccurateApiService::class)->post(
            config('services.accurate.base_url') . '/accurate/api/purchase-order/save.do',
            [
                'id' => $id,
                'branchName' => $cabang->nama_cabang === 'Kantor Pusat'
                    ? 'Head Office'
                    : $cabang->nama_cabang,
                'manualClosed' => true,
                'closeReason' => $reason,
            ]
        );
    }
    public function openAccurate($id, $form)
    {
        $terminal = Terminal::where('id', $form)
        ->first();
        $cabang = Cabang::where('id', $terminal->id_cabang)
        ->first();
        

        return app(AccurateApiService::class)->post(
            config('services.accurate.base_url') . '/accurate/api/purchase-order/save.do',
            [
                'id' => $id,
                'branchName' => $cabang->nama_cabang === 'Kantor Pusat'
                    ? 'Head Office'
                    : $cabang->nama_cabang,
                'manualClosed' => false
            ]
        );
    }

    public function deleteAccuratePO($id)
    {
        return app(AccurateApiService::class)->delete(
            config('services.accurate.base_url') . '/accurate/api/purchase-order/delete.do',
            [
                'id' => $id
            ]
        );
    }

    public function update($id, array $form, array $user)
    {
        return DB::transaction(function () use ($id, $form, $user) {

        
            $po = DB::table('inventory_vendor_po')
                ->where('id_master', $id)
                ->first();

            if (!$po) {
                throw new Exception('PO tidak ditemukan');
            }
 
            $subtotal = $form['volume_po'] * $form['harga_tebus'];

            // $total = $subtotal
            //     + ($form['ppn12'] ?? 0)
            //     - ($form['pph22'] ?? 0);

            $updateData = [
                'id_terminal' => $form['terminal'],
                'tanggal_inven' => $form['tanggal_inven'],
                'volume_po' => $form['volume_po'],
                'harga_tebus' => $form['harga_tebus'],
                'subtotal' => $subtotal,
                'ppn_12' => $form['ppn12'] ?? 0,
                'dpp_11_12' => $form['dpp'] ?? 0,
                'pph_22' => $form['pph22'] ?? 0,
                'total_order' => $form['total_order'],
                'kd_tax' => $form['kd_tax'],
                'terms' => $form['terms'],
                'terms_day' => $form['terms_day'],
                'kategori_oa' => $form['kategori_oa'],
                'kategori_plat' => $form['kategori_plat'],
                'jenis_kirim' => $form['jenis_kirim'],
                'jenis_harga' => $form['jenis_harga'],
                'ongkos_angkut' => $form['ongkos_angkut'],
                'is_biaya' => $form['jenis_oa'],
                'internal_notes' => $form['internal_notes'],
                'keterangan' => $form['catatan_po'],
                'iuran_migas' => $form['iuran_migas'],
                'nominal_migas' => $form['nominal_migas'],

                // reset approval
                'disposisi_po' => 1,
                'cfo_result' => 0,
                'ceo_result' => 0,
                'revert_cfo' => 0,
                'revert_ceo' => 0,

                'lastupdate_time' => now(),
                'lastupdate_by' => $user['name'],
                'lastupdate_ip' => request()->ip(),
            ];
            if (
                $po->is_close != 1 &&
                $po->is_cancel != 1 &&
                $po->ceo_result == 1 &&
                $po->revert_ceo == 0 &&
                ($po->resubmission_count ?? 0) < 3
            ) {
                $this->saveHistory($po,$form['catatan_resubmit']);

                $po->resubmission_count = ($po->resubmission_count ?? 0) + 1;
                $po->is_resubmission = 1;
                $po->resubmission_date = now();

                //update PO
                $updateData['resubmission_count'] = $po->resubmission_count;
                $updateData['is_resubmission'] = 1;
                $updateData['resubmission_date'] = $po->resubmission_date;
            }

            DB::table('inventory_vendor_po')
            ->where('id_master', $id)
            ->update($updateData);

            //update SYOP lama
            DB::connection('mysql_old')
            ->table('new_pro_inventory_vendor_po')
            ->where('id_master', $id)
            ->update($updateData);

            // UPDATE ACCURATE
            if ($po->id_accurate) {

                $delete = $this->deleteAccuratePO(
                    $po->id_accurate
                );

                if (($delete['s'] ?? false) == false) {
                    throw new Exception('Gagal delete Accurate');
                }

                $items = $this->buildDetailItems($form);

                $expenses = $this->buildExpenses($form);

                $save = $this->sendToAccurate(
                    $form,
                    $items,
                    $expenses,
                    $po->nomor_po
                );

                if (($save['s'] ?? false) == false) {
                    throw new Exception('Gagal save Accurate');
                }

                $newId = $save['r']['id'];

                $close = $this->closeAccurate(
                    $newId,
                    $form,
                    'Menunggu Approve'
                );

                if (($close['s'] ?? false) == false) {
                    throw new Exception('Gagal close Accurate');
                }

                if ($po->disposisi_po>1){
                
                    DB::afterCommit(function () use ($po, $form, $user) { 
                        $emails = User::whereHas('roles', function ($q) {
                           $q->where('nama', 'Chief Executive Officer');
                       })
                       ->pluck('email')
                       ->toArray();
    
                        Mail::to($emails)->send( new POTradingMail( $po->nomor_po, $form, $user, 'resubmit' ) ); 
                    });
                }
                
                DB::table('inventory_vendor_po')
                    ->where('id_master', $id)
                    ->update([
                        'id_accurate' => $newId
                    ]);

                //update syop old
                DB::connection('mysql_old')
                    ->table('new_pro_inventory_vendor_po')
                    ->where('id_master', $id)
                    ->update([
                        'id_accurate' => $newId
                    ]);
            }

            return [ 'success' => true, 'message' => 'PO berhasil diupdate' ];
        });
    }
    public function approveCFO($id, array $form, array $user)
    {
        try {

            $result = DB::transaction(function () use ($id, $form, $user) {

                $po = InventoryVendorPo::where('id_master', $id)
                    ->firstOrFail();

                $po_old = InventoryVendorPoOld::where('id_master', $id)
                    ->firstOrFail();

                if ($form['decision'] == 1) {

                    $po->update([
                        'cfo_result'   => 1,
                        'cfo_summary'  => $form['note'] ?? null,
                        'cfo_pic'      => $user['name'],
                        'cfo_tanggal'  => now(),
                        'disposisi_po' => 2,
                    ]);

                    $po_old->update([
                        'cfo_result'   => 1,
                        'cfo_summary'  => $form['note'] ?? null,
                        'cfo_pic'      => $user['name'],
                        'cfo_tanggal'  => now(),
                        'disposisi_po' => 2,
                    ]);
                }

                if ($form['decision'] == 2) {

                    $po->update([
                        'cfo_result'         => 2,
                        'revert_cfo'         => 1,
                        'revert_cfo_summary' => $form['note'] ?? null,
                        'cfo_pic'            => $user['name'],
                        'cfo_tanggal'        => now(),
                        'disposisi_po'       => 3,
                    ]);

                    $po_old->update([
                        'cfo_result'         => 2,
                        'revert_cfo'         => 1,
                        'revert_cfo_summary' => $form['note'] ?? null,
                        'cfo_pic'            => $user['name'],
                        'cfo_tanggal'        => now(),
                        'disposisi_po'       => 3,
                    ]);


                }
                

                // 🔥 EMAIL SETELAH COMMIT
                DB::afterCommit(function () use ($po, $form, $user) {

                    $emails = User::whereHas('roles', function ($q) {
                        $q->where('nama', 'Chief Executive Officer');
                    })
                    ->pluck('email')
                    ->toArray();
                    $type = $form['decision'] == 1 ? 'need_ceo' : 'rejected';

                    Mail::to($emails)->send(
                        new POTradingMail(
                            $po->nomor_po,
                            [
                                'vendor' => $po->id_vendor,
                                'produk' => $po->id_produk,
                                'volume_po' => $po->volume_po,
                                'harga_tebus' => $po->harga_tebus,
                            ],
                            $user,
                            $type
                        )
                    );
                });

                return [
                    'success' => true,
                    'message' => 'Approval CFO berhasil',
                ];
            });

            return $result;

        } catch (Throwable $e) {

            Log::error('Approve CFO Error', [
                'id' => $id,
                'form' => $form,
                'user' => $user,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function approveCEO($id, array $form, array $user)
    {
        return DB::transaction(function () use ($id, $form, $user) {

            $po = InventoryVendorPo::where('id_master', $id)
                ->firstOrFail();

            $po_old = InventoryVendorPoOld::where('id_master', $id)
                ->firstOrFail();

            // =========================
            // APPROVE
            // =========================
            if ($form['decision'] == 1) {

                $po->update([
                    'ceo_result'   => 1,
                    'ceo_summary'  => $form['note'] ?? null,
                    'ceo_pic'      => $user['name'],
                    'ceo_tanggal'  => now(),
                    'disposisi_po' => 4,
                ]);
                $po_old->update([
                    'ceo_result'   => 1,
                    'ceo_summary'  => $form['note'] ?? null,
                    'ceo_pic'      => $user['name'],
                    'ceo_tanggal'  => now(),
                    'disposisi_po' => 4,
                ]);
            }

            // =========================
            // REJECT / REVERT
            // =========================
            if ($form['decision'] == 2) {

                $po->update([
                    'ceo_result'         => 2,
                    'revert_ceo'         => 1,
                    'revert_ceo_summary' => $form['note'] ?? null,
                    'ceo_pic'            => $user['name'],
                    'ceo_tanggal'        => now(),
                    'disposisi_po'       => 5,
                ]);
                $po_old->update([
                    'ceo_result'         => 2,
                    'revert_ceo'         => 1,
                    'revert_ceo_summary' => $form['note'] ?? null,
                    'ceo_pic'            => $user['name'],
                    'ceo_tanggal'        => now(),
                    'disposisi_po'       => 5,
                ]);
            }

            $res=$this->openAccurate($po->id_accurate,  $po->id_terminal);
                
            if (!$res || ($res['s'] ?? false) == false) {

                Log::info('Accurate response ', $res);

                $message = is_array($res['d'])
                    ? ($res['d']['message'] ?? json_encode($res['d']))
                    : $res['d'];

                throw new \Exception($message);
            }
            DB::afterCommit(function () use ($po, $form, $user) {

                $emails = User::whereHas('departemen', function ($q) {
                    $q->where('kode', 'PROC');
                })
                ->pluck('email')
                ->toArray();

                $type = $form['decision'] == 1 ? 'approved' : 'rejected';

                Mail::to($emails)->send(
                    new POTradingMail(
                        $po->nomor_po,
                        [
                            'vendor' => $po->id_vendor,
                            'produk' => $po->id_produk,
                            'volume_po' => $po->volume_po,
                            'harga_tebus' => $po->harga_tebus,
                        ],
                        $user,
                        $type
                    )
                );
            });

            return [
                'success' => true,
                'message' => 'Approval CFO berhasil disimpan',
            ];
        });
    }
    // private function getCfoEmails()
    // {
    //     return DB::table('users')
    //         ->where('role', 'CFO')
    //         ->pluck('email')
    //         ->toArray();
    // }
   private function saveHistory($po,$catatan_resubmit): void
    {
        $data = (array) $po;

        unset($data['id']);
        unset($data['id_master']);

        $data['id_po_supplier'] = $po->id_master;
        $data['keterangan_resubmission'] = $catatan_resubmit;

        DB::table('inventory_vendor_po_history')->insert($data);
        DB::connection('mysql_old')
        ->table('new_pro_inventory_vendor_po_history')
        ->insert($data);
    }


    public function cancel($id, $cancelReason)
    {
        if (!$cancelReason) {
            throw new Exception("KOSONG");
        }

   
        return DB::transaction(function () use ($id, $cancelReason) {

            $po = InventoryVendorPo::findOrFail($id);
            $po->update([
                'is_cancel' => 1,
                'keterangan_cancel' => $cancelReason,
            ]);

             DB::connection('mysql_old')
            ->table('new_pro_inventory_vendor_po')
            ->where('id_master', $id)
            ->update([
                'is_cancel' => 1,
                'keterangan_cancel' => $cancelReason,
            ]);
          
            // kalau tidak ada accurate id langsung commit
            if (!$po->id_accurate) {
                return true;
            }

           $res = $this->closeAccurate(
                $po->id_accurate,
                ['terminal' => $po->id_terminal],
                $cancelReason
            );
            
            if (!$res['s']) {
                throw new Exception($res['d'][0] ?? 'Accurate Error');
            }

            return true;
        });
    }

    public function close($id, $tglClose, $volumeClose)
    {

        if (!$tglClose) {
            throw new Exception("KOSONG");
        }

        return DB::transaction(function () use ($id, $tglClose, $volumeClose) {
            $po = InventoryVendorPo::findOrFail($id);

            $po->update([
                'is_close' => 1,
                'tanggal_close' => $tglClose,
                'volume_close' => $volumeClose,
            ]);

            
            DB::connection('mysql_old')
            ->table('new_pro_inventory_vendor_po')
            ->where('id_master', $id)
            ->update([
                'is_close' => 1,
                'tanggal_close' => $tglClose,
                'volume_close' => $volumeClose,
            ]);

            if (!$po->id_accurate) {
                return true;
            }

            // CASE 1: full close → delete PO di Accurate
            if ($volumeClose == $po->volume_po) {

                $res = $this->deleteAccuratePO([
                    "id" => $po->id_accurate
                ]);

                if (!$res['s']) {
                    throw new Exception($res['d'][0] ?? 'Accurate Delete Error');
                }

                return true;
            }else{
                // CASE 2: partial close → update manual close
                // $payload = [
                //     "id" => $po->id_accurate,
                //     'toAddress' => 'Graha Irama Lt 6 Jl. HR Rasuna Said Kota Administrasi Jakarta Selatan DKI Jakarta Indonesia',
                //     'branchName' => $user->cabang->nama_cabang === 'Kantor Pusat'
                //     ? 'Head Office'
                //     : $user->cabang->nama_cabang,
                //     "manualClosed" => true,
                //     "closeReason" => "CLOSE PO {$tglClose} - volume close = {$volumeClose}",
                // ];

                $res = $this->closeAccurate( $po->id_accurate,['terminal' => $po->id_terminal], "CLOSE PO {$tglClose} - volume close = {$volumeClose}");

                if (!$res['s']) {
                    throw new Exception($res['d'][0] ?? 'Accurate Error');
                }

                return true;
            }

         
        });
    }

    public function autoApproveCfo()
    {
    try {
        $result = DB::transaction(function () {

            $pos = InventoryVendorPo::where('disposisi_po', '>', 0)
                ->where('ceo_result', 0)
                ->where('cfo_result', 0)
                ->whereYear('tanggal_inven', '>=', 2024)
                ->where('created_time', '<=', now()->subMinutes(20))
                ->get();

            if ($pos->isEmpty()) {
                return [
                    'success' => true,
                    'message' => 'Tidak ada PO yang perlu auto approve',
                    'total' => 0,
                ];
            }

            $systemUser = [
                'name' => 'Syop System',
                'email' => null,
            ];

            foreach ($pos as $po) {
                $po->update([
                    'cfo_result'   => 1,
                    'cfo_summary'  => 'Approved By System Automatically',
                    'cfo_pic'      => $systemUser['name'],
                    'cfo_tanggal'  => now(),
                    'disposisi_po' => 2,
                ]);

                InventoryVendorPoOld::where('id_master', $po->id_master)
                    ->update([
                        'cfo_result'   => 1,
                        'cfo_summary'  => 'Approved By System Automatically',
                        'cfo_pic'      => $systemUser['name'],
                        'cfo_tanggal'  => now(),
                        'disposisi_po' => 2,
                    ]);
            }

            DB::afterCommit(function () use ($pos, $systemUser) {
                $emails = User::whereHas('roles', function ($q) {
                        $q->where('nama', 'Chief Executive Officer');
                    })
                    ->whereNotNull('email')
                    ->pluck('email')
                    ->filter()
                    ->toArray();

                if (empty($emails)) {
                    return;
                }

                foreach ($pos as $po) {
                    Mail::to($emails)->send(
                        new POTradingMail(
                            $po->nomor_po,
                            [
                                'vendor' => $po->id_vendor,
                                'produk' => $po->id_produk,
                                'volume_po' => $po->volume_po,
                                'harga_tebus' => $po->harga_tebus,
                            ],
                            $systemUser,
                            'need_ceo'
                        )
                    );
                }
            });

            return [
                'success' => true,
                'message' => 'Auto approval CFO berhasil',
                'total' => $pos->count(),
            ];
        });

        return $result;

    } catch (Throwable $e) {
        Log::error('Auto Approve CFO PO Supplier Error', [
            'message' => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'message' => $e->getMessage(),
            'total' => 0,
        ];
    }
}

}