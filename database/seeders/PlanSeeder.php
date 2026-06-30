<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** Seeds the default plan catalogue (idempotent). Admins edit these from the back-office. */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'free', 'name' => 'Free', 'price' => 0, 'interval' => null, 'published_quota' => 1, 'is_public' => true, 'sort' => 1],
            ['key' => 'medium', 'name' => 'Medium', 'price' => 9, 'interval' => 'month', 'published_quota' => 5, 'is_public' => true, 'sort' => 2],
            ['key' => 'commercial', 'name' => 'Commercial', 'price' => null, 'interval' => 'custom', 'published_quota' => null, 'is_public' => true, 'sort' => 3],
        ];

        foreach ($defaults as $plan) {
            Plan::updateOrCreate(['key' => $plan['key']], $plan);
        }
    }
}
