<?php

namespace Database\Factories;

use App\Enums\UserGender;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserProfile>
 */
class UserProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->optional()->e164PhoneNumber(),
            'gender' => fake()->randomElement(UserGender::cases()),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'address_line1' => fake()->streetAddress(),
            'postal_code' => fake()->postcode(),
            'bio' => fake()->optional()->sentence(),
            'website' => fake()->optional()->url(),
            'locale' => 'vi',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'metadata' => [],
        ];
    }
}
