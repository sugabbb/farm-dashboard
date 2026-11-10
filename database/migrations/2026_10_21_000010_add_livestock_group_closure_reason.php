<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('livestock_groups', function (Blueprint $table) {
            $table->text('closure_reason')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('livestock_groups', function (Blueprint $table) {
            $table->dropColumn('closure_reason');
        });
    }
};
