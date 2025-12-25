<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CostController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalaryController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\CostCategoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\EmployeeCostCategoryController;

Route::post('/login', [UserController::class, 'login'])->name('user.login'); // Public Route

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // logout
    Route::post('/logout', [UserController::class, 'logout'])->name('user.logout');

    // Designation Routes
    Route::get('/designations', [DesignationController::class, 'index'])->name('designation.index');
    Route::get('/designationShow/{id}', [DesignationController::class, 'show'])->name('designation.show');
    Route::post('/designationStore', [DesignationController::class, 'store'])->name('designation.store');
    Route::post('/designationUpdate/{id}', [DesignationController::class, 'update'])->name('designation.update');
    Route::post('/designationDelete/{id}', [DesignationController::class, 'destroy'])->name('designation.destroy');

    // Employee Routes
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employee.index');
    Route::get('/employeeShow/{id}', [EmployeeController::class, 'show'])->name('employee.show');
    Route::post('/employeeStore', [EmployeeController::class, 'store'])->name('employee.store');
    Route::post('/employeeUpdate/{id}', [EmployeeController::class, 'update'])->name('employee.update');
    Route::post('/employeeDelete/{id}', [EmployeeController::class, 'destroy'])->name('employee.destroy');
    Route::get('/employees/credit-report', [EmployeeController::class, 'creditReport'])->name('employee.creditReport');

    // User Routes
    Route::get('/users', [UserController::class, 'index'])->name('user.index');
    Route::get('/userShow/{id}', [UserController::class, 'show'])->name('user.show');
    Route::post('/userStore', [UserController::class, 'store'])->name('user.store');
    Route::post('/userUpdate/{id}', [UserController::class, 'update'])->name('user.update');
    Route::post('/userDelete/{id}', [UserController::class, 'delete'])->name('user.delete');

    // Relation Routes
    Route::get('/getOfficers', [RelationController::class, 'getOfficers'])->name('employee.officer');
    Route::post('/getRelatedEmployees', [RelationController::class, 'getRelatedEmployees'])->name('employee.related');
    Route::post('/relationStore', [RelationController::class, 'store'])->name('relation.store');

    //customer 
    Route::get('customers', [CustomerController::class, 'index'])->name('customer.index');
    Route::get('customerByEmployee/{id}', [CustomerController::class, 'customerByEmployee'])->name('customer.customerByEmployee');
    Route::get('customerShow/{id}', [CustomerController::class, 'show'])->name('customer.show');
    Route::post('customerStore', [CustomerController::class, 'store'])->name('customer.store');
    Route::post('customerUpdate/{id}', [CustomerController::class, 'update'])->name('customer.update');
    Route::post('customerDelete/{id}', [CustomerController::class, 'destroy'])->name('customer.destroy');

    // brand 
    Route::get('brands', [BrandController::class, 'index'])->name('brand.index');
    Route::get('brandShow/{id}', [BrandController::class, 'show'])->name('brand.show');
    Route::post('brandStore', [BrandController::class, 'store'])->name('brand.store');
    Route::post('brandUpdate/{id}', [BrandController::class, 'update'])->name('brands.update');
    Route::post('brandDelete/{id}', [BrandController::class, 'destroy'])->name('brand.destroy');

    // category 
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categoryShow/{id}', [CategoryController::class, 'show'])->name('categories.show');
    Route::post('categoryStore', [CategoryController::class, 'store'])->name('categories.store');
    Route::post('categoryUpdate/{id}', [CategoryController::class, 'update'])->name('categories.update');
    Route::post('categoryDelete/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // suplier 
    Route::get('suppliers', [SupplierController::class, 'index'])->name('supplier.index');
    Route::get('supplierShow/{id}', [SupplierController::class, 'show'])->name('supplier.show');
    Route::post('supplierStore', [SupplierController::class, 'store'])->name('supplier.store');
    Route::post('supplierUpdate/{supplier}', [SupplierController::class, 'update'])->name('supplier.update');
    Route::post('supplierDelete/{supplier}', [SupplierController::class, 'destroy'])->name('supplier.destroy');

    // product
    Route::get('products', [ProductController::class, 'index'])->name('product.index');
    Route::get('productShow/{id}', [ProductController::class, 'show'])->name('product.show');
    Route::post('productStore', [ProductController::class, 'store'])->name('product.store');
    Route::post('productUpdate/{id}', [ProductController::class, 'update'])->name('product.update');
    Route::post('productDelete/{id}', [ProductController::class, 'destroy'])->name('product.destroy');

    //in out product
    Route::post('stock_in', [ProductController::class, 'stockIn'])->name('stock_in');
    Route::post('stock_out', [ProductController::class, 'stockOut'])->name('stock_out');
    Route::get('stockInOutHistory/{id}', [ProductController::class, 'stockInOutHistory'])->name('stockInOutHistory');

    // order
    Route::get('orders', [OrderController::class, 'index'])->name('order.index');
    Route::get('orderShow/{id}', [OrderController::class, 'show'])->name('order.show');
    Route::post('orderStore', [OrderController::class, 'store'])->name('order.store');
    Route::post('orderUpdate/{id}', [OrderController::class, 'update'])->name('order.update');
    Route::post('orderDelete/{id}', [OrderController::class, 'destroy'])->name('order.destroy');
    Route::post('orders/{order}/status', [OrderController::class, 'statusChange'])->name('order.status.change');

    // invoice
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoice.index');
    Route::get('salesByEmployee/{id}', [InvoiceController::class, 'salesByEmployee'])->name('invoice.salesByEmployee');
    Route::get('invoiceShow/{id}', [InvoiceController::class, 'show'])->name('invoice.show');
    Route::post('invoiceStore/{orderId?}', [InvoiceController::class, 'store'])->name('invoice.store');
    Route::post('invoiceUpdate/{id}', [InvoiceController::class, 'update'])->name('invoice.update');
    Route::post('invoiceDelete/{id}', [InvoiceController::class, 'destroy'])->name('invoice.destroy');
    Route::post('invoice/{id}/mark-printed', [InvoiceController::class, 'markPrinted'])->name('invoice.printed');


    // report
    Route::get('productReport/{id}', [ReportController::class, 'productReport'])->name('report.product');
    Route::get('customerReport/{id}', [ReportController::class, 'customerReport'])->name('report.customer');
    Route::get('customerWiseSalesReport', [ReportController::class, 'customerWiseSalesReport'])->name('report.customerWiseSales');
    Route::get('productWiseSalesReport', [ReportController::class, 'productWiseSalesReport'])->name('report.productWiseSales');
    Route::get('categoryWiseSalesReport', [ReportController::class, 'categoryWiseSalesReport'])->name('report.categoryWiseSales');

    Route::get('employeeReport/{id}', [ReportController::class, 'employeeReport'])->name('report.employee');
    Route::get('supplierReport/{id}', [ReportController::class, 'supplierReport'])->name('report.supplier');
    Route::get('dueInvoice', [ReportController::class, 'dueInvoice'])->name('report.dueInvoice');
    Route::get('cashCreditSale', [ReportController::class, 'cashCreditSale'])->name('report.cashCreditSale');

    // cost category 
    Route::get('costCategories', [CostCategoryController::class, 'index'])->name('costCategory.index');
    Route::get('costCategoryShow/{id}', [CostCategoryController::class, 'show'])->name('costCategory.show');
    Route::post('costCategoryStore', [CostCategoryController::class, 'store'])->name('costCategory.store');
    Route::post('costCategoryUpdate/{id}', [CostCategoryController::class, 'update'])->name('costCategory.update');
    Route::post('costCategoryDelete/{id}', [CostCategoryController::class, 'destroy'])->name('costCategory.destroy');

    // cost category 
    Route::get('employeeCostCategories', [EmployeeCostCategoryController::class, 'index'])->name('employeeCostCategory.index');
    Route::get('employeeCostCategoryShow/{id}', [EmployeeCostCategoryController::class, 'show'])->name('employeeCostCategory.show');
    Route::post('employeeCostCategoryStore', [EmployeeCostCategoryController::class, 'store'])->name('employeeCostCategory.store');
    Route::post('employeeCostCategoryUpdate/{id}', [EmployeeCostCategoryController::class, 'update'])->name('employeeCostCategory.update');
    Route::post('employeeCostCategoryDelete/{id}', [EmployeeCostCategoryController::class, 'destroy'])->name('employeeCostCategory.destroy');

    // payment
    Route::get('payments', [PaymentController::class, 'index'])->name('payment.index');
    Route::get('paymentShow/{id}', [PaymentController::class, 'show'])->name('payment.show');
    Route::get('custPaymentHistory/{custId}', [PaymentController::class, 'custPaymentHistory'])->name('payment.custPaymentHistory');
    Route::get('supplierPaymentHistory/{supplierId}', [PaymentController::class, 'supplierPaymentHistory'])->name('payment.supplierPaymentHistory');
    Route::post('paymentStore', [PaymentController::class, 'store'])->name('payment.store');
    Route::post('paymentUpdate/{id}', [PaymentController::class, 'update'])->name('payment.update');
    Route::post('paymentDelete/{id}', [PaymentController::class, 'destroy'])->name('payment.destroy');

    // cost 
    Route::get('costs', [CostController::class, 'index'])->name('cost.index');
    Route::get('costShow/{id}', [CostController::class, 'show'])->name('cost.show');
    Route::get('employeeCost/{employeeId}', [CostController::class, 'employeeCost'])->name('cost.employeeCost');
    Route::get('officeCost', [CostController::class, 'officeCost'])->name('cost.officeCost');
    Route::post('costStore', [CostController::class, 'store'])->name('cost.store');
    Route::post('costUpdate/{id}', [CostController::class, 'update'])->name('cost.update');
    Route::post('costDelete/{id}', [CostController::class, 'destroy'])->name('cost.destroy');

    // permission 
    Route::get('permissions', [PermissionController::class, 'index'])->name('permission.index');
    Route::get('permissionShow/{id}', [PermissionController::class, 'show'])->name('permission.show');
    Route::post('permissionStore', [PermissionController::class, 'store'])->name('permission.store');
    Route::post('permissionUpdate/{id}', [PermissionController::class, 'update'])->name('permission.update');
    Route::post('permissionDelete/{id}', [PermissionController::class, 'destroy'])->name('permission.destroy');

    // role 
    Route::get('roles', [RoleController::class, 'index'])->name('role.index');
    Route::get('roleShow/{id}', [RoleController::class, 'show'])->name('role.show');
    Route::post('roleStore', [RoleController::class, 'store'])->name('role.store');
    Route::post('roleUpdate/{id}', [RoleController::class, 'update'])->name('role.update');
    Route::post('roleDelete/{id}', [RoleController::class, 'destroy'])->name('role.destroy');
    Route::get('roles/{roleId}/give-permissions', [RoleController::class, 'addPermissionToRole'])->name('role.addPermissionToRole');
    Route::post('roles/{roleId}/give-permissions', [RoleController::class, 'givePermissionToRole'])->name('role.givePermissionToRole');

    // salary
    Route::get('salaries', [SalaryController::class, 'index'])->name('salary.index');
    Route::get('salaryShow/{id}', [SalaryController::class, 'show'])->name('salary.show');
    Route::get('employeeSalary/{employeeId}', [SalaryController::class, 'employeeSalary'])->name('salary.employeeSalary');
    Route::post('salaryStore', [SalaryController::class, 'store'])->name('salary.stpre');
    Route::post('salaryUpdate/{id}', [SalaryController::class, 'update'])->name('salary.update');
    Route::post('salaryDelete/{id}', [SalaryController::class, 'destroy'])->name('salary.destroy');



    // report
    Route::get('brandReport/{id}', [ReportController::class, 'brandReport'])->name('report.brand');
    Route::get('categoryReport/{id}', [ReportController::class, 'categoryReport'])->name('report.category');

    Route::get('/profit-loss-report', [ReportController::class, 'generateProfitLossReport']);
    Route::get('/next-payable-month-salary', [ReportController::class, 'nextMonthSalary']);
    Route::get('/dashboard-report', [ReportController::class, 'dashboardReport']);
    Route::get('/low_stock_alerts', [ReportController::class, 'lowStockAlerts']);
});




Route::get('/sales-report', [ReportController::class, 'generateSalesReport']);
Route::get('/customer-payment-report', [ReportController::class, 'generatePaymentReport']);
Route::get('/inventory-report', [ReportController::class, 'generateInventoryReport']);
Route::get('/employee-report', [ReportController::class, 'generateEmployeePerformanceReport']);
Route::get('/expense-report', [ReportController::class, 'generateExpenseReport']);
Route::get('/cashflow-report', [ReportController::class, 'generateCashFlowReport']);
Route::get('/account-report', [ReportController::class, 'generateAccountsReport']);
Route::get('/product-profitability-report', [ReportController::class, 'generateProductProfitabilityReport']);
Route::get('/supplier-report', [ReportController::class, 'generateSupplierReport']);
Route::get('/customer-report/{customerId}', [ReportController::class, 'customerReport2']);


// GET /api/due-invoices?from_date=2025-01-01&to_date=2025-01-31
// GET /api/due-invoices?days=90
// GET /api/orders?customer_id=5&employee_id=12
// GET /api/invoices?customer_id=10&employee_id=7

