<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class IntegrationSetting extends Model
{
    protected $fillable = ['provider', 'is_enabled', 'encrypted_config', 'last_tested_at', 'last_status', 'last_error', 'updated_by'];
    protected function casts(): array { return ['is_enabled' => 'boolean', 'last_tested_at' => 'datetime']; }

    public function config(): array
    {
        if (!$this->encrypted_config) return [];
        try { return json_decode(Crypt::decryptString($this->encrypted_config), true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return []; }
    }

    public function replaceConfig(array $config): void
    {
        $this->encrypted_config = Crypt::encryptString(json_encode($config, JSON_THROW_ON_ERROR));
    }
}
