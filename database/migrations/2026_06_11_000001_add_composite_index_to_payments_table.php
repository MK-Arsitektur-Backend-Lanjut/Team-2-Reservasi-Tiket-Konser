<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan composite index (status, created_at) untuk optimasi
     * query payment berdasarkan status dengan sorting waktu.
     * Berguna sebagai fallback ketika Redis cache miss.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'idx_payments_status_created_at');
        });
    }

    /**
     * Hapus composite index.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_status_created_at');
        });
    }
};
