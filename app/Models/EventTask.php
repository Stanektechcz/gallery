<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTask extends Model
{
    protected $fillable = ['event_id', 'assigned_to', 'title', 'notes', 'due_at', 'completed_at', 'priority', 'sort_order', 'last_reminded_at', 'last_escalated_at'];
    protected function casts(): array { return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'last_reminded_at' => 'datetime', 'last_escalated_at' => 'datetime']; }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
}
