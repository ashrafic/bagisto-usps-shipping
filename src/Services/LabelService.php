<?php

namespace Ashrafic\UspsShipping\Services;

use Ashrafic\UspsShipping\Client\UspsClient;
use Ashrafic\UspsShipping\Data\LabelPackage;
use Ashrafic\UspsShipping\Models\UspsShipment;
use Ashrafic\UspsShipping\Repositories\UspsShipmentRepository;
use Ashrafic\UspsShipping\Support\ConfigResolver;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LabelService
{
    /**
     * Create a new service instance.
     */
    public function __construct(
        protected UspsClient $client,
        protected UspsShipmentRepository $repository,
        protected ConfigResolver $configResolver = new ConfigResolver,
    ) {}

    /**
     * Create one USPS label per package; the USPS API prices exactly one package per label.
     *
     * @param  array{name: ?string, address: ?string, address2: ?string, city: ?string, state: ?string, zipcode: ?string}  $shipper
     * @param  array{name: ?string, address: ?string, address2: ?string, city: ?string, state: ?string, zipcode: ?string}  $recipient
     * @param  LabelPackage[]  $packages
     * @return array<int, UspsShipment>
     */
    public function create(array $shipper, array $recipient, array $packages, string $mailClass, ?int $orderId = null): array
    {
        $paymentToken = $this->client->paymentAuthorizationToken();

        $shipments = [];

        foreach ($packages as $package) {
            $response = $this->client->createLabel(
                $this->buildLabel($shipper, $recipient, $package, $mailClass),
                $paymentToken
            );

            $shipments[] = $this->store($response, $mailClass, $orderId);
        }

        return $shipments;
    }

    /**
     * Cancel an unused label and request its refund.
     */
    public function void(UspsShipment $shipment): UspsShipment
    {
        $this->client->voidLabel($shipment->tracking_number);

        return $this->repository->update($shipment, [
            'status' => 'VOIDED',
            'voided_at' => now(),
        ]);
    }

    /**
     * Build the label request payload.
     *
     * @param  array<string, ?string>  $shipper
     * @param  array<string, ?string>  $recipient
     */
    protected function buildLabel(array $shipper, array $recipient, LabelPackage $package, string $mailClass): array
    {
        return [
            'imageInfo' => [
                'imageType' => 'PDF',
                'labelType' => '4X6LABEL',
                'receiptOption' => 'NONE',
            ],
            'toAddress' => $this->address($recipient),
            'fromAddress' => $this->address($shipper),
            'packageDescription' => [
                'mailClass' => $mailClass,
                'rateIndicator' => 'SP',
                'weightUOM' => 'lb',
                'weight' => $package->weight,
                'dimensionsUOM' => 'in',
                'length' => $package->length,
                'width' => $package->width,
                'height' => $package->height,
                'processingCategory' => 'MACHINABLE',
                'mailingDate' => now()->toDateString(),
                'destinationEntryFacilityType' => 'NONE',
            ],
        ];
    }

    /**
     * USPS address block from origin-style key/value data.
     *
     * @param  array<string, ?string>  $data
     */
    protected function address(array $data): array
    {
        [$firstName, $lastName] = $this->splitName((string) ($data['name'] ?? ''));

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'streetAddress' => (string) ($data['address'] ?? ''),
            'secondaryAddress' => (string) ($data['address2'] ?? ''),
            'city' => (string) ($data['city'] ?? ''),
            'state' => (string) ($data['state'] ?? ''),
            'ZIPCode' => $this->zip5((string) ($data['zipcode'] ?? '')),
        ];
    }

    /**
     * Split a full name into USPS first/last name parts.
     *
     * @return array<int, string>
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if ($parts === ['']) {
            return ['', ''];
        }

        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        return [
            implode(' ', array_slice($parts, 0, -1)),
            end($parts),
        ];
    }

    /**
     * Reduce a postcode to the five digit ZIP the label API expects.
     */
    protected function zip5(string $postcode): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $postcode) ?? '';

        return strtoupper(substr($normalized, 0, 5));
    }

    /**
     * Persist the label document and shipment record from the response.
     */
    protected function store(array $response, string $mailClass, ?int $orderId): UspsShipment
    {
        $trackingNumber = $response['trackingNumber']
            ?? throw new RuntimeException('USPS label response did not contain a tracking number.');

        $document = $response['labelImage']
            ?? throw new RuntimeException('USPS label response did not contain a label document.');

        $path = 'usps-labels/'.$trackingNumber.'.pdf';

        Storage::disk($this->config('label_disk', 'private'))
            ->put($path, base64_decode($document));

        try {
            return $this->repository->create([
                'order_id' => $orderId,
                'tracking_number' => $trackingNumber,
                'service_code' => $mailClass,
                'status' => 'LABEL_CREATED',
                'estimated_delivery' => $response['commitment']['scheduleDeliveryDate'] ?? null,
                'label_path' => $path,
                'label_cost' => is_numeric($response['postage'] ?? null) ? (float) $response['postage'] : null,
                'label_currency' => 'USD',
                'raw' => $this->stripLabelImage($response),
            ]);
        } catch (\Throwable $e) {
            Storage::disk($this->config('label_disk', 'private'))->delete($path);

            throw $e;
        }
    }

    /**
     * Remove the base64 label image from the response before persisting it.
     */
    protected function stripLabelImage(array $response): array
    {
        unset($response['labelImage']);

        return $response;
    }

    /**
     * Read carrier configuration value.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->configResolver->get($key, $default);
    }
}
