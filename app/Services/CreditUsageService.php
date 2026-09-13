<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Credit in use = what customers still owe + pending credit orders.
 *
 *   Σ customers.old_due + Σ invoices.grand_total − Σ payments.amount + Σ pending credit order value
 *
 * Cash invoices count too: invoices.paid is never filled, so a cash sale stays owed until a
 * payment row arrives, and payments are not linked to invoices. An officer's usage covers the
 * customers they own now (customers.employee_id), whoever created the invoice or order.
 */
class CreditUsageService
{
    public function forOfficer(int $officerId, ?int $excludeInvoiceId = null, ?int $excludeOrderId = null): float
    {
        return $this->usage('customers.employee_id', $officerId, $excludeInvoiceId, $excludeOrderId);
    }

    public function forCustomer(int $customerId, ?int $excludeInvoiceId = null, ?int $excludeOrderId = null): float
    {
        return $this->usage('customers.id', $customerId, $excludeInvoiceId, $excludeOrderId);
    }

    /**
     * Credit use of every officer that owns customers, keyed by employee id.
     *
     * @return Collection<int, float>
     */
    public function byOfficer(): Collection
    {
        $sum = fn (Builder $query, string $amount) => $query
            ->selectRaw("customers.employee_id AS officer_id, SUM({$amount}) AS total")
            ->groupBy('customers.employee_id')
            ->pluck('total', 'officer_id');

        $invoices = $sum($this->invoices(), 'invoices.grand_total');
        $payments = $sum($this->payments(), 'payments.amount');
        $orders = $sum($this->pendingCreditOrders(), 'order_products.quantity * order_products.unit_price');

        // Every officer with invoices, payments or orders also owns customers, so old_due has all keys.
        return $sum($this->customers(), 'customers.old_due')
            ->map(fn ($oldDue, $officerId) => (float) ($oldDue
                + ($invoices[$officerId] ?? 0)
                - ($payments[$officerId] ?? 0)
                + ($orders[$officerId] ?? 0)));
    }

    /**
     * Which limit a new credit sale breaks: 'employee', 'customer', or null when both hold.
     * The employee limit checked is the customer's officer, not whoever creates the sale.
     */
    public function exceededLimit(Customer $customer, float $newAmount, ?int $excludeInvoiceId = null, ?int $excludeOrderId = null): ?string
    {
        $officerLimit = (float) (Employee::whereKey($customer->employee_id)->value('credit_limit') ?? 0);

        if ($this->forOfficer($customer->employee_id, $excludeInvoiceId, $excludeOrderId) + $newAmount > $officerLimit) {
            return 'employee';
        }

        if ($this->forCustomer($customer->id, $excludeInvoiceId, $excludeOrderId) + $newAmount > (float) ($customer->credit_limit ?? 0)) {
            return 'customer';
        }

        return null;
    }

    private function usage(string $column, int $id, ?int $excludeInvoiceId, ?int $excludeOrderId): float
    {
        $oldDue = $this->customers()->where($column, $id)->sum('customers.old_due');
        $invoices = $this->invoices($excludeInvoiceId)->where($column, $id)->sum('invoices.grand_total');
        $payments = $this->payments()->where($column, $id)->sum('payments.amount');
        $orders = $this->pendingCreditOrders($excludeOrderId)->where($column, $id)
            ->sum(DB::raw('order_products.quantity * order_products.unit_price'));

        return (float) ($oldDue + $invoices - $payments + $orders);
    }

    private function customers(): Builder
    {
        return DB::table('customers');
    }

    private function invoices(?int $excludeInvoiceId = null): Builder
    {
        return DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.cust_id')
            ->when($excludeInvoiceId, fn ($q) => $q->where('invoices.id', '!=', $excludeInvoiceId));
    }

    // Supplier payments have no cust_id, so the join leaves them out.
    private function payments(): Builder
    {
        return DB::table('payments')->join('customers', 'customers.id', '=', 'payments.cust_id');
    }

    // Orders with no order_type are treated as credit so legacy rows stay counted.
    private function pendingCreditOrders(?int $excludeOrderId = null): Builder
    {
        return DB::table('orders')
            ->join('customers', 'customers.id', '=', 'orders.cust_id')
            ->join('order_products', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.status', 'pending')
            ->where(fn ($q) => $q->where('orders.order_type', '!=', 'cash')->orWhereNull('orders.order_type'))
            ->when($excludeOrderId, fn ($q) => $q->where('orders.id', '!=', $excludeOrderId));
    }
}
