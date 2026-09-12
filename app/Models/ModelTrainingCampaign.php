<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ModelTrainingCampaign extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['stored_path', 'sha256'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function result(): HasOne
    {
        return $this->hasOne(ModelTrainingResult::class, 'campaign_id');
    }
}
