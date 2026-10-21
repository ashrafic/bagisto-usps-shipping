<?php

use Ashrafic\UspsShipping\Client\UspsClient;
use Ashrafic\UspsShipping\Client\UspsOAuth;
use Ashrafic\UspsShipping\Data\LabelPackage;
use Ashrafic\UspsShipping\Repositories\UspsShipmentRepository;
use Ashrafic\UspsShipping\Services\LabelService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('carriers.usps.client_id', 'id-123');
    config()->set('carriers.usps.client_secret', 'secret-456');
    config()->set('carriers.usps.payment_crid', '1234567890');
    config()->set('carriers.usps.payment_mid', '123456');
    config()->set('carriers.usps.payment_account_number', '7654321');
    config()->set('carriers.usps.label_disk', 'private');

    Storage::fake('private');

    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'access_token' => 'tok', 'expires_in' => 3600,
        ], 200),
    ]);

    $this->artisan('migrate', ['--realpath' => __DIR__.'/../../src/Database/Migrations'])->run();
});

function fakeLabelResponse(): array
{
    return [
        'trackingNumber' => '9405500000000000000000',
        'labelImage' => base64_encode('%PDF-1.4 fake label'),
        'postage' => 7.99,
        'commitment' => ['name' => '2 Days', 'scheduleDeliveryDate' => '2026-10-23'],
    ];
}

function labelService(): LabelService
{
    return new LabelService(
        new UspsClient(new UspsOAuth),
        new UspsShipmentRepository
    );
}

function labelShipper(): array
{
    return ['name' => 'TH Treasures Co', 'address' => '123 Warehouse Way', 'address2' => null, 'city' => 'Los Angeles', 'state' => 'CA', 'zipcode' => '90001-1234'];
}

function labelRecipient(): array
{
    return ['name' => 'Jane Ann Customer', 'address' => '456 Home Street', 'address2' => 'Apt 2', 'city' => 'New York', 'state' => 'NY', 'zipcode' => '10001'];
}

it('creates a label, stores the pdf and persists a shipment record', function () {
    Http::fake([
        'apis.usps.com/payments/v3/payment-authorization' => Http::response(['paymentAuthorizationToken' => 'pay-tok'], 200),
        'apis.usps.com/labels/v3/label' => Http::response(fakeLabelResponse(), 200),
    ]);

    $shipments = labelService()->create(
        shipper: labelShipper(),
        recipient: labelRecipient(),
        packages: [new LabelPackage(weight: 5.0, length: 9.0, width: 6.0, height: 0.25)],
        mailClass: 'PRIORITY_MAIL',
        orderId: 42,
    );

    expect($shipments)->toHaveCount(1)
        ->and($shipments[0]->tracking_number)->toBe('9405500000000000000000')
        ->and($shipments[0]->order_id)->toBe(42)
        ->and($shipments[0]->status)->toBe('LABEL_CREATED')
        ->and($shipments[0]->service_code)->toBe('PRIORITY_MAIL')
        ->and($shipments[0]->label_cost)->toBe('7.9900')
        ->and($shipments[0]->label_currency)->toBe('USD')
        ->and($shipments[0]->estimated_delivery)->toBe('2026-10-23')
        ->and($shipments[0]->raw)->not->toHaveKey('labelImage')
        ->and(Storage::disk('private')->exists('usps-labels/9405500000000000000000.pdf'))->toBeTrue();
});

it('builds the label request with addresses, payment token and package data', function () {
    Http::fake([
        'apis.usps.com/payments/v3/payment-authorization' => Http::response(['paymentAuthorizationToken' => 'pay-tok'], 200),
        'apis.usps.com/labels/v3/label' => Http::response(fakeLabelResponse(), 200),
    ]);

    labelService()->create(
        shipper: labelShipper(),
        recipient: labelRecipient(),
        packages: [new LabelPackage(weight: 5.0, length: 9.0, width: 6.0, height: 0.25)],
        mailClass: 'PRIORITY_MAIL',
    );

    Http::assertSent(function ($request) {
        if (str_contains($request->url(), 'payments/v3/payment-authorization')) {
            $roles = $request->data()['roles'];

            return collect($roles)->pluck('roleName')->sort()->values()->all() === ['LABEL_OWNER', 'PAYER']
                && $roles[0]['CRID'] === '1234567890'
                && $roles[0]['MID'] === '123456'
                && $roles[0]['accountType'] === 'EPS'
                && $roles[0]['accountNumber'] === '7654321';
        }

        if (! str_contains($request->url(), 'labels/v3/label')) {
            return true;
        }

        $label = $request->data();

        return $label['imageInfo'] === ['imageType' => 'PDF', 'labelType' => '4X6LABEL', 'receiptOption' => 'NONE']
            && $label['toAddress'] === ['firstName' => 'Jane Ann', 'lastName' => 'Customer', 'streetAddress' => '456 Home Street', 'secondaryAddress' => 'Apt 2', 'city' => 'New York', 'state' => 'NY', 'ZIPCode' => '10001']
            && $label['fromAddress']['firstName'] === 'TH Treasures'
            && $label['fromAddress']['lastName'] === 'Co'
            && $label['fromAddress']['ZIPCode'] === '90001'
            && $label['packageDescription']['mailClass'] === 'PRIORITY_MAIL'
            && $label['packageDescription']['weight'] === 5.0
            && $label['packageDescription']['length'] === 9.0
            && $label['packageDescription']['processingCategory'] === 'MACHINABLE'
            && $label['packageDescription']['rateIndicator'] === 'SP'
            && $request->hasHeader('X-Payment-Authorization-Token', ['pay-tok']);
    });
});

it('creates one label per package', function () {
    Http::fake([
        'apis.usps.com/payments/v3/payment-authorization' => Http::response(['paymentAuthorizationToken' => 'pay-tok'], 200),
        'apis.usps.com/labels/v3/label' => Http::sequence()
            ->push(fakeLabelResponse(), 200)
            ->push(array_merge(fakeLabelResponse(), ['trackingNumber' => '9405500000000000000001']), 200),
    ]);

    $shipments = labelService()->create(
        shipper: labelShipper(),
        recipient: labelRecipient(),
        packages: [
            new LabelPackage(weight: 5.0, length: 9.0, width: 6.0, height: 0.25),
            new LabelPackage(weight: 3.0, length: 9.0, width: 6.0, height: 0.25),
        ],
        mailClass: 'PRIORITY_MAIL',
        orderId: 42,
    );

    expect($shipments)->toHaveCount(2)
        ->and(collect($shipments)->pluck('tracking_number')->all())->toBe(['9405500000000000000000', '9405500000000000000001'])
        ->and($shipments[1]->order_id)->toBe(42);
});

it('throws when the response has no label document', function () {
    Http::fake([
        'apis.usps.com/payments/v3/payment-authorization' => Http::response(['paymentAuthorizationToken' => 'pay-tok'], 200),
        'apis.usps.com/labels/v3/label' => Http::response(['trackingNumber' => '9405500000000000000000'], 200),
    ]);

    expect(fn () => labelService()->create(
        shipper: labelShipper(),
        recipient: labelRecipient(),
        packages: [new LabelPackage(weight: 5.0, length: 9.0, width: 6.0, height: 0.25)],
        mailClass: 'PRIORITY_MAIL',
    ))->toThrow(RuntimeException::class, 'label document');
});

it('voids a label and marks the record', function () {
    Http::fake([
        'apis.usps.com/labels/v3/label/*' => Http::response([], 200),
    ]);

    $repository = new UspsShipmentRepository;

    $shipment = $repository->create([
        'tracking_number' => '9405500000000000000000',
        'status' => 'LABEL_CREATED',
        'label_path' => 'usps-labels/9405500000000000000000.pdf',
    ]);

    $voided = labelService()->void($shipment);

    expect($voided->status)->toBe('VOIDED')
        ->and($voided->voided_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'labels/v3/label/9405500000000000000000'));
});

it('refuses to create labels when payment credentials are missing', function () {
    config()->set('carriers.usps.payment_crid', null);

    Http::fake(['apis.usps.com/*' => Http::response([], 200)]);

    expect(fn () => labelService()->create(
        shipper: labelShipper(),
        recipient: labelRecipient(),
        packages: [new LabelPackage(weight: 5.0, length: 9.0, width: 6.0, height: 0.25)],
        mailClass: 'PRIORITY_MAIL',
    ))->toThrow(RuntimeException::class, 'label payment is not configured');
});
