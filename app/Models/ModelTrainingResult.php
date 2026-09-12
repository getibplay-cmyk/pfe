<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ModelTrainingResult extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'eligible' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function review(): HasOne
    {
        return $this->hasOne(ModelTrainingReview::class, 'result_id');
    }
}
