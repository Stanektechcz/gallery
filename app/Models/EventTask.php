<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTask extends Model
{
    protected $fillable = ['event_id', 'assigned_to', 'title', 'notes', 'due_at', 'completed_at', 'priority', 'sort_order'];
    protected function casts(): array { return ['due_at' => 'datetime', 'completed_at' => 'datetime']; }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
}
