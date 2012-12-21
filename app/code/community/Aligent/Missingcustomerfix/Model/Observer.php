<?php
/**
 * Sales observer
 *
 * @category   Mage
 * @package    Mage_Sales
 * @author     Shaun O'Reilly <shaun@aligent.com.au>
 * Purpose of overriding this functionality, is to ensure recovering lost cutomer data 
 * when a quote gets converted to an order, but something goes wrong,
 * and we find the order without the customer. This is a work around, untill we can figure out what causes the customers
 * to dissapear. When we keep the quote data longer, then we can restore most of the information, when we check for this 
 * problem on the success page.
 */
class Aligent_Missingcustomerfix_Model_Observer extends Mage_Sales_Model_Observer
{
    
    /**
     * @var Mage_Sales_Model_Quote
     */
    protected $_quote = null;
    
    /**
     * @var Mage_Customer_Model_Session
     */
    protected $_customerSession;    
    
    /**
     * Get assigned quote object
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        return $this->_quote;
    }
    
    /**
     * Get customer session object
     *
     * @return Mage_Customer_Model_Session
     */
    public function getCustomerSession()
    {
        return $this->_customerSession;
    }       

    /**
     * Get the session from onepage checkout, and then find the last order id.
     * Check to see if it has a valid customer object attached to the order,
     * and if not, try to recreate the customer data
     *
     * @param array $data
     * @return false|Mage_Customer_Model_Customer
     */
    public function CheckMissingClientDetails(Varien_Event_Observer $observer)
    {
        
        $oOnePageCheckout = Mage::getSingleton('checkout/type_onepage');
        $session = $oOnePageCheckout->getCheckout();
        $lastQuoteId = $session->getLastQuoteId();
        $lastOrderId = $session->getLastOrderId();
        $this->_customerSession = Mage::getSingleton('customer/session');
        if (!$lastQuoteId && !$lastOrderId) {
            return;
        }
        
        try {                   
            
            $oQuote = Mage::getModel('sales/quote')->load($lastQuoteId);
            $this->_quote = $oQuote;
            
            // Get the checkout method from database directly, otherwise you might get the method's getCheckoutMethod() value
            $vCheckoutMethod = $this->getQuote()->getData('checkout_method');
            
            if ($vCheckoutMethod == "register") {                    
                    
                $oOrder = Mage::getModel('sales/order')->load($lastOrderId);
                
                $oQuoteCustomer = Mage::getModel('customer/customer')->load($this->getQuote()->getCustomerId());
                $oOrderCustomer = Mage::getModel('customer/customer')->load($oOrder->getCustomerId());
                
                // check if the Order is accociated with a valid customer record, if not, assign the correct customer
                if(!$oOrderCustomer->getEntityId()){                
                    // Check if the Quote is accociated with a valid customer record, and use it, otherwise creat a new customer
                    if($oQuoteCustomer->getEntityId()){
                        $oOrder->setCustomerId($this->getQuote()->getCustomerId());
                        $oOrder->save();
                    }
                    else {
                        $isNewCustomer = true;
                        $iSendResetEmail = false;
                        if ($this->getCustomerSession()->getCustomer()->getEntityId()) {
                            $oCustomer = $this->getCustomerSession()->getCustomer();
                            $aCustomerData = array('firstname' => $oCustomer->getFirstname(),
                              'lastname' => $oCustomer->getLastname(),                          
                              'email' => $oCustomer->getEmail(),
                              'password_hash' => $oCustomer->getPasswordHash(),
                            );
                        }
                        else {
                            $aCustomerData = array('firstname' => $this->getQuote()->getCustomerFirstname(),
                              'lastname' => $this->getQuote()->getCustomerLastname(),                          
                              'email' => $this->getQuote()->getCustomerEmail(),
                              'password_hash' => $this->getQuote()->getPasswordHash(),
                            );
                            if (!$this->getQuote()->getPasswordHash()){
                                $iSendResetEmail = true;
                            }
                        }
                        
                        $oCustomer = $this->RebuildCustomerData($aCustomerData);
                        
                        $oOrder->setCustomerId($oCustomer->getId());
                        $oOrder->save();
                        
                        // Send new account created email, and log the customer in.
                        $oCustomer->sendNewAccountEmail();
                        $this->getCustomerSession()->loginById($oCustomer->getId());
                        
                        // Send reset email if we could not get the password from the session or the quote
                        // this will not be executed most of the time, but is there just in case.
                        if ($iSendResetEmail) {
                            $oCustomer->sendPasswordReminderEmail();
                        }
                    }
                }

            }
        
        }
        Catch (Exception $e){
            Mage::logException($e);
            return;
        }      
 
    }       
    
    /**
     * Validate customer data and set some its data for further usage in quote
     * Will return either the customer model object
     * Code originally came from Mage_Checkout_Model_Type_Onepage::_validateCustomerData, 
     * but I added some more functionality to it from
     * Mage_Checkout_Model_Type_Onepage::_prepareNewCustomerQuote()
     * 
     * The reason for using $customerForm and copyFieldset methods, is because they
     * place all the relevant data in the right places
     *
     * @param array $data
     * @return false|Mage_Customer_Model_Customer
     */
    protected function RebuildCustomerData(array $data)
    {

        try {
            /* @var $customerForm Mage_Customer_Model_Form */
            $customerForm = Mage::getModel('customer/form');
            $customerForm->setFormCode('checkout_register');
            $customerRequest = $customerForm->prepareRequest($data);

            /* @var $customer Mage_Customer_Model_Customer */
            $customer = Mage::getModel('customer/customer');
            $customerForm->setEntity($customer);
            $customerData = $customerForm->extractData($customerRequest);
            $customerForm->compactData($customerData);

            if (strlen($customerRequest->getParam('password_hash'))>0)  {
                // set customer password
                $customer->setPasswordHash($customerRequest->getParam('password_hash'));
            } else {
                // emulate customer password for quest
                $password = $customer->generatePassword();
                $customer->changePassword($password, false);
                //$customer->setPasswordHash($customer->hashPassword($password));
            }

            $customer->save();    

            $this->getQuote()->assignCustomer($customer);
            $this->getQuote()->setCustomer($customer);

            // assign Shipping and Billing addresses to the customer
            $billing    = $this->getQuote()->getBillingAddress();
            $shipping   = $this->getQuote()->isVirtual() ? null : $this->getQuote()->getShippingAddress();

            //$customer = $this->getQuote()->getCustomer();
            $customerBilling = $billing->exportCustomerAddress();
            $customer->addAddress($customerBilling);
            $billing->setCustomerAddress($customerBilling);
            $customerBilling->setIsDefaultBilling(true);
            if ($shipping && !$shipping->getSameAsBilling()) {
                $customerShipping = $shipping->exportCustomerAddress();
                $customer->addAddress($customerShipping);
                $shipping->setCustomerAddress($customerShipping);
                $customerShipping->setIsDefaultShipping(true);
            } else {
                $customerBilling->setIsDefaultShipping(true);
            }

            Mage::helper('core')->copyFieldset('checkout_onepage_quote', 'to_customer', $this->getQuote(), $customer);       
            $customer->save();

            return $customer;
        
        } catch (Exception $e) {
            Mage::logException($e);
            return $customer;
        }
    }
}

