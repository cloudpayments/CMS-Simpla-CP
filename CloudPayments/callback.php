<?php
// Работаем в корневой директории
chdir('../../');
require_once('api/Simpla.php');
$simpla = new Simpla();

const CLOUDPAYMENTS_RESULT_SUCCESS             = 0;
const CLOUDPAYMENTS_RESULT_ERROR_INVALID_ORDER = 10;
const CLOUDPAYMENTS_RESULT_ERROR_INVALID_COST  = 11;
const CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED  = 13;
const CLOUDPAYMENTS_RESULT_ERROR_EXPIRED       = 20;

//Проверяем наличие обязательных параметров
$action = isset($_GET['s_action']) ? strtolower($_GET['s_action']) : '';
if (!in_array($action, array('check', 'pay', 'fail', 'refund', 'confirm', 'cancel'))) {
    exit_with_error(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED);
}
if (empty($_POST['InvoiceId']) || empty($_POST['Amount'])) {
    exit_with_error(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED);
}

// Выберем заказ из базы
$order = $simpla->orders->get_order(intval($_POST['InvoiceId']));
if (empty($order)) {
    exit_with_error(CLOUDPAYMENTS_RESULT_ERROR_INVALID_ORDER);
}

// Получаем метод оплаты
$payment_method = $simpla->payment->get_payment_method($order->payment_method_id);
if (empty($payment_method)) {
    exit_with_error(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED);
}
$payment_settings = unserialize($payment_method->settings);
$payment_currency = $simpla->money->get_currency(intval($payment_method->currency_id));

// Проверяем контрольную подпись
$post_data    = file_get_contents('php://input');
$check_sign   = base64_encode(hash_hmac('SHA256', $post_data, $payment_settings['secret_key'], true));
$request_sign = isset($_SERVER['HTTP_CONTENT_HMAC']) ? $_SERVER['HTTP_CONTENT_HMAC'] : '';

if ($check_sign !== $request_sign) {
    exit_with_error(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED);
};

// Запросы связанные с оплатой
$is_payment_callback = in_array($action, array('check', 'pay', 'confirm'));

// Нельзя оплатить уже оплаченный заказ
if ($is_payment_callback && $order->paid) {
    exit_with_error(CLOUDPAYMENTS_RESULT_ERROR_NOT_ACCEPTED);
}

// Проверяем сумму заказа
$amount = floatval($simpla->money->convert($order->total_price, $payment_method->currency_id, false));

if ($_POST['Amount'] != $amount || $_POST['Amount'] <= 0) {
    exit_with_error(CLOUDPAYMENTS_RESULT_ERROR_INVALID_COST);
}

if ($is_payment_callback) {
    // Проверяем наличие товара
    $purchases = $simpla->orders->get_purchases(array('order_id' => intval($order->id)));
    foreach ($purchases as $purchase) {
        $variant = $simpla->variants->get_variant(intval($purchase->variant_id));
        if (empty($variant) || (!$variant->infinity && $variant->stock < $purchase->amount)) {
            exit_with_error(CLOUDPAYMENTS_RESULT_ERROR_INVALID_COST);
        }
    }
}

if (($action == 'pay' && $_POST['Status'] == 'Completed') || $action == 'confirm') {
    // Установим статус оплачен
    $simpla->orders->update_order(intval($order->id), array('paid' => 1));

    // Отправим уведомление на email
    $simpla->notify->email_order_user(intval($order->id));
    $simpla->notify->email_order_admin(intval($order->id));

    // Спишем товары
    $simpla->orders->close(intval($order->id));
} else if ($action == 'refund' || $action == 'cancel') {
    $note = $order->note;
    if (!empty($note)) {
        $note .= "\n";
    }
    if ($action == 'refund') {
    $note .= 'Совершён полный возврат денежных средств.';
    }
    else $note .= 'Платеж отменен.';
    // Установим статус не оплачен и запишем заметку
    $simpla->orders->update_order(intval($order->id), array('paid' => 0, 'note' => $note));

    // Отправим уведомление на email
    $simpla->notify->email_order_user(intval($order->id));
    $simpla->notify->email_order_admin(intval($order->id));
}

print_callback_response(CLOUDPAYMENTS_RESULT_SUCCESS);

function print_callback_response($code) {
    header('Content-Type: application/json');
    echo json_encode(array('code' => $code));
}

function exit_with_error($code) {
    print_callback_response($code);
    die();
}