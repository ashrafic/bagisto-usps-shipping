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
        Schema::create('usps_shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('tracking_number')->index();
            $table->string('service_code')->nullable();
            $table->string('status')->nullable();
            $table->string('estimated_delivery')->nullable();
            $table->string('label_path')->nullable();
            $table->decimal('label_cost', 12, 4)->nullable();
            $table->char('label_currency', 3)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->longText('raw')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usps_shipments');
    }
};
