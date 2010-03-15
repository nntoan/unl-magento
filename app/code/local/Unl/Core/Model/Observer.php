<?php

class Unl_Core_Model_Observer
{
    public function prepareWysiwygConfig($observer)
    {
        $config = $observer->getEvent()->getConfig();
        $design = Mage::getModel('core/design_package')->setStore(Mage::app()->getDefaultStoreView());
        $css = array(
            '/wdn/templates_3.0/css/all.css',
            $design->getSkinUrl('css/styles.css')
        );
        
        if ($config->getContentCss()) {
            array_unshift($css, $config->getContentCss());
        }
        
        $config->setContentCss(implode(',', $css));
    }
    
    public function correctAdminBlocks($observer)
    {
        $block = $observer->getEvent()->getBlock();
        
        //Do actions based on block type
        
        $type = 'Mage_Adminhtml_Block_Catalog_Category_Tree';
        if ($block instanceof $type) {
            $block->getChild('store_switcher')->setTemplate('unl/store/switcher/enhanced.phtml');
        }
        
        $type = 'Mage_Adminhtml_Block_Report_Sales_Tax_Grid';
        if ($block instanceof $type) {
            $block->setStoreSwitcherVisibility(false);
        }
    }
    
    // These occur before the correctAdminBlocks (_beforeToHtml) calls
    public function beforeCoreBlockToHtml($observer)
    {
        $block = $observer->getEvent()->getBlock();
        
        $type = 'Mage_Adminhtml_Block_Permissions_User_Edit_Tabs';
        if ($block instanceof $type) {
            $block->addTab('scope_section', array(
                'label'     => Mage::helper('adminhtml')->__('User Scope'),
                'title'     => Mage::helper('adminhtml')->__('User Scope'),
                'content'   => $block->getLayout()->createBlock('unl_core/adminhtml_permissions_user_edit_tab_scope')->toHtml(),
                'after'     => 'roles_section',
            ));
        }
        
        $type = 'Mage_Adminhtml_Block_Catalog_Product_Grid';
        if ($block instanceof $type) {
            $request = Mage::app()->getRequest();
            $request->setParam('_unlcore_std_product_grid', true);
        }
        
        $type = 'Mage_Page_Block_Switch';
        if ($block instanceof $type) {
            /* @var $block Mage_Page_Block_Switch */
            $groups = $block->getGroups();
            if (count($groups) > 1) {
                usort($groups, array($this, '_compareStores'));
                $block->setData('groups', $groups);
            }
        }
    }
    
    /**
     * 
     * @param Mage_Core_Model_Store_Group $a
     * @param Mage_Core_Model_Store_Group $b
     * @return int
     */
    protected function _compareStores($a, $b)
    {
        $sortA = $a->getDefaultStore()->getSortOrder();
        $sortB = $b->getDefaultStore()->getSortOrder();
        
        if ($sortA == $sortB) {
            return 0;
        }
        return ($sortA > $sortB) ? 1 : -1;
    }
    
    public function beforeEavCollectionLoad($observer)
    {
        $request = Mage::app()->getRequest();
        $collection = $observer->getEvent()->getCollection();
        
        $type = 'Mage_Catalog_Model_Resource_Eav_Mysql4_Product_Collection';
        if ($request->getParam('_unlcore_std_product_grid') && $collection instanceof $type) {
            $user = Mage::getSingleton('admin/session')->getUser();
            if ($scope = $user->getScope()) {
                $scope = explode(',', $scope);
                $collection->addAttributeToFilter('source_store_view', array('in' => $scope));
            }
        }
    }
    
    /**
     * Save order tax information
     *
     * @param Varien_Event_Observer $observer
     */
    public function salesEventOrderAfterSave(Varien_Event_Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order->getConvertingFromQuote()) {
            return;
        }

        $taxes = $order->getAppliedTaxes();
        foreach ($taxes as $row) {
            foreach ($row['rates'] as $tax) {
                if (is_null($row['percent'])) {
                    $baseRealAmount = $row['base_amount'];
                } else {
                    if ($row['percent'] == 0 || $tax['percent'] == 0) {
                        $baseRealAmount = 0;
                    } else {
                        $baseRealAmount = $row['base_amount']/$row['percent']*$tax['percent'];
                    }
                }
                $hidden = (isset($row['hidden']) ? $row['hidden'] : 0);
                $data = array(
                            'order_id'=>$order->getId(),
                            'code'=>$tax['code'],
                            'title'=>$tax['title'],
                            'hidden'=>$hidden,
                            'percent'=>$tax['percent'],
                            'priority'=>$tax['priority'],
                            'position'=>$tax['position'],
                            'amount'=>$row['amount'],
                            'base_amount'=>$row['base_amount'],
                            'process'=>$row['process'],
                            'base_real_amount'=>$baseRealAmount,
                            'sale_amount'=>$row['sale_amount'],
                            'base_sale_amount'=>$row['base_sale_amount']
                            );

                Mage::getModel('sales/order_tax')->setData($data)->save();
            }
        }
    }
    
    /**
     * Daily DB backup (called from cron)
     *
     * @param   Varien_Event_Observer $observer
     * @return  Unl_Core_Model_Observer
     */
    public function generateNightlyBackup($observer)
    {
        try {
            $backupDb = Mage::getModel('backup/db');
            $backup   = Mage::getModel('backup/backup')
                ->setTime(time())
                ->setType('db')
                ->setPath(Mage::getBaseDir("var") . DS . "backups");

            $backupDb->createBackup($backup);
        }
        catch (Exception  $e) { }
        
        return $this;
    }
    
    public function isPaymentMethodActive($observer)
    {
        $method = $observer->getEvent()->getMethodInstance();
        $result = $observer->getEvent()->getResult();
        
        if ($method instanceof Mage_Payment_Model_Method_Purchaseorder) {
            /* @var $customerSession Mage_Customer_Model_Session */
            $customerSession = Mage::getSingleton('customer/session');
            if (!$customerSession->isLoggedIn()) {
                $result->isAvailable = false;
                return;
            } else {
                $customer = $customerSession->getCustomer();
                $facStaffModel = Mage::getModel('customer/group')->load('UNL Faculty/Staff', 'customer_group_code');
                if ($customer->getGroupId() !== $facStaffModel->getId()) {
                    $result->isAvailable = false;
                    return;
                }
            }
        }
    }
}
