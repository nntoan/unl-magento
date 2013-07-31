<?php

class Unl_Ship_Model_Shipping_Carrier_Usps_Endicia
{
    const DEFAULT_MAX_POSTAGE = 500;
    const DEFAULT_MIN_RECREDIT = 10;

    protected $_carrier;

    /**
     * Get the singleton instance of the USPS shippnig carrier
     *
     * @return Unl_Ship_Model_Shipping_Carrier_Usps
     */
    public function getCarrier()
    {
        if (null === $this->_carrier) {
            $this->_carrier = Mage::getSingleton('usa/shipping_carrier_usps');
        }

        return $this->_carrier;
    }

    public function setCarrier($usps)
    {
        $this->_carrier = $usps;
        return $this;
    }

    public function requestBuyPostage($force = false, $currentBalence = null)
    {
        if (is_null($currentBalence)) {
            $status = $this->requestAccountStatus();
            $currentBalence = (float)$status->CertifiedIntermediary->PostageBalance;
        }

        $usps = $this->getCarrier();
        $autoThreshold = $usps->getConfigData('endicia_auto_purchase_threshold');
        $maxBalence = $usps->getConfigData('endicia_max_postage');
        if (empty($maxBalence)) {
            $maxBalence = self::DEFAULT_MAX_POSTAGE;
        }

        if ($force || ($autoThreshold != '' && $currentBalence <= $autoThreshold)) {
            $recreditAmount = $maxBalence - $currentBalence;

            if ($recreditAmount < self::DEFAULT_MIN_RECREDIT) {
                return false;
            }

            $requstId = sha1(microtime() . 'ENDICIA_RECREDIT_REQUEST');
            $xmlRequest = new SimpleXMLElement('<RecreditRequest/>');
            $xmlRequest->addChild('RequesterID', $usps->getConfigData('endicia_requester_id'));
            $xmlRequest->addChild('RequestID', $requstId);

            $certifiedIntermediary = $xmlRequest->addChild('CertifiedIntermediary');
            $certifiedIntermediary->addChild('AccountID', $usps->getConfigData('endicia_account_id'));
            $certifiedIntermediary->addChild('PassPhrase', $usps->getConfigData('endicia_passphrase'));

            $xmlRequest->addChild('RecreditAmount', round($recreditAmount, 2));

            $url = $usps->getConfigData('endicia_els_url') . '/BuyPostageXML';

            $client = new Zend_Http_Client();
            $client->setUri($url);
            $client->setConfig(array('maxredirects' => 0, 'timeout' => 30));
            $client->setParameterPost('recreditRequestXML', $xmlRequest->asXML());

            $response = $client->request(Zend_Http_Client::POST);
            $xmlResponse = $response->getBody();

            if ($response->getStatus() != 200) {
                throw new Exception('Invalid Request: ' . $xmlResponse);
            }

            try {
                $xml = @new SimpleXMLElement($xmlResponse);
            } catch (Exception $e) {
                throw new Exception('Invalid Endicia Response');
            }

            if ($xml->Status != 0) {
                throw new Exception($xml->ErrorMessage);
            }

            return true;
        }
    }

    public function requestAccountStatus()
    {
        $usps = $this->getCarrier();
        $requstId = sha1(microtime() . 'ENDICIA_ACCOUNT_STATUS');
        $xmlRequest = new SimpleXMLElement('<AccountStatusRequest/>');
        $xmlRequest->addChild('RequesterID', $usps->getConfigData('endicia_requester_id'));
        $xmlRequest->addChild('RequestID', $requstId);

        $certifiedIntermediary = $xmlRequest->addChild('CertifiedIntermediary');
        $certifiedIntermediary->addChild('AccountID', $usps->getConfigData('endicia_account_id'));
        $certifiedIntermediary->addChild('PassPhrase', $usps->getConfigData('endicia_passphrase'));

        $url = $usps->getConfigData('endicia_els_url') . '/GetAccountStatusXML';

        $client = new Zend_Http_Client();
        $client->setUri($url);
        $client->setConfig(array('maxredirects' => 0, 'timeout' => 30));
        $client->setParameterPost('accountStatusRequestXML', $xmlRequest->asXML());

        $response = $client->request(Zend_Http_Client::POST);
        $xmlResponse = $response->getBody();

        if ($response->getStatus() != 200) {
            throw new Exception('Invalid Request: ' . $xmlResponse);
        }

        try {
            $xml = @new SimpleXMLElement($xmlResponse);
        } catch (Exception $e) {
            throw new Exception('Invalid Endicia Response');
        }

        if ($xml->Status != 0) {
            throw new Exception($xml->ErrorMessage);
        }

        return $xml;
    }

    public function requestChangePassPhrase($oldPassPhrase, $newPassPhrase)
    {
        $usps = $this->getCarrier();
        $requstId = sha1(microtime() . 'ENDICIA_PASSPHRASE_CHANGE');

        $xmlRequest = new SimpleXMLElement('<ChangePassPhraseRequest/>');
        $xmlRequest->addChild('RequesterID', $usps->getConfigData('endicia_requester_id'));
        $xmlRequest->addChild('RequestID', $requstId);

        $certifiedIntermediary = $xmlRequest->addChild('CertifiedIntermediary');
        $certifiedIntermediary->addChild('AccountID', $usps->getConfigData('endicia_account_id'));
        $certifiedIntermediary->addChild('PassPhrase', $oldPassPhrase);

        $xmlRequest->addChild('NewPassPhrase', $newPassPhrase);

        $debugData = array('request' => $xmlRequest->asXML());
        try {
            $url = $usps->getConfigData('endicia_els_url') . '/ChangePassPhraseXML';

            $client = new Zend_Http_Client();
            $client->setUri($url);
            $client->setConfig(array('maxredirects' => 0, 'timeout' => 30));
            $client->setParameterPost('changePassPhraseRequestXML', $xmlRequest->asXML());

            $response = $client->request(Zend_Http_Client::POST);
            $xmlResponse = $response->getBody();
        } catch (Exception $e) {
            $debugData['result'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
            $xmlResponse = '';
        }

        if ($response->getStatus() != 200) {
            $debugData['result'] = array('error' => 'Invalid Request: ' . $xmlResponse);
            $xml = new SimpleXMLElement('<Error/>');
        } else {
            try {
                $xml = @new SimpleXMLElement($xmlResponse);
            } catch (Exception $e) {
                $debugData['result'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
                $xml = new SimpleXMLElement('<Error/>');
            }
        }

        $usps->debugData($debugData);

        if (!isset($xml->Status) || $xml->Status != 0) {
            throw new Exception($xml->ErrorMessage);
        }

        return $this;
    }

    public function getMailpieceInfoFromService($service)
    {
        $codes = array(
            'First-Class'                                   => array('First', null),
            'First-Class Mail International Large Envelope' => array('FirstClassMailInternational', 'Flat'),
            'First-Class Mail International Letter'         => array('FirstClassMailInternational', 'Letter'),
            'First-Class Mail International Package'        => array('FirstClassPackageInternationalService', 'Parcel'),
            'First-Class Mail International Parcel'         => array('FirstClassPackageInternationalService', 'Parcel'),
            'First-Class Mail'                 => array('First', null),
            'First-Class Mail Flat'            => array('First', 'Flat'),
            'First-Class Mail Large Envelope'  => array('First', 'Flat'),
            'First-Class Mail International'   => array('FirstClassMailInternational', 'Letter'),
            'First-Class Mail Letter'          => array('First', 'Letter'),
            'First-Class Mail Parcel'          => array('First', 'Parcel'),
            'First-Class Mail Package'         => array('First', 'Parcel'),
            'Parcel Post'                      => array('ParcelSelect', 'Parcel'),
            'Bound Printed Matter'             => false,
            'Media Mail'                       => array('MediaMail', 'Parcel'),
            'Library Mail'                     => array('LibraryMail', 'Parcel'),
            'Express Mail'                     => array('PriorityExpress', null),
            'Express Mail PO to PO'            => array('PriorityExpress', null),
            'Express Mail Flat Rate Envelope'  => array('PriorityExpress', 'FlatRateEnvelope'),
            'Express Mail Flat-Rate Envelope Sunday/Holiday Guarantee'  => array('PriorityExpress', 'FlatRateEnvelope', array('Sunday')),
            'Express Mail Sunday/Holiday Guarantee'            => array('PriorityExpress', null, array('Sunday')),
            'Express Mail Flat Rate Envelope Hold For Pickup'  => array('PriorityExpress', 'FlatRateEnvelope', array('Hfp')),
            'Express Mail Hold For Pickup'                     => array('PriorityExpress', null, array('Hfp')),
            'Global Express Guaranteed (GXG)'                  => array('GXG', null),
            'Global Express Guaranteed Non-Document Rectangular'     => array('GXG', 'Parcel'),
            'Global Express Guaranteed Non-Document Non-Rectangular' => array('GXG', 'Parcel'),
            'USPS GXG Envelopes'                               => array('GXG', 'Flat'),
            'Express Mail International'                       => array('PriorityMailExpressInternational', null),
            'Express Mail International Flat Rate Envelope'    => array('PriorityMailExpressInternational', 'FlatRateEnvelope'),
            'Priority Mail'                        => array('Priority', null),
            'Priority Mail Small Flat Rate Box'    => array('Priority', 'SmallFlatRateBox'),
            'Priority Mail Medium Flat Rate Box'   => array('Priority', 'MediumFlatRateBox'),
            'Priority Mail Large Flat Rate Box'    => array('Priority', 'LargeFlatRateBox'),
            'Priority Mail Flat Rate Box'          => array('Priority', 'MediumFlatRateBox'),
            'Priority Mail Flat Rate Envelope'     => array('Priority', 'FlatRateEnvelope'),
            'Priority Mail International'                            => array('PriorityMailInternational', null),
            'Priority Mail International Flat Rate Envelope'         => array('PriorityMailInternational', 'FlatRateEnvelope'),
            'Priority Mail International Small Flat Rate Box'        => array('PriorityMailInternational', 'SmallFlatRateBox'),
            'Priority Mail International Medium Flat Rate Box'       => array('PriorityMailInternational', 'MediumFlatRateBox'),
            'Priority Mail International Large Flat Rate Box'        => array('PriorityMailInternational', 'LargeFlatRateBox'),
            'Priority Mail International Flat Rate Box'              => array('PriorityMailInternational', 'MediumFlatRateBox'),

            //services added after magento core
            'First-Class Mail Postcards'                 => array('First', 'Card'),
            'First-Class Mail Large Postcards'           => array('First', 'Card'),
            'First-Class Package International Service'  => array('FirstClassPackageInternationalService', 'Parcel'),
            'Standard Post'                              => array('ParcelSelect', 'Parcel'),
            'Express Mail Sunday/Holiday Delivery'                           => array('PriorityExpress', null, array('Sunday')),
            'Express Mail Flat Rate Boxes'                                   => array('PriorityExpress', 'MediumFlatRateBox'),
            'Express Mail Flat Rate Boxes Hold For Pickup'                   => array('PriorityExpress', 'MediumFlatRateBox', array('Hfp')),
            'Express Mail Sunday/Holiday Delivery Flat-Rate Boxes'           => array('PriorityExpress', 'MediumFlatRateBox', array('Sunday')),
            'Express Mail Sunday/Holiday Delivery Flat-Rate Envelope'        => array('PriorityExpress', 'FlatRateEnvelope', array('Sunday')),
            'Express Mail Legal Flat Rate Envelope'                          => array('PriorityExpress', 'FlatRateLegalEnvelope'),
            'Express Mail Legal Flat Rate Envelope Hold For Pickup'          => array('PriorityExpress', 'FlatRateLegalEnvelope', array('Hfp')),
            'Express Mail Sunday/Holiday Delivery Legal Flat Rate Envelope'  => array('PriorityExpress', 'FlatRateLegalEnvelope', array('Sunday')),
            'Express Mail Padded Flat Rate Envelope'                         => array('PriorityExpress', 'FlatRatePaddedEnvelope'),
            'Express Mail Padded Flat Rate Envelope Hold For Pickup'         => array('PriorityExpress', 'FlatRatePaddedEnvelope', array('Hfp')),
            'Express Mail Sunday/Holiday Delivery Padded Flat Rate Envelope' => array('PriorityExpress', 'FlatRatePaddedEnvelope', array('Sunday')),
            'Express Mail International Flat Rate Boxes'                     => array('PriorityMailExpressInternational', 'MediumFlatRateBox'),
            'Express Mail International Legal Flat Rate Envelope'            => array('PriorityMailExpressInternational', 'FlatRateLegalEnvelope'),
            'Express Mail International Padded Flat Rate Envelope'           => array('PriorityMailExpressInternational', 'FlatRatePaddedEnvelope'),
            'Priority Mail Legal Flat Rate Envelope'     => array('Priority', 'FlatRateLegalEnvelope'),
            'Priority Mail Padded Flat Rate Envelope'    => array('Priority', 'FlatRatePaddedEnvelope'),
            'Priority Mail Gift Card Flat Rate Envelope' => array('Priority', 'FlatRateGiftCardEnvelope'),
            'Priority Mail Small Flat Rate Envelope'     => array('Priority', 'SmallFlatRateEnvelope'),
            'Priority Mail Window Flat Rate Envelope'    => array('Priority', 'FlatRateWindowEnvelope'),
            'Priority Mail International DVD Flat Rate priced box'           => array('PriorityMailInternational', 'DVDFlatRateBox'),
            'Priority Mail International Large Video Flat Rate priced box'   => array('PriorityMailInternational', 'LargeVideoFlatRateBox'),
            'Priority Mail International Legal Flat Rate Envelope'           => array('PriorityMailInternational', 'FlatRateLegalEnvelope'),
            'Priority Mail International Padded Flat Rate Envelope'          => array('PriorityMailInternational', 'FlatRatePaddedEnvelope'),
            'Priority Mail International Gift Card Flat Rate Envelope'       => array('PriorityMailInternational', 'FlatRateGiftCardEnvelope'),
            'Priority Mail International Small Flat Rate Envelope'           => array('PriorityMailInternational', 'SmallFlatRateEnvelope'),
            'Priority Mail International Window Flat Rate Envelope'          => array('PriorityMailInternational', 'FlatRateWindowEnvelope'),

            //service added after USPS 7/28/2013
            'Priority Mail 2-Day'                                            => array('Priority', null),
            'Priority Mail 2-Day Flat Rate Envelope'                         => array('Priority', 'FlatRateEnvelope'),
            'Priority Mail 2-Day Gift Card Flat Rate Envelope'               => array('Priority', 'FlatRateGiftCardEnvelope'),
            'Priority Mail 2-Day Large Flat Rate Box'                        => array('Priority', 'LargeFlatRateBox'),
            'Priority Mail 2-Day Legal Flat Rate Envelope'                   => array('Priority', 'FlatRateLegalEnvelope'),
            'Priority Mail 2-Day Medium Flat Rate Box'                       => array('Priority', 'MediumFlatRateBox'),
            'Priority Mail 2-Day Padded Flat Rate Envelope'                  => array('Priority', 'FlatRatePaddedEnvelope'),
            'Priority Mail 2-Day Small Flat Rate Box'                        => array('Priority', 'SmallFlatRateBox'),
            'Priority Mail 2-Day Small Flat Rate Envelope'                   => array('Priority', 'SmallFlatRateEnvelope'),
            'Priority Mail 2-Day Window Flat Rate Envelope'                  => array('Priority', 'FlatRateWindowEnvelope'),
            'Priority Mail Express 2-Day'                                           => array('PriorityExpress', null),
            'Priority Mail Express 2-Day Flat Rate Boxes'                           => array('PriorityExpress', 'MediumFlatRateBox'),
            'Priority Mail Express 2-Day Flat Rate Boxes Hold For Pickup'           => array('PriorityExpress', 'MediumFlatRateBox', array('Hfp')),
            'Priority Mail Express 2-Day Flat Rate Envelope'                        => array('PriorityExpress', 'FlatRateEnvelope'),
            'Priority Mail Express 2-Day Flat Rate Envelope Hold For Pickup'        => array('PriorityExpress', 'FlatRateEnvelope', array('Hfp')),
            'Priority Mail Express 2-Day Hold For Pickup'                           => array('PriorityExpress', null, array('Hfp')),
            'Priority Mail Express 2-Day Legal Flat Rate Envelope'                  => array('PriorityExpress', 'FlatRateLegalEnvelope'),
            'Priority Mail Express 2-Day Legal Flat Rate Envelope Hold For Pickup'  => array('PriorityExpress', 'FlatRateLegalEnvelope', array('Hfp')),
            'Priority Mail Express 2-Day Padded Flat Rate Envelope'                 => array('PriorityExpress', 'FlatRatePaddedEnvelope'),
            'Priority Mail Express 2-Day Padded Flat Rate Envelope Hold For Pickup' => array('PriorityExpress', 'FlatRatePaddedEnvelope', array('Hfp')),
            'Priority Mail Express International'                                   => array('PriorityMailExpressInternational', null),
            'Priority Mail Express International Flat Rate Boxes'                   => array('PriorityMailExpressInternational', 'MediumFlatRateBox'),
            'Priority Mail Express International Flat Rate Envelope'                => array('PriorityMailExpressInternational', 'FlatRateEnvelope'),
            'Priority Mail Express International Legal Flat Rate Envelope'          => array('PriorityMailExpressInternational', 'FlatRateLegalEnvelope'),
            'Priority Mail Express International Padded Flat Rate Envelope'         => array('PriorityMailExpressInternational', 'FlatRatePaddedEnvelope'),
        );

        if (!isset($codes[$service])) {
            return false;
        }

        return $codes[$service];
    }

    /**
     * Sends and processes a label request to Endicia Label Server
     *
     * @param Unl_Ship_Model_Shipping_Carrier_Usps $usps
     * @param Varien_Object $request
     */
    public function doShipmentRequest($usps, $request, $domestic)
    {
        $this->setCarrier($usps);
        $result = new Varien_Object();
        $mailinfo = $this->getMailpieceInfoFromService($request->getShippingMethod());

        if (!$mailinfo) {
            throw new Exception(Mage::helper('usa')->__('Service type does not match'));
        }

        $packageParams = $request->getPackageParams();
        $packageWeight = $request->getPackageWeight();

        if ($packageParams->getWeightUnits() != Zend_Measure_Weight::OUNCE) {
            $packageWeight = Mage::helper('usa')->convertMeasureWeight(
                $request->getPackageWeight(),
                $packageParams->getWeightUnits(),
                Zend_Measure_Weight::OUNCE
            );
            $packageWeight = round($packageWeight, 1);
        }

        $labelFormat = 'PNG';

        $xmlRequest = new SimpleXMLElement('<LabelRequest/>');
        $xmlRequest->addAttribute('ImageFormat', $labelFormat . 'MONOCHROME');

        if ($domestic) {
            $xmlRequest->addAttribute('LabelType', 'Default');
            $xmlRequest->addAttribute('LabelSize', '4x6');

        } else {
            $xmlRequest->addAttribute('LabelType', 'International');
            $xmlRequest->addAttribute('LabelSubtype', 'Integrated');
            $xmlRequest->addAttribute('LabelSize', '4x6c');
            $xmlRequest->addAttribute('ImageRotation', 'Rotate270');
        }

        if ($usps->getConfigFlag('endicia_test_mode')) {
            $xmlRequest->addAttribute('Test', 'YES');
        }

        $xmlRequest->addChild('RequesterID', $usps->getConfigData('endicia_requester_id'));
        $xmlRequest->addChild('AccountID', $usps->getConfigData('endicia_account_id'));
        $xmlRequest->addChild('PassPhrase', $usps->getConfigData('endicia_passphrase'));

        $xmlRequest->addChild('MailClass', $mailinfo[0]);
        $xmlRequest->addChild('WeightOz', $packageWeight);

        if (!is_null($mailinfo[1])) {
            $xmlRequest->addChild('MailpieceShape', $mailinfo[1]);
        }

        if ($mailinfo[0] == 'ParcelSelect') {
            $xmlRequest->addChild('SortType', 'Nonpresorted');
            $xmlRequest->addChild('EntryFacility', 'Other');
        }

        if ($packageParams->getLength() || $packageParams->getWidth() || $packageParams->getHeight()) {
            if ($packageParams->getDimensionUnits() != Zend_Measure_Length::INCH) {
                $length = round(Mage::helper('usa')->convertMeasureDimension(
                    $packageParams->getLength(),
                    $packageParams->getDimensionUnits(),
                    Zend_Measure_Length::INCH
                ));
                $width = round(Mage::helper('usa')->convertMeasureDimension(
                    $packageParams->getWidth(),
                    $packageParams->getDimensionUnits(),
                    Zend_Measure_Length::INCH
                ));
                $height = round(Mage::helper('usa')->convertMeasureDimension(
                    $packageParams->getHeight(),
                    $packageParams->getDimensionUnits(),
                    Zend_Measure_Length::INCH
                ));
            } else {
                $length = round($packageParams->getLength());
                $width = round($packageParams->getWidth());
                $height = round($packageParams->getHeight());
            }
            $dimensions = $xmlRequest->addChild('MailpieceDimensions');
            $dimensions->addChild('Length', $length);
            $dimensions->addChild('Width', $width);
            $dimensions->addChild('Height', $height);
        }

        if (!$domestic) {
            $xmlRequest->addChild('IntegratedFormType', 'FORM2976A');
            $customsInfo = $xmlRequest->addChild('CustomsInfo');
            $customsInfo->addChild('ContentsType', $packageParams->getContentType());
            if ($packageParams->getContentType() == 'OTHER') {
                $customsInfo->addChild('ContentsExplanation', $packageParams->getContentTypeOther());
            }

            $customsItems = $customsInfo->addChild('CustomsItems');
            foreach ($request->getPackageItems() as $item) {
                $cItem = $customsItems->addChild('CustomsItem');
                $cItem->addChild('Description', substr($item['name'], 0, 50));
                $cItem->addChild('Quantity', $item['qty']);
                $cItem->addChild('Weight', floor(Mage::helper('usa')->convertMeasureWeight(
                    $item['weight'],
                    Zend_Measure_Weight::POUND,
                    Zend_Measure_Weight::OUNCE
                )));
                $cItem->addChild('Value', round($item['customs_value'] * $item['qty'], 2));
            }

            //$xmlRequest->addChild('Value', $packageParams->getCustomsValue());
            //$xmlRequest->addChild('Description', 'Order # ' . $request->getOrderShipment()->getOrder()->getIncrementId());
        }

        if ($packageParams->getDeliveryConfirmation() === 'False') {
            $xmlRequest->addChild('Services')
                ->addAttribute('SignatureConfirmation', 'ON');
        }

        $xmlRequest->addChild('ReferenceID', $request->getOrderShipment()->getOrder()->getIncrementId());
        $xmlRequest->addChild('RubberStamp1', 'Order # ' . $request->getOrderShipment()->getOrder()->getIncrementId());

        $requestId = substr(sha1(microtime() . $request->getShipperEmail() . 'ENDICIA_LABEL_REQUEST'), 0, 25);
        $xmlRequest->addChild('PartnerCustomerID', $request->getShipperContactPhoneNumber());
        $xmlRequest->addChild('PartnerTransactionID', $requestId);

        $xmlRequest->addChild('ResponseOptions')
            ->addAttribute('PostagePrice', 'TRUE');

        $xmlRequest->addChild('FromName', htmlspecialchars($request->getShipperContactPersonName()));
        if ($request->getShipperContactCompanyName()) {
            $xmlRequest->addChild('FromCompany', htmlspecialchars($request->getShipperContactCompanyName()));
        }
        $xmlRequest->addChild('ReturnAddress1', $request->getShipperAddressStreet1());
        $xmlRequest->addChild('ReturnAddress2', $request->getShipperAddressStreet2());
        $xmlRequest->addChild('FromCity', $request->getShipperAddressCity());
        $xmlRequest->addChild('FromState', $request->getShipperAddressStateOrProvinceCode());
        $xmlRequest->addChild('FromPostalCode', $request->getShipperAddressPostalCode());
        if ($request->getShipperAddressCountryCode() != 'US') {
            $xmlRequest->addChild('FromCountry', $request->getShipperAddressCountryCode());
        }
        $xmlRequest->addChild('FromPhone', $request->getShipperContactPhoneNumber());
        $xmlRequest->addChild('FromEMail', $request->getShipperEmail());

        $postalCode = $request->getRecipientAddressPostalCode();
        if ($domestic) {
            $postalCode = explode('-', $postalCode);
            $toZip5 = substr($postalCode[0], 0, 5);
            $toZip4 = isset($postalCode[1]) ? $postalCode[1] : substr($postalCode[0], 5, 4);
        }
        $xmlRequest->addChild('ToName', htmlspecialchars($request->getRecipientContactPersonName()));
        $xmlRequest->addChild('ToCompany', htmlspecialchars($request->getRecipientContactCompanyName()));
        $xmlRequest->addChild('ToAddress1', $request->getRecipientAddressStreet1());
        $xmlRequest->addChild('ToAddress2', $request->getRecipientAddressStreet2());

        $city = $request->getRecipientAddressCity();
        if ($domestic) {
            $city = preg_replace('/[^a-zA-Z \-\.]/', '', $city);
        }
        $xmlRequest->addChild('ToCity', $city);

        if ($request->getRecipientAddressStateOrProvinceCode()) {
            $xmlRequest->addChild('ToState', $request->getRecipientAddressStateOrProvinceCode());
        }
        $xmlRequest->addChild('ToPostalCode', $domestic ? $toZip5 : $postalCode);
        if ($domestic && $toZip4) {
            $xmlRequest->addChild('ToZip4', $toZip4);
        }
        if ($request->getRecipientAddressCountryCode() != 'US') {
            //$toCountry = Mage::getModel('directory/country')->loadByCode($request->getRecipientAddressCountryCode());
            //$xmlRequest->addChild('ToCountry', $toCountry->getName());
            $xmlRequest->addChild('ToCountryCode', $request->getRecipientAddressCountryCode());
        }
        $xmlRequest->addChild('ToPhone', $request->getRecipientContactPhoneNumber());
        $xmlRequest->addChild('ToEMail', $request->getRecipientEmail());

        $debugData = array('request' => $xmlRequest->asXML());

        try {
            $url = $usps->getConfigData('endicia_els_url') . '/GetPostageLabelXML';

            $client = new Zend_Http_Client();
            $client->setUri($url);
            $client->setConfig(array('maxredirects' => 0, 'timeout' => 30));
            $client->setParameterPost('labelRequestXML', $xmlRequest->asXML());

            $response = $client->request(Zend_Http_Client::POST);
            $xmlResponse = $response->getBody();
        } catch (Exception $e) {
            $debugData['result'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
            $xmlResponse = '';
        }

        if ($response->getStatus() != 200) {
            $debugData['result'] = array('error' => 'Invalid Request: ' . $xmlResponse);
            $xml = new SimpleXMLElement('<Error/>');
        } else {
            try {
                $xml = @new SimpleXMLElement($xmlResponse);
            } catch (Exception $e) {
                $debugData['result'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
                $xml = new SimpleXMLElement('<Error/>');
            }
        }

        if (!isset($xml->Status) || $xml->Status != 0) {
            $result->setErrors((string)$xml->ErrorMessage);
            $debugData['result'] = array('error' => $result->getErrors());
        } else {
            $debugData['result'] = $xmlResponse;

            if (isset($xml->Base64LabelImage)) {
                $labelContent = base64_decode((string)$xml->Base64LabelImage);
            } else {
                $intlDoc = new Zend_Pdf();
                foreach ($xml->Label->Image as $img) {
                    if ((string)$img['PartNumber'] == '1') {
                        $labelContent = base64_decode((string)$img);
                    } else {
                        Mage::helper('unl_ship/pdf')->attachImagePage($intlDoc, new Zend_Pdf_Resource_Image_Png('data://image/png;base64,' . (string)$img));
                    }
                }
            }

            $trackingNumber = (string)$xml->TrackingNumber;

            $result->setShippingLabelContent($labelContent);
            $result->setTrackingNumber($trackingNumber);

            $pkg = Mage::getModel('unl_ship/shipment_package')
                ->setCarrierShipmentId((string)$xml->PIC)
                ->setWeightUnits('OZ')
                ->setWeight($packageWeight)
                ->setTrackingNumber($trackingNumber)
                ->setCurrencyUnits('USD')
                ->setShippingTotal((string)$xml->PostagePrice['TotalAmount'])
                ->setTransportationCharge(0)
                ->setServiceOptionCharge((string)$xml->PostagePrice->Fees['TotalAmount'])
                ->setLabelFormat($labelFormat);

            if (isset($intlDoc) && count($intlDoc->pages)) {
                $pkg->setIntlDoc($intlDoc->render());
            }

            $result->setPackage($pkg);

            try {
                $this->requestBuyPostage(false, (float)$xml->PostageBalance);
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        $usps->debugData($debugData);

        return $result;
    }

    public function doRefundRequest($usps, $trackingNumber)
    {
        $result = new Varien_Object();
        $xmlRequest = new SimpleXMLElement('<RefundRequest/>');
        $xmlRequest->addChild('AccountID', $usps->getConfigData('endicia_account_id'));
        $xmlRequest->addChild('PassPhrase', $usps->getConfigData('endicia_passphrase'));

        if ($usps->getConfigFlag('endicia_test_mode')) {
            $xmlRequest->addChild('Test', 'Y');
        }

        $refundList = $xmlRequest->addChild('RefundList');
        $refundList->addChild('PICNumber', $trackingNumber);

        $debugData = array('request' => $xmlRequest->asXML());

        $url = $usps->getConfigData('endicia_els_int_url') . '&method=RefundRequest';

        $client = new Zend_Http_Client();
        $client->setUri($url);
        $client->setConfig(array('maxredirects' => 0, 'timeout' => 30));
        $client->setParameterPost('XMLInput', $xmlRequest->asXML());

        $response = $client->request(Zend_Http_Client::POST);
        $xmlResponse = $response->getBody();

        if ($response->getStatus() != 200) {
            $debugData['result'] = array('error' => 'Invalid Request: ' . $xmlResponse);
            $result->setErrors('Invalid Request');
        } else {
            try {
                $xml = @new SimpleXMLElement($xmlResponse);
            } catch (Exception $e) {
                $debugData['result'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
                $result->setErrors('Bad Response');
            }
        }

        if (!$result->hasErrors()) {
            if ((string)$xml->ErrorMsg != '') {
                $result->setErrors((string)$xml->ErrorMsg);
                $debugData['result'] = array('error' => $result->getErrors());
            } elseif ($xml->RefundList->PICNumber->IsApproved != 'YES') {
                $result->setErrors((string)$xml->RefundList->PICNumber->ErrorMsg);
                $debugData['result'] = array('error' => $result->getErrors());
            } else {
                $result->setMessage(Mage::helper('unl_ship')->__('Endicia refund request submitted with ID: "%s".', (string)$xml->FormNumber));
            }
        }

        $usps->debugData($debugData);

        return $result;
    }
}
