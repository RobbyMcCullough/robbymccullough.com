<?php

namespace FL\DesignSystem\Generation;

/**
 * Static registry of AI model definitions and cost rates.
 */
class ModelRegistry {

	/**
	 * Model definitions keyed by model ID.
	 *
	 * Each entry contains:
	 * - provider (string)
	 * - input_cost_per_million (float)  — cost in USD per 1,000,000 input tokens
	 * - output_cost_per_million (float) — cost in USD per 1,000,000 output tokens
	 *
	 * @var array<string, array{provider: string, input_cost_per_million: float, output_cost_per_million: float}>
	 */
	private static array $models = [
		'claude-sonnet-4-6' => [
			'provider'                => 'anthropic',
			'input_cost_per_million'  => 3.00,
			'output_cost_per_million' => 15.00,
		],
		'claude-sonnet-4-5-20250514' => [
			'provider'                => 'anthropic',
			'input_cost_per_million'  => 3.00,
			'output_cost_per_million' => 15.00,
		],
		'claude-haiku-4-5-20251001' => [
			'provider'                => 'anthropic',
			'input_cost_per_million'  => 0.80,
			'output_cost_per_million' => 4.00,
		],
	];

	/**
	 * Returns the config for a given model, or null if not found.
	 *
	 * @param string $model Model ID.
	 * @return array{provider: string, input_cost_per_million: float, output_cost_per_million: float}|null
	 */
	public static function get( string $model ): ?array {
		return self::$models[ $model ] ?? null;
	}

	/**
	 * Calculates the USD cost for a given model and token counts.
	 *
	 * Returns 0.0 if the model is not found in the registry.
	 *
	 * @param string $model         Model ID.
	 * @param int    $input_tokens  Number of input tokens consumed.
	 * @param int    $output_tokens Number of output tokens generated.
	 * @return float Cost in USD.
	 */
	public static function get_cost( string $model, int $input_tokens, int $output_tokens ): float {
		$config = self::get( $model );

		if ( null === $config ) {
			return 0.0;
		}

		$input_cost  = ( $input_tokens / 1_000_000 ) * $config['input_cost_per_million'];
		$output_cost = ( $output_tokens / 1_000_000 ) * $config['output_cost_per_million'];

		return $input_cost + $output_cost;
	}

	/**
	 * Returns the full model registry.
	 *
	 * @return array<string, array{provider: string, input_cost_per_million: float, output_cost_per_million: float}>
	 */
	public static function get_all(): array {
		return self::$models;
	}
}
