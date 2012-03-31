<?php

class Unl_Core_Block_Adminhtml_Report_Sales_Reconcile_Cc_Products_Refunded
    extends Unl_Core_Block_Adminhtml_Report_Sales_Reconcile_Grid_Products
{
    protected function _construct()
    {
        parent::_construct();
        $this->_resourceCollectionName  = 'unl_core/report_reconcile_cc_products_refunded';
        $this->_exportExcelUrl = '*/*/exportExcelCcProductsRefunded';
        $this->_exportCsvUrl   = '*/*/exportCsvCcProductsRefunded';
    }
}
