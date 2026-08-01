<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Position;
use App\Models\User;

class OrgUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        Position::with('orgUnit')->get()->each(function (Position $position) {
            $user = User::factory()->create([
                'org_unit_id' => $position->org_unit_id,
                'position_id' => $position->id,
            ]);

            $user->assignRole('user');

            // The Administrative Assistant in each division becomes its focal.
            if ($position->title === 'Administrative Assistant') {
                $user->assignRole('document_focal');
            }
        });

        $this->command->info(
            'Seeded ' . Position::count() . ' users, one per position, with focals assigned.'
        );
    }
}
