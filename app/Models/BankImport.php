<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BankImport extends Model
{
    protected $fillable = ['uuid', 'gallery_space_id', 'bank_connection_id', 'bank_account_id', 'imported_by', 'original_filename',
        'file_sha256', 'format', 'status', 'rows_total', 'rows_imported', 'rows_duplicate', 'rows_failed', 'period_from', 'period_to',
        'error_summary', 'encrypted_metadata'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'encrypted_metadata' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->uuid ??= (string) Str::uuid());
    }
}
