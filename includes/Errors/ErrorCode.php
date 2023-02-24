<?php

namespace Payex\Woocommerce\Errors;

require_once __DIR__.'/../../payex-sdk/Payex.php';

use Payex\Api\Errors as ApiErrors;

class ErrorCode extends ApiErrors\ErrorCode
{
    const INVALID_CURRENCY_ERROR_CODE          = 'INVALID_CURRENCY_ERROR';
    const WOOCS_CURRENCY_MISSING_ERROR_CODE    = 'WOOCS_CURRENCY_MISSING_ERROR';
    const WOOCS_MISSING_ERROR_CODE             = 'WOOCS_MISSING_ERROR';

    const WOOCS_MISSING_ERROR_MESSAGE          = 'The WooCommerce Currency Switcher plugin is missing.';
    const INVALID_CURRENCY_ERROR_MESSAGE       = 'The selected currency is invalid.';
    const WOOCS_CURRENCY_MISSING_ERROR_MESSAGE = 'Woocommerce Currency Switcher plugin is not configured with INR correctly';
}
