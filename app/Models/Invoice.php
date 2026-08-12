<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'uuid', 'number', 'payment_id', 'gallery_space_id', 'issued_to',
        'customer_name', 'customer_email', 'description',
        'amount', 'currency', 'vat_rate', 'issued_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function payment() { return $this->belongsTo(Payment::class); }
    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }
}
