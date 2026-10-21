<?php

namespace Ashrafic\UspsShipping\Listeners;

use Webkul\Theme\ViewRenderEventManager;

class OrderViewButton
{
    /**
     * Register the USPS label button on the admin order view.
     */
    public function handle(ViewRenderEventManager $viewRenderEventManager): void
    {
        $order = $viewRenderEventManager->getParam('order');

        if (! core()->getConfigData('sales.carriers.usps.active')) {
            return;
        }

        if (! $order?->shipping_address) {
            return;
        }

        $viewRenderEventManager->addTemplate('usps-shipping::admin.orders.label-button');
    }
}
