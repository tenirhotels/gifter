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
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'phone' => fake()->numerify('+7 7## ### ## ##'),
            'email' => fake()->safeEmail(),
        ];
    }
}
