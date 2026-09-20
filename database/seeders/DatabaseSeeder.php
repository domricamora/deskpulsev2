<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Only the demo organization. The skeleton's `Test User` factory call is
     * gone: `users` requires an `org_id`, so it fails against this schema, and
     * a user with no organization is not a state the application can reach.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
