<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\A3Pay;

/**
 * 2026-09-24 dev 測試商戶（MerchantCode gff）實測抓到的三方回應與回調解密後明文，原樣保留形狀與值；
 * 只把三方收款帳戶資料與收銀台 token 換成佔位。測試以這些為 fixture，避免用「文件說的形狀」自己編。
 *
 * 來源：GCP log（A3Pay_sendRequest）與 orders.remark／withdrawal_payout_records.callback_request_body。
 */
final class A3PayRealPayloads
{
    /** CreatePayin 成功（BQR，下單 10，三方唯一化上調成 10.01）；dev 訂單 13913 */
    public const array PAYIN_SUCCESS_BQR_ADJUSTED = [
        'isSuccess' => true, 'successCode' => 200, 'errorCode' => '', 'errorMessage' => '', 'errorList' => null,
        'data' => [
            'paymentOrderId' => 'D2026092400000355',
            'paymentURL' => 'https://example.invalid/Deposit/SGQRDeposit/?a=<token>',
            'paymentBankCode' => 'Maribank',
            'paymentAccountNumber' => '2227XXXXX',
            'paymentAccountHolderName' => 'RECEIVING ACCOUNT PTE.LTD',
            'paymentReferenceNo' => null,
            'paymentQRCode' => 'data:image/png;base64,iVBORw0KGgo=',
            'payinActualAmount' => 10.01,
            'paymentDeepLink' => null,
            'bankBranchCode' => null,
        ],
    ];

    /** CreatePayin 失敗（通道未開通，HTTP 400）；data 是舊鍵名；dev 訂單 13904 */
    public const array PAYIN_FAIL_PMT10005_LEGACY_KEYS = [
        'isSuccess' => false, 'successCode' => 0, 'errorCode' => 'PMT10005', 'errorMessage' => 'Invalid Transaction Amount.', 'errorList' => null,
        'data' => [
            'transactionId' => null,
            'url' => 'https://example.invalid/Error/Error?ErrorCode=PMT10005&ErrorMessage=Service Not Available. Please Contact Customer Service for assistance.',
            'bankCode' => null, 'accountNumber' => null, 'accountHolderName' => null, 'referenceNo' => null,
            'qrCode' => null, 'depositActualAmount' => null, 'deepLink' => null, 'bankBranchCode' => null,
        ],
    ];

    /** Payin Callback Data（解密後）：成功、實付 10.01（下單 10）；dev 訂單 13913 */
    public const array PAYIN_CALLBACK_SUCCESS_ADJUSTED = [
        'MerchantCode' => 'gff', 'CurrencyCode' => 'SGD', 'MerchantTransactionId' => '202609246689807767',
        'Status' => 6, 'Amount' => 10.01, 'NetAmount' => 9.78, 'Remark' => null, 'PaymentMethodCode' => 'BQR',
        'BankReferenceNo' => null, 'UniqueReferenceNo' => null,
    ];

    /** Payin Callback Data：成功、金額未上調；dev 訂單 13912 */
    public const array PAYIN_CALLBACK_SUCCESS_EXACT = [
        'MerchantCode' => 'gff', 'CurrencyCode' => 'SGD', 'MerchantTransactionId' => '202609248453700384',
        'Status' => 6, 'Amount' => 10, 'NetAmount' => 9.77, 'Remark' => null, 'PaymentMethodCode' => 'OB',
        'BankReferenceNo' => null, 'UniqueReferenceNo' => null,
    ];

    /** Payin Callback Data：失敗（Status 7），金額也被上調；dev 訂單 13916 */
    public const array PAYIN_CALLBACK_FAIL_ADJUSTED = [
        'MerchantCode' => 'gff', 'CurrencyCode' => 'SGD', 'MerchantTransactionId' => '202609246374867247',
        'Status' => 7, 'Amount' => 20.01, 'NetAmount' => 19.55, 'Remark' => null, 'PaymentMethodCode' => 'BQR',
        'BankReferenceNo' => null, 'UniqueReferenceNo' => null,
    ];

    /** SubmitPayout 受理；dev 提款單 705 */
    public const array PAYOUT_ACCEPTED = [
        'isSuccess' => true, 'successCode' => 200, 'errorCode' => '', 'errorMessage' => '', 'errorList' => null,
        'data' => ['paymentOrderId' => 'W2026092400000014'],
    ];

    /** SubmitPayout 拒絕（HTTP 400，餘額不足）；dev 提款單 700 */
    public const array PAYOUT_REJECTED_PMT10013 = [
        'isSuccess' => false, 'successCode' => 0, 'errorCode' => 'PMT10013', 'errorMessage' => 'Insufficient Balance.', 'errorList' => null,
        'data' => ['transactionId' => null],
    ];

    /** Payout Callback Data（解密後）：成功 Status 4；dev 提款單 705 */
    public const array PAYOUT_CALLBACK_SUCCESS = [
        'MerchantCode' => 'gff', 'CurrencyCode' => 'SGD', 'MerchantTransactionId' => 'A3PAY2026092407510277559',
        'Status' => 4, 'Amount' => 50, 'NetAmount' => 50.65, 'Remark' => '', 'PaymentMethodCode' => 'WD',
        'BankReferenceNo' => '', 'UniqueReferenceNo' => null,
    ];

    /** Payout Callback Data：三方取消 Status 5；dev 提款單 700 */
    public const array PAYOUT_CALLBACK_CANCELLED = [
        'MerchantCode' => 'gff', 'CurrencyCode' => 'SGD', 'MerchantTransactionId' => 'A3PAY2026092406595095564',
        'Status' => 5, 'Amount' => 50, 'NetAmount' => 50.65, 'Remark' => 'test', 'PaymentMethodCode' => 'WD',
        'BankReferenceNo' => null, 'UniqueReferenceNo' => null,
    ];

    /** GetPayoutDetail：已取消的單 */
    public const array PAYOUT_DETAIL_FAILED = [
        'isSuccess' => true, 'successCode' => 200, 'errorCode' => null, 'errorMessage' => '', 'errorList' => null,
        'data' => [
            'paymentOrderId' => 'W2026092400000010', 'mTransactionId' => 'A3PAY2026092406595095564',
            'statusId' => 5, 'status' => 'Fail', 'payoutAmount' => 50.00, 'payoutNetAmount' => 50.65, 'paymentMethodId' => 'WD',
        ],
    ];

    /** GetPayoutDetail 查不存在的交易號：HTTP 400 純文字（廠商確認） */
    public const string PAYOUT_DETAIL_NOT_FOUND_BODY = 'Not found';
}
