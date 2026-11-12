<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class EmployeeSalesSummaryTest extends TestCase
{
    use RefreshDatabase;

    private int $adminDesigId;
    private int $rsmDesigId;
    private int $managerDesigId;
    private int $officerDesigId;
    private int $sequence = 0;

    protected function beforeRefreshingDatabase()
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('EmployeeSalesSummaryTest must run on sqlite :memory: (see phpunit.xml).');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminDesigId = DB::table('designations')->insertGetId(['name' => 'Admin', 'slug' => 'admin']);
        $this->rsmDesigId = DB::table('designations')->insertGetId(['name' => 'RSM', 'slug' => 'rsm']);
        $this->managerDesigId = DB::table('designations')->insertGetId(['name' => 'Manager', 'slug' => 'manager']);
        $this->officerDesigId = DB::table('designations')->insertGetId(['name' => 'Officer', 'slug' => 'officer']);
    }

    public function test_defaults_to_today_and_returns_employee_cash_and_credit_totals(): void
    {
        $today = Carbon::today()->format('Y-m-d');
        $yesterday = Carbon::yesterday()->format('Y-m-d');

        $adminEmp = $this->createEmployee($this->adminDesigId, 'Admin Boss');
        $adminUser = $this->createUser($adminEmp);

        $empA = $this->createEmployee($this->officerDesigId, 'Rahim');
        $empB = $this->createEmployee($this->officerDesigId, 'Karim');
        $empZero = $this->createEmployee($this->officerDesigId, 'Zero Sale Officer');

        $custA = $this->createCustomer($empA);
        $custB = $this->createCustomer($empB);

        // Today's sales
        $this->createInvoice($custA, $empA, 1500, 'cash', $today);
        $this->createInvoice($custA, $empA, 2500, 'credit', $today);
        $this->createInvoice($custB, $empB, 1000, 'cash', $today);

        // Yesterday's sales (should NOT appear in default today view)
        $this->createInvoice($custA, $empA, 9999, 'cash', $yesterday);

        Sanctum::actingAs($adminUser);

        $response = $this->getJson('/api/employee-sales-summary');
        $response->assertOk()->assertJson([
            'status' => true,
            'data' => [
                'summary' => [
                    'from_date' => $today,
                    'to_date' => $today,
                    'total_sales' => 5000.0,
                    'cash_sales' => 2500.0,
                    'credit_sales' => 2500.0,
                    'total_invoices' => 3,
                    'cash_invoices' => 2,
                    'credit_invoices' => 1,
                    'cash_percentage' => 50.0,
                    'credit_percentage' => 50.0,
                    'active_selling_employees' => 2,
                ],
            ],
        ]);

        $employees = collect($response->json('data.employees'))->keyBy('employee_id');
        $this->assertEquals(1500.0, $employees[$empA]['cash_sales']);
        $this->assertEquals(2500.0, $employees[$empA]['credit_sales']);
        $this->assertEquals(4000.0, $employees[$empA]['total_sales']);
        $this->assertEquals(1000.0, $employees[$empB]['cash_sales']);
        $this->assertEquals(0.0, $employees[$empB]['credit_sales']);
        $this->assertEquals(0.0, $employees[$empZero]['total_sales']);
    }

    public function test_date_range_filter_includes_historical_dates_and_inactive_selling_employee(): void
    {
        $adminEmp = $this->createEmployee($this->adminDesigId, 'Admin Boss');
        $adminUser = $this->createUser($adminEmp);

        $inactiveEmp = $this->createEmployee($this->officerDesigId, 'Old Officer', 'deactive');
        $customer = $this->createCustomer($inactiveEmp);

        $this->createInvoice($customer, $inactiveEmp, 700, 'cash', '2026-10-05');
        $this->createInvoice($customer, $inactiveEmp, 300, 'credit', '2026-10-08 14:30:00');
        $this->createInvoice($customer, $inactiveEmp, 500, 'cash', '2026-10-20');

        Sanctum::actingAs($adminUser);

        $response = $this->getJson('/api/employee-sales-summary?from_date=2026-10-01&to_date=2026-10-10');
        $response->assertOk()->assertJson([
            'status' => true,
            'data' => [
                'summary' => [
                    'from_date' => '2026-10-01',
                    'to_date' => '2026-10-10',
                    'total_sales' => 1000.0,
                    'cash_sales' => 700.0,
                    'credit_sales' => 300.0,
                    'total_invoices' => 2,
                ],
            ],
        ]);

        $employees = collect($response->json('data.employees'))->keyBy('employee_id');
        $this->assertTrue($employees->has($inactiveEmp));
        $this->assertEquals(1000.0, $employees[$inactiveEmp]['total_sales']);
    }

    public function test_role_scoping_for_manager_and_officer_and_invoice_drilldown(): void
    {
        $today = Carbon::today()->format('Y-m-d');

        $adminEmp = $this->createEmployee($this->adminDesigId, 'Admin');
        $adminUser = $this->createUser($adminEmp);

        $manager = $this->createEmployee($this->managerDesigId, 'Manager 1');
        $managerUser = $this->createUser($manager);

        $officerUnderManager = $this->createEmployee($this->officerDesigId, 'Officer 1');
        $officerUser = $this->createUser($officerUnderManager);

        $otherOfficer = $this->createEmployee($this->officerDesigId, 'Other Officer');

        DB::table('relations')->insert([
            'employee_id' => $officerUnderManager,
            'relation_id' => $manager,
            'created_by' => $adminUser->id,
        ]);

        $cust1 = $this->createCustomer($officerUnderManager);
        $cust2 = $this->createCustomer($otherOfficer);

        $this->createInvoice($cust1, $officerUnderManager, 1200, 'cash', $today);
        $this->createInvoice($cust2, $otherOfficer, 3000, 'credit', $today);

        // Manager only sees self + Officer 1
        Sanctum::actingAs($managerUser);
        $mgrRes = $this->getJson('/api/employee-sales-summary');
        $mgrRes->assertOk();
        $this->assertEquals(1200.0, $mgrRes->json('data.summary.total_sales'));
        $mgrEmpIds = collect($mgrRes->json('data.employees'))->pluck('employee_id')->all();
        $this->assertContains($manager, $mgrEmpIds);
        $this->assertContains($officerUnderManager, $mgrEmpIds);
        $this->assertNotContains($otherOfficer, $mgrEmpIds);

        // Officer cannot query another officer's drilldown
        Sanctum::actingAs($officerUser);
        $deniedDrilldown = $this->getJson("/api/employee-sales-summary?employee_id={$otherOfficer}&include_invoices=1");
        $deniedDrilldown->assertOk();
        $this->assertEmpty($deniedDrilldown->json('data.employees'));
        $this->assertEmpty($deniedDrilldown->json('data.invoices'));

        // Officer can query own drilldown invoices
        $ownDrilldown = $this->getJson("/api/employee-sales-summary?employee_id={$officerUnderManager}&include_invoices=1");
        $ownDrilldown->assertOk();
        $this->assertCount(1, $ownDrilldown->json('data.invoices'));
        $this->assertEquals(1200.0, $ownDrilldown->json('data.invoices.0.grand_total'));
    }

    private function createEmployee(int $designationId, string $name, string $status = 'active'): int
    {
        $n = ++$this->sequence;

        return DB::table('employees')->insertGetId([
            'employee_id' => "EMP-{$n}",
            'designation_id' => $designationId,
            'name' => $name,
            'phone' => "0170000{$n}",
            'territory' => "Territory {$n}",
            'district' => 'Dhaka',
            'credit_limit' => 0,
            'basic_salary' => 0,
            'created_by' => 1,
            'status' => $status,
        ]);
    }

    private function createUser(int $employeeId): User
    {
        $n = ++$this->sequence;
        $userId = DB::table('users')->insertGetId([
            'employee_id' => $employeeId,
            'email' => "user{$n}@example.com",
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        return User::findOrFail($userId);
    }

    private function createCustomer(int $officerId): int
    {
        $n = ++$this->sequence;

        return DB::table('customers')->insertGetId([
            'employee_id' => $officerId,
            'customer_name' => "Customer {$n}",
            'customer_id' => "CUST-{$n}",
            'proprietor_name' => 'Owner',
            'phone' => "0180000{$n}",
            'address' => 'Address',
            'credit_limit' => 0,
            'old_due' => 0,
        ]);
    }

    private function createInvoice(int $customerId, int $employeeId, float $total, string $saleType, string $saleDate): int
    {
        $n = ++$this->sequence;

        return DB::table('invoices')->insertGetId([
            'invoiceId' => "INV-{$n}",
            'cust_id' => $customerId,
            'employee_id' => $employeeId,
            'total_item' => 1,
            'total_price' => $total,
            'grand_total' => $total,
            'paid' => $saleType === 'cash' ? $total : 0,
            'due' => $saleType === 'credit' ? $total : 0,
            'sale_date' => $saleDate,
            'sale_type' => $saleType,
        ]);
    }
}
