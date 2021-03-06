<?php

/**
 * Inventory audit model
 *
 * Makes a record of any inventory change, note for certain products.
 *
 * @method Unl_Inventory_Model_Resource_Audit _getResource _getResource()
 * @method int getProductId getProductId()
 * @method Unl_Inventory_Model_Audit setProductId setProductId(int $value)
 * @method int getType getType()
 * @method Unl_Inventory_Model_Audit setType setType(int $value)
 * @method float getQty getQty()
 * @method Unl_Inventory_Model_Audit setQty setQty(float $value)
 * @method float getAmount getAmount()
 * @method Unl_Inventory_Model_Audit setAmount setAmount(float $value)
 * @method string getCreatedAt getCreatedAt()
 * @method Unl_Inventory_Model_Audit setCreatedAt setCreatedAt(string $value)
 * @method string getNote getNote()
 * @method Unl_Inventory_Model_Audit setNote setNote(string $value)
 * @method int getInvoiceItemId getInvoiceItemId()
 * @method Unl_Inventory_Model_Audit setInvoiceItemId setInvoiceItemId(int $value)
 * @method int getCreditmemoItemId getCreditmemoItemId()
 * @method Unl_Inventory_Model_Audit setCreditmemoItemId setCreditmemoItemId(int $value)
 * @method array getPurchaseAssociations getPurchaseAssociations()
 * @method Unl_Inventory_Model_Audit setPurchaseAssociations setPurchaseAssociations(array $value)
 *
 * @category Unl
 * @package Unl_Inventory
 * @author Kevin Abel <kabel2@unl.edu>
 *
 */
class Unl_Inventory_Model_Audit extends Mage_Core_Model_Abstract
{
    const TYPE_SALE        = 1;
    const TYPE_CREDIT      = 2;
    const TYPE_PURCHASE    = 3;
    const TYPE_ADJUSTMENT  = 4;
    const TYPE_NOTE_ONLY   = 5;

    const TYPE_ADJUSTMENT_SET     = 1;
    const TYPE_ADJUSTMENT_OFFSET  = 2;

    protected function _construct()
	{
		$this->_init('unl_inventory/audit');
	}

	protected function _beforeSave()
	{
	    if (is_null($this->getData('created_at'))) {
	        $this->setData('created_at', Mage::getSingleton('core/date')->gmtDate());
	    }
	    return parent::_beforeSave();
	}

	protected function _afterSave()
	{
	    $accounting = Mage::getSingleton('unl_inventory/config')->getAccounting();

	    if ($accounting != Unl_Inventory_Model_Config::ACCOUNTING_AVG && $this->hasPurchaseAssociations()) {
	        $this->_getResource()->insertPurchaseAssociations($this);
	    }

	    return parent::_afterSave();
	}

	public function setProduct($product)
	{
	    parent::setProduct($product);
	    $this->setProductId($product->getId());

	    return $this;
	}

	/**
	 *
	 * @return Mage_Catalog_Model_Product
	 */
	public function getProduct()
	{
	    if (!$this->hasProduct() && $this->hasProductId()) {
	        $this->setProduct(Mage::getModel('catalog/product')->load($this->getProductId()));
	    }

	    return $this->getData('product');
	}

	public function getCostPerItem()
	{
	    return $this->getAmount() / $this->getQty();
	}

	/**
	 * Gets the related invoice item object
	 *
	 * @return Mage_Sales_Model_Order_Invoice_Item
	 */
	public function getInvoiceItem()
	{
	    if (!$this->hasInvoiceItem() && $this->hasInvoiceItemId()) {
	        $this->setInvoiceItem(Mage::getModel('sales/order_invoice_item')->load($this->getInvoiceItemId()));
	    }

	    return $this->getData('invoice_item');
	}

	/**
	 * Gets the related creditmemo item object
	 *
	 * @return Mage_Sales_Model_Order_Creditmemo_Item
	 */
	public function getCreditmemoItem()
	{
	    if (!$this->hasCreditmemoItem() && $this->hasCreditmemoItemId()) {
	        $this->setCreditmemoItem(Mage::getModel('sales/order_creditmemo_item')->load($this->getCreditmemoItemId()));
	    }

	    return $this->getData('creditmemo_item');
	}

	public function syncItemCost()
	{
	    if ($this->getInvoiceItem()) {
	        $item = $this->getInvoiceItem();
	        $item->setBaseCost($this->getCostPerItem());
	        $item->getOrderItem()->setBaseCost($this->getCostPerItem());

	        $item->save();
	    } elseif ($this->getCreditmemoItem()) {
	        $item = $this->getCreditmemoItem();
	        $item->setBaseCost($this->getCostPerItem());

	        $item->save();
	    }

	    return $this;
	}
}
