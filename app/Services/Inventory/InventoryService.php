<?php

namespace App\Services\Inventory;

use App\Models\InventoryDepot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    public function postGR($form, $idGR, $user)
    {
        $poItems = DB::table('inventory_vendor_po')
            ->where('id_master', $form['id_po_supplier'])
            ->get();
        foreach ($poItems as $item) {
            DB::table('inventory_depot')->insert([
                'id_datanya' => 'generated_po',
                'id_jenis' => 21,
                'id_produk' => $item->id_produk,
                'id_terminal' => $item->id_terminal,
                'id_vendor' => $item->id_vendor,
                'id_po_supplier' => $form['id_po_supplier'],
                'id_po_receive' => $idGR,
                'tanggal_inven' => $form['tgl_terima'],
                'in_inven' => $form['volume_terima'],
                'keterangan' =>  'Penerimaan stock dari PO supplier',
                'created_time' => now(),
                'created_by' => $user->name,
                'created_ip' => request()->ip()
            ]);
        }
    }

     public function deleteGR($idPO, $idGR)
    {
        $oke = true;

        try {
             $deleted =InventoryDepot::where('id_po_supplier', $idPO)
            ->where('id_po_receive', $idGR)
            ->delete();


            $oke = $oke && ($deleted >= 0);

        } catch (\Throwable $e) {
            Log::error('Delete Inventory Depot Error', [
                'message' => $e->getMessage(),
                'id_po_supplier' => $idPO,
                'id_po_receive' => $idGR,
            ]);

            $oke = false;
        }

        return $oke;
    }
}