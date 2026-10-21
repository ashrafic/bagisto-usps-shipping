<?php

namespace Ashrafic\UspsShipping\Http\Controllers\Admin;

use Ashrafic\UspsShipping\Repositories\UspsShipmentRepository;
use Ashrafic\UspsShipping\Services\LabelService;
use Ashrafic\UspsShipping\Support\ItemPacker;
use Ashrafic\UspsShipping\Support\ServiceCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Sales\Models\Order;

class UspsShipmentController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected UspsShipmentRepository $repository,
        protected LabelService $labelService,
    ) {}

    /**
     * List USPS shipments and labels.
     */
    public function index(): View
    {
        return view('usps-shipping::admin.shipments.index', [
            'shipments' => $this->repository->paginate(),
        ]);
    }

    /**
     * Show the label creation form for an order.
     */
    public function create(int $orderId): View|RedirectResponse
    {
        $order = Order::findOrFail($orderId);

        if (! $order->shipping_address) {
            session()->flash('error', trans('usps-shipping::app.admin.orders.no-shipping-address'));

            return redirect()->route('admin.sales.orders.view', $orderId);
        }

        if (! $this->isDomestic($order)) {
            session()->flash('error', trans('usps-shipping::app.admin.orders.international-unsupported'));

            return redirect()->route('admin.sales.orders.view', $orderId);
        }

        $catalog = new ServiceCatalog;

        $services = array_intersect_key(
            $catalog->domesticClasses(),
            array_flip($catalog->allowedClasses(core()->getConfigData('sales.carriers.usps.services')))
        );

        return view('usps-shipping::admin.orders.label-create', [
            'order' => $order,
            'services' => $services,
        ]);
    }

    /**
     * Create USPS labels for an order, one per packed package.
     */
    public function store(int $orderId): RedirectResponse
    {
        $data = request()->validate([
            'service_code' => 'required|string',
        ]);

        $order = Order::findOrFail($orderId);

        $catalog = new ServiceCatalog;

        if (! isset($catalog->domesticClasses()[$data['service_code']])) {
            session()->flash('error', trans('usps-shipping::app.admin.orders.label-failed').': unknown mail class.');

            return redirect()->back();
        }

        if (! $this->isDomestic($order)) {
            session()->flash('error', trans('usps-shipping::app.admin.orders.international-unsupported'));

            return redirect()->back();
        }

        try {
            $shipments = $this->labelService->create(
                shipper: $this->origin(),
                recipient: $this->recipient($order),
                packages: app(ItemPacker::class)->pack($this->items($order)),
                mailClass: $data['service_code'],
                orderId: $orderId,
            );
        } catch (\Throwable $e) {
            logger()->error('[USPS] label creation failed', ['exception' => $e, 'order_id' => $orderId]);

            session()->flash('error', trans('usps-shipping::app.admin.orders.label-failed').': '.$e->getMessage());

            return redirect()->back();
        }

        $order->shipments()->latest('id')->first()?->update(['track_number' => $shipments[0]->tracking_number]);

        session()->flash('success', trans('usps-shipping::app.admin.orders.label-created').' — '.implode(', ', array_map(fn ($shipment) => $shipment->tracking_number, $shipments)));

        return redirect()->route('admin.sales.orders.view', $orderId);
    }

    /**
     * Download a label PDF.
     */
    public function download(int $id): StreamedResponse
    {
        $shipment = $this->repository->findOrFail($id);

        return Storage::disk(config('carriers.usps.label_disk', 'private'))->download($shipment->label_path, 'usps-label-'.$shipment->tracking_number.'.pdf');
    }

    /**
     * Cancel a label and request its refund.
     */
    public function void(int $id): RedirectResponse
    {
        $shipment = $this->repository->findOrFail($id);

        try {
            $this->labelService->void($shipment);

            session()->flash('success', trans('usps-shipping::app.admin.shipments.voided').' — '.$shipment->tracking_number);
        } catch (\Throwable $e) {
            logger()->error('[USPS] label void failed', ['exception' => $e]);

            session()->flash('error', trans('usps-shipping::app.admin.shipments.void-failed').': '.$e->getMessage());
        }

        return redirect()->back();
    }

    /**
     * Whether the order ships on the domestic price table the Labels API serves.
     */
    protected function isDomestic(Order $order): bool
    {
        return in_array(strtoupper((string) $order->shipping_address?->country), ['US', 'AS', 'GU', 'MP', 'PR', 'PW', 'VI'], true);
    }

    /**
     * Shipper origin from the channel shipping configuration.
     *
     * @return array<string, ?string>
     */
    protected function origin(): array
    {
        return [
            'name' => core()->getConfigData('sales.shipping.origin.store_name') ?: 'Store',
            'address' => core()->getConfigData('sales.shipping.origin.address'),
            'address2' => null,
            'city' => core()->getConfigData('sales.shipping.origin.city'),
            'state' => core()->getConfigData('sales.shipping.origin.state'),
            'zipcode' => core()->getConfigData('sales.shipping.origin.zipcode'),
        ];
    }

    /**
     * Recipient block from the order shipping address.
     *
     * @return array<string, ?string>
     */
    protected function recipient(Order $order): array
    {
        $address = $order->shipping_address;

        return [
            'name' => $address->name,
            'address' => $address->address ?? $address->address1,
            'address2' => $address->address2,
            'city' => $address->city,
            'state' => $address->state,
            'zipcode' => $address->postcode,
        ];
    }

    /**
     * Shippable order items with product dimensions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function items(Order $order): array
    {
        return $order->items
            ->filter(fn ($item) => (float) $item->weight > 0)
            ->map(fn ($item) => [
                'weight' => (float) $item->weight,
                'quantity' => (int) $item->qty_ordered,
                'store_weight_unit' => core()->getConfigData('general.general.locale_options.weight_unit') ?: 'LBS',
                'length' => $this->optionalFloat($item->product?->length),
                'width' => $this->optionalFloat($item->product?->width),
                'height' => $this->optionalFloat($item->product?->height),
            ])
            ->values()
            ->all();
    }

    /**
     * Cast an optional dimension; EAV attributes arrive as strings or empty strings.
     */
    protected function optionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }
}
