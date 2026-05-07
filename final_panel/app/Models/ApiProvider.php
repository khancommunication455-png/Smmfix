<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiProvider extends Model
{
    protected $fillable = [
        'name',
        'url',
        'api_key',
        'status',
        'percentage_increase',
    ];

    protected $casts = [
        'percentage_increase' => 'float',
        'api_key' => 'encrypted',
    ];

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
