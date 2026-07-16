<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventReminder extends Model
{
    protected $fillable = [
        'event_id', 'user_id', 'channel', 'remind_at', 'original_remind_at', 'snoozed_until',
        'snooze_count', 'status', 'delivered_at', 'acknowledged_at', 'dismissed_at', 'last_error',
        'automation_source', 'automation_key',
    ];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime', 'original_remind_at' => 'datetime', 'snoozed_until' => 'datetime',
            'delivered_at' => 'datetime', 'acknowledged_at' => 'datetime', 'dismissed_at' => 'datetime',
            'snooze_count' => 'integer',
        ];
    }

    public function event() { return $this->belongsTo(CalendarEvent::class, 'event_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function deliveryLogs() { return $this->hasMany(ReminderDeliveryLog::class, 'event_reminder_id')->latest('created_at'); }
}
