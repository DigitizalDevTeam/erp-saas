<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Utility;
use App\Models\Vender;
use App\Services\OperationalMessagingService;

class SalesPurchaseNotifier
{
    public static function customerInvoiceSent(Customer $customer, array $customerArr): ?array
    {
        $creatorId = (int) \Auth::user()->creatorId();
        $settings = Utility::settingsById($creatorId);
        $resp = null;

        $tmplOn = ($settings['customer_invoice_sent'] ?? '1') == '1';
        $email = $customer->operationalEmail();
        if ($tmplOn && $customer->wantsNotification('invoice', 'email') && $email) {
            $resp = Utility::sendEmailTemplate('customer_invoice_sent', [$customer->id => $email], $customerArr);
        }

        if ($tmplOn) {
            self::contactSmsWhatsapp($creatorId, $settings, $customer, 'invoice', self::invoicePlainMessage($settings, $customerArr));
        }

        $plain = self::invoicePlainMessage($settings, $customerArr);
        InternalOperationalAlerts::dispatch(
            $creatorId,
            'invoice_sent',
            __('New invoice sent') . ': ' . ($customerArr['invoice_number'] ?? ''),
            $plain
        );

        return $resp;
    }

    public static function customerInvoicePayment(Customer $customer, array $invoicePaymentArr): ?array
    {
        $creatorId = (int) \Auth::user()->creatorId();
        $settings = Utility::settingsById($creatorId);
        $resp = null;

        $tmplOn = ($settings['new_invoice_payment'] ?? '1') == '1';
        $email = $customer->operationalEmail();
        if ($tmplOn && $customer->wantsNotification('payment', 'email') && $email) {
            $resp = Utility::sendEmailTemplate('new_invoice_payment', [$customer->id => $email], $invoicePaymentArr);
        }

        $plain = trim(($settings['company_name'] ?? '') . "\n"
            . __('Invoice payment') . "\n"
            . ($invoicePaymentArr['invoice_payment_name'] ?? '') . "\n"
            . ($invoicePaymentArr['invoice_payment_amount'] ?? ''));

        if ($tmplOn) {
            self::contactSmsWhatsapp($creatorId, $settings, $customer, 'payment', $plain);
        }

        InternalOperationalAlerts::dispatch(
            $creatorId,
            'invoice_payment',
            __('New invoice payment'),
            $plain
        );

        return $resp;
    }

    public static function customerPaymentReminder(Customer $customer, array $reminderArr, array $twilioVars): ?array
    {
        $creatorId = (int) \Auth::user()->creatorId();
        $settings = Utility::settingsById($creatorId);

        $resp = null;
        $tmplOn = ($settings['new_payment_reminder'] ?? '1') == '1';
        $email = $customer->operationalEmail();
        if ($tmplOn && $customer->wantsNotification('payment', 'email') && $email) {
            $resp = Utility::sendEmailTemplate('new_payment_reminder', [$customer->id => $email], $reminderArr);
        }

        $plain = trim(($settings['company_name'] ?? '') . "\n"
            . __('Payment reminder') . "\n"
            . ($reminderArr['payment_reminder_name'] ?? '') . "\n"
            . ($reminderArr['invoice_payment_number'] ?? '') . "\n"
            . ($reminderArr['invoice_payment_dueAmount'] ?? ''));

        if ($tmplOn && ($settings['twilio_reminder_notification'] ?? null) == 1
            && $customer->wantsNotification('payment', 'sms')) {
            Utility::send_twilio_msg($customer->operationalPhone(), 'invoice_payment_reminder', $twilioVars, $creatorId);
        } elseif ($tmplOn && ($settings['operational_sms_enabled'] ?? 'off') === 'on'
            && OperationalMessagingService::twilioConfigured($settings)
            && $customer->wantsNotification('payment', 'sms')) {
            OperationalMessagingService::send($creatorId, $customer->operationalPhone(), $plain, 'sms');
        }

        if ($tmplOn && ($settings['operational_whatsapp_enabled'] ?? 'off') === 'on'
            && OperationalMessagingService::twilioConfigured($settings)
            && $customer->wantsNotification('payment', 'whatsapp')) {
            OperationalMessagingService::send($creatorId, $customer->operationalPhone(), $plain, 'whatsapp');
        }

        return $resp;
    }

    public static function vendorBillSent(Vender $vender, array $vendorArr): ?array
    {
        $creatorId = (int) \Auth::user()->creatorId();
        $settings = Utility::settingsById($creatorId);
        $resp = null;

        $tmplOn = ($settings['vender_bill_sent'] ?? '1') == '1';
        $email = $vender->operationalEmail();
        if ($tmplOn && $vender->wantsNotification('bill', 'email') && $email) {
            $resp = Utility::sendEmailTemplate('vender_bill_sent', [$vender->id => $email], $vendorArr);
        }

        $plain = trim(($settings['company_name'] ?? '') . "\n"
            . __('Purchase / bill') . "\n"
            . ($vendorArr['vender_bill_number'] ?? '') . "\n"
            . ($vendorArr['vender_bill_url'] ?? ''));

        if ($tmplOn) {
            self::contactSmsWhatsappVendor($creatorId, $settings, $vender, 'bill', $plain);
        }

        InternalOperationalAlerts::dispatch(
            $creatorId,
            'bill_sent',
            __('Vendor bill sent'),
            $plain
        );

        return $resp;
    }

    public static function vendorBillPayment(Vender $vender, array $billPaymentArr): ?array
    {
        $creatorId = (int) \Auth::user()->creatorId();
        $settings = Utility::settingsById($creatorId);
        $resp = null;

        $tmplOn = ($settings['new_bill_payment'] ?? '1') == '1';
        $email = $vender->operationalEmail();
        if ($tmplOn && $vender->wantsNotification('payment', 'email') && $email) {
            $resp = Utility::sendEmailTemplate('new_bill_payment', [$vender->id => $email], $billPaymentArr);
        }

        $plain = trim(($settings['company_name'] ?? '') . "\n"
            . __('Bill payment') . "\n"
            . ($billPaymentArr['payment_name'] ?? '') . "\n"
            . ($billPaymentArr['payment_amount'] ?? ''));

        if ($tmplOn) {
            self::contactSmsWhatsappVendor($creatorId, $settings, $vender, 'payment', $plain);
        }

        InternalOperationalAlerts::dispatch(
            $creatorId,
            'bill_payment',
            __('New bill payment'),
            $plain
        );

        return $resp;
    }

    private static function invoicePlainMessage(array $settings, array $customerArr): string
    {
        return trim(($settings['company_name'] ?? '') . "\n"
            . __('Invoice') . ': ' . ($customerArr['invoice_number'] ?? '') . "\n"
            . ($customerArr['invoice_url'] ?? ''));
    }

    private static function contactSmsWhatsapp(int $creatorId, array $settings, Customer $customer, string $category, string $plain): void
    {
        if (($settings['operational_sms_enabled'] ?? 'off') === 'on'
            && OperationalMessagingService::twilioConfigured($settings)
            && $customer->wantsNotification($category, 'sms')) {
            OperationalMessagingService::send($creatorId, $customer->operationalPhone(), $plain, 'sms');
        }

        if (($settings['operational_whatsapp_enabled'] ?? 'off') === 'on'
            && OperationalMessagingService::twilioConfigured($settings)
            && $customer->wantsNotification($category, 'whatsapp')) {
            OperationalMessagingService::send($creatorId, $customer->operationalPhone(), $plain, 'whatsapp');
        }
    }

    private static function contactSmsWhatsappVendor(int $creatorId, array $settings, Vender $vender, string $category, string $plain): void
    {
        if (($settings['operational_sms_enabled'] ?? 'off') === 'on'
            && OperationalMessagingService::twilioConfigured($settings)
            && $vender->wantsNotification($category, 'sms')) {
            OperationalMessagingService::send($creatorId, $vender->operationalPhone(), $plain, 'sms');
        }

        if (($settings['operational_whatsapp_enabled'] ?? 'off') === 'on'
            && OperationalMessagingService::twilioConfigured($settings)
            && $vender->wantsNotification($category, 'whatsapp')) {
            OperationalMessagingService::send($creatorId, $vender->operationalPhone(), $plain, 'whatsapp');
        }
    }
}
