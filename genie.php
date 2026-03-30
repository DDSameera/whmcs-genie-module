<?php
/**
 * Genie Payment Gateway for WHMCS
 * Simple Redirect Version
 *
 * @author    SecureWebByUs
 * @version   1.4.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// ─────────────────────────────────────────────────────────────
// LOGGING — WHMCS only (no debug log files)
// ─────────────────────────────────────────────────────────────
function genie_log($action, $request, $response, $description, $apiKey = '')
{
    // Mask sensitive data before logging
    $requestStr  = is_array($request)  ? json_encode($request,  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $request;
    $responseStr = is_array($response) ? json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $response;
    
    // Remove API key from logs
    $maskedApiKey = !empty($apiKey) ? substr($apiKey, 0, 4) . '****' : '****';

    logModuleCall('genie', $action, $requestStr, $responseStr, $description, [$maskedApiKey]);

    logTransaction('genie', [
        'action'      => $action,
        'description' => $description,
        'request'     => $requestStr,
        'response'    => $responseStr,
    ], $description);
}

// ─────────────────────────────────────────────────────────────
// SECURITY VALIDATION
// ─────────────────────────────────────────────────────────────
function genie_validate_transaction_id($transactionId)
{
    // Only allow alphanumeric, dash, underscore
    return preg_match('/^[a-zA-Z0-9_-]+$/', $transactionId) ? $transactionId : '';
}

function genie_is_safe_redirect_url($url, $systmUrl)
{
    // Ensure redirect URL is internal to WHMCS
    if (empty($url) || !is_string($url)) {
        return false;
    }
    // Must start with system URL (same domain)
    return strpos($url, $systmUrl) === 0;
}

// ─────────────────────────────────────────────────────────────
// 1. MODULE META
// ─────────────────────────────────────────────────────────────
function genie_MetaData()
{
    return [
        'DisplayName'                => 'Genie Payment Gateway',
        'APIVersion'                 => '1.1',
        'DisableLocalCreditCardInput'=> true,
        'TokenisedStorage'           => false,
    ];
}

// ─────────────────────────────────────────────────────────────
// 2. CONFIG
// ─────────────────────────────────────────────────────────────
function genie_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Genie Payment Gateway',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key (Authorization)',
            'Type'         => 'password',
            'Size'         => 60,
            'Default'      => '',
            'Description'  => 'Your Genie API Authorization key.',
        ],
        'localId' => [
            'FriendlyName' => 'Merchant / Hash ID',
            'Type'         => 'text',
            'Size'         => 60,
            'Default'      => '',
            'Description'  => 'Merchant identifier used for hash verification.',
        ],
        'currencyCode' => [
            'FriendlyName' => 'Currency Code',
            'Type'         => 'text',
            'Size'         => 10,
            'Default'      => 'LKR',
            'Description'  => 'Currency for transactions (e.g. LKR).',
        ],
        'testMode' => [
            'FriendlyName' => 'Test / Sandbox Mode',
            'Type'         => 'yesno',
            'Description'  => 'Tick to use the UAT (sandbox) endpoint.',
        ],
        'prodBaseUrl' => [
            'FriendlyName' => '[Production] Base URL',
            'Type'         => 'text',
            'Size'         => 100,
            'Default'      => 'https://api.geniebiz.lk/public',
            'Description'  => 'Live base URL. Do not add a trailing slash.',
        ],
        'uatBaseUrl' => [
            'FriendlyName' => '[UAT/Sandbox] Base URL',
            'Type'         => 'text',
            'Size'         => 100,
            'Default'      => 'https://api.uat.geniebiz.lk/public',
            'Description'  => 'Sandbox base URL. Do not add a trailing slash.',
        ],
        'termsUrl' => [
            'FriendlyName' => 'Terms & Conditions URL',
            'Type'         => 'text',
            'Size'         => 100,
            'Default'      => '',
            'Description'  => 'Your Terms & Conditions page URL.',
        ],
    ];
}

// ─────────────────────────────────────────────────────────────
// 3. URL HELPER
// ─────────────────────────────────────────────────────────────
function genie_get_urls($params)
{
    $isTest  = ($params['testMode'] === 'on');
    $baseUrl = rtrim($isTest ? $params['uatBaseUrl'] : $params['prodBaseUrl'], '/');

    return [
        'transactionUrl' => $baseUrl . '/v2/transactions',
        'refundUrl'      => $baseUrl . '/transactions',
    ];
}

// ─────────────────────────────────────────────────────────────
// 4. PAYMENT LINK  (redirect only — no QR)
// ─────────────────────────────────────────────────────────────
function genie_link($params)
{
    $apiKey       = $params['apiKey'];
    $currencyCode = $params['currencyCode'] ?: 'LKR';
    $invoiceId    = $params['invoiceid'];
    $amount       = (int) round((float) $params['amount'] * 100);
    $systemUrl    = $params['systemurl'];
    $termsUrl     = !empty($params['termsUrl'])
                    ? $params['termsUrl']
                    : $systemUrl . 'terms';

    // ── Client details ─────────────────────────────────────
    $client   = $params['clientdetails'] ?? [];
    $fullName = trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? ''));
    $email    = $client['email']       ?? '';
    $address1 = $client['address1']    ?? '';
    $address2 = $client['address2']    ?? '';
    $city     = $client['city']        ?? '';
    $country  = $client['countrycode'] ?? 'LK';
    $postcode = $client['postcode']    ?? '00000';

    // ── URLs ───────────────────────────────────────────────
    $urls           = genie_get_urls($params);
    $transactionUrl = $urls['transactionUrl'];
    // Add CSRF token to callback URL for security
    $csrfToken      = session_id();
    $callbackUrl    = $systemUrl . 'modules/gateways/callback/genie.php?csrf=' . urlencode($csrfToken);

    // ── Build payload ──────────────────────────────────────
    $payloadArray = [
        'amount'            => $amount,
        'currency'          => $currencyCode,
        'customerReference' => (string) $invoiceId,
        'redirectUrl'       => $callbackUrl,
        'paymentPortalExperience' => [
            'externalWebsiteTermsAccepted' => true,
            'externalWebsiteTermsUrl'      => $termsUrl,
            'hideTermsAndConditions'       => true,
        ],
    ];

    if (!empty($email) && !empty($address1) && !empty($city)) {
        $payloadArray['customer'] = [
            'name'            => $fullName ?: 'Customer',
            'email'           => $email,
            'billingEmail'    => $email,
            'billingAddress1' => $address1,
            'billingAddress2' => $address2,
            'billingCity'     => $city,
            'billingCountry'  => $country,
            'billingPostCode' => $postcode ?: '00000',
        ];
    }

    $payload = json_encode($payloadArray);

    // ── API call ───────────────────────────────────────────
    $ch = curl_init($transactionUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $responseData = json_decode($response, true) ?: [];

    // ── Error handling ─────────────────────────────────────
    if ($curlErr || !in_array($httpCode, [200, 201], true)) {
        genie_log(
            'CreateTransaction',
            $payloadArray,
            $responseData,
            "FAILED – HTTP {$httpCode}" . ($curlErr ? " | cURL: {$curlErr}" : ''),
            $apiKey
        );
        return '<p style="color:red;">Genie payment unavailable. Please try again later. (HTTP ' . $httpCode . ')</p>';
    }

    // ── Payment URL ────────────────────────────────────────
    $paymentUrl = $responseData['shortUrl']
               ?? $responseData['url']
               ?? $responseData['paymentUrl']
               ?? '';

    if (empty($paymentUrl)) {
        genie_log('CreateTransaction', $payloadArray, $responseData,
            'FAILED – No payment URL returned', $apiKey);
        return '<p style="color:red;">Genie payment link could not be generated.</p>';
    }

    genie_log(
        'CreateTransaction',
        $payloadArray,
        $responseData,
        'SUCCESS – Invoice: ' . $invoiceId . ' | TxnID: ' . ($responseData['id'] ?? ''),
        $apiKey
    );

    $langPayNow = $params['langpaynow'] ?? 'Pay Now';
    $safeUrl    = htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div style="text-align:center;margin:16px 0;">
    <a href="{$safeUrl}" target="_blank"
       style="display:inline-block;background:#f97316;color:#fff;text-decoration:none;
              padding:12px 32px;font-size:16px;border-radius:6px;font-weight:bold;">
        {$langPayNow}
    </a>
</div>
HTML;
}

// ─────────────────────────────────────────────────────────────
// 5. CAPTURE / VERIFY
// ─────────────────────────────────────────────────────────────
function genie_capture(array $params)
{
    $transactionId = $params['transactionId'] ?? '';
    $apiKey        = $params['apiKey']        ?? '';
    $systemUrl     = $params['systemurl']     ?? '';
    $returnUrl     = $systemUrl . 'clientarea.php';

    $urls           = genie_get_urls($params);
    $transactionUrl = $urls['transactionUrl'];

    if (empty($transactionId) || empty($apiKey)) {
        genie_log('VerifyTransaction', [], [], 'FAILED – Missing transactionId or apiKey', $apiKey);
        return [
            'status'    => 'error',
            'data'      => [],
            'message'   => 'Missing params',
            'returnUrl' => $returnUrl,
        ];
    }

    $ch = curl_init($transactionUrl . '/' . urlencode($transactionId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $data = json_decode($response, true) ?: [];

    if ($curlErr || $httpCode !== 200) {
        genie_log('VerifyTransaction',
            ['transactionId' => $transactionId],
            $data,
            "FAILED – HTTP {$httpCode}" . ($curlErr ? " | cURL: {$curlErr}" : ''),
            $apiKey
        );
        return [
            'status'    => 'error',
            'data'      => [],
            'message'   => "API error (HTTP {$httpCode})",
            'returnUrl' => $returnUrl,
        ];
    }

    $genieStatus     = strtolower($data['state'] ?? '');
    $successStatuses = ['confirmed', 'success', 'completed', 'paid', 'captured'];

    if (!in_array($genieStatus, $successStatuses, true)) {
        genie_log('VerifyTransaction',
            ['transactionId' => $transactionId],
            $data,
            "FAILED – State: {$genieStatus}",
            $apiKey
        );
        return [
            'status'    => 'error',
            'data'      => $data,
            'message'   => "Payment state: {$genieStatus}",
            'returnUrl' => $returnUrl,
        ];
    }

    $invoiceId = $data['customerReference'] ?? '';
    if ($invoiceId) {
        $returnUrl = $systemUrl . 'viewinvoice.php?id=' . (int) $invoiceId . '&paymentsuccess=true';
    }

    genie_log('VerifyTransaction',
        ['transactionId' => $transactionId],
        $data,
        'SUCCESS – Invoice: ' . $invoiceId,
        $apiKey
    );

    return [
        'status'    => 'success',
        'data'      => $data,
        'message'   => 'Payment verified',
        'returnUrl' => $returnUrl,
    ];
}

// ─────────────────────────────────────────────────────────────
// 6. REFUND
// ─────────────────────────────────────────────────────────────
function genie_refund($params)
{
    $transactionId = $params['transid']    ?? '';
    $apiKey        = $params['apiKey']     ?? '';
    $amount        = (int) round((float) $params['amount'] * 100);
    $reason        = 'Refund for invoice #' . ($params['invoiceid'] ?? '');

    if (empty($transactionId) || empty($apiKey)) {
        genie_log('Refund', [], [], 'FAILED – Missing transactionId or apiKey', $apiKey);
        return [
            'status'  => 'error',
            'rawdata' => 'Missing transactionId or apiKey',
        ];
    }

    $urls     = genie_get_urls($params);
    $endpoint = $urls['refundUrl'] . '/' . urlencode($transactionId) . '/refunds';

    $payloadArray = [
        'refundAmount' => $amount,
        'refundReason' => $reason,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payloadArray),
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $responseData = json_decode($response, true) ?: [];

    if ($curlErr || !in_array($httpCode, [200, 201], true)) {
        $errorMsg = $responseData['message'] ?? $responseData['error'] ?? "HTTP {$httpCode}";
        genie_log('Refund',
            ['transactionId' => $transactionId, 'amount' => $amount],
            $responseData,
            "FAILED – {$errorMsg}" . ($curlErr ? " | cURL: {$curlErr}" : ''),
            $apiKey
        );
        return [
            'status'  => 'error',
            'rawdata' => $errorMsg,
        ];
    }

    $refundId = $responseData['id'] ?? $responseData['refundId'] ?? $transactionId;

    genie_log('Refund',
        ['transactionId' => $transactionId, 'amount' => $amount],
        $responseData,
        'SUCCESS – RefundId: ' . $refundId,
        $apiKey
    );

    return [
        'status'  => 'success',
        'rawdata' => $responseData,
        'transid' => $refundId,
    ];
}