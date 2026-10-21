<?php

namespace Ashrafic\UspsShipping\Repositories;

use Ashrafic\UspsShipping\Models\UspsShipment;

class UspsShipmentRepository
{
    /**
     * Create a shipment record.
     */
    public function create(array $attributes): UspsShipment
    {
        return UspsShipment::create($attributes);
    }

    /**
     * Update a shipment record.
     */
    public function update(UspsShipment $shipment, array $attributes): UspsShipment
    {
        $shipment->fill($attributes)->save();

        return $shipment->refresh();
    }

    /**
     * Find a shipment record by id.
     */
    public function find(int $id): ?UspsShipment
    {
        return UspsShipment::find($id);
    }

    /**
     * Find a shipment record by id or throw.
     */
    public function findOrFail(int $id): UspsShipment
    {
        return UspsShipment::findOrFail($id);
    }

    /**
     * Get paginated shipment records, newest first.
     */
    public function paginate(int $perPage = 20)
    {
        return UspsShipment::query()
            ->latest('id')
            ->select(['id', 'order_id', 'tracking_number', 'service_code', 'status', 'estimated_delivery', 'label_path', 'label_cost', 'label_currency', 'voided_at', 'created_at'])
            ->paginate($perPage);
    }
}
