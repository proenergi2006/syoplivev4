<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentCounter extends Model
{
    protected $table = 'document_counters';

    protected $fillable = [
        'doc_code',
        'department',
        'branch',
        'year',
        'last_number'
    ];

    public $timestamps = false;
}
