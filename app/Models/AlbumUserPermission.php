<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlbumUserPermission extends Model
{
    protected $table = 'album_user_permissions';
    protected $fillable = ['album_id', 'user_id', 'role', 'inherited'];

    protected function casts(): array
    {
        return ['inherited' => 'boolean'];
    }

    public function album() { return $this->belongsTo(Album::class); }
    public function user()  { return $this->belongsTo(User::class); }
}
