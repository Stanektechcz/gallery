<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Facades\DB;

class Tag extends Model
{
    protected $fillable = [
        'gallery_space_id', 'parent_id', 'name', 'slug', 'depth',
        'materialized_path', 'color', 'created_by',
    ];

    public function gallerySpace() { return $this->belongsTo(GallerySpace::class); }
    public function parent()       { return $this->belongsTo(Tag::class, 'parent_id'); }
    public function children()     { return $this->hasMany(Tag::class, 'parent_id'); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    public function media()
    {
        return $this->belongsToMany(MediaItem::class, 'media_tag')
            ->withPivot(['tagged_by'])
            ->withTimestamps();
    }

    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_tag');
    }

    public function ancestors()
    {
        return $this->belongsToMany(Tag::class, 'tag_closure', 'descendant_id', 'ancestor_id')
            ->withPivot('depth')
            ->orderByPivot('depth');
    }

    public function descendants()
    {
        return $this->belongsToMany(Tag::class, 'tag_closure', 'ancestor_id', 'descendant_id')
            ->withPivot('depth')
            ->orderByPivot('depth');
    }
}
