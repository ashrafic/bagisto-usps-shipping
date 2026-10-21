<x-admin::layouts>
    <x-slot:title>
        @lang('usps-shipping::app.admin.orders.create-label')
    </x-slot>

    <div class="max-w-xl">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">
            @lang('usps-shipping::app.admin.orders.create-label') — {{ __('Order') }} #{{ $order->id }}
        </p>

        <form action="{{ route('admin.usps-shipping.labels.store', $order->id) }}" method="POST"
            class="mt-4 bg-white dark:bg-gray-900 rounded shadow p-6 space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="service_code">
                    @lang('usps-shipping::app.admin.orders.service')
                </label>
                <select id="service_code" name="service_code"
                    class="mt-1 block w-full rounded border-gray-300 dark:border-gray-700 dark:bg-gray-800">
                    @foreach ($services as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="text-sm text-gray-600 dark:text-gray-400">
                {{ $order->shipping_address->name }}<br>
                {{ $order->shipping_address->address ?? $order->shipping_address->address1 }}<br>
                {{ $order->shipping_address->city }}, {{ $order->shipping_address->state }} {{ $order->shipping_address->postcode }}, {{ $order->shipping_address->country }}
            </div>

            <button type="submit"
                class="px-4 py-2 rounded bg-blue-600 text-white text-sm font-medium">@lang('usps-shipping::app.admin.orders.create-label')</button>
        </form>
    </div>
</x-admin::layouts>
