<?php

/*
 * One-time database setup for a fresh Curriculum MAP database.
 * Loads database/schema/mysql-schema.dump, runs newer migrations and the
 * reference-data seeders. Safe to re-run: each step is skipped if done.
 * Optional: SETUP_ADMIN_EMAIL marks that registered user as verified admin.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

header('Content-Type: text/plain; charset=utf-8');
$app->make(Kernel::class)->bootstrap();

try {
    // Managed MySQL (e.g. Aiven) may require primary keys; the legacy schema has keyless tables
    try {
        DB::statement('SET SESSION sql_require_primary_key = 0');
    } catch (Throwable $e) {
        echo 'Note: could not unset sql_require_primary_key: '.$e->getMessage()."\n";
    }

    // Load the schema dump unless a previous run completed it (dump drops tables first, so reruns are safe)
    if (! Schema::hasTable('migrations') || DB::table('migrations')->count() === 0) {
        $sql = file_get_contents(base_path('database/schema/mysql-schema.dump'));
        $statements = preg_split('/;\s*\n/', $sql);
        Schema::dropAllTables(); // clear leftovers from an earlier failed run
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                DB::unprepared($statement);
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        echo "Loaded schema dump (".count($statements)." statements)\n";
    } else {
        echo "Schema already present\n";
    }

    Artisan::call('migrate', ['--force' => true]);
    echo Artisan::output();

    if (DB::table('roles')->count() === 0) {
        $seeders = [
            'RoleSeeder', 'MappingScaleCategoriesSeeder', 'MappingScaleSeeder',
            'OptionalPrioritiesSeeder', 'StandardCategorySeeder', 'StandardSeeder',
            'StandardScaleCategorySeeder', 'StandardScaleSeeder',
            'OkanaganSyllabusResourceSeeder', 'VancouverSyllabusResourceSeeder',
            'CampusFacultyDepartmentSeeder',
        ];
        foreach ($seeders as $seeder) {
            Artisan::call('db:seed', ['--class' => "Database\\Seeders\\$seeder", '--force' => true]);
            echo "Seeded $seeder\n";
        }
    } else {
        echo "Reference data already seeded\n";
    }

    if ($email = getenv('SETUP_ADMIN_EMAIL')) {
        $user = DB::table('users')->where('email', $email)->first();
        if ($user) {
            DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
            $roleId = DB::table('roles')->where('role', 'administrator')->value('id');
            DB::table('role_user')->updateOrInsert(
                ['role_id' => $roleId, 'user_id' => $user->id],
                ['created_at' => now(), 'updated_at' => now()]
            );
            echo "Verified $email and granted administrator role\n";
        } else {
            echo "No registered user with $email yet; register first, then reload this page\n";
        }
    }

    echo "\nDone. Remove RUN_SETUP from Vercel when finished.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Setup failed: '.$e->getMessage()."\n";
}
