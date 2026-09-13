<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Due-based credit checks
    |--------------------------------------------------------------------------
    |
    | true:  order and invoice create/update check credit with CreditUsageService
    |        (old_due + all invoices − payments + pending credit orders), against
    |        the limits of the customer and the customer's officer.
    | false: the legacy checks, which can report negative usage.
    |
    | The credit report and profile always show the due-based numbers. Turn this
    | on after over-limit employees and customers are sorted out.
    |
    */

    'due_based_checks' => env('CREDIT_DUE_BASED', false),

];
