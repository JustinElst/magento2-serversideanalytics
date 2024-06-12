<?php
/**
 * Copyright Elgentos BV. All rights reserved.
 * https://www.elgentos.nl/
 */
declare(strict_types=1);

namespace Elgentos\ServerSideAnalytics\Observer;

use Elgentos\ServerSideAnalytics\Config\ModuleConfiguration;
use Elgentos\ServerSideAnalytics\Model\Source\CurrencySource;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Item;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Elgentos\ServerSideAnalytics\Logger\Logger;
use Magento\Framework\Event\ManagerInterface;
use Elgentos\ServerSideAnalytics\Model\GAClient;
use Elgentos\ServerSideAnalytics\Model\GAClientFactory;
use Elgentos\ServerSideAnalytics\Model\SalesOrderRepository;
use Elgentos\ServerSideAnalytics\Model\ResourceModel\SalesOrder\CollectionFactory;
use Magento\Tax\Model\Config;

class SendPurchaseEvent implements ObserverInterface
{
    public function __construct(
        private readonly ModuleConfiguration $moduleConfiguration,
        private readonly Emulation $emulation,
        private readonly Logger $logger,
        private readonly GAClientFactory $GAClientFactory,
        private readonly ManagerInterface $event,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CollectionFactory $elgentosSalesOrderCollectionFactory,
        private readonly SalesOrderRepository $elgentosSalesOrderRepository
    ) {
    }

    /**
     * @param $observer
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $observer->getPayment();
        $invoice = $observer->getInvoice();

        $order = $payment->getOrder();
        $orderStoreId = $order->getStoreId();

        $gaUserDatabaseId = $order->getId();

        if (!$gaUserDatabaseId) {
            $gaUserDatabaseId = $payment->getOrder()->getQuoteId();
        }

        if (!$gaUserDatabaseId) {
            return;
        }

        $this->emulation->startEnvironmentEmulation($orderStoreId, 'adminhtml');

        if (!$this->moduleConfiguration->isReadyForUse()) {
            $this->emulation->stopEnvironmentEmulation();
            return;
        }

        /** @var Invoice $invoice */

        $elgentosSalesOrderCollection = $this->elgentosSalesOrderCollectionFactory->create();
        $elgentosSalesOrder = $elgentosSalesOrderCollection
            ->addFieldToFilter(
                ['quote_id', 'order_id'],
                [
                    ['eq' => $gaUserDatabaseId],
                    ['eq' => $gaUserDatabaseId]
                ]
            )
            ->getFirstItem();

        if (!$elgentosSalesOrder->getGaUserId()
                ||
            !$elgentosSalesOrder->getGaSessionId()
        ) {
            $this->emulation->stopEnvironmentEmulation();
            return;
        }

        $products = [];

        $gaclient = $this->GAClientFactory->create();

        if ($this->moduleConfiguration->isLogging()) {
            $gaclient->createLog('Got payment Pay event for Ga UserID: ' . $elgentosSalesOrder->getGaUserId(), []);
        }

        /** @var \Magento\Sales\Model\Order\Invoice\Item $item */
        foreach ($invoice->getAllItems() as $item) {
            if (!$item->isDeleted() && !$item->getOrderItem()->getParentItemId()) {
                $orderItem = $item->getOrderItem();

                $product = new DataObject([
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'price' => $this->getPaidProductPrice($item->getOrderItem()),
                    'quantity' => $orderItem->getQtyOrdered(),
                    'position' => $item->getId(),
                    'item_brand' => $orderItem->getProduct()?->getAttributeText('manufacturer')
                ]);

                $this->event->dispatch(
                    'elgentos_serversideanalytics_product_item_transport_object',
                    ['product' => $product, 'item' => $item]
                );

                $products[] = $product;
            }
        }

        $trackingDataObject = new DataObject([
            'client_id' => $elgentosSalesOrder->getGaUserId(),
            'ip_override' => $order->getRemoteIp(),
            'document_path' => '/checkout/onepage/success/'
        ]);

        if ($userId = $payment->getOrder()->getCustomerId()) {
            $trackingDataObject->setData('user_id', $userId);
        }

        $transactionDataObject = $this->getTransactionDataObject($order, $invoice, $elgentosSalesOrder);

        $this->sendPurchaseEvent($gaclient, $transactionDataObject, $products, $trackingDataObject);

        $this->emulation->stopEnvironmentEmulation();
    }

    /**
     * @param $order
     * @param $invoice
     *
     * @return DataObject
     */
    public function getTransactionDataObject($order, $invoice, $elgentosSalesOrder): DataObject
    {
        $currency = $this->moduleConfiguration->getCurrencySource() === CurrencySource::GLOBAL ?
            $invoice->getGlobalCurrencyCode() :
            $order->getBaseCurrencyCode();

        $transactionDataObject = new DataObject(
            [
                'transaction_id' => $order->getIncrementId(),
                'affiliation' => $order->getStoreName(),
                'currency' => $currency,
                'value' => $invoice->getBaseGrandTotal(),
                'tax' => $invoice->getBaseTaxAmount(),
                'shipping' => ($this->getPaidShippingCosts($invoice) ?? 0),
                'coupon_code' => $order->getCouponCode(),
                'session_id' => $elgentosSalesOrder->getGaSessionId()
            ]
        );

        $this->event->dispatch(
            'elgentos_serversideanalytics_transaction_data_transport_object',
            ['transaction_data_object' => $transactionDataObject, 'order' => $order]
        );

        return $transactionDataObject;
    }

    /**
     * @param GAClient $client
     * @param DataObject $transactionDataObject
     * @param array $products
     * @param DataObject $trackingDataObject
     */
    public function sendPurchaseEvent(GAClient $gaclient, DataObject $transactionDataObject, array $products, DataObject $trackingDataObject)
    {
        try {
            $gaclient->setTransactionData($transactionDataObject);

            $gaclient->addProducts($products);
        } catch (\Exception $e) {
            $gaclient->createLog($e);
            return;
        }

        try {
            $this->event->dispatch(
                'elgentos_serversideanalytics_tracking_data_transport_object',
                ['tracking_data_object' => $trackingDataObject]
            );

            $gaclient->setTrackingData($trackingDataObject);

            $gaclient->firePurchaseEvent();
        } catch (\Exception $e) {
            $gaclient->createLog($e);
        }
    }

    /**
     * Get the actual price the customer also saw in it's cart.
     *
     * @param Item $orderItem
     *
     * @return float
     */
    private function getPaidProductPrice(Item $orderItem)
    {
        return $this->scopeConfig->getValue('tax/display/type') == Config::DISPLAY_TYPE_EXCLUDING_TAX
            ? $orderItem->getBasePrice()
            : $orderItem->getBasePriceInclTax();
    }

    /**
     * @param Invoice $invoice
     *
     * @return float
     */
    private function getPaidShippingCosts(Invoice $invoice)
    {
        return $this->scopeConfig->getValue('tax/display/type') == Config::DISPLAY_TYPE_EXCLUDING_TAX
            ? $invoice->getBaseShippingAmount()
            : $invoice->getBaseShippingInclTax();
    }
}
