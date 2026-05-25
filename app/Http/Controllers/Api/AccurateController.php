<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Services\AccurateApiService;
use App\Http\Controllers\Controller;


class AccurateController extends Controller
{
    public function products(Request $request, AccurateApiService $api)
    {
        $query_params = [
            'fields' => 'id,no,name',
            'filter.itemType.val' => ['INVENTORY', 'NON_INVENTORY', 'SERVICE'],
            'sp.page' => $request->page ?? 1,
            'sp.pageSize' => 30,
        ];

        if ($request->q) {
            $query_params['filter.keywords.op'] = 'CONTAIN';
            $query_params['filter.keywords.val'] = $request->q;
            $query_params['filter.no.op'] = 'CONTAIN';
            $query_params['filter.no.val'] = $request->q;
        }

        $url = 'https://zeus.accurate.id/accurate/api/item/list.do?' . http_build_query($query_params);

        $result = $api->get($url);

        $item_details = [];
        $total_count = 0;

        if (data_get($result, 's') === true) {

            $total_count = data_get($result, 'sp.rowCount', 0);

            $item_details = collect(data_get($result, 'd', []))
                ->map(function ($item) {
                    return [
                        'id' => $item['no'],
                        'text' =>  $item['name'],
                    ];
                
                })
                ->values()
                ->all();
        }

        return response()->json([
            'success' => data_get($result, 's', false),
            'total' => $total_count,
            'data' => $item_details
        ]);
    }
    
    public function accounts(Request $request, AccurateApiService $api)
    {
        $query_params = [
            'fields' => 'id,no,nameWithIndent,accountTypeName,noWithIndent,name',
            'sp.page' => $request->page ?? 1,
            'sp.pageSize' => 30,
        ];

        if ($request->q) {
            $query_params['filter.keywords.op'] = 'CONTAIN';
            $query_params['filter.keywords.val'] = $request->q;
        }

        $url = 'https://zeus.accurate.id/accurate/api/glaccount/list.do?' . http_build_query($query_params);

        $result = $api->get($url);

        $item_details = [];
        $total_count = 0;

        if (data_get($result, 's') === true) {

            $total_count = data_get($result, 'sp.rowCount', 0);

            $item_details = collect(data_get($result, 'd', []))
                ->map(function ($item) {
                    return [
                        'id' => $item['no'],
                        'text' => html_entity_decode($item['nameWithIndent'], ENT_QUOTES | ENT_HTML5),
                        'noWithIndent' => html_entity_decode($item['noWithIndent'], ENT_QUOTES | ENT_HTML5),
                    ];
                
                })
                ->values()
                ->all();
        }

        return response()->json([
            'success' => data_get($result, 's', false),
            'total' => $total_count,
            'data' => $item_details
        ]);
    }
    public function getDetailPO(Request $request, AccurateApiService $api)
    {
        $query_params = [
            'id' => $request->id_accurate,
        ];

        $url = 'https://zeus.accurate.id/accurate/api/purchase-order/detail.do?' . http_build_query($query_params);

        $result = $api->get($url);

        if (data_get($result, 's') !== true) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail PO'
            ], 400);
        }

        $detailItems = data_get($result, 'd.detailItem', []);
        $detailExpenses = data_get($result, 'd.detailExpense', []);

        $response = [
            'kode_item' => null,
            'nama_item' => null,
            'keterangan_item' => null,
            'alokasi_item' => false,

            'kode_item_oa' => null,
            'nama_item_oa' => null,
            'keterangan_item_oa' => null,
            'alokasi_item_oa' => false,

            'biaya_pbbkb' => null,
            'alokasi_pbbkb' => false,

            'biaya_pph22' => null,
            'alokasi_pph22' => false,

            'biaya_vat' => null,
            'alokasi_vat' => false,

            'biaya_migas' => null,
            'alokasi_migas' => false,

            'biaya_oa' => null,
            'alokasi_biaya_oa' => false,
        ];

        // DETAIL ITEM
        foreach ($detailItems as $item) {

            $itemType = data_get($item, 'item.itemType');
            $itemNo = data_get($item, 'item.no');
            $itemName = data_get($item, 'item.name');
            $notes = data_get($item, 'detailNotes');
            $allocate = data_get($item, 'allocateToItemCost', false);

            if ($itemType === 'INVENTORY') {

                $response['kode_item'] = $itemNo;
                $response['nama_item'] = $itemName;
                $response['keterangan_item'] = $notes;
                $response['alokasi_item'] = $allocate;

            } else {

                $response['kode_item_oa'] = $itemNo;
                $response['nama_item_oa'] = $itemName;
                $response['keterangan_item_oa'] = $notes;
                $response['alokasi_item_oa'] = $allocate;
            }
        }

        // DETAIL EXPENSE
        foreach ($detailExpenses as $expense) {

            $name = data_get($expense, 'expenseName');
            $no = data_get($expense, 'account.no');
            $notes = data_get($expense, 'expenseNotes');
            $allocate = data_get($expense, 'allocateToItemCost', false);

            if (
                $name === 'PBBKB'
                && in_array($notes, ['null', null, 'NULL', ''])
            ) {

                $response['biaya_pbbkb'] = $no;
                $response['alokasi_pbbkb'] = $allocate;
                $response['keterangan_pbbkb'] = $notes;

            } elseif (str_contains($name, '22')) {

                $response['biaya_pph22'] = $no;
                $response['alokasi_pph22'] = $allocate;
                $response['keterangan_pph22'] = $notes;

            } elseif (str_contains($name, 'VAT')) {

                $response['biaya_lain_oa'] = $no;
                $response['alokasi_biaya_lain_oa'] = $allocate;
                $response['ket_biaya_lain_oa'] = $notes;

            } elseif (
                str_contains(strtolower($notes), 'iuran')
                || str_contains(strtolower($name), 'iuran')
            ) {

                $response['biaya_migas'] = $no;
                $response['alokasi_migas'] = $allocate;
                $response['keterangan_migas'] = $notes;

            } elseif (str_contains(strtolower($name), 'cost')) {

                $response['biaya_oa'] = $no;
                $response['alokasi_biaya_oa'] = $allocate;
                $response['keterangan_biaya_oa'] = $notes;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $response
        ]);
    }
}