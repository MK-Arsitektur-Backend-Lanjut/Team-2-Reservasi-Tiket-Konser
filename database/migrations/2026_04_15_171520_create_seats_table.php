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
        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->string('seat_number');
            $table->string('category');
            $table->decimal('price', 15, 2);
            $table->enum('status', ['available', 'hold', 'sold'])->default('available');
            $table->timestamps();

            // Optimasi performa untuk Modul 1:
            // Menambahkan composite index untuk kolom yang sering digunakan bersamaan saat pencarian kursi.
            // Query filter: where('venue_id', $venueId)->where('status', 'available')->where('category', $category)
            // Tanpa index ini, MySQL harus melakukan full-table scan pada 100.000+ data kursi, yang akan menyebabkan latensi ("kurang tanggap").
            $table->index(['venue_id', 'status', 'category'], 'idx_seats_venue_status_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
