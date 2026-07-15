<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SharedTodoComment extends Model
{
    protected $fillable = ['uuid', 'todo_id', 'user_id', 'body'];
    protected static function booted(): void { static::creating(fn (SharedTodoComment $comment) => $comment->uuid ??= (string) Str::uuid()); }
    public function user() { return $this->belongsTo(User::class); }
}
