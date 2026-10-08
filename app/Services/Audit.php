<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\DB;

/** Audit trail: who did what and when.  Audit::log('void', 'ticket', $id, ['reason' => '...']); */
class Audit
{
    public static function log(string $action, ?string $entity = null, ?int $entityId = null, $details = null): void
    {
        DB::insert('audit_log', [
            'ts' => now(),
            'user_id' => Auth::id(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'details' => $details === null ? null : (is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE)),
        ]);
    }
}
