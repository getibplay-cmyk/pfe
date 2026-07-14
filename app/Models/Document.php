<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['agency_id', 'documentable_type', 'documentable_id', 'document_type', 'title', 'retention_until', 'is_sensitive', 'created_by'];

    protected function casts(): array
    {
        return ['document_type' => DocumentType::class, 'retention_until' => 'date', 'is_sensitive' => 'boolean'];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }
}
