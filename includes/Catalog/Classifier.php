<?php
/**
 * Rule-based product classifier (decision X5).
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP (no WordPress calls) so it can be unit-tested and ported to the SaaS.
 *
 * Scoring: each source that mentions a category adds its weight once — category
 * names 1.0, title 0.8, tags 0.5, attribute values 0.3. In the title, the garment
 * word that appears last gets a head-noun bonus ("t-shirt dress" is a dress, "dress
 * shirt" is a shirt). Confidence drops when a runner-up in another body region is
 * close behind. Precision over recall: when unsure, it says so.
 */
final class Classifier {

	private const WEIGHTS = array(
		'category'  => 1.0,
		'title'     => 0.8,
		'tag'       => 0.5,
		'attribute' => 0.3,
	);

	private const HEAD_BONUS = 0.35;

	/**
	 * Vocabulary.
	 *
	 * @var array<string,array>
	 */
	private $categories;

	/**
	 * Exclusion phrases.
	 *
	 * @var string[]
	 */
	private $exclusions;

	/**
	 * Constructor.
	 *
	 * @param array<string,array>|null $categories Category vocabulary (defaults to Keywords::categories()).
	 * @param string[]|null            $exclusions Exclusion phrases.
	 */
	public function __construct( ?array $categories = null, ?array $exclusions = null ) {
		$this->categories = $categories ?? Keywords::categories();
		$this->exclusions = $exclusions ?? Keywords::exclusions();
	}

	/**
	 * Classify a product.
	 *
	 * @param string                 $title      Product title.
	 * @param string[]               $categories Category names.
	 * @param string[]               $tags       Tag names.
	 * @param array<string,string[]> $attributes Attribute label => values.
	 */
	public function classify( string $title, array $categories = array(), array $tags = array(), array $attributes = array() ): Classification {
		$result = new Classification();

		$title_n = self::normalize( $title );
		$sources = array(
			'category'  => array_map( array( self::class, 'normalize' ), $categories ),
			'title'     => array( $title_n ),
			'tag'       => array_map( array( self::class, 'normalize' ), $tags ),
			'attribute' => $this->attribute_texts( $attributes ),
		);

		$all_text = implode( ' | ', array_merge( $sources['category'], $sources['title'], $sources['tag'] ) );
		foreach ( $this->exclusions as $phrase ) {
			if ( self::contains( $all_text, self::normalize( $phrase ) ) ) {
				$result->evidence[] = 'excluded:' . $phrase;
				$this->decorate( $result, $title_n, $sources, $attributes );
				return $result;
			}
		}

		$scores   = array();
		$title_at = array(); // category => array( start, end ) of its right-most title match.
		foreach ( $this->categories as $key => $def ) {
			foreach ( $sources as $source => $texts ) {
				foreach ( (array) $def['keywords'] as $keyword ) {
					$needle = self::normalize( (string) $keyword );
					foreach ( $texts as $text ) {
						$pos = self::position( $text, $needle );
						if ( null === $pos ) {
							continue;
						}
						if ( ! isset( $scores[ $key ][ $source ] ) ) {
							$scores[ $key ][ $source ] = self::WEIGHTS[ $source ];
							$result->evidence[]        = $source . ':' . $keyword;
						}
						if ( 'title' === $source && ( ! isset( $title_at[ $key ] ) || $pos > $title_at[ $key ][0] ) ) {
							$title_at[ $key ] = array( $pos, $pos + strlen( ltrim( $needle, '*' ) ) );
						}
					}
				}
			}
		}

		// A title match inside a longer match of another category ("shirt" inside
		// "t-shirt") is not evidence for the shorter category.
		foreach ( $title_at as $key => $span ) {
			foreach ( $title_at as $other => $other_span ) {
				if ( $other !== $key && $other_span[0] <= $span[0] && $other_span[1] >= $span[1] && ( $other_span[1] - $other_span[0] ) > ( $span[1] - $span[0] ) ) {
					unset( $scores[ $key ]['title'], $title_at[ $key ] );
					if ( empty( $scores[ $key ] ) ) {
						unset( $scores[ $key ] );
					}
					break;
				}
			}
		}

		if ( ! $scores ) {
			$this->decorate( $result, $title_n, $sources, $attributes );
			return $result;
		}

		$totals = array();
		foreach ( $scores as $key => $by_source ) {
			$totals[ $key ] = array_sum( $by_source );
		}
		$head = self::head_category( $title_at, $title_n );
		if ( '' !== $head ) {
			$totals[ $head ] += self::HEAD_BONUS;
		}

		arsort( $totals );
		$keys  = array_keys( $totals );
		$best  = (string) $keys[0];
		$def   = $this->categories[ $best ];
		$score = $totals[ $best ];

		$confidence = min( 1.0, $score / 1.3 );
		if ( isset( $keys[1] ) ) {
			$runner       = (string) $keys[1];
			$other_region = $this->categories[ $runner ]['region'] !== $def['region'];
			$is_set       = isset( $title_at[ $best ], $title_at[ $runner ] ) && preg_match( '/(\s(and|&|\+|y|et|und|e)\s)|\bset\b|\bconjunto\b|\bensemble\b/u', $title_n );
			if ( $other_region && ( $totals[ $runner ] >= 0.8 * $score || $is_set ) ) {
				$confidence *= 0.6;
			}
		}

		$result->category   = $best;
		$result->region     = (string) $def['region'];
		$result->layer      = (string) $def['layer'];
		$result->tryon_mode = (string) $def['mode'];
		$result->confidence = round( $confidence, 3 );

		$this->decorate( $result, $title_n, $sources, $attributes );

		return $result;
	}

	/**
	 * Gender, colours and pattern (independent of the category).
	 *
	 * @param Classification         $result     Result to fill.
	 * @param string                 $title_n    Normalised title.
	 * @param array<string,string[]> $sources    Normalised sources.
	 * @param array<string,string[]> $attributes Raw attributes.
	 */
	private function decorate( Classification $result, string $title_n, array $sources, array $attributes ): void {
		$text = implode( ' | ', array_merge( $sources['category'], array( $title_n ), $sources['tag'] ) );

		foreach ( Keywords::genders() as $gender => $words ) {
			foreach ( $words as $word ) {
				if ( self::contains( $text, self::normalize( $word ) ) ) {
					$result->gender = $gender;
					break 2;
				}
			}
		}

		// Colours: colour attributes first (most reliable), then the title.
		$color_texts = array();
		foreach ( $attributes as $label => $values ) {
			if ( preg_match( '/colou?r|farbe|couleur|colore|cor\b/i', (string) $label ) ) {
				foreach ( (array) $values as $value ) {
					$color_texts[] = self::normalize( (string) $value );
				}
			}
		}
		$color_texts[] = $title_n;
		foreach ( Keywords::colors() as $canonical => $words ) {
			foreach ( $words as $word ) {
				foreach ( $color_texts as $color_text ) {
					if ( self::contains( $color_text, self::normalize( $word ) ) ) {
						$result->colors[] = $canonical;
						continue 3;
					}
				}
			}
		}
		// "Navy blue" is navy, not navy + blue.
		if ( in_array( 'navy', $result->colors, true ) && in_array( 'blue', $result->colors, true ) ) {
			$blue_alone = false;
			foreach ( $color_texts as $color_text ) {
				$without    = (string) preg_replace( '/\bnavy(\s|-)?blue\b/u', 'navy', $color_text );
				$blue_alone = $blue_alone || self::contains( $without, 'blue' );
			}
			if ( ! $blue_alone ) {
				$result->colors = array_values( array_diff( $result->colors, array( 'blue' ) ) );
			}
		}

		foreach ( Keywords::patterns() as $pattern => $words ) {
			foreach ( $words as $word ) {
				if ( self::contains( $text, self::normalize( $word ) ) ) {
					$result->pattern = $pattern;
					return;
				}
			}
		}
	}

	/**
	 * The title's head noun: normally the right-most garment word ("t-shirt dress" →
	 * dress), but when the next garment word follows a connector ("veste en jean",
	 * "camisa de lino", "jacket in denim") the earlier word is the head.
	 *
	 * @param array<string,array{0:int,1:int}> $title_at Right-most title match per category.
	 * @param string                           $title_n  Normalised title.
	 */
	private static function head_category( array $title_at, string $title_n ): string {
		if ( ! $title_at ) {
			return '';
		}

		uasort(
			$title_at,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		$keys = array_keys( $title_at );
		$head = (string) end( $keys );

		foreach ( $keys as $key ) {
			if ( $key === $head || $title_at[ $key ][1] > $title_at[ $head ][0] ) {
				continue;
			}
			$between = substr( $title_n, $title_at[ $key ][1], $title_at[ $head ][0] - $title_at[ $key ][1] );
			if ( preg_match( '/^(?:e?s)?\s+(de|en|di|da|du|del|des|in|with|con|avec|mit|aus)\s/u', $between ) ) {
				return (string) $key;
			}
		}

		return $head;
	}

	/**
	 * Normalised attribute values that describe the garment (not colour or size).
	 *
	 * @param array<string,string[]> $attributes Attributes.
	 * @return string[]
	 */
	private function attribute_texts( array $attributes ): array {
		$out = array();
		foreach ( $attributes as $label => $values ) {
			if ( preg_match( '/colou?r|farbe|couleur|size|talla|taille|grosse|größe|taglia/i', (string) $label ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$out[] = self::normalize( (string) $value );
			}
		}

		return $out;
	}

	/**
	 * Lower-case, fold common accents, normalise dashes and whitespace.
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		$text = mb_strtolower( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ), 'UTF-8' );
		$text = strtr(
			$text,
			array(
				'á' => 'a',
				'à' => 'a',
				'â' => 'a',
				'ä' => 'a',
				'ã' => 'a',
				'é' => 'e',
				'è' => 'e',
				'ê' => 'e',
				'ë' => 'e',
				'í' => 'i',
				'ì' => 'i',
				'î' => 'i',
				'ï' => 'i',
				'ó' => 'o',
				'ò' => 'o',
				'ô' => 'o',
				'ö' => 'o',
				'õ' => 'o',
				'ú' => 'u',
				'ù' => 'u',
				'û' => 'u',
				'ü' => 'u',
				'ñ' => 'n',
				'ç' => 'c',
				'ß' => 'ss',
				'’' => "'",
				'–' => '-',
				'—' => '-',
				'_' => ' ',
			)
		);

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Whether $needle occurs as whole word(s) (optional plural s/es).
	 *
	 * @param string $haystack Normalised text.
	 * @param string $needle   Normalised keyword.
	 */
	public static function contains( string $haystack, string $needle ): bool {
		return null !== self::position( $haystack, $needle );
	}

	/**
	 * Byte offset of the right-most whole-word match, or null.
	 *
	 * @param string $haystack Normalised text.
	 * @param string $needle   Normalised keyword.
	 */
	private static function position( string $haystack, string $needle ): ?int {
		if ( '' === $needle || '' === $haystack ) {
			return null;
		}

		// A leading "*" allows the keyword at the end of a longer word (German compounds:
		// "*jacke" matches "Winterjacke"). "t shirt", "t-shirt" and "tshirt" all match.
		$suffix  = 0 === strpos( $needle, '*' );
		$quoted  = str_replace( array( ' ', '\-' ), '[\s\-]?', preg_quote( ltrim( $needle, '*' ), '/' ) );
		$pattern = '/' . ( $suffix ? '' : '(?<![\p{L}\p{N}])' ) . $quoted . '(?:e?s|n)?(?![\p{L}\p{N}])/u';
		if ( ! preg_match_all( $pattern, $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$last = end( $m[0] );

		return (int) $last[1];
	}
}
