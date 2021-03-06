<?php

class Unl_Core_Block_Catalog_Product_Featured_Global extends Unl_Core_Block_Catalog_Product_Featured
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('catalog/product/featured.phtml');
        $this->setWrapperClass('featured global');
    }

    protected function _initCollection()
    {
        $collection = $this->_getProductCollection();
        $this->setProductCollection($collection);

        return $this;
    }

    public function isSourceStoreActive($product) {
        $store_id = $product->getSourceStoreView();
        $stores = Mage::app()->getStores();
        if ($store_id && isset($stores[$store_id]) && $stores[$store_id]->getIsActive()) {
            return true;
        }

        return false;
    }

    public function getProduct()
    {
        $product = parent::getProduct();

        if ($product->getId() && $this->isSourceStoreActive($product)) {
            $product->setStoreId($product->getSourceStoreView());
        }

        return $product;
    }
}
