<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BrandPlatform;
use Illuminate\Database\Seeder;

class BrandPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = BrandPlatform::PLATFORM_CONFIGS;

        foreach ($platforms as $code => $config) {
            BrandPlatform::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $config['name'],
                    'icon_color' => $config['icon_color'],
                    'is_enabled' => false,
                    'check_interval_minutes' => 60,
                    'api_config' => [],
                ]
            );
        }
    }
}
