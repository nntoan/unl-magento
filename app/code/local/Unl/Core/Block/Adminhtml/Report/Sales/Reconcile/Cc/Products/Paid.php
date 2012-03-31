<?php

class Unl_Core_Block_Adminhtml_Report_Sales_Reconcile_Cc_Products_Paid
    extends Unl_Core_Block_Adminhtml_Report_Sales_Reconcile_Grid_Products
{
    protected function _construct()
    {
        parent::_construct();
        $this->_resourceCollectionName  = 'unl_core/report_reconcile_cc_products_paid';
        $this->_exportExcelUrl = '*/*/exportExcelCcProductsPaid';
        $this->_exportCsvUrl   = '*/*/exportCsvCcProductsPaid';
    }
}
