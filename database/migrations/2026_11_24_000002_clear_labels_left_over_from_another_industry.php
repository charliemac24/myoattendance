<?php

use App\Support\OrganizationTerminology;
use App\Support\OrganizationTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Saving the Labels page right after an industry switch could store the old industry's six words as custom labels
     * (e.g. a school showing Members / Plans / Classes). Clear any organization whose saved labels are exactly another
     * industry's defaults, so its labels follow its own industry again.
     */
    public function up(): void
    {
        DB::table('organizations')->whereNotNull('terminology')->orderBy('id')->each(function (object $organization): void {
            $labels = json_decode((string) $organization->terminology, true);
            if (! is_array($labels) || count($labels) !== 6) {
                return;
            }
            foreach (OrganizationTypes::VALUES as $type) {
                $defaults = OrganizationTerminology::defaults($type);
                if ($type !== $organization->type && $labels == $defaults) {
                    DB::table('organizations')->where('id', $organization->id)->update(['terminology' => null]);

                    return;
                }
            }
        });
    }

    public function down(): void {}
};
