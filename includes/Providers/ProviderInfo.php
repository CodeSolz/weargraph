<?php
/**
 * What the Settings screen shows about a provider.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Declarative catalog entry: identity, how to get a key, what it can be used for, its
 * settings fields and what data it receives. Built by DescribedProvider::info().
 */
final class ProviderInfo {

	/**
	 * Tasks a provider can be assigned to (Settings → AI Provider → Use for).
	 */
	public const TASK_STUDIO = 'studio';
	public const TASK_TRYON  = 'tryon';
	public const TASK_VISION = 'vision';

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Display name, e.g. "OpenAI".
	 *
	 * @var string
	 */
	public $label = '';

	/**
	 * Two letters for the card's badge.
	 *
	 * @var string
	 */
	public $initials = '';

	/**
	 * One sentence under the name.
	 *
	 * @var string
	 */
	public $tagline = '';

	/**
	 * Short badges, e.g. "55+ models", "Try-on".
	 *
	 * @var string[]
	 */
	public $badges = array();

	/**
	 * Where to create a key.
	 *
	 * @var string
	 */
	public $key_url = '';

	/**
	 * Hint under the key field.
	 *
	 * @var string
	 */
	public $key_help = '';

	/**
	 * Regex a key must match (format check before saving; '' = any).
	 *
	 * @var string
	 */
	public $key_pattern = '';

	/**
	 * Tasks (TASK_*) this provider can be used for.
	 *
	 * @var string[]
	 */
	public $tasks = array();

	/**
	 * Settings fields: name => array{label:string,type:string,choices?:array<string,string>,
	 * default?:mixed,description?:string,min?:float,max?:float,step?:float,live?:bool}.
	 * Types: select, number, text. "live" selects can be refreshed from the provider.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public $fields = array();

	/**
	 * What is sent to the provider (plain sentence).
	 *
	 * @var string
	 */
	public $data_note = '';

	/**
	 * Terms of service URL.
	 *
	 * @var string
	 */
	public $terms_url = '';

	/**
	 * Privacy policy URL.
	 *
	 * @var string
	 */
	public $privacy_url = '';

	/**
	 * Plugin that adds it ('free' or 'pro'), for ordering.
	 *
	 * @var string
	 */
	public $origin = 'free';

	/**
	 * Build from an array (unknown keys ignored).
	 *
	 * @param array<string,mixed> $data Values.
	 */
	public static function make( array $data ): self {
		$info = new self();
		foreach ( get_object_vars( $info ) as $key => $default ) {
			if ( array_key_exists( $key, $data ) ) {
				$info->$key = is_array( $default ) ? (array) $data[ $key ] : (string) $data[ $key ];
			}
		}
		if ( '' === $info->initials ) {
			$info->initials = strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $info->label ), 0, 2 ) );
		}

		return $info;
	}

	/**
	 * Whether a key looks right for this provider ('' pattern = anything non-empty).
	 *
	 * @param string $key Plain key.
	 */
	public function key_looks_valid( string $key ): bool {
		return '' !== $key && ( '' === $this->key_pattern || 1 === preg_match( $this->key_pattern, $key ) );
	}
}
