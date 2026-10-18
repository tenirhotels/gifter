<?php

namespace Database\Factories;

use App\Models\Recipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Recipient> */
class RecipientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone' => fake()->numerify('+7 7## ### ## ##'),
            'email' => fake()->safeEmail(),
        ];
    }
}
