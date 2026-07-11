<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventReminder extends Model
{
    protected $fillable = ['event_id', 'user_id', 'channel', 'remind_at', 'status', 'delivered_at', 'last_error'];
    protected function casts(): array { return ['remind_at' => 'datetime', 'delivered_at' => 'datetime']; }
    public function event() { return $this->belongsTo(CalendarEvent::class, 'event_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
