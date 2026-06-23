<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('invoice_number')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('subtotal_usd', 10, 4)->default(0);
            $table->decimal('margin_usd', 10, 4)->default(0);
            $table->decimal('total_usd', 10, 4)->default(0);
            $table->string('status')->default('draft'); // draft, sent, paid
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price_usd', 10, 4)->default(0);
            $table->decimal('total_usd', 10, 4)->default(0);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
