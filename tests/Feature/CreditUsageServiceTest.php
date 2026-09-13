<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\CreditUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CreditUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreditUsageService $service;
    private int $designationId;
    private int $productId;
    private int $sequence = 0;

    protected function beforeRefreshingDatabase()
    {
        // RefreshDatabase drops every table, so refuse to run against a real database.
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('CreditUsageServiceTest must run on sqlite :memory: (see phpunit.xml).');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CreditUsageService();
        $this->designationId = DB::table('designations')->insertGetId(['name' => 'Officer', 'slug' => 'officer']);
        $this->productId = DB::table('products')->insertGetId([
            'cat_id' => DB::table('categories')->insertGetId(['name' => 'Seed']),
            'brand_id' => DB::table('brands')->insertGetId(['name' => 'Brand']),
            'name' => 'Product',
        ]);
    }

    public function test_usage_counts_old_due_all_invoices_payments_and_pending_credit_orders(): void
    {
        $officer = $this->employee();
        $customer = $this->customer($officer, oldDue: 1000);

        $this->invoice($customer, $officer, 500, 'credit');
        $this->invoice($customer, $officer, 300, 'cash');
        $this->payment($customer, 700);
        $this->order($customer, $officer, 200, 'credit');
        $this->order($customer, $officer, 50, null);                 // legacy order without a type counts as credit
        $this->order($customer, $officer, 400, 'cash');              // cash order holds no credit
        $this->order($customer, $officer, 900, 'credit', 'active');  // not pending

        // 1000 + 500 + 300 − 700 + 200 + 50
        $this->assertEquals(1350, $this->service->forOfficer($officer));
        $this->assertEquals(1350, $this->service->forCustomer($customer));
    }

    public function test_payments_without_customer_and_other_officers_customers_are_left_out(): void
    {
        $officer = $this->employee();
        $other = $this->employee();
        $customer = $this->customer($officer);
        $otherCustomer = $this->customer($other, oldDue: 5000);

        $this->invoice($customer, $officer, 800, 'credit');
        $this->invoice($otherCustomer, $other, 900, 'credit');
        $this->payment(null, 300, $officer);

        $this->assertEquals(800, $this->service->forOfficer($officer));
    }

    public function test_usage_follows_current_customer_owner_not_invoice_creator(): void
    {
        $officer = $this->employee();
        $admin = $this->employee();
        $customer = $this->customer($officer);

        $this->invoice($customer, $admin, 600, 'credit');
        $this->payment($customer, 100, $admin);

        $this->assertEquals(500, $this->service->forOfficer($officer));
        $this->assertEquals(0, $this->service->forOfficer($admin));
    }

    public function test_excluded_invoice_and_order_are_not_counted(): void
    {
        $officer = $this->employee();
        $customer = $this->customer($officer);
        $invoice = $this->invoice($customer, $officer, 600, 'credit');
        $order = $this->order($customer, $officer, 400, 'credit');

        $this->assertEquals(400, $this->service->forOfficer($officer, excludeInvoiceId: $invoice));
        $this->assertEquals(600, $this->service->forCustomer($customer, excludeOrderId: $order));
    }

    public function test_by_officer_matches_for_officer(): void
    {
        $first = $this->employee();
        $second = $this->employee();
        $firstCustomer = $this->customer($first, oldDue: 100);
        $secondCustomer = $this->customer($second);

        $this->invoice($firstCustomer, $first, 250, 'cash');
        $this->payment($firstCustomer, 1000); // overpaid, so usage is negative
        $this->order($secondCustomer, $second, 80, 'credit');

        $byOfficer = $this->service->byOfficer();

        $this->assertEquals(-650, $byOfficer[$first]);
        $this->assertEquals(80, $byOfficer[$second]);
        $this->assertEquals($this->service->forOfficer($first), $byOfficer[$first]);
        $this->assertEquals($this->service->forOfficer($second), $byOfficer[$second]);
    }

    public function test_exceeded_limit_checks_the_officer_then_the_customer(): void
    {
        $officer = $this->employee(limit: 1000);
        $customerId = $this->customer($officer, oldDue: 300, limit: 500);
        $this->invoice($customerId, $officer, 100, 'cash');
        $customer = Customer::find($customerId);

        // Usage is 400 for both the officer and the customer.
        $this->assertNull($this->service->exceededLimit($customer, 100));
        $this->assertSame('customer', $this->service->exceededLimit($customer, 101));
        $this->assertSame('employee', $this->service->exceededLimit($customer, 601));
    }

    private function employee(float $limit = 0): int
    {
        $n = ++$this->sequence;

        return DB::table('employees')->insertGetId([
            'employee_id' => "EMP{$n}",
            'designation_id' => $this->designationId,
            'name' => "Employee {$n}",
            'phone' => "0170000{$n}",
            'territory' => 'Territory',
            'district' => 'District',
            'credit_limit' => $limit,
            'created_by' => 1,
        ]);
    }

    private function customer(int $officerId, float $oldDue = 0, float $limit = 0): int
    {
        $n = ++$this->sequence;

        return DB::table('customers')->insertGetId([
            'employee_id' => $officerId,
            'customer_name' => "Customer {$n}",
            'customer_id' => "RA18/{$n}",
            'proprietor_name' => 'Owner',
            'phone' => "0180000{$n}",
            'address' => 'Address',
            'credit_limit' => $limit,
            'old_due' => $oldDue,
        ]);
    }

    private function invoice(int $customerId, int $creatorId, float $total, string $saleType): int
    {
        $n = ++$this->sequence;

        return DB::table('invoices')->insertGetId([
            'invoiceId' => "RAINVO-{$n}",
            'cust_id' => $customerId,
            'employee_id' => $creatorId,
            'total_item' => 1,
            'total_price' => $total,
            'grand_total' => $total,
            'sale_date' => '2026-09-01',
            'sale_type' => $saleType,
        ]);
    }

    private function payment(?int $customerId, float $amount, ?int $employeeId = null): int
    {
        return DB::table('payments')->insertGetId([
            'cust_id' => $customerId,
            'employee_id' => $employeeId,
            'amount' => $amount,
            'payment_method' => 'cash',
            'payment_date' => '2026-09-02',
        ]);
    }

    private function order(int $customerId, int $creatorId, float $amount, ?string $orderType, string $status = 'pending'): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'cust_id' => $customerId,
            'employee_id' => $creatorId,
            'order_type' => $orderType,
            'order_date' => '2026-09-03',
            'status' => $status,
        ]);

        // Two units so the test also covers quantity × unit_price.
        DB::table('order_products')->insert([
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'quantity' => 2,
            'unit_price' => $amount / 2,
            'price_type' => 'tp',
        ]);

        return $orderId;
    }
}
