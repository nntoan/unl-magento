<?php

class Unl_Core_Block_Adminhtml_Sales_Creditmemo_Grid extends Mage_Adminhtml_Block_Sales_Creditmemo_Grid
{
    protected function _prepareCollection()
    {
        $collection = Mage::getResourceModel($this->_getCollectionClass());
        
        $user  = Mage::getSingleton('admin/session')->getUser();
        if (!is_null($user->getScope())) {
            $scope = explode(',', $user->getScope());
            $order_items = Mage::getModel('sales/order_item')->getCollection();
            /* @var $order_items Mage_Sales_Model_Mysql4_Order_Item_Collection */
            $select = $order_items->getSelect()->reset(Zend_Db_Select::COLUMNS)
                ->columns(array('order_id'))
                ->where('source_store_view IN (?)', $scope)
                ->group('order_id');
                
            $collection->getSelect()
                ->joinInner(array('scope' => $select), 'main_table.order_id = scope.order_id', array());
        }
        
        $this->setCollection($collection);
        
        return Mage_Adminhtml_Block_Widget_Grid::_prepareCollection();
    }
    
    public function getRowUrl($row)
    {
        if (!Mage::getSingleton('admin/session')->isAllowed('sales/creditmemo')) {
            return false;
        }

        return $this->getUrl('*/sales_creditmemo/view',
            array(
                'creditmemo_id'=> $row->getId(),
            )
        );
    }
}