<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TerminalController extends Controller
{
    public function terminal() {
        return response()->json(Terminal::all(['id', 'nama_terminal','lokasi_terminal']));
    }
    public function index(Request $request)
    {
        $q = Terminal::query()
            ->with([
                'cabang:id,kode,nama',
                'area:id,nama_area', // sesuaikan kolom area kamu
            ]);
    
        if ($request->filled('search')) {
            $s = (string) $request->input('search');
            $q->where(function ($qq) use ($s) {
                $qq->where('nama_terminal', 'ilike', "%{$s}%")
                   ->orWhere('inisial_terminal', 'ilike', "%{$s}%")
                   ->orWhere('lokasi_terminal', 'ilike', "%{$s}%");
            });
        }
    
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
    
        if ($request->filled('id_cabang')) {
            $q->where('id_cabang', (int) $request->input('id_cabang'));
        }
    
        if ($request->filled('id_area')) {
            $q->where('id_area', (int) $request->input('id_area'));
        }
    
        $perPage = (int) $request->input('per_page', 10);
    
        return response()->json(
            $q->orderBy('nama_terminal')->paginate($perPage)
        );
    }
    
    public function store(Request $request)
    {
        $data = $request->validate([
            'nama_terminal' => ['required', 'string', 'max:150'],
            'inisial_terminal' => ['nullable', 'string', 'max:30'],

            'tanki_terminal' => ['nullable', 'string', 'max:100'],
            'lokasi_terminal' => ['nullable', 'string', 'max:150'],
            'kategori_terminal' => ['required', Rule::in(['Depo', 'Dispenser', 'Truck Gantung'])],

            'batas_atas' => ['nullable', 'numeric'],
            'batas_bawah' => ['nullable', 'numeric'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],

            'alamat_terminal' => ['nullable', 'string'],
            'telp_terminal' => ['nullable', 'string', 'max:50'],
            'fax_terminal' => ['nullable', 'string', 'max:50'],
            'cc_terminal' => ['nullable', 'string', 'max:100'],
            'catatan_terminal' => ['nullable', 'string'],

            'is_active' => ['nullable', 'boolean'],

            'id_cabang' => ['nullable', 'integer'],
            'id_area' => ['nullable', 'integer'],

            'att_terminal' => ['nullable', 'file', 'max:5120'], // 5MB
        ]);

        // audit
        $data['created_time'] = now();
        $data['created_ip'] = $request->ip();
        $data['created_by'] = optional($request->user())->email ?? 'system';

        // upload
        if ($request->hasFile('att_terminal')) {
            $data['att_terminal'] = $request->file('att_terminal')->store('terminal', 'public');
        }

        $row = Terminal::create($data);

        return response()->json($row, 201);
    }

    public function show(Terminal $terminal)
{
    $terminal->load([
        'cabang:id,kode,nama',
        'area:id,nama_area',
    ]);

    return response()->json($terminal);
}

    public function update(Request $request, Terminal $terminal)
    {
        $data = $request->validate([
            'nama_terminal' => ['required', 'string', 'max:150'],
            'inisial_terminal' => ['nullable', 'string', 'max:30'],

            'tanki_terminal' => ['nullable', 'string', 'max:100'],
            'lokasi_terminal' => ['nullable', 'string', 'max:150'],
            'kategori_terminal' => ['required', Rule::in(['Depo', 'Dispenser', 'Truck Gantung'])],

            'batas_atas' => ['nullable', 'numeric'],
            'batas_bawah' => ['nullable', 'numeric'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],

            'alamat_terminal' => ['nullable', 'string'],
            'telp_terminal' => ['nullable', 'string', 'max:50'],
            'fax_terminal' => ['nullable', 'string', 'max:50'],
            'cc_terminal' => ['nullable', 'string', 'max:100'],
            'catatan_terminal' => ['nullable', 'string'],

            'is_active' => ['nullable', 'boolean'],
            'id_cabang' => ['nullable', 'integer'],
            'id_area' => ['nullable', 'integer'],

            'att_terminal' => ['nullable', 'file', 'max:5120'],
            'remove_att_terminal' => ['nullable', 'boolean'],
        ]);

        // audit
        $data['lastupdate_time'] = now();
        $data['lastupdate_by'] = optional($request->user())->email ?? 'system';

        // remove attachment
        $remove = filter_var($request->input('remove_att_terminal'), FILTER_VALIDATE_BOOLEAN);
        if ($remove && $terminal->att_terminal) {
            Storage::disk('public')->delete($terminal->att_terminal);
            $data['att_terminal'] = null;
        }

        // upload new file
        if ($request->hasFile('att_terminal')) {
            if ($terminal->att_terminal) {
                Storage::disk('public')->delete($terminal->att_terminal);
            }
            $data['att_terminal'] = $request->file('att_terminal')->store('terminal', 'public');
        }

        $terminal->update($data);

        return response()->json($terminal);
    }

  

    public function destroy(Terminal $terminal)
    {
        if ($terminal->att_terminal) {
            Storage::disk('public')->delete($terminal->att_terminal);
        }

        $terminal->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
