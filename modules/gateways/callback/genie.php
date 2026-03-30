<?php
/**
 * Genie Payment Gateway – Callback Handler
 * Simple Redirect Version (no webhook)
 *
 * Place at: /modules/gateways/callback/genie.php
 *
 * @author  Sameera Dananjaya Wijerathna
 * @version 1.2.0
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../genie.php';

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

// ── CSRF Token Validation ─────────────────────────────────────
$csrfToken = $_GET['csrf'] ?? $_POST['csrf'] ?? '';
if (empty($csrfToken) || !hash_equals(session_id(), $csrfToken)) {
    logTransaction($gatewayModuleName, ['error' => 'CSRF validation failed'], 'CSRF Token verification failed');
    die('Security validation failed');
}

// ── Get transactionId from GET (redirect) ─────────────────────
$transactionId = genie_validate_transaction_id(trim($_GET['transactionId'] ?? $_POST['transactionId'] ?? ''));
$amount        = !empty($_GET['amount']) ? (int)$_GET['amount'] : (!empty($_POST['amount']) ? (int)$_POST['amount'] : 0);

if (empty($transactionId)) {
    logTransaction($gatewayParams['name'], ['error' => 'Invalid transaction ID'], 'Invalid Transaction ID format');
    die('Invalid transaction ID');
}

if ($amount <= 0) {
    logTransaction($gatewayParams['name'], ['error' => 'Invalid amount'], 'Amount must be greater than zero');
    die('Invalid payment amount');
}



// ── Verify with Genie API ─────────────────────────────────────
$result = genie_capture([
    'transactionId' => $transactionId,
    'apiKey'        => $gatewayParams['apiKey'],
    'currencyCode'  => $gatewayParams['currencyCode'],
    'hash'          => $gatewayParams['localId']     ?? '',
    'paymentAmount' => $amount,
    'testMode'      => $gatewayParams['testMode']    ?? 'off',
    'systemurl'     => $gatewayParams['systemurl'],
    'prodBaseUrl'   => $gatewayParams['prodBaseUrl'] ?? 'https://api.geniebiz.lk/public',
    'uatBaseUrl'    => $gatewayParams['uatBaseUrl']  ?? 'https://api.uat.geniebiz.lk/public',
]);

$responseData  = $result['data']      ?? [];
$captureStatus = $result['status']    ?? 'error';
$message       = $result['message']   ?? '';
$returnUrl     = $result['returnUrl'] ?? $gatewayParams['systemurl'];

logTransaction($gatewayParams['name'], [
    'transactionId' => $transactionId,
    'result'        => $result,
], $captureStatus === 'success' ? 'Payment Successful' : $message);

// ── Failed ────────────────────────────────────────────────────
if ($captureStatus !== 'success') {
    // Validate redirect URL is internal
    if (!genie_is_safe_redirect_url($returnUrl, $gatewayParams['systemurl'])) {
        $returnUrl = $gatewayParams['systemurl'] . 'clientarea.php';
    }
    header('Location: ' . $returnUrl);
    exit;
}

// ── Invoice ID ────────────────────────────────────────────────
$invoiceId = $responseData['customerReference'] ?? '';

if (empty($invoiceId)) {
    logTransaction($gatewayParams['name'], $result, 'Missing Invoice Reference');
    die('Invoice reference missing');
}

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
checkCbTransID($transactionId);

// ── Amount ────────────────────────────────────────────────────
$paymentAmount = isset($responseData['amount'])
    ? number_format(((float) $responseData['amount']) / 100, 2, '.', '')
    : number_format((float) $amount / 100, 2, '.', '');

// ── Verify amount matches invoice ─────────────────────────────
$invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId], $gatewayParams['name']);
if (!empty($invoice) && isset($invoice['total'])) {
    if (abs((float)$paymentAmount - (float)$invoice['total']) > 0.01) {
        logTransaction($gatewayParams['name'], [
            'invoiceId'     => $invoiceId,
            'expectedAmount' => $invoice['total'],
            'receivedAmount' => $paymentAmount,
        ], 'Amount mismatch - possible fraud attempt');
        die('Payment amount does not match invoice total');
    }
}

// ── Apply payment ─────────────────────────────────────────────
addInvoicePayment($invoiceId, $transactionId, $paymentAmount, 0.00, $gatewayModuleName);

// Payment applied successfully - no sensitive debug logging

if (function_exists('genie_send_payment_confirmation_email')) {
    genie_send_payment_confirmation_email($invoiceId);
}

// ── Redirect ──────────────────────────────────────────────────
$successUrl = $gatewayParams['systemurl'] . 'viewinvoice.php?id=' . (int) $invoiceId . '&paymentsuccess=true';
// Validate redirect URL is internal
if (!genie_is_safe_redirect_url($successUrl, $gatewayParams['systemurl'])) {
    $successUrl = $gatewayParams['systemurl'] . 'clientarea.php';
}
header('Location: ' . $successUrl);
exit;