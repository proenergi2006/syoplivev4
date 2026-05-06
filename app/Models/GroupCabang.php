<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupCabang extends Model
{
    protected $table = 'group_cabang';

    public $timestamps = false;

    protected $fillable = [
        'group_wilayah',
        'is_active',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];
}
