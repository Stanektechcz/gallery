<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderDeliveryLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_reminder_id', 'channel', 'status', 'detail', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function reminder() { return $this->belongsTo(EventReminder::class, 'event_reminder_id'); }
}
