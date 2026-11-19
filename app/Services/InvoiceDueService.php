<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class InvoiceDueService
{
    /**
     * Recalculate and sync FIFO invoice dues for a single customer.
     */
    public static function syncCustomerInvoiceDues(int $customerId): void
    {
        $customer = Customer::with(['invoices' => function ($q) {
            $q->orderBy('sale_date', 'asc')->orderBy('id', 'asc');
        }])->find($customerId);

        if (!$customer) {
            return;
        }

        $oldDue = (float)($customer->old_due ?? 0);
        $totalPayments = (float)Payment::where('cust_id', $customerId)->sum('amount');

        // Remaining payments after covering old_due
        $remPayment = max(0, $totalPayments - $oldDue);

        foreach ($customer->invoices as $invoice) {
            $grandTotal = (float)$invoice->grand_total;

            if ($remPayment >= $grandTotal) {
                $invPaid = $grandTotal;
                $invDue = 0;
                $remPayment -= $grandTotal;
            } elseif ($remPayment > 0) {
                $invPaid = $remPayment;
                $invDue = $grandTotal - $remPayment;
                $remPayment = 0;
            } else {
                $invPaid = 0;
                $invDue = $grandTotal;
            }

            if (abs((float)$invoice->paid - $invPaid) > 0.001 || abs((float)$invoice->due - $invDue) > 0.001) {
                $invoice->update([
                    'paid' => $invPaid,
                    'due' => $invDue,
                ]);
            }
        }
    }

    /**
     * Recalculate and sync FIFO invoice dues for all customers.
     * Returns an array with stats.
     */
    public static function syncAllInvoiceDues(?callable $progressCallback = null): array
    {
        $customers = Customer::with(['invoices' => function ($q) {
            $q->orderBy('sale_date', 'asc')->orderBy('id', 'asc');
        }])->get();

        $totalCustomers = $customers->count();
        $updatedInvoicesCount = 0;
        $totalInvoicesCount = 0;

        DB::beginTransaction();
        try {
            foreach ($customers as $index => $customer) {
                $oldDue = (float)($customer->old_due ?? 0);
                $totalPayments = (float)Payment::where('cust_id', $customer->id)->sum('amount');
                $remPayment = max(0, $totalPayments - $oldDue);

                foreach ($customer->invoices as $invoice) {
                    $totalInvoicesCount++;
                    $grandTotal = (float)$invoice->grand_total;

                    if ($remPayment >= $grandTotal) {
                        $invPaid = $grandTotal;
                        $invDue = 0;
                        $remPayment -= $grandTotal;
                    } elseif ($remPayment > 0) {
                        $invPaid = $remPayment;
                        $invDue = $grandTotal - $remPayment;
                        $remPayment = 0;
                    } else {
                        $invPaid = 0;
                        $invDue = $grandTotal;
                    }

                    if (abs((float)$invoice->paid - $invPaid) > 0.001 || abs((float)$invoice->due - $invDue) > 0.001) {
                        $invoice->update([
                            'paid' => $invPaid,
                            'due' => $invDue,
                        ]);
                        $updatedInvoicesCount++;
                    }
                }

                if ($progressCallback && ($index + 1) % 100 === 0) {
                    $progressCallback($index + 1, $totalCustomers);
                }
            }

            DB::commit();

            return [
                'status' => true,
                'total_customers' => $totalCustomers,
                'total_invoices' => $totalInvoicesCount,
                'updated_invoices' => $updatedInvoicesCount,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
