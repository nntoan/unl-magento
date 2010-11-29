<?php


class Unl_Core_Model_Sales_Observer
{
    /**
     * Sets the quote_item's source store view
     *
     * @param $observer Varien_Event_Observer
     */
    public function setQuoteItemSourceStore($observer)
    {
        $product = $observer->getEvent()->getProduct();
        $quote_item = $observer->getEvent()->getQuoteItem();

        $sourceStore = $product->getSourceStoreView();

        if (null !== $sourceStore) {
            $quote_item->setSourceStoreView($sourceStore);
        }
    }

    public function onShippingMethodSet($observer)
    {
        /* @var $address Unl_Core_Model_Sales_Quote_Address */
        $address = $observer->getEvent()->getQuoteAddress();
        $method = $address->getShippingMethod();

        if (!empty($method) && strpos($method, 'pickup_store') === 0) {
            $address->getShippingRateByCode($method)->getCarrierInstance()->updateAddress($address);
            $address->save();
        }
    }

    /**
     * Adds some missing data members of quote_address_item to order_item from quote_item
     *
     * @param $observer Varien_Event_Observer
     */
    public function onSalesConvertQuoteItemToOrderItem($observer)
    {
        $item = $observer->getEvent()->getItem();

        if ($item instanceof Mage_Sales_Model_Quote_Address_Item) {
            $quoteItem = $item->getQuoteItem();
            $orderItem = $observer->getEvent()->getOrderItem();

            $orderItem->setStoreId($quoteItem->getStoreId())
                ->setSourceStoreView($quoteItem->getSourceStoreView());

            //TODO: Fix tax_percent issue displayed in adminhtml
//            if (null === $orderItem->getTaxPercent()) {
//
//            }
        }
    }
}