<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryVendorPo;
use App\Services\AccurateApiService;
use App\Services\PurchaseOrderInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        ]);

        // FILTER
        if ($request->keyword) {
            $query->where(function ($q) use ($request) {
                $q->where('nomor_po', 'like', '%' . $request->keyword . '%');
            });
        }

        if ($request->id_vendor) {
            $query->where('id_vendor', $request->id_vendor);
        }

        if ($request->id_terminal) {
            $query->where('id_terminal', $request->id_terminal);
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

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
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
}
