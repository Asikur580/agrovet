<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Cost;
use App\Models\Order;
use App\Models\Salary;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Relation;
use App\Models\Supplier;
use App\Models\StockInOut;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{

    public function productReport($id)
    {
        $productDetails = Product::find($id);
        $productSales = DB::table('invoice_products')
            ->join('invoices', 'invoice_products.invoice_id', '=', 'invoices.id')
            ->join('customers', 'invoices.cust_id', '=', 'customers.id')
            ->select(
                'invoices.sale_date',
                'invoice_products.quantity',
                'invoices.grand_total',
                'customers.customer_name as customer_name'
            )
            ->where('invoice_products.product_id', $id)
            ->orderBy('invoices.sale_date', 'asc') // বিক্রয়ের তারিখ অনুযায়ী সাজানো
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Product sale report retrieved successfully',
            'data' => ['prodcut_details' => $productDetails, 'product_sales' => $productSales]
        ]);
    }

    public function customerReport($id)
    {
        // Get customer details by ID
        $customerDetails = Customer::find($id);

        // Check if the customer exists
        if (!$customerDetails) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        // Get all invoices for the specific customer, ordered by sale date
        $customerInvoices = DB::table('invoices')
            ->join('customers', 'invoices.cust_id', '=', 'customers.id')
            ->select(
                'invoices.invoiceId',
                'invoices.sale_date',
                'invoices.grand_total',
                'invoices.due'
            )
            ->where('invoices.cust_id', $id)
            ->orderBy('invoices.sale_date', 'asc')
            ->get();

        // Calculate the total due for the customer
        $totalDue = $customerInvoices->sum('due');

        // 1. Total Purchases (Total amount spent by the customer)
        $totalPurchases = $customerInvoices->sum('grand_total'); // Sum of grand_total from the invoices

        // 2. Total Payments Made by the customer
        $totalPayments = Payment::where('cust_id', $id)
            ->sum('amount'); // Total payments made by the customer

        // Return the data in the response
        return response()->json([
            'status' => true,
            'message' => 'Customer report retrieved successfully',
            'data' => [
                'customer_details' => $customerDetails,
                'customer_invoices' => $customerInvoices,
                'total_purchases' => $totalPurchases,
                'total_payments' => $totalPayments,
                'total_due' => $totalDue,
            ]
        ]);
    }

    public function employeeReport($id)
    {

        // Fetch the employee details along with their own invoices
        $employee = Employee::with('invoices.customer', 'designation')->findOrFail($id);

        // Initialize an empty collection for all related invoices
        $allInvoices = collect([]);

        // If the employee is an RSM
        if ($employee->designation->slug == 'rsm') {
            // Get all Managers related to the RSM
            $managers = DB::table('relations')
                ->where('relation_id', $id)
                ->pluck('employee_id'); // Get related Manager IDs

            // Get all Officers under these Managers
            $officers = DB::table('relations')
                ->whereIn('relation_id', $managers)
                ->pluck('employee_id'); // Get related Officer IDs

            // Get invoices for all Officers
            $officerInvoices = Invoice::whereIn('employee_id', $officers)->get();

            // Merge all officer invoices
            $allInvoices = $allInvoices->merge($officerInvoices);
        }

        // If the employee is a Manager
        elseif ($employee->designation->slug == 'manager') {
            // Get all Officers under this Manager
            $officers = DB::table('relations')
                ->where('relation_id', $id)
                ->pluck('employee_id'); // Get related Officer IDs

            // Get invoices for Officers under the Manager
            $officerInvoices = Invoice::whereIn('employee_id', $officers)->get();

            // Merge all officer invoices
            $allInvoices = $allInvoices->merge($officerInvoices);
        }

        // If the employee is an Officer or any other type
        else {
            // Only fetch their own invoices
            $allInvoices = $allInvoices->merge($employee->invoices);
        }

        // Return the response with employee details and all related invoices
        return response()->json([
            'status' => true,
            'message' => 'Employee and related invoices retrieved successfully',
            'data' => [
                'employee' => $employee, // Employee details
                'invoices' => $allInvoices // Only officer invoices
            ]
        ]);
    }

    public function supplierReport($id)
    {
        // Retrieve the supplier data with its stockInOuts relationship
        $supplier = Supplier::with('stockInOuts.product:id,name')->findOrFail($id);

        // 2. Supplier-wise total purchase amount (for the specific supplier)
        $supplierPurchases = DB::table('stock_in_outs')
            ->join('suppliers', 'stock_in_outs.supplier_id', '=', 'suppliers.id')
            ->where('stock_in_outs.supplier_id', $id)
            ->selectRaw('SUM(stock_in_outs.quantity * stock_in_outs.buy_price) AS total_purchase')
            ->first(); // Get only one supplier's data

        // 3. Supplier-wise total payment amount (for the specific supplier)
        $supplierPayments = DB::table('payments')
            ->where('supplier_id', $id)
            ->selectRaw('SUM(payments.amount) AS total_payment')
            ->first(); // Get only one supplier's payment data

        // Check if purchases or payments data is missing
        $totalPurchase = $supplierPurchases->total_purchase ?? 0;
        $totalPayment = $supplierPayments->total_payment ?? 0;

        // Calculate net due (purchase - payment)
        $netDue = $totalPurchase - $totalPayment;

        // Return the result in JSON format
        return response()->json([
            'status' => true,
            'message' => 'Supplier Report retrieved successfully',
            'data' => [
                'supplier' => $supplier,
                'total_purchase' => $totalPurchase,
                'total_payment' => $totalPayment,
                'net_due' => $netDue,
            ]
        ]);
    }

    public function brandReport($id)
    {
        // Total sales amount for the brand
        $salesByBrand = Invoice::join('invoice_products', 'invoices.id', '=', 'invoice_products.invoice_id')
            ->join('products', 'invoice_products.product_id', '=', 'products.id')
            ->join('brands', 'products.brand_id', '=', 'brands.id')
            ->where('brands.id', $id) // Filter by brand ID
            ->groupBy('brands.id', 'brands.name')
            ->selectRaw('brands.name, SUM(invoice_products.quantity * invoice_products.unit_price) as total_sales')
            ->first(); // Get single brand sales data

        // Get total quantity of each product sold under this brand along with sale dates
        $productSales = Invoice::join('invoice_products', 'invoices.id', '=', 'invoice_products.invoice_id')
            ->join('products', 'invoice_products.product_id', '=', 'products.id')
            ->where('products.brand_id', $id) // Filter by brand ID
            ->groupBy('products.id', 'products.name', 'invoices.sale_date')
            ->orderBy('invoices.sale_date', 'asc') // Order by sale date
            ->selectRaw('products.name, invoices.sale_date, SUM(invoice_products.quantity) as total_quantity_sold')
            ->get(); // Get multiple products under the brand with sale dates

        if (!$salesByBrand) {
            return response()->json([
                'status' => false,
                'message' => 'No sales data found for this brand.',
                'data' => null
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Brand sales report retrieved successfully',
            'data' => [
                'brand_name' => $salesByBrand->name,
                'total_sales' => $salesByBrand->total_sales,
                'products_sold' => $productSales // List of products with sale date and total quantity
            ]
        ]);
    }


    public function categoryReport($id)
    {
        $salesByCategory = Invoice::join('invoice_products', 'invoices.id', '=', 'invoice_products.invoice_id')
            ->join('products', 'invoice_products.product_id', '=', 'products.id')
            ->join('categories', 'products.cat_id', '=', 'categories.id')
            ->where('categories.id', $id) // Filter by category ID
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.name, SUM(invoice_products.quantity * invoice_products.unit_price) as total_sales')
            ->first(); // Get single category sales data

        // Get total quantity of each product sold under this category along with sale dates
        $productSales = Invoice::join('invoice_products', 'invoices.id', '=', 'invoice_products.invoice_id')
            ->join('products', 'invoice_products.product_id', '=', 'products.id')
            ->where('products.cat_id', $id) // Filter by category ID
            ->groupBy('products.id', 'products.name', 'invoices.sale_date')
            ->orderBy('invoices.sale_date', 'asc') // Order by sale date
            ->selectRaw('products.name, invoices.sale_date, SUM(invoice_products.quantity) as total_quantity_sold')
            ->get(); // Get multiple products under the category with sale dates

        if (!$salesByCategory) {
            return response()->json([
                'status' => false,
                'message' => 'No sales data found for this category.',
                'data' => null
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Category sales report retrieved successfully',
            'data' => [
                'category_name' => $salesByCategory->name,
                'total_sales' => $salesByCategory->total_sales,
                'products_sold' => $productSales // List of products with sale date and total quantity
            ]
        ]);
    }

    public function dueInvoice(Request $request)
    {
        $currentDate = Carbon::now();

        // Get filters
        $daysFilter = $request->days; // 30,45,60,90
        $fromDate = $request->from_date;
        $toDate = $request->to_date;

        // Base query: only due invoices
        $invoicesQuery = Invoice::with('employee:id,name', 'customer:id,customer_name')
            ->where('due', '>', 0);

        // -------------------------
        // 🔥 FILTER 1: PREDEFINED DAYS (30,45,60,90)
        // -------------------------
        if ($daysFilter) {

            $startDate = Carbon::now()->subDays($daysFilter)->startOfDay();
            $endDate = Carbon::now()->endOfDay();

            $invoicesQuery->whereBetween('sale_date', [$startDate, $endDate]);
        }


        // -------------------------
        // 🔥 FILTER 2: CUSTOM DATE RANGE
        // -------------------------
        if ($fromDate && $toDate) {
            $invoicesQuery->whereBetween('sale_date', [
                Carbon::parse($fromDate),
                Carbon::parse($toDate)
            ]);
        }

        // Fetch
        $invoices = $invoicesQuery->get();

        if ($invoices->isNotEmpty()) {

            // attach days_since_sale
            $invoicesWithDays = $invoices->map(function ($invoice) use ($currentDate) {
                $saleDate = Carbon::parse($invoice->sale_date);
                $invoice->days_since_sale = $saleDate->diffInDays($currentDate);
                return $invoice;
            });

            return response()->json([
                'status' => true,
                'message' => 'Due invoices retrieved successfully.',
                'data' => $invoicesWithDays
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'No due invoices found for the selected filter.',
        ]);
    }


    public function dashboardReport()
    {
        try {
            $data = [
                'total_employee' => Employee::where('status', 'active')->count(),
                'total_customer' => Customer::count(),
                'total_supplier' => Supplier::count(),
                'total_order' => Order::count()
            ];

            return response()->json([
                'status' => true,
                'message' => 'Dashboard data retrieved successfully.',
                'data' => $data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve dashboard data.',
                'error' => $e->getMessage()
            ]);
        }
    }


    public function generateProfitLossReport(Request $request)
    {
        // Get the date range from the request (e.g., from_date, to_date)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Calculate Total Sales (Revenue)
        $sales = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->sum('grand_total');

        $dues = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->sum('due');

        // 2. Calculate Total COGS (Cost of Goods Sold)
        $cogs = StockInOut::whereBetween('in_out_date', [$fromDate, $toDate])
            ->sum(DB::raw('quantity * buy_price'));

        // 3. Calculate Operating Expenses (Salary, Costs, etc.)
        $salaries = Salary::whereBetween('month_year', [$fromDate, $toDate])
            ->sum('paid_amount');

        $costs = Cost::whereBetween('cost_date', [$fromDate, $toDate])
            ->sum('amount');

        // 4. Calculate Net Profit (Total Revenue - Total COGS - Operating Expenses)
        $netProfit = $sales - $dues - $cogs - $salaries - $costs;

        // Return the result in JSON
        return response()->json([
            'sales' => $sales,
            'dues' => $dues,
            'cogs' => $cogs,
            'salaries' => $salaries,
            'costs' => $costs,
            'net_profit' => $netProfit
        ]);
    }

    public function nextMonthSalary()
    {
        try {
            // 🔹 Retrieve all employees with basic salary
            $employees = Employee::select('id', 'name', 'basic_salary')->get();

            if ($employees->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No employees found.',
                    'data' => [],
                ]);
            }

            $salaryData = [];

            foreach ($employees as $employee) {
                // 🔹 Last Paid Month ber kora
                $lastPaidMonth = Salary::where('employee_id', $employee->id)->max('month_year');

                if (!$lastPaidMonth) {
                    // 🔹 If No Previous Salary Data, Use Basic Salary from Employee Table
                    $salaryData[] = [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->name,
                        'month_year' => Carbon::now()->format('Y-m'), // Current Month
                        'basic_salary' => $employee->basic_salary,
                        'advance_amount' => 0,
                        'due_amount' => 0,
                        'payable_amount' => $employee->basic_salary
                    ];
                    continue;
                }

                // 🔹 Next Payable Month
                $nextPayableMonth = Carbon::createFromFormat('Y-m', $lastPaidMonth)->addMonth()->format('Y-m');

                // 🔹 Employee Last Month Salary Calculation
                $salaryInfo = Salary::where('employee_id', $employee->id)
                    ->where('month_year', $lastPaidMonth)
                    ->select(
                        'basic_salary',
                        DB::raw("IF(advance_amount > 0, advance_amount, 0) as advance_amount"),
                        DB::raw("IF(due_amount > 0, due_amount, 0) as due_amount")
                    )
                    ->first();

                if (!$salaryInfo) {
                    $salaryData[] = [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->name,
                        'month_year' => $nextPayableMonth,
                        'basic_salary' => $employee->basic_salary,
                        'advance_amount' => 0,
                        'due_amount' => 0,
                        'payable_amount' => $employee->basic_salary
                    ];
                    continue;
                }

                // 🔹 Advance amount thakle due amount 0 hobe & vice versa
                $payableAmount = $salaryInfo->basic_salary - $salaryInfo->advance_amount + $salaryInfo->due_amount;

                $salaryData[] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'month_year' => $nextPayableMonth,
                    'basic_salary' => $salaryInfo->basic_salary,
                    'advance_amount' => $salaryInfo->advance_amount,
                    'due_amount' => $salaryInfo->due_amount,
                    'payable_amount' => $payableAmount
                ];
            }

            // 🔹 API Response
            return response()->json([
                'status' => true,
                'message' => "Next month salary details for all employees",
                'data' => $salaryData,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve salary details.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function lowStockAlerts(Request $request)
    {
        try {
            // Get the low stock threshold from the request, or use default (10)
            $lowStockThreshold = $request->input('threshold', 10);

            $lowStockProducts = Product::where('quantity', '<=', $lowStockThreshold)
                ->select('id', 'name', 'quantity')
                ->get()
                ->map(function ($product) {
                    return [
                        'product_name' => $product->name,
                        'current_stock' => $product->quantity,
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Low stock products retrieved successfully.',
                'low_stock_alerts' => $lowStockProducts,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve low stock alerts.',
                'error' => $e->getMessage(),
            ]);
        }
    }




    public function generateSalesReport(Request $request)
    {
        // Get the date range from the request (e.g., from_date, to_date)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Calculate Total Sales
        $totalSales = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->sum('grand_total');

        // 2. Calculate Sales by Product/Category
        $salesByProduct = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->join('invoice_products', 'invoices.id', '=', 'invoice_products.invoice_id')
            ->join('products', 'invoice_products.product_id', '=', 'products.id')
            ->groupBy('products.name')
            ->selectRaw('products.name, SUM(invoice_products.quantity * invoice_products.unit_price) as total_sales')
            ->get();

        $salesByCategory = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->join('invoice_products', 'invoices.id', '=', 'invoice_products.invoice_id')
            ->join('products', 'invoice_products.product_id', '=', 'products.id')
            ->join('categories', 'products.cat_id', '=', 'categories.id')
            ->groupBy('categories.name')
            ->selectRaw('categories.name, SUM(invoice_products.quantity * invoice_products.unit_price) as total_sales')
            ->get();

        // 3. Sales by Customer
        $salesByCustomer = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->join('customers', 'invoices.cust_id', '=', 'customers.id')
            ->groupBy('customers.customer_name')
            ->selectRaw('customers.customer_name, SUM(invoices.grand_total) as total_sales')
            ->get();

        // 4. Sales by Employee
        $salesByEmployee = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->join('employees', 'invoices.employee_id', '=', 'employees.id')
            ->groupBy('employees.name')
            ->selectRaw('employees.name, SUM(invoices.grand_total) as total_sales')
            ->get();

        // Return the result in JSON
        return response()->json([
            'total_sales' => $totalSales,
            'sales_by_product' => $salesByProduct,
            'sales_by_category' => $salesByCategory,
            'sales_by_customer' => $salesByCustomer,
            'sales_by_employee' => $salesByEmployee,
        ]);
    }

    public function generatePaymentReport(Request $request)
    {
        // Get the date range from the request (e.g., from_date, to_date)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Total Payments Received
        $totalPaymentsReceived = Payment::whereBetween('payment_date', [$fromDate, $toDate])
            ->sum('amount');

        // 2. Outstanding Dues (Total unpaid amount)
        $outstandingDues = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->where('due', '>', 0)
            ->sum('due');

        // 3. Paid Amount vs Due Amount (For each customer)
        $paymentsVsDues = Customer::with(['invoices' => function ($query) use ($fromDate, $toDate) {
            $query->whereBetween('sale_date', [$fromDate, $toDate]);
        }])->get()->map(function ($customer) {
            $grand_total = $customer->invoices->sum('grand_total');
            $totalPaid = $customer->invoices->sum('paid');
            $totalDue = $customer->invoices->sum('due');
            return [
                'customer_name' => $customer->customer_name,
                'grand_total' => $grand_total,
                'total_paid' => $totalPaid,
                'total_due' => $totalDue,
            ];
        });

        // 4. Customer-wise Outstanding Payments
        $outstandingPayments = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->where('invoices.due', '>', 0)
            ->join('customers', 'invoices.cust_id', '=', 'customers.id')
            ->groupBy('customers.customer_name')
            ->selectRaw('customers.customer_name, SUM(invoices.due) as total_due')
            ->get();

        // Return the result in JSON
        return response()->json([
            'total_payments_received' => $totalPaymentsReceived,
            'outstanding_dues' => $outstandingDues,
            'payments_vs_dues' => $paymentsVsDues,
            'outstanding_payments_by_customer' => $outstandingPayments,
        ]);
    }

    public function generateInventoryReport(Request $request)
    {
        // Get the date range from the request (e.g., from_date, to_date)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Current Stock Level (Available Quantity)
        $currentStockLevels = Product::select('id', 'name', 'quantity')
            ->get()
            ->map(function ($product) {
                return [
                    'product_name' => $product->name,
                    'current_stock' => $product->quantity,
                ];
            });

        // 2. Stock In/Out Movements
        $stockMovements = StockInOut::whereBetween('in_out_date', [$fromDate, $toDate])
            ->select('product_id', 'in_out', 'quantity', 'in_out_date')
            ->get()
            ->groupBy('product_id')
            ->map(function ($movements) {
                return [
                    'stock_in' => $movements->where('in_out', 'in')->sum('quantity'),
                    'stock_out' => $movements->where('in_out', 'out')->sum('quantity'),
                    'total_stock_movement' => $movements->sum('quantity'),
                ];
            });

        // 3. Low Stock Alerts (Define a threshold, e.g., 10 items)
        $lowStockThreshold = 10;
        $lowStockProducts = Product::where('quantity', '<=', $lowStockThreshold)
            ->select('id', 'name', 'quantity')
            ->get()
            ->map(function ($product) {
                return [
                    'product_name' => $product->name,
                    'current_stock' => $product->quantity,
                ];
            });

        // Return the result in JSON
        return response()->json([
            'current_stock_levels' => $currentStockLevels,
            'stock_movements' => $stockMovements,
            'low_stock_alerts' => $lowStockProducts,
        ]);
    }

    public function generateEmployeePerformanceReport(Request $request)
    {
        // Get the date range from the request (e.g., from_date, to_date)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Employee Salary Details
        $salaryDetails = Salary::whereBetween('created_at', [$fromDate, $toDate])
            ->with('employee') // Assuming the relationship is defined
            ->get()
            ->map(function ($salary) {
                return [
                    'employee_name' => $salary->employee->name,
                    'basic_salary' => $salary->basic_salary,
                    'paid_amount' => $salary->paid_amount,
                    'advance_amount' => $salary->advance_amount,
                    'due_amount' => $salary->due_amount,
                ];
            });

        // 2. Employee Performance (Sales made by each employee)
        $employeePerformance = Invoice::whereBetween('sale_date', [$fromDate, $toDate])
            ->join('employees', 'invoices.employee_id', '=', 'employees.id')
            ->selectRaw('employees.name as employee_name, SUM(invoices.grand_total) as total_sales')
            ->groupBy('employees.name')
            ->get();

        // 3. Salary Due/Paid Amount
        $salaryDuePaid = Salary::whereBetween('created_at', [$fromDate, $toDate])
            ->with('employee')
            ->get()
            ->map(function ($salary) {
                return [
                    'employee_name' => $salary->employee->name,
                    'salary_due' => $salary->due_amount,
                    'salary_paid' => $salary->paid_amount,
                ];
            });

        // Return the report data as JSON
        return response()->json([
            'salary_details' => $salaryDetails,
            'employee_performance' => $employeePerformance,
            'salary_due_paid' => $salaryDuePaid,
        ]);
    }



    public function generateExpenseReport(Request $request)
    {
        // Get the date range from the request (e.g., from_date, to_date)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Total Operational Costs (Sum of all costs)
        $totalOperationalCosts = Cost::whereBetween('cost_date', [$fromDate, $toDate])
            ->sum('amount');

        // 2. Costs by Categories
        $costByCategories = Cost::whereBetween('cost_date', [$fromDate, $toDate])
            ->join('cost_categories', 'costs.cost_cat_id', '=', 'cost_categories.id')
            ->selectRaw('cost_categories.name as category, SUM(costs.amount) as total_cost')
            ->groupBy('cost_categories.name')
            ->get();


        // 4. Salaries as part of Expenses
        $totalSalariesPaid = Salary::whereBetween('created_at', [$fromDate, $toDate])
            ->sum('paid_amount');

        // Return the report data as JSON
        return response()->json([
            'total_operational_costs' => $totalOperationalCosts,
            'cost_by_categories' => $costByCategories,
            'total_salaries_paid' => $totalSalariesPaid,
        ]);
    }

    public function generateCashFlowReport(Request $request)
    {
        // Get the date range from the request (e.g., from_date, to_date)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Cash Inflows (Total Payments Received from Customers)
        $cashInflows = Payment::whereBetween('payment_date', [$fromDate, $toDate])
            ->sum('amount');

        // 2. Cash Outflows
        // - Payments made to suppliers
        $supplierPayments = Payment::whereBetween('payment_date', [$fromDate, $toDate])
            ->whereNotNull('supplier_id')
            ->sum('amount');

        // - Salaries Paid to Employees
        $salariesPaid = Salary::whereBetween('created_at', [$fromDate, $toDate])
            ->sum('paid_amount');

        // - Other Business Expenses (from costs table)
        $businessExpenses = Cost::whereBetween('cost_date', [$fromDate, $toDate])
            ->sum('amount');

        // 3. Total Cash Outflows (Sum of all outflows)
        $totalCashOutflows = $supplierPayments + $salariesPaid + $businessExpenses;

        // 4. Net Cash Flow (Cash Inflows - Cash Outflows)
        $netCashFlow = $cashInflows - $totalCashOutflows;

        // Return the report data as JSON
        return response()->json([
            'cash_inflows' => $cashInflows,
            'cash_outflows' => [
                'supplier_payments' => $supplierPayments,
                'salaries_paid' => $salariesPaid,
                'business_expenses' => $businessExpenses,
                'total_outflows' => $totalCashOutflows
            ],
            'net_cash_flow' => $netCashFlow
        ]);
    }

    public function generateAccountsReport(Request $request)
    {
        // Get the date range from the request (optional)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Accounts Receivable (Total Outstanding Dues from Customers)
        $accountsReceivable = Invoice::when($fromDate && $toDate, function ($query) use ($fromDate, $toDate) {
            return $query->whereBetween('sale_date', [$fromDate, $toDate]);
        })
            ->where('due', '>', 0)
            ->sum('due');

        // 2. Accounts Payable (Total Outstanding Dues to Suppliers)
        $accountsPayable = Payment::when($fromDate && $toDate, function ($query) use ($fromDate, $toDate) {
            return $query->whereBetween('payment_date', [$fromDate, $toDate]);
        })
            ->whereNotNull('supplier_id')
            ->sum('amount');

        // 3. Receivables vs Payables (Difference)
        $netReceivablePayable = $accountsReceivable - $accountsPayable;

        // 4. Customer-wise Outstanding Receivables
        $customerReceivables = Invoice::where('invoices.due', '>', 0)
            ->join('customers', 'invoices.cust_id', '=', 'customers.id')
            ->groupBy('customers.customer_name')
            ->selectRaw('customers.customer_name, SUM(invoices.due) as total_due')
            ->get();

        // 5. Supplier-wise Outstanding Payables
        $supplierPayables = Payment::whereNotNull('supplier_id')
            ->join('suppliers', 'payments.supplier_id', '=', 'suppliers.id')
            ->groupBy('suppliers.company_name')
            ->selectRaw('suppliers.company_name, SUM(payments.amount) as total_pay')
            ->get();

        // Return the report data as JSON
        return response()->json([
            'accounts_receivable' => $accountsReceivable,
            'accounts_payable' => $accountsPayable,
            'net_receivable_vs_payable' => $netReceivablePayable,
            'customer_outstanding_dues' => $customerReceivables,
            'supplier_outstanding_pay' => $supplierPayables,
        ]);
    }

    public function generateProductProfitabilityReport(Request $request)
    {
        // Get the date range from the request
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // Fetch product profitability
        $productProfitability = DB::table('invoice_products')
            ->join('products', 'invoice_products.product_id', '=', 'products.id')
            ->join('invoices', 'invoice_products.invoice_id', '=', 'invoices.id')
            ->whereBetween('invoices.sale_date', [$fromDate, $toDate])
            ->groupBy('products.id', 'products.name')
            ->selectRaw(
                'products.name AS product_name,
                 SUM(invoice_products.quantity * invoice_products.unit_price) AS total_revenue,
                 SUM(invoice_products.quantity * products.buy_price) AS total_cogs,
                 (SUM(invoice_products.quantity * invoice_products.unit_price) - SUM(invoice_products.quantity * products.buy_price)) AS total_profit,
                 ((SUM(invoice_products.quantity * invoice_products.unit_price) - SUM(invoice_products.quantity * products.buy_price)) / SUM(invoice_products.quantity * invoice_products.unit_price)) * 100 AS profit_margin'
            )
            ->get();

        // Return JSON response
        return response()->json([
            'product_profitability' => $productProfitability
        ]);
    }


    public function generateSupplierReport(Request $request)
    {
        // Get the date range from the request (e.g., from_date, to_date)
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // 1. Total Payments Made (Total payments to suppliers)
        $totalPaymentsMade = Payment::whereBetween('payment_date', [$fromDate, $toDate])
            ->whereNotNull('supplier_id')
            ->sum('amount');

        // 2. Supplier-wise total purchase amount
        $supplierPurchases = DB::table('stock_in_outs')
            ->join('suppliers', 'stock_in_outs.supplier_id', '=', 'suppliers.id')
            ->whereBetween('stock_in_outs.in_out_date', [$fromDate, $toDate])
            ->groupBy('suppliers.id', 'suppliers.company_name')
            ->selectRaw('suppliers.id, suppliers.company_name AS supplier_name, 
                     SUM(stock_in_outs.quantity * stock_in_outs.buy_price) AS total_purchase')
            ->get();

        // 3. Supplier-wise total payment amount
        $supplierPayments = DB::table('payments')
            ->join('suppliers', 'payments.supplier_id', '=', 'suppliers.id')
            ->whereBetween('payments.payment_date', [$fromDate, $toDate])
            ->groupBy('suppliers.id', 'suppliers.company_name')
            ->selectRaw('suppliers.id, suppliers.company_name AS supplier_name, 
                     SUM(payments.amount) AS total_payment')
            ->get();

        // 4. Merging the two datasets (purchases and payments)
        $report = $supplierPurchases->map(function ($purchase) use ($supplierPayments) {
            // Find the matching payment record by supplier ID
            $payment = $supplierPayments->firstWhere('id', $purchase->id);
            return [
                'supplier_name' => $purchase->supplier_name,
                'total_purchase' => $purchase->total_purchase,
                'total_payment' => $payment->total_payment ?? 0, // Default 0 if no payment found
                'net_due' => $purchase->total_purchase - ($payment->total_payment ?? 0),
            ];
        });

        // 5. Supplier-wise Payments (For payments in date range)
        // $supplierPaymentsAndDues = Supplier::with(['payments' => function ($query) use ($fromDate, $toDate) {
        //     $query->whereBetween('payment_date', [$fromDate, $toDate]);
        // }])->get()->map(function ($supplier) {
        //     $totalPaid = $supplier->payments->sum('amount');
        //     return [
        //         'supplier_name' => $supplier->company_name,
        //         'total_paid' => $totalPaid,
        //     ];
        // });

        // Return the combined result in JSON
        return response()->json([
            'total_payments_made' => $totalPaymentsMade,
            'supplier_report' => $report,
            //'supplier_payments_and_dues' => $supplierPaymentsAndDues,
        ]);
    }



    public function customerReport2($customerId)
    {
        // Get the customer details
        $customer = Customer::findOrFail($customerId);

        // 1. Total Purchases (Total amount spent by the customer)
        $totalPurchases = Invoice::where('cust_id', $customerId)
            ->sum(DB::raw('grand_total')); // Total spent on all invoices

        // 2. Total Payments Made by the customer
        $totalPayments = Payment::where('cust_id', $customerId)
            ->sum('amount'); // Total payments made by the customer

        // 3. Outstanding Due (Total unpaid amount)
        $totalDue = Invoice::where('cust_id', $customerId)
            ->where('due', '>', 0)  // Filter only the unpaid dues
            ->sum('due'); // Total outstanding du        

        // Return JSON response with the customer report
        return response()->json([
            'status' => true,
            'message' => 'Customer report retrieved successfully',
            'data' => [
                'customer_name' => $customer->customer_name,
                'total_purchases' => $totalPurchases,
                'total_payments' => $totalPayments,
                'total_due' => $totalDue,
            ]
        ]);
    }




    public function report($id) {}
}
