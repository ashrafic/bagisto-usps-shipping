<?php

namespace Ashrafic\UspsShipping\Models;

use Illuminate\Database\Eloquent\Model;

class UspsShipment extends Model
{
    /**
     * Fillable property of the model.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'tracking_number',
        'service_code',
        'status',
        'estimated_delivery',
        'label_path',
        'label_cost',
        'label_currency',
        'voided_at',
        'raw',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'label_cost' => 'decimal:4',
        'voided_at' => 'datetime',
        'raw' => 'array',
    ];
}
