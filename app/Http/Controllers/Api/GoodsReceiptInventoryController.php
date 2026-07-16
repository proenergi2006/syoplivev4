<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryVendorReceive;
use App\Models\InventoryVendorReceiveHistory;
use App\Services\Trade\GoodsReceiptInventoryService;
use Illuminate\Http\Request;

class GoodsReceiptInventoryController extends Controller
{
    protected $service;

    public function __construct(GoodsReceiptInventoryService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $query = InventoryVendorReceive::with('po_supplier');

        if ($request->id_po_supplier) {
            $query->where('id_po_supplier', $request->id_po_supplier);
        }

        $data = $query
        ->paginate($request->per_page ?? 25);
            // ->orderByDesc('tgl_terima')

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
    $user = auth()->user();

    $result = $this->service->create(
        $request->all(),
        $request->file('file'),
        $user
    );

    return $result;
}

// public function store(Request $request)
// {
//     dd('SAMPAI CONTROLLER');
// }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
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
    public function update($id,Request $request, GoodsReceiptInventoryService $service)
    {

        $request->validate([
            'no_terima' => 'required',
            'nama_pic' => 'required',
            'tgl_terima' => 'required',
            'volume_bol' => 'required',
            'volume_terima' => 'required',
            'harga_tebus' => 'required',
            'keterangan' => 'required',
        ]);

        // dd($request);
        try {
            $result = $service->update(
                $request->all(),
                $request->file('file'),
                $id,
                $request->user()
            );

            return response()->json($result);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {

            // $receive = InventoryVendorReceive::findOrFail($id);

            $this->service->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil dihapus'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);

        }
    }

     public function grHistory($id)
    {
         $grLog = InventoryVendorReceiveHistory::where('id_po_receive', $id)->get();

        return response()->json($grLog);
    }
}
