<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Pbbkb;
use Illuminate\Validation\Rule;

class PbbkbController extends Controller
{
    public function pbbkb() {
        return response()->json(Pbbkb::all(['id', 'nilai_pbbkb',
        'ket_pbbkb',
        'is_active']));
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $q = Pbbkb::query();

        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('nilai_pbbkb', 'like', "%{$s}%")
                   ->orWhere('ket_pbbkb', 'like', "%{$s}%");
            });
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->input('per_page', 15);

        return response()->json(
            $q->orderBy('nilai_pbbkb')->paginate($perPage)
        );
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
        $request->merge([
            'nilai_pbbkb' => str_replace(',', '.', $request->nilai_pbbkb),
        ]);

        $data = $request->validate([
            'nilai_pbbkb' => ['required','numeric'],
            'ket_pbbkb' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['created_time'] = now();
        $data['created_ip'] = $request->ip();
        $data['created_by'] = optional($request->user())->email ?? 'system';

        $row = Pbbkb::create($data);
        return response()->json($row, 201);
    }

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
    public function update(Request $request,  Pbbkb $pbbkb)
    {
        $request->merge([
            'nilai_pbbkb' => floatval(str_replace(',', '.', trim($request->nilai_pbbkb))),
        ]);

        // Validasi
        $data = $request->validate([
            'nilai_pbbkb' => ['required','numeric','min:0'],
            'ket_pbbkb' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['lastupdate_time'] = now();
        $data['lastupdate_by'] = optional($request->user())->email ?? 'system';

        $pbbkb->update($data);

        return response()->json($request->nilai_pbbkb);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Pbbkb $pbbkb)
    {
         $pbbkb->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
