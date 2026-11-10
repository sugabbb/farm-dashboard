<?php
namespace App\Services;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Audit {
    public static function record(Model $model, string $action, mixed $before = null, ?string $reason = null, mixed $after = null, ?int $actorId = null, ?int $actorEmployeeId = null): void {
        $key=(string)$model->getKey(); $numericKey=ctype_digit($key);
        DB::table('audit_events')->insert([
            'actor_id'=>$actorId ?? auth()->id(), 'actor_employee_id'=>$actorEmployeeId, 'entity_type'=>$model->getTable(),
            'entity_id'=>$numericKey?(int)$key:null,'entity_uuid'=>$numericKey?null:$key,
            'action'=>$action, 'happened_at'=>now(), 'reason'=>$reason,
            'before'=>$before === null ? null : json_encode($before),
            'after'=>json_encode($after ?? $model->fresh()->toArray()), 'created_at'=>now(), 'updated_at'=>now(),
        ]);
    }
}
