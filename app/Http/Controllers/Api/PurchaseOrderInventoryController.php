<?php

namespace App\Http\Controllers\Api;

use App\Exports\PurchaseOrderInventoryExport;
use App\Http\Controllers\Controller;
use App\Models\InventoryGainLoss;
use App\Models\InventoryVendorPo;
use App\Models\InventoryVendorPoHistory;
use App\Models\InventoryVendorPoOld;
use App\Models\InventoryVendorReceive;
use App\Services\AccurateApiService;
use App\Services\Trade\PurchaseOrder\PurchaseOrderInventoryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseOrderInventoryController extends Controller
{

    protected $poService;

    public function __construct(PurchaseOrderInventoryService $poService)
    {
        $this->poService = $poService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = InventoryVendorPo::with([
            'vendor',
            'produk',
            'terminal'
        ])
        ->withSum('goodReceipt as total_bl', 'volume_bol')
        ->withSum('goodReceipt as total_ri', 'volume_terima');

        // FILTER
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('nomor_po', 'ILIKE', '%' . $request->search . '%');
            });
        }

        if ($request->vendor) {
            $query->where('id_vendor', $request->vendor);
        }

        if ($request->terminal) {
            $query->where('id_terminal', $request->terminal);
        }

        if ($request->status) {
            $query->where('disposisi_po', $request->status);
        }

        if ($request->tanggal_awal && $request->tanggal_akhir) {
            $query->whereBetween('tanggal_inven', [
                $request->tanggal_awal,
                $request->tanggal_akhir
            ]);
        }

        foreach ($query as $item) {

            $map = [
                1 => 'Verifikasi CFO',
                2 => 'Verifikasi CEO',
                3 => 'Ditolak CFO',
                4 => 'Terverifikasi',
                5 => 'Ditolak CEO',
            ];

            $item->status_label = $map[$item->disposisi_po] ?? '-';
        }
        $data = $query
            ->orderByDesc('tanggal_inven')
            ->paginate($request->per_page ?? 25);

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
        try {

            // ambil data form
            $form = $request->all();

            // data user login
            $user = auth()->user()->toArray();

            // panggil service
            $result = $this->poService->create($form, $user);

            return response()->json([
                'success' => true,
                'message' => 'PO berhasil dibuat',
                'data' => $result
            ]);

        }catch (\Throwable $e) {

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ], 500);

            }
    }
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
         $po = InventoryVendorPo::with([
            'vendor',
            'terminal',
            'produk'
        ])->where('id_master', $id)->firstOrFail();

        return response()->json($po);
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
    public function update(Request $request, $id)
    {
        return response()->json(
            $this->poService->update(
                $id,
                $request->all(),
                auth()->user()->toArray()
            )
        );
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

    public function approveCFO(Request $request, $id)
    {
        $result =$this->poService->approveCFO(
            $id,
            $request->all(),
            [
                'name' => auth()->user()->name,
            ]
        );

        return response()->json($result);
    }
    public function approveCEO(Request $request, $id)
    {
        $result =$this->poService->approveCEO(
            $id,
            $request->all(),
            [
                'name' => auth()->user()->name,
            ]
        );

        return response()->json($result);
    }

    public function print($id)
    {
        $po = InventoryVendorPo::with(['vendor', 'produk'])->findOrFail($id);

        $pdf = Pdf::loadView('pdf.trading.po', compact('po'));

        return $pdf->stream('purchase-order.pdf');
        
    }

    public function printGainLoss($id)
    {
        $po = InventoryVendorPo::with(['vendor', 'produk'])->findOrFail($id);
        $gr = InventoryVendorReceive::where('id_po_supplier', $id)->firstOrFail();
        $gl = InventoryGainLoss::where('id_po_supplier', $id)->firstOrFail();

        $pdf = Pdf::loadView('pdf.trading.poGainLoss', compact('po','gr','gl'));

        return $pdf->stream('purchase-order-gain-loss.pdf');
        
    }

    public function history($id)
    {
        $histories = InventoryVendorPoHistory::with([
                'vendor',
                'produk',
                'terminal'
            ])
            ->where('id_po_supplier', $id)
            ->get();

        $latestHistory = $histories->first(); // perubahan terakhir

        return response()->json([
            'history' => $histories,
            'latest' => $latestHistory
        ]);
    }
    
   public function cancel(Request $request, $id)
    {
        try {
            $this->poService->cancel($id, $request->cancel_reason);

            return response()->json([
                'success' => true,
                'message' => 'PO berhasil dicancel',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function close(Request $request, $id)
    {
        try {
            $this->poService->close(
                $id,
                $request->tanggal_close,
                $request->volume_close
            );

            return response()->json([
                'success' => true,
                'message' => 'PO berhasil diclose',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    
    public function export(Request $request)
    {
        return Excel::download(
            new PurchaseOrderInventoryExport($request),
            'Rekap-PO-' . now()->format('dmYHis') . '.xlsx'
        );
    }
}
