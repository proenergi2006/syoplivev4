<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequestItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'purchase_request_items';

    protected $fillable = [
        'purchase_request_id',
        'nama_item',
        'qty',
        'qty_po',
        'qty_outstanding',
        'satuan',
        'spesifikasi',
        'keterangan',
        'harga_unit',
        'subtotal',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'qty_po' => 'decimal:2',
        'qty_outstanding' => 'decimal:2',
        'harga_unit' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    /**
     * Relasi ke Purchase Request
     */
    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'satuan', 'id');
    }
}
