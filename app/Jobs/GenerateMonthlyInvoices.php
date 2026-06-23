<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\LlmUsage;
use App\Models\Tenant\Incident;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMonthlyInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startOfMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfMonth = Carbon::now()->subMonth()->endOfMonth();

        $clients = Client::where('status', 'active')->get();

        foreach ($clients as $client) {
            // Generate LLM Usage items
            $llmUsages = LlmUsage::where('client_id', $client->id)
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->get()
                ->groupBy('model');

            if ($llmUsages->isEmpty()) {
                continue; // Skip if no usage
            }

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'invoice_number' => 'INV-' . strtoupper($client->slug) . '-' . $startOfMonth->format('Ym'),
                'period_start' => $startOfMonth->toDateString(),
                'period_end' => $endOfMonth->toDateString(),
                'status' => 'draft',
            ]);

            $subtotal = 0;

            foreach ($llmUsages as $model => $usages) {
                $totalCost = $usages->sum('estimated_cost_usd');
                if ($totalCost > 0) {
                    $invoice->items()->create([
                        'description' => "LLM Cost - {$model}",
                        'quantity' => 1,
                        'unit_price_usd' => $totalCost,
                        'total_usd' => $totalCost,
                    ]);
                    $subtotal += $totalCost;
                }
            }

            // Margin markup 20%
            $margin = $subtotal * 0.20;
            $total = $subtotal + $margin;

            $invoice->update([
                'subtotal_usd' => $subtotal,
                'margin_usd' => $margin,
                'total_usd' => $total,
            ]);
        }
    }
}
