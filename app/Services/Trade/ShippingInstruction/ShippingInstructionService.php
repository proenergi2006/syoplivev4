<?php

namespace App\Services\Trade\ShippingInstruction;

use App\Models\InventoryVendorPoShip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class ShippingInstructionService
{
    public function create(array $data, $user)
    {
        return DB::transaction(function () use ($data, $user) {

            // =========================
            // 1. Generate nomor request
            // =========================
            $year = date('Y');
            $month = date('m');

            $romawi = [
                "1"=>"I","II","III","IV","V","VI","VII","VIII","IX","X","XI","XII"
            ];

            $last =InventoryVendorPoShip::where('nomor_req', 'like', "%$year%")
                ->orderByDesc('nomor_req')
                ->first();

            if ($last) {
                $explode = explode('/', $last->nomor_req);
                $urut = ((int) $explode[0]) + 1;
            } else {
                $urut = 1;
            }

            $no = str_pad($urut, 3, '0', STR_PAD_LEFT);
            $nomorReq = $no . '/PE-Purch/250/' . $romawi[(int)$month] . '/' . $year;

            // =========================
            // 2. Insert SI Request
            // =========================
            $shipReq = InventoryVendorPoShip::create([
                'id_vendor_po' => $data['id_vendor_po'],
                'id_transportir' => $data['transportir_id'] ?? null,
                'tipe_kapal' => $data['tipe_kapal'] == 'Other' ? 2 : 1 ,
                'id_vessel_tb' => $data['vessel_tb_id'] ?? null,
                'id_vessel' => $data['vessel_id'] ?? null,
                'id_terminal_discharging' => $data['discharging_port_id'],
                'flag' => $data['flag'] ?? null,
                'quantity' => $data['volume_po'] ?? 0,
                'nomor_req' => $nomorReq,
                'loading_port' => $data['loading_port_id'] ?? null,
                'etl_date_first' => $data['eta_loading'] ?? null,
                'etl_date_last' => $data['eta_arrival'] ?? null,
                'cargo_name' => $data['cargo_name'] ?? null,
                'lead_time' => $data['lead_time'] ?? null,
                'losstype' => $data['loss_type'] ?? null,
                'loss_tolerance' => $data['loss'] ?? 0,
                'satuan' => $data['satuan'] ?? null,
                'freight' => $data['freight'] ?? null,
                'demurrage' => $data['demurrage'] ?? 0,
                'ket_ship' => $data['catatan_purchasing'] ?? null,
                'country_origin' => $data['country'] ?? null,
                'shipper' => $data['shipper'] ?? null,
                'consignee' => $data['consignee'] ?? null,
                'bl_ship' => $data['bl_ship_on_board'] ?? null,
                'created_at' => now(),
                'created_by' => $user->name,
            ]);

            // =========================
            // 3. Return result (controller nanti handle redirect/email kalau mau)
            // =========================
            return $shipReq;
        });
    }

    public function update(int $id, array $data, $user)
    {
        return DB::transaction(function () use ($id, $data, $user) {

            $shipReq = InventoryVendorPoShip::findOrFail($id);

            $shipReq->update([
                'id_transportir' => $data['transportir_id'] ?? null,
                'id_vessel_tb' => $data['vessel_tb_id'] ?? null,
                'tipe_kapal' => $data['tipe_kapal'] == 'Other' ? 2 : 1 ,
                'id_vessel' => $data['vessel_id'] ?? null,
                'id_terminal_discharging' => $data['discharging_port_id'] ?? null,

                'flag' => $data['flag'] ?? null,
                'quantity' => $data['volume_po'] ?? 0,

                'etl_date_first' => $data['eta_loading'] ?? null,
                'etl_date_last' => $data['eta_arrival'] ?? null,

                'cargo_name' => $data['cargo_name'] ?? null,
                'loss_tolerance' => $data['loss'] ?? 0,
                'freight' => $data['freight'] ?? null,
                'demurrage' => $data['demurrage'] ?? null,

                'country_origin' => $data['country_origin'] ?? null,
                'shipper' => $data['shipper'] ?? null,
                'consignee' => $data['consignee'] ?? null,

                'bl_ship' => $data['bl_ship'] ?? null,
                'ket_ship' => $data['ket_ship'] ?? null,

                'loading_port' => $data['loading_port_id'] ?? null,
                'leadtime' => $data['lead_time'] ?? null,
                'losstype' => $data['loss_type'] ?? null,
                'satuan' => $data['satuan'] ?? null,

                // reset logic dari SQL lama kamu
                'nomor_si' => null,
                'status' => 0,
                'updated_at' => now(),
                'updated_by' => $user->name,

                'ket_log' => null,
                'log_pic' => null,
                'log_tanggal' => null,

                'mgrfin_result' => 0,
                'mgrfin_pic' => null,
                'mgrfin_tanggal' => null,
                'mgrfin_summary' => null,

                'cfo_result' => 0,
                'cfo_pic' => null,
                'cfo_tanggal' => null,
                'cfo_summary' => null,

                'ceo_result' => 0,
                'ceo_pic' => null,
                'ceo_tanggal' => null,
                'ceo_summary' => null,
            ]);

            return $shipReq;
        });
    }

    // =========================
    // CANCEL
    // =========================
    public function cancel(int $id, string $ketCancel, $user)
    {
        return DB::transaction(function () use ($id, $ketCancel, $user) {

            $shipReq = InventoryVendorPoShip::findOrFail($id);

            $shipReq->update([
                'is_cancel' => 1,
                'ket_cancel' => $ketCancel,
                'updated_at' => now(),
                'updated_by' => $user->name,
            ]);

            return $shipReq;
        });
    }
      public function logistikApprove($si, $user, $request)
    {
        $noReq = $this->generateSiNumber($si);

        $si->update([
            'nomor_si' => $noReq,
            'ket_log' => $request->note,
            'status' => 1,
            'log_tanggal' => now(),
            'log_pic' => $user->name,
        ]);

        // $this->sendEmailByRole(4,
        //     "Persetujuan Shipping Instruction [{$si->nomor_req}]",
        //     "{$user->fullname} mengirim SI untuk approval CFO"
        // );

        return $si;
    }

    // =========================
    // CFO (ROLE 4)
    // =========================
    public function cfoProcess($si, $user, $request)
    {
        $si->update([
            'cfo_summary' => $request->note,
            'cfo_result' => $request->status,
            'status' => 2,
            'cfo_tanggal' => now(),
            'cfo_pic' => $user->name,
        ]);

        // if ($request->approve == 1) {

        //     $this->sendEmailByRole(21,
        //         "Persetujuan SI [{$si->nomor_req}]",
        //         "{$user->fullname} menyetujui SI, lanjut ke CEO"
        //     );

        // } else {

        //     $this->sendEmailByRole(5,
        //         "Revisi SI [{$si->nomor_req}]",
        //         "{$user->fullname} meminta revisi: {$request->cfo_summary}"
        //     );
        // }

        return $si;
    }

    // =========================
    // CEO (ROLE 21 / default)
    // =========================
    public function ceoProcess($si, $user, $request)
    {
        $si->update([
            'ceo_summary' => $request->note,
            'ceo_result' => $request->status,
            'status' => 3,
            'ceo_tanggal' => now(),
            'ceo_pic' => $user->name,
        ]);

        // if ($request->approve == 1) {

        //     $this->sendEmailByRole([5,16],
        //         "SI Approved [{$si->nomor_req}]",
        //         "{$user->fullname} telah menyetujui SI"
        //     );

        // } else {

        //     $this->sendEmailByRole(5,
        //         "Revisi SI [{$si->nomor_req}]",
        //         "{$user->fullname} meminta revisi: {$request->ceo_summary}"
        //     );
        // }

        return $si;
    }

    // =========================
    // GENERATE NOMOR SI
    // =========================
    private function generateSiNumber($si)
    {
        // $last = InventoryVendorPoShip::where('id_master', $si->id)->first();
        // dd($si);
        $explode = explode("/", $si->nomor_req);

        $urut = ($explode[0] ?? 0) + 1;
        $month = $explode[3] ?? date('m');
        $year = $explode[4] ?? date('Y');

        return sprintf("%03s", $urut)
            . "/PE/LOG-HO/250/"
            . $month . "/" . $year;
    }
}