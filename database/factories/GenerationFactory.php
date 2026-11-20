<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_charge_nano' => 72_250_000,
            'max_charge_nano' => 106_250_000,
        ];
    }

    public function queued(?string $providerId = null): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'provider_id' => $providerId ?? 'gen_'.Str::ulid(),
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'submitted_at' => now(),
        ]);
    }

    public function succeeded(int $providerCostNano, int $chargeNano, ?string $completedAt = null): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'provider_cost_nano' => $providerCostNano,
            'charge_nano' => $chargeNano,
            'completed_at' => $completedAt ?? now(),
        ]);
    }
}
