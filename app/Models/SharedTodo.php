<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SharedTodo extends Model
{
    protected $fillable = [
        'uuid', 'series_uuid', 'gallery_space_id', 'list_id', 'parent_id', 'created_by', 'assigned_to', 'completed_by',
        'calendar_event_id', 'trip_id', 'title', 'description', 'status', 'priority', 'starts_at', 'due_at', 'remind_at',
        'last_reminded_at', 'estimate_minutes', 'location', 'recurrence', 'tags', 'metadata', 'sort_order', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'due_at' => 'datetime', 'remind_at' => 'datetime', 'last_reminded_at' => 'datetime',
            'completed_at' => 'datetime', 'recurrence' => 'array', 'tags' => 'array', 'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SharedTodo $todo) {
            $todo->uuid ??= (string) Str::uuid();
            $todo->series_uuid ??= $todo->uuid;
        });
    }

    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function list() { return $this->belongsTo(SharedTodoList::class, 'list_id'); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order'); }
    public function comments() { return $this->hasMany(SharedTodoComment::class, 'todo_id')->latest(); }
}
