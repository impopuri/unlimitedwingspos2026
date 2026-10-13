<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categoryIds = [];
        foreach ([
            ['UNLIWINGS', 'unliwings'],
            ['MEALS', 'meals'],
            ['PLATTER', 'platter'],
            ['BILAO', 'bilao'],
            ['EXTRAS', 'extras'],
        ] as [$name, $slug]) {
            DB::table('categories')->insertOrIgnore([
                'slug' => $slug,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $categoryIds[$slug] = DB::table('categories')->where('slug', $slug)->value('id');
        }

        $products = [
            ['UNLI-A', 'Set A', 'unliwings', 38900, null, 0, ['Unlimited wings', 'Unlimited rice']],
            ['UNLI-B', 'Set B', 'unliwings', 39900, null, 0, ['Unlimited wings', 'Unlimited rice', 'Unlimited drinks']],
            ['UNLI-C', 'Set C', 'unliwings', 45900, null, 0, ['Unlimited wings', 'Unlimited fries', 'Unlimited drinks']],
            ['UNLI-D', 'Set D', 'unliwings', 24900, null, 0, ['Unlimited wings', 'Unlimited rice', 'Unlimited fries', 'Unlimited drinks']],
            ['MEAL-2', '2 pcs Wings', 'meals', 9900, 2, 1, []],
            ['MEAL-3', '3 pcs Wings', 'meals', 13000, 3, 1, []],
            ['MEAL-4', '4 pcs Wings', 'meals', 16000, 4, 2, []],
            ['MEAL-6R', '6 pcs Wings + 2 Rice', 'meals', 23900, 6, 2, []],
            ['MEAL-6F', '6 pcs Wings + Fries', 'meals', 24900, 6, 2, []],
            ['PLATTER-12', '12 pcs Wings', 'platter', 34900, 12, 3, []],
            ['PLATTER-24', '24 pcs Wings', 'platter', 68900, 24, 4, []],
            ['BILAO-36', '36 pcs Wings', 'bilao', 94900, 36, 4, []],
            ['BILAO-50', '50 pcs Wings', 'bilao', 136900, 50, 5, []],
            ['EXTRA-TEA', 'Red Iced Tea', 'extras', 3500, null, 0, []],
            ['EXTRA-RICE', 'Rice', 'extras', 2000, null, 0, []],
            ['EXTRA-FRIES', 'Fries', 'extras', 5000, null, 0, []],
            ['EXTRA-SAUCE', 'Sauce', 'extras', 3000, null, 0, []],
        ];
        foreach ($products as [$sku, $name, $category, $price, $wings, $flavors, $inclusions]) {
            DB::table('products')->insertOrIgnore([
                'sku' => $sku,
                'category_id' => $categoryIds[$category],
                'name' => $name,
                'price_cents' => $price,
                'wing_count' => $wings,
                'flavor_limit' => $flavors,
                'inclusions' => json_encode($inclusions),
                'available' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        foreach (['Buffalo', 'Ranch', 'Cheese', 'Salted Egg', 'Yangyeom', 'Chili Cheese', 'Garlic Mayo', 'Honey Sriracha', 'Garlic Parmesan', 'Honey Soy Garlic', 'Plain'] as $name) {
            DB::table('flavors')->insertOrIgnore([
                'name' => $name,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            ['WINGS', 'Chicken wings', 'pcs', 1000, 100],
            ['RICE', 'Rice', 'servings', 500, 50],
            ['FRIES', 'French fries', 'servings', 300, 30],
            ['DRINKS', 'Red iced tea', 'servings', 300, 30],
            ['SAUCE', 'Sauce', 'servings', 300, 30],
        ] as [$sku, $name, $unit, $quantity, $minimum]) {
            DB::table('inventory_items')->insertOrIgnore([
                'sku' => $sku,
                'name' => $name,
                'unit' => $unit,
                'quantity' => $quantity,
                'minimum_quantity' => $minimum,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        for ($number = 1; $number <= 6; $number++) {
            DB::table('dining_tables')->insertOrIgnore([
                'label' => 'Table '.$number,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            ['Admin', 'admin', 'superadmin'],
            ['Cashier', 'cashier', 'cashier'],
            ['Kitchen', 'kitchen', 'kitchen'],
        ] as [$name, $username, $role]) {
            DB::table('users')->insertOrIgnore([
                'name' => $name,
                'username' => $username,
                'password' => Hash::make('password'),
                'role' => $role,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
