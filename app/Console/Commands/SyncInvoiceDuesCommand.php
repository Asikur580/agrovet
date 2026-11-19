<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\InvoiceDueService;

class SyncInvoiceDuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:sync-dues {--customer= : Specific customer ID to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync invoice dues across all customers using chronological FIFO allocation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $customerId = $this->option('customer');

        if ($customerId) {
            $this->info("Syncing invoice dues for customer ID: {$customerId}...");
            InvoiceDueService::syncCustomerInvoiceDues((int)$customerId);
            $this->info("Successfully synced invoices for customer ID: {$customerId}.");
            return Command::SUCCESS;
        }

        $this->info("Starting full synchronization of invoice dues via FIFO...");
        $startTime = microtime(true);

        $result = InvoiceDueService::syncAllInvoiceDues(function ($processed, $total) {
            $this->output->write("Processed {$processed}/{$total} customers...\r");
        });

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->newLine();
        $this->info("Sync completed in {$elapsed} seconds.");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Customers', $result['total_customers']],
                ['Total Invoices', $result['total_invoices']],
                ['Invoices Updated', $result['updated_invoices']],
            ]
        );

        return Command::SUCCESS;
    }
}
