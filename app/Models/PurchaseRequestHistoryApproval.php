<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestHistoryApproval extends Model
{
    use HasFactory;
    protected $table = 'purchase_request_history_approvals';
    protected $fillable = [
        'purchase_request_id',
        'level',
        'approver_user_id',
        'approver_role',
        'status',
        'notes',
    ];
}
