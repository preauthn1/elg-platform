<?php

declare(strict_types=1);

namespace App\Enums\General;

enum HttpCode: int
{
    /**
     * 轉跳2FA (Google Authenticator)
     */
    case HTTP_REDIRECT_2FA = 318;

    /**
     * 登出 (回到登入頁)
     */
    case HTTP_REDIRECT_LOGOUT = 319;

    /**
     * 轉跳首頁 (遊戲商維護中)
     */
    case HTTP_REDIRECT_HOME = 320;

    /**
     * 轉跳會員中心 (當日提款次數已用完)
     */
    case HTTP_REDIRECT_MY = 321;

    /**
     * 轉跳找回密碼 (帳號鎖定中)
     */
    case HTTP_REDIRECT_RESET_PASSWORD = [REDACTED];

    /**
     * 轉跳設定支付密碼
     */
    case HTTP_REDIRECT_SET_PAYMENT_PASSWORD = [REDACTED];

    /**
     * 轉跳設定手機號
     */
    case HTTP_REDIRECT_SET_MOBILE = 324;

    /**
     * 轉跳設定真實姓名
     */
    case HTTP_REDIRECT_SET_REALNAME = 325;

    /**
     * 轉跳設定Email
     */
    case HTTP_REDIRECT_SET_EMAIL = 326;

    /**
     * 轉跳洗分頁 (提款)
     */
    case HTTP_REDIRECT_WITHDRAWAL = 330;

    /**
     * 轉跳洗分頁 (提款)
     */
    case HTTP_REDIRECT_SET_REALNAME_THEN_WITHDRAWAL = 331;

    /**
     * Continue
     */
    case HTTP_CONTINUE = 100;

    /**
     * Switching Protocols
     */
    case HTTP_SWITCHING_PROTOCOLS = 101;

    /**
     * Processing
     */
    case HTTP_PROCESSING = 102;            // RFC2518

    /**
     * Early Hints
     */
    case HTTP_EARLY_HINTS = 103;           // RFC8297

    /**
     * 操作成功
     */
    case HTTP_OK = 200;

    /**
     * Created
     */
    case HTTP_CREATED = 201;

    /**
     * Accepted
     */
    case HTTP_ACCEPTED = 202;

    /**
     * Non-Authoritative Information
     */
    case HTTP_NON_AUTHORITATIVE_INFORMATION = 203;

    /**
     * No Content
     */
    case HTTP_NO_CONTENT = 204;

    /**
     * Reset Content
     */
    case HTTP_RESET_CONTENT = 205;

    /**
     * Partial Content
     */
    case HTTP_PARTIAL_CONTENT = 206;

    /**
     * Multi-Status
     */
    case HTTP_MULTI_STATUS = 207;          // RFC4918

    /**
     * Already Reported
     */
    case HTTP_ALREADY_REPORTED = 208;      // RFC5842

    /**
     * IM Used
     */
    case HTTP_IM_USED = 226;               // RFC3229

    /**
     * Multiple Choices
     */
    case HTTP_MULTIPLE_CHOICES = 300;

    /**
     * Moved Permanently
     */
    case HTTP_MOVED_PERMANENTLY = 301;

    /**
     * Found
     */
    case HTTP_FOUND = 302;

    /**
     * See Other
     */
    case HTTP_SEE_OTHER = 303;

    /**
     * Not Modified
     */
    case HTTP_NOT_MODIFIED = 304;

    /**
     * Use Proxy
     */
    case HTTP_USE_PROXY = 305;

    /**
     * Reserved
     */
    case HTTP_RESERVED = 306;

    /**
     * Temporary Redirect
     */
    case HTTP_TEMPORARY_REDIRECT = 307;

    /**
     * Permanent Redirect
     */
    case HTTP_PERMANENTLY_REDIRECT = 308;  // RFC7238

    /**
     * Bad Request
     */
    case HTTP_BAD_REQUEST = 400;

    /**
     * Unauthorized
     */
    case HTTP_UNAUTHORIZED = 401;

    /**
     * Payment Required
     */
    case HTTP_PAYMENT_REQUIRED = 402;

    /**
     * Forbidden
     */
    case HTTP_FORBIDDEN = 403;

    /**
     * Not Found
     */
    case HTTP_NOT_FOUND = 404;

    /**
     * Method Not Allowed
     */
    case HTTP_METHOD_NOT_ALLOWED = 405;

    /**
     * Not Acceptable
     */
    case HTTP_NOT_ACCEPTABLE = 406;

    /**
     * Proxy Authentication Required
     */
    case HTTP_PROXY_AUTHENTICATION_REQUIRED = 407;

    /**
     * Request Timeout
     */
    case HTTP_REQUEST_TIMEOUT = 408;

    /**
     * Conflict
     */
    case HTTP_CONFLICT = 409;

    /**
     * Gone
     */
    case HTTP_GONE = 410;

    /**
     * Length Required
     */
    case HTTP_LENGTH_REQUIRED = 411;

    /**
     * Precondition Failed
     */
    case HTTP_PRECONDITION_FAILED = 412;

    /**
     * Content Too Large
     */
    case HTTP_REQUEST_ENTITY_TOO_LARGE = 413;

    /**
     * URI Too Long
     */
    case HTTP_REQUEST_URI_TOO_LONG = 414;

    /**
     * Unsupported Media Type
     */
    case HTTP_UNSUPPORTED_MEDIA_TYPE = 415;

    /**
     * Range Not Satisfiable
     */
    case HTTP_REQUESTED_RANGE_NOT_SATISFIABLE = 416;

    /**
     * Expectation Failed
     */
    case HTTP_EXPECTATION_FAILED = 417;

    /**
     * I\'m a teapot
     */
    case HTTP_I_AM_A_TEAPOT = 418;                                               // RFC2324

    /**
     * Misdirected Request
     */
    case HTTP_MISDIRECTED_REQUEST = 421;                                         // RFC7540

    /**
     * Unprocessable Content
     */
    case HTTP_UNPROCESSABLE_ENTITY = 422;                                        // RFC4918

    /**
     * Locked
     */
    case HTTP_LOCKED = 423;                                                      // RFC4918

    /**
     * Failed Dependency
     */
    case HTTP_FAILED_DEPENDENCY = 424;                                           // RFC4918

    /**
     * Too Early
     */
    case HTTP_TOO_EARLY = 425;                                                   // RFC-ietf-httpbis-replay-04

    /**
     * Upgrade Required
     */
    case HTTP_UPGRADE_REQUIRED = 426;                                            // RFC2817

    /**
     * Precondition Required
     */
    case HTTP_PRECONDITION_REQUIRED = 428;                                       // RFC6585

    /**
     * Too Many Requests
     */
    case HTTP_TOO_MANY_REQUESTS = 429;                                           // RFC6585

    /**
     * Request Header Fields Too Large
     */
    case HTTP_REQUEST_HEADER_FIELDS_TOO_LARGE = 431;                             // RFC6585

    /**
     * Unavailable For Legal Reasons
     */
    case HTTP_UNAVAILABLE_FOR_LEGAL_REASONS = 451;                               // RFC7725

    /**
     * Internal Server Error
     */
    case HTTP_INTERNAL_SERVER_ERROR = 500;

    /**
     * Not Implemented
     */
    case HTTP_NOT_IMPLEMENTED = 501;

    /**
     * Bad Gateway
     */
    case HTTP_BAD_GATEWAY = 502;

    /**
     * Service Unavailable
     */
    case HTTP_SERVICE_UNAVAILABLE = 503;

    /**
     * Gateway Timeout
     */
    case HTTP_GATEWAY_TIMEOUT = 504;

    /**
     * HTTP Version Not Supported
     */
    case HTTP_VERSION_NOT_SUPPORTED = 505;

    /**
     * Variant Also Negotiates
     */
    case HTTP_VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL = 506;                        // RFC2295

    /**
     * Insufficient Storage
     */
    case HTTP_INSUFFICIENT_STORAGE = 507;                                        // RFC4918

    /**
     * Loop Detected
     */
    case HTTP_LOOP_DETECTED = 508;                                               // RFC5842

    /**
     * Not Extended
     */
    case HTTP_NOT_EXTENDED = 510;                                                // RFC2774

    /**
     * Network Authentication Required
     */
    case HTTP_NETWORK_AUTHENTICATION_REQUIRED = 511;                             // RFC6585
}
