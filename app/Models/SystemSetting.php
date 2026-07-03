<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table      = 'system_settings';
    protected $primaryKey = 'key';
    public    $incrementing = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value', 'type', 'group'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);
        return $setting?->value ?? $default;
    }

    public static function set(string $key, mixed $value, string $type = 'string', ?string $group = null): void
    {
        static::updateOrCreate(['key' => $key], [
            'value' => (string) $value,
            'type'  => $type,
            'group' => $group,
        ]);
    }
}
