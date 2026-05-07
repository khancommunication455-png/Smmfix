<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'name',
        'description',
        'category_id',
        'api_provider_id',
        'api_service_id',
        'rate',
        'min',
        'max',
        'status',
        'type',
    ];

    protected $casts = [
        'rate' => 'float',
        'min' => 'integer',
        'max' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function apiProvider()
    {
        return $this->belongsTo(ApiProvider::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
