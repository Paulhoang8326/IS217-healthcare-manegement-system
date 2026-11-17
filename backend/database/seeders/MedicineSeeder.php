<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MedicineSeeder extends Seeder
{
    /**
     * Medicines inserted by V27__seed_medicine_data.sql. selling_price takes the
     * column default 0, as V30 added it after these rows existed.
     */
    public function run(): void
    {
        $rows = [
            ['Paracetamol 500mg', 'Paracetamol', 'Thuốc giảm đau, hạ sốt nhanh chóng'],
            ['Amoxicillin 500mg', 'Amoxicillin', 'Kháng sinh diệt khuẩn nhóm penicillin'],
            ['Ibuprofen 400mg', 'Ibuprofen', 'Thuốc giảm đau và kháng viêm không steroid (NSAID)'],
            ['Decolgen Forte', 'Paracetamol, Phenylephrine, Chlorpheniramine', 'Điều trị cảm cúm, nghẹt mũi và hắt hơi'],
            ['Panadol Extra', 'Paracetamol, Caffeine', 'Giảm đau đầu, đau cơ và đau răng hiệu quả'],
            ['Cetirizine 10mg', 'Cetirizine Hydrochloride', 'Thuốc kháng histamin trị dị ứng và sổ mũi'],
            ['Salbutamol 2mg', 'Salbutamol', 'Thuốc giãn phế quản điều trị hen suyễn'],
            ['Vitamin C 500mg', 'Acid Ascorbic', 'Tăng cường sức đề kháng và bổ sung vitamin C'],
        ];

        foreach ($rows as [$name, $ingredient, $description]) {
            DB::table('medicine')->insert([
                'medicine_name' => $name,
                'active_ingredient' => $ingredient,
                'unit' => 'Viên',
                'description' => $description,
                'is_active' => 1,
            ]);
        }
    }
}
