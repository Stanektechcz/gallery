<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document for every payment taken.
 *
 * Separate from `payments` on purpose. A payment is what the gateway did; an invoice is
 * what the customer is owed as a record, and the two have different lifetimes — a payment
 * can be retried, refunded or fail, while an issued invoice number can never be reused.
 *
 * The number is unique across the whole table rather than per year, because a duplicate
 * invoice number is the kind of error that surfaces at an audit rather than at a screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) return;

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('number', 20)->unique();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gallery_space_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issued_to')->nullable()->constrained('users')->nullOnDelete();

            // Copied, not joined. An invoice must still read correctly when the plan it
            // was for has been renamed or the customer has changed their address.
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('CZK');
            $table->unsignedTinyInteger('vat_rate')->default(0);

            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
