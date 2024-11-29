<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
        $this->call(RolesAndPermissionsSeeder::class);   
        $this->call(UserSeeder::class);  
        $this->call(PaisSeeder::class);   
        $this->call(ProvinciaSeeder::class); 
        $this->call(CantonSeeder::class);
        $this->call(ParroquiaSeeder::class); 
        $this->call(TipoRegimenSeeder::class);  
        $this->call(ActividadEconomicaSeeder::class); 
        $this->call(ObligacionTributariaSeeder::class); 
    }
}