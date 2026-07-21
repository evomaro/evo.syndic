<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->date('allocated_on')->nullable()->after('allocation_order');
            $table->index(['lot_id', 'allocated_on'], 'pay_alloc_lot_effective_idx');
        });

        DB::table('payment_allocations')->orderBy('id')->each(function ($allocation) {
            DB::table('payment_allocations')->where('id', $allocation->id)->update([
                'allocated_on' => DB::table('payments')->where('id', $allocation->payment_id)->value('payment_date'),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropIndex('pay_alloc_lot_effective_idx');
            $table->dropColumn('allocated_on');
        });
    }
};
