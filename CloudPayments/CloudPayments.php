<?php

require_once('api/Simpla.php');

class CloudPayments extends Simpla {

    public function checkout_form($order_id, $button_text = null) {
        if (empty($button_text)) {
            $button_text = 'Оплатить';
        }

        $order            = $this->orders->get_order((int)$order_id);
        $payment_method   = $this->payment->get_payment_method($order->payment_method_id);
        $payment_settings = $this->payment->get_payment_settings($payment_method->id);
        $payment_currency = $this->money->get_currency(intval($payment_method->currency_id));

        $order_description = 'Оплата заказа №' . $order->id;

        $amount      = floatval($this->money->convert($order->total_price, $payment_method->currency_id, false));
        $success_url = $this->config->root_url . '/order/' . $order->url;
        $fail_url    = $this->config->root_url . '/order/' . $order->url;

        $params = array(
            'publicId'    => $payment_settings['public_id'],  //id из личного кабинета
            'description' => $order_description, //назначение
            'amount'      => $amount, //сумма
            'currency'    => $payment_currency->code, //валюта
            'invoiceId'   => $order->id, //номер заказа  (необязательно)
            'accountId'   => $order->email, //идентификатор плательщика (необязательно)
            'data'        => array(
                'name'          => $order->name,
                'phone'         => $order->phone,
                'cloudPayments' => array(),
            )
        );
        if (intval($payment_settings['enable_kkt'])) {
            $params['data']['cloudPayments']['customerReceipt'] = $this->get_receipt($order, $payment_settings, $payment_method->currency_id);
        }

        $lang   = $payment_settings['language'];
        $params = json_encode($params);

        $button = "<script src=\"https://widget.cloudpayments.ru/bundles/cloudpayments\"></script>" . PHP_EOL;
        $button .= "<form id='simpla_cloudpayments_form' method='post'>";
        $button .= "<input type='submit' value='" . $button_text . "'>";
        $button .= "</form>" . PHP_EOL;
        $button .= <<<SCRIPT
        <script>
            (function(show_widget_callback) {
                var form = document.getElementById('simpla_cloudpayments_form');
                if (form.addEventListener) {
                    form.addEventListener('click', show_widget_callback, false);
                } else {
                    form.attachEvent('onclick', show_widget_callback);
                }
            })(function(e) {
                var evt = e || window.event; // Совместимость с IE8
                if (evt.preventDefault) {  
                    evt.preventDefault();  
                } else {  
                    evt.returnValue = false;  
                    evt.cancelBubble = true;  
                }
                var widget = new cp.CloudPayments({language: '{$lang}'});
                widget.charge({$params}, '{$success_url}', '{$fail_url}');
            });
        </script>
SCRIPT;

        return $button;
    }

    private function get_receipt($order, $payment_settings, $currency_id) {
        $tax_system   = $payment_settings['taxation_system'];
        $receipt_data = array(
            'Items'          => array(),
            'taxationSystem' => $tax_system,
            'email'          => $order->email,
            'phone'          => $order->phone
        );

        $purchases = $this->orders->get_purchases(array('order_id' => intval($order->id)));
        $vat       = $payment_settings['vat'];
        if ($vat == 'none') {
            $vat = '';
        }
        foreach ($purchases as $purchase) {
            $price                   = $this->money->convert($purchase->price, $currency_id, false);
            $price                   = number_format($price, 2, '.', '');
            $receipt_data['Items'][] = array(
                'label'    => trim($purchase->product_name . ' ' . $purchase->variant_name),
                'price'    => floatval($price),
                'quantity' => floatval($purchase->amount),
                'amount'   => floatval($price * $purchase->amount),
                'vat'      => $vat
            );
        }
        if ($order->delivery_price && !$order->separate_delivery) {
            $delivery_method = $this->delivery->get_delivery($order->delivery_id);
            $price           = $this->money->convert($order->delivery_price, $currency_id, false);
            $price           = number_format($price, 2, '.', '');
            $vat_delivery    = $payment_settings['vat_delivery'];
            if ($vat_delivery == 'none') {
                $vat_delivery = '';
            }
            $receipt_data['Items'][] = array(
                'label'    => $delivery_method->name,
                'price'    => floatval($price),
                'quantity' => 1,
                'amount'   => floatval($price),
                'vat'      => $vat_delivery
            );
        }

        return $receipt_data;
    }
}