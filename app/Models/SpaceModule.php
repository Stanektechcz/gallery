<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaceModule extends Model
{
    protected $fillable = ['gallery_space_id', 'billing_module_id', 'status', 'activated_at', 'ends_at', 'granted_by'];
    protected function casts(): array { return ['activated_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function module() { return $this->belongsTo(BillingModule::class, 'billing_module_id'); }
    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }
}
