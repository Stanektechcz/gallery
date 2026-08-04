<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaceSubscription extends Model
{
    protected $fillable = ['gallery_space_id', 'billing_plan_id', 'status', 'started_at', 'ends_at', 'granted_by', 'note'];
    protected function casts(): array { return ['started_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function plan() { return $this->belongsTo(BillingPlan::class, 'billing_plan_id'); }
    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }
}
