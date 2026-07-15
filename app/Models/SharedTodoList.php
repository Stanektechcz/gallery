<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SharedTodoList extends Model
{
    protected $fillable = ['uuid', 'gallery_space_id', 'created_by', 'title', 'description', 'kind', 'color', 'icon', 'sort_order', 'archived_at'];
    protected function casts(): array { return ['archived_at' => 'datetime']; }
    protected static function booted(): void { static::creating(fn (SharedTodoList $list) => $list->uuid ??= (string) Str::uuid()); }
    public function todos() { return $this->hasMany(SharedTodo::class, 'list_id'); }
}
