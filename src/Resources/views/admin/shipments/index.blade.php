<x-admin::layouts>
    <x-slot:title>
        @lang('usps-shipping::app.admin.shipments.title')
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">
            @lang('usps-shipping::app.admin.shipments.title')
        </p>
    </div>

    <div class="overflow-auto mt-4 bg-white dark:bg-gray-900 rounded shadow">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-600 dark:text-gray-300 border-b dark:border-gray-700">
                    <th class="p-4">@lang('usps-shipping::app.admin.shipments.tracking-number')</th>
                    <th class="p-4">@lang('usps-shipping::app.admin.shipments.service')</th>
                    <th class="p-4">@lang('usps-shipping::app.admin.shipments.status')</th>
                    <th class="p-4">@lang('usps-shipping::app.admin.shipments.cost')</th>
                    <th class="p-4">@lang('usps-shipping::app.admin.shipments.estimated-delivery')</th>
                    <th class="p-4">@lang('usps-shipping::app.admin.shipments.actions')</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    <tr class="border-b dark:border-gray-700">
                        <td class="p-4 font-medium">{{ $shipment->tracking_number }}</td>
                        <td class="p-4">{{ $shipment->service_code }}</td>
                        <td class="p-4">
                            {{ $shipment->voided_at ? __('usps-shipping::app.admin.shipments.voided') : ($shipment->status ?? '—') }}
                        </td>
                        <td class="p-4">
                            @if ($shipment->label_cost)
                                {{ $shipment->label_currency }} {{ number_format($shipment->label_cost, 2) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="p-4">{{ $shipment->estimated_delivery ?? '—' }}</td>
                        <td class="p-4 flex gap-2">
                            @if ($shipment->label_path && ! $shipment->voided_at)
                                <a class="px-3 py-1.5 rounded bg-blue-600 text-white text-xs"
                                    href="{{ route('admin.usps-shipping.labels.download', $shipment->id) }}">@lang('usps-shipping::app.admin.shipments.download')</a>
                            @endif

                            @if (! $shipment->voided_at)
                                <form action="{{ route('admin.usps-shipping.labels.void', $shipment->id) }}" method="POST"
                                    @click.prevent="$el.submit()">
                                    @csrf
                                    <button class="px-3 py-1.5 rounded bg-red-600 text-white text-xs">@lang('usps-shipping::app.admin.shipments.void')</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="p-4 text-gray-500" colspan="6">@lang('usps-shipping::app.admin.shipments.empty')</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
