<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    protected $table = 'print_jobs';

    protected $fillable = [
        'impresora_id',
        'payload_base64',
        'status',
        'error_message',
    ];

    public function impresora()
    {
        return $this->belongsTo(ImpresoraNube::class, 'impresora_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
