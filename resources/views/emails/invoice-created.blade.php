@component('mail::message')
# New Invoice Created

Hello,

A new invoice has been created with ID **{{ $invoice->invoiceId }}**.

@component('mail::button', ['url' => $url])
View Invoice
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
