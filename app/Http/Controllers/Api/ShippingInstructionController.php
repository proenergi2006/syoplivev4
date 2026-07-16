<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryVendorPoShip;
use App\Services\Trade\ShippingInstruction\ShippingInstructionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShippingInstructionController extends Controller
{

    protected $shippingInstructionService;

    public function __construct(ShippingInstructionService $shippingInstructionService)
    {
        $this->shippingInstructionService = $shippingInstructionService;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $data = InventoryVendorPoShip::with('po_supplier','po_supplier.vendor','po_supplier.terminal')
            ->where('is_cancel', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $form = $request->all();
        $validated = $request->validate([
            'id_vendor_po' => 'required',
            'nomor_req' => 'required',
            'transportir_id' => 'required',
            'tipe_kapal' => 'required',
            'vessel_id' => 'required',
            'loading_port_id' => 'required',
            'discharging_port_id' => 'required',
        ]);

        $data = $this->shippingInstructionService->create(
            $form,
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Shipping Instruction berhasil dibuat',
            'data' => $data,
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
   public function show($id)
    {
        $data = InventoryVendorPoShip::with([
            'po_supplier', 'load_port','discharge_port','transportir','vessel','vessel_tb','po_supplier.vendor'
        ])->find($id);

        if (!$data) {
            return response()->json([
                'message' => 'Data not found',
            ], 404);
        }

        return response()->json($data);
    }
    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id, ShippingInstructionService $service)
    {
        $data = $service->update($id, $request->all(), auth()->user());

        return response()->json([
            'message' => 'success',
            'data' => $data
        ]);
    }
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
    public function byPo($poId)
    {
         $data = InventoryVendorPoShip::with('po_supplier')
                ->where('id_vendor_po', $poId)
                ->first();
                
               
        return response()->json($data);
    }

    public function cancel(Request $request)
    {
        $this->shippingInstructionService->cancel(
           $request->id,
            $request->cancel_reason,
            auth()->user()
        );

        return response()->json([
            'message' => 'Shipping Instruction berhasil dibatalkan'
        ]);
    }

public function print(Request $request, $id)
{
    $tipe = $request->tipe;

    $si = InventoryVendorPoShip::with([
        'po_supplier',
        'transportir',
        'load_port',
        'discharge_port',
        'vessel_tb',
        'vessel',
    ])->findOrFail($id);

    switch ($tipe) {

        case 'shipping_request':
            $view = 'pdf.trading.shipping-instruction.shippingRequest';
            break;

        case 'shipping_instruction':
            $view = 'pdf.trading.shipping-instruction.shippingInstruction';
            break;

        case 'LO':
            $view = 'pdf.trading.shipping-instruction.loadingOrder';
            break;

        case 'spal':
            $view = 'pdf.trading.shipping-instruction.spal';
            break;

        default:
            abort(404);
    }

    $pdf = Pdf::loadView($view, [
        'res' => $si,
    ]);

    return $pdf->stream("SI-{$id}-{$tipe}.pdf");
}
public function approve(Request $request, $id)
{
    $user = auth()->user();
    $role = $user->role;

    return DB::transaction(function () use ($request, $id, $user, $role) {

        $si = InventoryVendorPoShip::findOrFail($id);
        switch ( $request->role) {

            case 'Logistic Manager':
                return $this->shippingInstructionService
                    ->logistikApprove($si, $user, $request);

            case 'CFO':
                return $this->shippingInstructionService
                    ->cfoProcess($si, $user, $request);

            case 'CEO':
                return $this->shippingInstructionService
                    ->ceoProcess($si, $user, $request);

            default:
                abort(403, 'Role tidak memiliki akses approval');
        }
    });
}
}
