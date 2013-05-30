<?php
/**
 * Inchoo
 *
 * Observer class to be triggered by cron daily.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Please do not edit or add to this file if you wish to upgrade
 * Magento or this extension to newer versions in the future.
 * Inchoo developers (Inchooer's) give their best to conform to
 * "non-obtrusive, best Magento practices" style of coding.
 * However, Inchoo does not guarantee functional accuracy of
 * specific extension behavior. Additionally we take no responsibility
 * for any possible issue(s) resulting from extension usage.
 * We reserve the full right not to provide any kind of support for our free extensions.
 * Thank you for your understanding.
 *
 * @category Inchoo
 * @package OrderReminder
 * @author Marko Martinović <marko.martinovic@inchoo.net>
 * @copyright Copyright (c) Inchoo (http://inchoo.net/)
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

class Inchoo_OrderReminder_Model_Observer extends Varien_Object
{
    const XML_PATH_ENABLED = 'sales_email/inchoo_orderreminder/enabled';
    const XML_PATH_NUMBER = 'sales_email/inchoo_orderreminder/number';
    const XML_PATH_INTERVAL = 'sales_email/inchoo_orderreminder/interval';
    const XML_PATH_LAST_ACTION = 'sales_email/inchoo_orderreminder/last_action';
    const XML_PATH_MOVE_GROUP = 'sales_email/inchoo_orderreminder/move_group';
    const XML_PATH_IDENTITY = 'sales_email/inchoo_orderreminder/identity';
    const XML_PATH_TEMPLATE = 'sales_email/inchoo_orderreminder/template';
    const XML_PATH_LAST_TEMPLATE = 'sales_email/inchoo_orderreminder/last_template';
    const XML_PATH_COPY_TO = 'sales_email/inchoo_orderreminder/copy_to';
    const XML_PATH_COPY_METHOD = 'sales_email/inchoo_orderreminder/copy_method';

    public function processOrderReminders()
    {
        $this->_log('Processing triggered...');

        // If not enabled
        if(!$this->_isEnabled()) {
            $this->_log('Processing disabled.');

            return;
        } else {
            $this->_log('Processing enabled, continue...');
        }

        $tz = new DateTimeZone(
            Mage::app()->getStore()->getConfig('general/locale/timezone')
        );
        $this->_log(sprintf('%s timezone detected.', $tz->getName()));

        $reminderNumber = intval($this->_getStoreConfigNumber());
        $this->_log(sprintf('%d reminders detected.', $reminderNumber));

        $reminderInterval = intval($this->_getStoreConfigInterval());
        $this->_log(sprintf('Reminder interval every %d days detected.', $reminderInterval));

        $reminderLimit = $reminderNumber*$reminderInterval;
        $this->_log(sprintf('%d days detected as reminder limit.', $reminderInterval));

        // Calculate reminder dates from config settings
        $reminderDateTimes = array();
        for ($i = $reminderInterval; $i <= $reminderLimit; $i+=$reminderInterval) {
            /* Cron job runs daily at 1 am. For -10 days we process whole -10th 
             * day (00:00:00 to 23:59:59), for -20 days we process whole -20th 
             * day (00:00:00 to 23:59:59) etc...
             */
            $reminderDateTimes[$i] = new DateTime("-$i days", $tz);
        }

        // If there arent any reminder date times
        if(empty($reminderDateTimes)) {
            $this->_log('Incorrect reminder number or interval system config, aborting!');

            return;
        }

        /*
         * Foreach reminder dates get customers
         * created at the day in question
         */
        foreach ($reminderDateTimes as $reminderKey => $reminderDateTime) {
            $this->_log(
                sprintf(
                    'Processing -%d days, %s date...',
                    $reminderKey,
                    $reminderDateTime->format('Y-m-d')
                )
            );

            $customers = Mage::getResourceModel('customer/customer_collection')
                ->addNameToSelect()
                ->addAttributeToFilter(
                    'created_at',
                    array(
                        'gteq' => sprintf(
                            '%s 00:00:00',
                            $reminderDateTime->format('Y-m-d')
                            )
                    )
                )
                ->addAttributeToFilter(
                    'created_at',
                    array(
                        'lteq' => sprintf(
                            '%s 23:59:59',
                            $reminderDateTime->format('Y-m-d')
                        )
                    )
                );

            if($customers->getSize() == 0) {
                $this->_log('No customers, skip this date.');

                continue;
            }

            foreach ($customers as $customer) {
                $orders = Mage::getResourceModel('sales/order_collection')
                        ->addFieldToFilter(
                            'customer_id',
                            array(
                                'eq' => $customer->getId()
                            )
                        );

                $this->_log(
                    sprintf(
                        'Processing %s <%s> account.',
                        $customer->getName(),
                        $customer->getEmail()
                    )
                );

                // If there are orders
                if ($orders->getSize() > 0) {
                    $this->_log('Existing orders found, skip this account.');

                    continue;
                }

                if($reminderKey == $reminderLimit) {
                    // Last email reminder template
                    $template = $this->_getStoreConfigLastTemplate();

                    $this->_log('Picked last email reminder template.');
                } else {
                    // Email reminder template
                    $template = $this->_getStoreConfigTemplate();

                    $this->_log('Picked regular email reminder template.');
                }

                // Send email
                $this->_sendOrderReminderEmail($customer, $reminderLimit, $reminderKey, $template);

                if($reminderKey == $reminderLimit)
                {
                    $lastAction = $this->_getStoreConfigLastAction();
                    switch ($lastAction) {
                        case 1:
                            // Move to customer group
                            $customerGroupName = $this->_getStoreConfigMoveGroup();
                            if(empty($customerGroupName)) {
                                $this->_log(
                                    'Customer Group name empty, could not move customer.'
                                );

                                break;
                            }

                            $this->_log(
                                sprintf(
                                    'Move to Customer Group %s detected.',
                                        $customerGroupName
                                    )
                            );

                            // Move to customer group id
                            $customerGroupId = $this->_getCustomerGroupIdByCode(
                                $customerGroupName
                            );

                            if(empty($customerGroupId)) {
                                $this->_log(
                                    'Customer Group doesn\'t exist, could not move customer.'
                                );

                                break;
                            }

                            // Move to customer group
                            $customer->setGroupId($customerGroupId)->save();

                            $this->_log(
                                'Customer successfully moved.'
                            );

                            break;
                        case 2:
                            $this->_log('Delete Account detected.');

                            // Delete customer
                            $customer->delete();

                            $this->_log('Account deleted!');
                            break;
                    }
                }
            }
        }
    }

    protected function _sendOrderReminderEmail($customer, $reminderLimit, $reminderKey, $template)
    {
        $this->_log('Preparing email...');

        // Get necessary vars
        $copyTo = $this->_getStoreConfigCopyTo();
        $copyMethod = $this->_getStoreConfigCopyMethod();
        $storeId = Mage::app()->getStore()->getId();

        // Uses code from Mage_Sales_Model_Order::sendNewOrderEmail()
        $mailer = Mage::getModel('core/email_template_mailer');
        $emailInfo = Mage::getModel('core/email_info');
        $emailInfo->addTo($customer->getEmail(), $customer->getName());
        if ($copyTo && $copyMethod == 'bcc') {
            // Add bcc to customer email
            foreach ($copyTo as $email) {
                $emailInfo->addBcc($email);

                $this->_log(sprintf('Add %s to Bcc.', $email));
            }
        }
        $mailer->addEmailInfo($emailInfo);

        // Email copies are sent as separated emails if their copy method is 'copy'
        if ($copyTo && $copyMethod == 'copy') {
            foreach ($copyTo as $email) {
                $emailInfo = Mage::getModel('core/email_info');
                $emailInfo->addTo($email);
                $mailer->addEmailInfo($emailInfo);

                $this->_log(sprintf('Will send a copy to  %s.', $email));
            }
        }

        // Set all required params and send emails
        $mailer->setSender($this->_getStoreConfigIdentity(), $storeId);
        $mailer->setStoreId($storeId);
        $mailer->setTemplateId($template);
        $mailer->setTemplateParams(
            array(
                // Customer object
                'customer' => $customer,

                // Reminder for number of days
                'reminder_days' => $reminderKey,

                // Last reminder number of days
                'reminder_limit' => $reminderLimit
            )
        );

        // Send
        $mailer->send();

        $this->_log('Email sent.');
    }

    protected function  _getCustomerGroupIdByCode($code)
    {
        return Mage::getModel('customer/group')
                         ->getCollection()
                         ->addFieldToFilter('customer_group_code', $code)
                         ->getFirstItem()
                         ->getId();
    }

    protected function _log ($message)
    {
        Mage::log(sprintf('Inchoo_OrderReminder - %s', $message));
    }

    protected function _isEnabled()
    {
        return $this->_getStoreConfig(self::XML_PATH_ENABLED);
    }

    protected function _getStoreConfigNumber()
    {
        return $this->_getStoreConfig(self::XML_PATH_NUMBER);
    }

    protected function _getStoreConfigInterval()
    {
        return $this->_getStoreConfig(self::XML_PATH_INTERVAL);
    }

    protected function _getStoreConfigLastAction()
    {
        return $this->_getStoreConfig(self::XML_PATH_LAST_ACTION);
    }

    protected function _getStoreConfigMoveGroup()
    {
        return $this->_getStoreConfig(self::XML_PATH_MOVE_GROUP);
    }

    protected function _getStoreConfigIdentity()
    {
        return $this->_getStoreConfig(self::XML_PATH_IDENTITY);
    }

    protected function _getStoreConfigTemplate()
    {
        return $this->_getStoreConfig(self::XML_PATH_TEMPLATE);
    }

    protected function _getStoreConfigLastTemplate()
    {
        return $this->_getStoreConfig(self::XML_PATH_LAST_TEMPLATE);
    }

    protected function _getStoreConfigCopyTo()
    {
        $data = $this->_getStoreConfig(self::XML_PATH_COPY_TO);
        if (!empty($data)) {
            return array_map('trim', explode(',', $data));
        }

        return false;
    }

    protected function _getStoreConfigCopyMethod()
    {
        return $this->_getStoreConfig(self::XML_PATH_COPY_METHOD);
    }

    protected function _getStoreConfig($xmlPath)
    {
        return Mage::getStoreConfig($xmlPath, Mage::app()->getStore()->getId());
    }
}