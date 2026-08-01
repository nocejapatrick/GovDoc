<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use App\Models\OrgUnit;
use App\Models\Position;

class OrgStructureSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'document_focal']);

        $ictoOffice = OrgUnit::firstOrCreate(
            ['name' => 'ICT Office', 'type' => 'office'],
        );

        $divisions = [
            'IT Planning Management Division',
            'IT Operation Division',
            'E-TESDA',
        ];

        $positionTitles = [
            'Chief IT Officer III',
            'Supervisor IT Officer II',
            'Senior IT Officer I',
            'IT Officer II',
            'IT Officer I',
            'Administrative Assistant',
        ];

        foreach ($divisions as $index => $name) {
            $division = OrgUnit::firstOrCreate(
                ['name' => $name, 'type' => 'division', 'parent_id' => $ictoOffice->id],
            );

            foreach ($positionTitles as $rank => $title) {
                Position::firstOrCreate([
                    'title' => $title,
                    'org_unit_id' => $division->id,
                ], [
                    'rank' => $rank,
                ]);
            }
        }

        $this->command->info('Org structure seeded: ICT Office → 3 divisions × 6 positions.');
    }
}
