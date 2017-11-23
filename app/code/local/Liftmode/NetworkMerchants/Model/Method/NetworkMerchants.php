<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_NetworkMerchants
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors.
 */

class Liftmode_NetworkMerchants_Model_Method_NetworkMerchants extends Mage_Payment_Model_Method_Cc
{
    protected $_code = 'networkmerchants';
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = false;
    protected $_canRefund = true;
    protected $_canVoid = true;
    protected $_canUseCheckout = true;
    protected $_canSaveCC = false;
    protected $_canUseInternal = true;
    protected $_canFetchTransactionInfo = true;

    private $_gatewayURL = 'https://secure.nmi.com/api/transact.php';

    const REQUEST_TYPE_AUTH_CAPTURE = 'AUTH_CAPTURE';
    const REQUEST_TYPE_AUTH_ONLY    = 'AUTH_ONLY';
    const REQUEST_TYPE_CAPTURE_ONLY = 'CAPTURE_ONLY';

    const RESPONSE_CODE_APPROVED    = 1;
    const RESPONSE_CODE_DECLINED    = 2;
    const RESPONSE_CODE_ERROR       = 3;
    const RESPONSE_CODE_HELD        = 4;

    const APPROVED                  = 1;
    const DECLINED                  = 2;
    const ERROR                     = 3;


    /**
     * Send authorize request to gateway
     *
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid amount for authorization.'));
        }

        $payment->setAmount($amount);
        $payment->setAnetTransType(self::REQUEST_TYPE_AUTH_ONLY);

        //
        if(!$payment->getCcNumber()){
            Mage::throwException(Mage::helper($this->_code)->__('Wrong Credit Card Number'));
        }

        $query = $this->_buildRequest($payment);
        $r = $this->doSale($query);

        $this->log(array(
            'Authorize Response =>',
            $this->_sanitizeData($query),
            $r
        ));


        if (empty($r) === false && empty($r['response']) === false && $r['response'] == self::RESPONSE_CODE_APPROVED) {
            $payment->setTransactionId($r['transactionid'])
                ->setCcApproval($r['authcode'])
                ->setCcTransId($r['transactionid'])
                ->setIsTransactionClosed(1)
                ->setParentTransactionId(null)
                ->setCcAvsStatus($r['avsresponse'])
                ->setCcCidStatus($r['cvvresponse'])
                ->setStatus(self::STATUS_APPROVED);
        } else {
            if ($r['responsetext']) {
                $r['responsetext'] = $this->_mapResponseText(trim($r['responsetext']));
            } else {
                $r['responsetext'] = 'Unknown error';
            }

            if (Mage::getStoreConfig('slack/general/enable_notification')) {
                $notificationModel   = Mage::getSingleton('mhauri_slack/notification');
                $notificationModel->setMessage(
                    Mage::helper($this->_code)->__("*NetworkMerchants payment failed with data:*\nNetworkmerchants response ```%s```\n\nData sent ```%s```", json_encode($r), $this->_sanitizeData(json_encode($query)))
                )->send(array('icon_emoji' => ':cop::skin-tone-6:'));
            }

            Mage::throwException(Mage::helper($this->_code)->__("Error during payment processing: response code: %s %s\nThis credit card processor cannot accept your card; please select a different payment method.", $r['response'], $r['responsetext'] . "\r\n" ));
        }

        return $this;
    }

    protected function _mapResponseText($responsetext)
    {
        switch ($responsetext) {
            case 'Insufficient funds':
                return Mage::helper($this->_code)->__('Your bank declined this attempted transaction, because you try to make an order using a debit card without having enough money in your account or you might reach credit card limit.');
            case 'AVS REJECTED':
                return Mage::helper($this->_code)->__('Your bank declined this attempted transaction. Please check billing zip code/address you entered, it should match with the address/zip code you supplied to your bank.');
            case 'Issuer Declined':
                return Mage::helper($this->_code)->__('Your bank declined this attempted transaction. Please check that all information was entered correctly. If so, then please call the phone number on the back of your card for more information or select a different payment method.');
            default:
                return $responsetext;
        }
    }

    protected function _buildRequest(Varien_Object $payment)
    {
        $order = $payment->getOrder();
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $testMode = $this->getConfigData('test_mode');


        $billingRegion = strval($billing->getRegionCode());
        if (!(empty($billingRegion) === false && preg_match("/^[A-Za-z]{2,7}$/i", $billingRegion))) {
            $billingRegion = 'MN';
        }

        $shippingRegion = strval($shipping->getRegionCode());
        if (!(empty($shippingRegion) === false && preg_match("/^[A-Za-z]{2,7}$/i", $shippingRegion))) {
            $shippingRegion = 'MN';
        }

        return array (
            // Login Information
            'username'      => ($testMode) ? 'demo' : $this->getConfigData('username'),
            'password'      => ($testMode) ? 'password' : $this->getConfigData('password'),

            // Sales Information
            'ccnumber'           => $payment->getCcNumber(),
            'ccexp'              => sprintf('%02d%02d', $payment->getCcExpMonth(), substr($payment->getCcExpYear(), -2)),
            'amount'             => number_format($payment->getAmount(), 2, ".", ""),
            'cvv'                => $payment->getCcCid(),

            // Order Information
            'ipaddress'          => trim(strval($order->getRemoteIp())),
            'orderid'            => $order->getIncrementId(),
            'orderdescription'   => 'Order ' . $order->getIncrementId() . ' at ' . Mage::app()->getStore()->getFrontendName() . '. Thank you.',
            'tax'                => number_format(sprintf('%.2F', $order->getBaseTaxAmount()), 2, ".",""),
            'shipping'           => number_format(sprintf('%.2F', $order->getBaseShippingAmount()), 2, ".",""),
            'ponumber'           => trim(strval($payment->getPoNumber())),

            // Billing Information
            'firstname'          => trim(strval($billing->getFirstname())),
            'lastname'           => trim(strval($billing->getLastname())),
            'company'            => trim(strval($billing->getCompany())),
            'address1'           => trim(strval($billing->getStreet(1))),
            'address2'           => (trim(strval($billing->getStreet(1))) === trim(strval($billing->getStreet(2)))) ? '' : trim(strval($billing->getStreet(2))),
            'city'               => trim(strval($billing->getCity())),
            'state'              => $billingRegion,
            'zip'                => trim(strval($billing->getPostcode())),
            'country'            => trim(strval($billing->getCountry())),
            'phone'              => trim(strval($billing->getTelephone())),
            'fax'                => trim(strval($billing->getFax())),
            'email'              => trim(strval($order->getCustomerEmail())),
            'website'            => "",

            // Shipping Information
            'shipping_firstname' => trim(strval($shipping->getFirstname())),
            'shipping_lastname'  => trim(strval($shipping->getLastname())),
            'shipping_company'   => trim(strval($shipping->getCompany())),
            'shipping_address1'  => trim(strval($shipping->getStreet(1))),
            'shipping_address2'  => (trim(strval($shipping->getStreet(1))) === trim(strval($shipping->getStreet(2)))) ? : trim(strval($shipping->getStreet(2))),
            'shipping_city'      => trim(strval($shipping->getCity())),
            'shipping_state'     => $shippingRegion,
            'shipping_zip'       => trim(strval($shipping->getPostcode())),
            'shipping_country'   => trim(strval($shipping->getCountry())),
            'shipping_email'     => trim(strval($order->getCustomerEmail())),
            'type'               => 'sale'
        );
    }


    public function doSale(array $query) {
        return $this->_doPost($query);
    }

    public function _doPost(array $query) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->_gatewayURL);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 40);
        curl_setopt($ch, CURLOPT_TIMEOUT, 80);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($query, null, '&', PHP_QUERY_RFC3986));
        curl_setopt($ch, CURLOPT_POST, 1);


        $data = curl_exec($ch);
        $errCode = curl_errno($ch);
        $errMessage = curl_error($ch);

        curl_close($ch);

        if ($errCode || $errMessage) {
            $this->log(array('_doPost=>', $this->_gatewayURL, $query, $errCode, $errMessage, $r));

            if (Mage::getStoreConfig('slack/general/enable_notification')) {
                $notificationModel   = Mage::getSingleton('mhauri_slack/notification');
                $notificationModel->setMessage(
                    Mage::helper($this->_code)->__("*NetworkMerchants payment failed with data:*\nNetworkmerchants response ```%s```\n\nData sent ```%s```", json_encode(array('code' => $errCode, 'message' => $errMessage)), $this->_sanitizeData(json_encode($query)))
                )->send(array('icon_emoji' => ':cop::skin-tone-6:'));
            }

            Mage::throwException(Mage::helper($this->_code)->__("Error during payment processing: response code: %s %s. This credit card processor cannot accept your card; please select a different payment method.", $httpCode, $errMessage . "\r\n"));
        }

        $responses = array();
        parse_str($data, $responses);

        return $responses;
    }

    /**
     * Refund the amount
     *
     * @param Varien_Object $payment
     * @param decimal $amount
     * @return GatewayProcessingServices_ThreeStep_Model_PaymentMethod
     * @throws Mage_Core_Exception
     */
    public function refund(Varien_Object $payment, $amount) {
        if ($payment->getRefundTransactionId() && $amount > 0) {
            $order = $payment->getOrder();

            $testMode = $this->getConfigData('test_mode');

            $query  = array (
                // Login Information
                'username'      => ($testMode) ? 'demo' : $this->getConfigData('username'),
                'password'      => ($testMode) ? 'password' : $this->getConfigData('password'),

                // Transaction Information
                'transactionid' => $payment->getParentTransactionId(),
                'amount'        => number_format($amount, 2,".",""),
                'type'          => 'refund'
            );

            $result = $this->_doPost($query);

            if (isset($result['response']) && ($result['response'] == 1)) {
                $payment->setStatus(self::STATUS_SUCCESS );
                 return $this;
            } else {
                $payment->setStatus(self::STATUS_ERROR);
                Mage::throwException('Refund Failed: Invalid transaction ID');
            }
        }

        $payment->setStatus(self::STATUS_ERROR);
        Mage::throwException('Refund Failed: Invalid transaction ID');
    }

    /**
     * Void the payment through gateway
     *
     * @param Varien_Object $payment
     * @return GatewayProcessingServices_ThreeStep_Model_PaymentMethod
     * @throws Mage_Core_Exception
     */
    public function void(Varien_Object $payment) {

        if ($payment->getParentTransactionId()) {
            $order = $payment->getOrder();
            $testMode = $this->getConfigData('test_mode');

            $query  = array (
                // Login Information
                'username'      => ($testMode) ? 'demo' : $this->getConfigData('username'),
                'password'      => ($testMode) ? 'password' : $this->getConfigData('password'),

                // Transaction Information
                'transactionid' => $payment->getParentTransactionId(),
                'amount'        => number_format($amount, 2, ".",""),
                'type'          => 'void'
            );

            $result = $this->_doPost($query);

            if (isset($result['response']) && ($result['response'] == 1)) {
                $payment->setStatus(self::STATUS_SUCCESS );
                 return $this;
            } else {
                $payment->setStatus(self::STATUS_ERROR);
                Mage::throwException('Void Failed: Invalid transaction ID.');
            }
        }

        $payment->setStatus(self::STATUS_ERROR);
        Mage::throwException('Void Failed: Invalid transaction ID.');
    }

    public function cancel(Varien_Object $payment)
    {
        return $this->void($payment);
    }

    private function _sanitizeData($data) {
        if (is_string($data)) {
            return  preg_replace(
                        '/"password":\s*"([^"]*)"/i',
                        '"password":"***"',
                        preg_replace(
                            '/"ccnumber":\s*"[^"]*([^"]{4})"/i',
                            '"ccnumber":"***$1"',
                            preg_replace(
                                '/"cvv":\s*"([^"]*)"/i',
                                '"cvv":"***"',
                                $data
                            )
                        )
                    );
        }

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    return $this->_sanitizeData($v);
                } else {
                    if ($k === 'ccnumber' || $k === 'password') {
                        $data[$k] = "***" . substr($data[$k], -4);
                    }

                    if ($k === 'cvv' || $k === 'username') {
                        $data[$k] = "***";
                    }
                }
            }
        }

        return $data;
    }

    public function log($data)
    {
        Mage::log($data, null, 'NetworkMerchants.log');
    }
}
