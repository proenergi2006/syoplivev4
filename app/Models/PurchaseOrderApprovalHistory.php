<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderApprovalHistory extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'purchase_order_approval_histories';

    protected $fillable = [
        'purchase_order_id',
        'role',
        'status',
        'notes',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    /* ===============================
     | RELATIONSHIPS
     =============================== */

    // Relasi ke PO
    public function purchaseOrder()
    {
        return $this->belongsTo(
            PurchaseOrder::class,
            'purchase_order_id'
        );
    }

    // User yang approve
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
