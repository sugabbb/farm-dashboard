<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_events', function(Blueprint $table) {
            $table->uuid('entity_uuid')->nullable()->index()->after('entity_id');
        });
        DB::statement('ALTER TABLE audit_events ALTER COLUMN entity_id DROP NOT NULL');
        DB::statement('ALTER TABLE audit_events ADD CONSTRAINT audit_entity_key_check CHECK (entity_id IS NOT NULL OR entity_uuid IS NOT NULL)');
    }

    public function down(): void
    {
        if(DB::table('audit_events')->whereNotNull('entity_uuid')->exists()) throw new RuntimeException('Rollback ditolak: audit entity UUID akan hilang. Pertahankan migration ini saat rollback kode.');
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT IF EXISTS audit_entity_key_check');
        DB::statement('ALTER TABLE audit_events ALTER COLUMN entity_id SET NOT NULL');
        Schema::table('audit_events',fn(Blueprint $table)=>$table->dropColumn('entity_uuid'));
    }
};
