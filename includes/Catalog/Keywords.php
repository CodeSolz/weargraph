<?php
/**
 * Default classification vocabulary (multilingual).
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Category => region, layer, try-on mode and keywords. Keywords are lower-case and
 * accent-free (the classifier folds accents); a trailing "s"/"es" plural is matched
 * automatically. A leading "*" also matches at the end of a longer word (German
 * compounds such as "Winterjacke"). English plus common Spanish, French, German, Italian and Portuguese.
 * Filterable as a whole via weargraph_catalog_keywords (applied by the scan service).
 */
final class Keywords {

	/**
	 * Garment categories.
	 *
	 * @return array<string,array{label:string,region:string,layer:string,mode:string,keywords:string[]}>
	 */
	public static function categories(): array {
		return array(
			// Upper body, worn next to the body.
			'tshirt'   => self::cat( 'T-shirt', 'upper', 'inner', array( 't-shirt', 'tshirt', 't shirt', 'tee', 'tee-shirt', 'camiseta', 'maglietta', 'camisa de manga corta' ) ),
			'shirt'    => self::cat( 'Shirt', 'upper', 'inner', array( 'shirt', 'button-down', 'button down', 'oxford shirt', 'flannel', 'camisa', 'chemise', 'hemd', 'camicia' ) ),
			'blouse'   => self::cat( 'Blouse', 'upper', 'inner', array( 'blouse', 'blusa', 'bluse' ) ),
			'top'      => self::cat( 'Top', 'upper', 'inner', array( 'top', 'tank top', 'tank', 'camisole', 'cami', 'crop top', 'halter', 'bodysuit', 'debardeur', 'canotta' ) ),
			'polo'     => self::cat( 'Polo', 'upper', 'inner', array( 'polo', 'polo shirt' ) ),
			'sweater'  => self::cat( 'Sweater', 'upper', 'inner', array( 'sweater', 'jumper', 'pullover', 'knit', 'knitwear', 'turtleneck', 'sueter', 'jersey', 'strickpullover', 'maglione' ) ),
			'hoodie'   => self::cat( 'Hoodie / sweatshirt', 'upper', 'inner', array( 'hoodie', 'hooded sweatshirt', 'sweatshirt', 'sudadera', 'sweat', 'kapuzenpullover', 'felpa', 'moletom' ) ),

			// Outer layers.
			'jacket'   => self::cat( 'Jacket', 'outer', 'outer', array( 'jacket', 'bomber', 'windbreaker', 'shacket', 'overshirt', 'chaqueta', 'cazadora', 'veste', 'blouson', '*jacke', 'giacca', 'giubbotto', 'jaqueta' ) ),
			'coat'     => self::cat( 'Coat', 'outer', 'outer', array( 'coat', 'overcoat', 'trench', 'trenchcoat', 'parka', 'puffer', 'raincoat', 'abrigo', 'manteau', '*mantel', 'cappotto', 'casaco' ) ),
			'blazer'   => self::cat( 'Blazer', 'outer', 'outer', array( 'blazer', 'sport coat', 'americana', 'sakko' ) ),
			'cardigan' => self::cat( 'Cardigan', 'outer', 'outer', array( 'cardigan', 'cardigans', 'strickjacke', 'gilet en maille' ) ),
			'vest'     => self::cat( 'Vest / gilet', 'outer', 'outer', array( 'vest', 'gilet', 'waistcoat', 'body warmer', 'chaleco', 'weste' ) ),

			// Lower body.
			'trousers' => self::cat( 'Trousers', 'lower', 'base', array( 'trousers', 'pants', 'chinos', 'chino', 'slacks', 'joggers', 'sweatpants', 'cargo pants', 'pantalon', 'pantalones', '*hose', 'pantaloni', 'calca' ) ),
			'jeans'    => self::cat( 'Jeans', 'lower', 'base', array( 'jeans', 'jean', 'denim pants', 'vaqueros', 'vaquero' ) ),
			'shorts'   => self::cat( 'Shorts', 'lower', 'base', array( 'shorts', 'bermuda', 'bermudas', 'pantalon corto' ) ),
			'skirt'    => self::cat( 'Skirt', 'lower', 'base', array( 'skirt', 'miniskirt', 'falda', 'jupe', 'rock', 'gonna', 'saia' ) ),
			'leggings' => self::cat( 'Leggings', 'lower', 'base', array( 'leggings', 'legging', 'mallas' ) ),

			// One-piece.
			'dress'    => self::cat( 'Dress', 'one_piece', 'base', array( 'dress', 'gown', 'sundress', 'maxi dress', 'midi dress', 'vestido', 'robe', '*kleid', 'abito' ) ),
			'jumpsuit' => self::cat( 'Jumpsuit', 'one_piece', 'base', array( 'jumpsuit', 'romper', 'playsuit', 'overall', 'overalls', 'dungarees', 'mono', 'combinaison', 'macacao' ) ),

			// Footwear.
			'shoes'    => self::cat( 'Shoes', 'footwear', 'base', array( 'shoe', 'shoes', 'sneaker', 'sneakers', 'trainer', 'trainers', 'boot', 'boots', 'sandal', 'sandals', 'heels', 'loafer', 'loafers', 'flats', 'zapato', 'zapatos', 'zapatillas', 'chaussure', 'chaussures', '*schuhe', 'scarpe', 'botas', 'tenis' ) ),

			// Accessories (experimental placement).
			'hat'      => self::cat( 'Hat / cap', 'accessory', 'accessory', array( 'hat', 'cap', 'beanie', 'beret', 'bucket hat', 'gorra', 'sombrero', 'chapeau', 'casquette', 'mutze', 'cappello' ), 'accessory' ),
			'eyewear'  => self::cat( 'Eyewear', 'accessory', 'accessory', array( 'glasses', 'sunglasses', 'eyewear', 'spectacles', 'gafas', 'lunettes', 'brille', 'occhiali', 'oculos' ), 'accessory' ),
			'bag'      => self::cat( 'Bag', 'accessory', 'accessory', array( 'bag', 'handbag', 'backpack', 'tote', 'purse', 'clutch', 'crossbody', 'bolso', 'mochila', 'sac', 'tasche', 'borsa' ), 'accessory' ),
			'watch'    => self::cat( 'Watch', 'accessory', 'accessory', array( 'watch', 'wristwatch', 'reloj', 'montre', 'armbanduhr', 'orologio' ), 'accessory' ),
			'jewelry'  => self::cat( 'Jewellery', 'accessory', 'accessory', array( 'necklace', 'earring', 'earrings', 'bracelet', 'ring', 'pendant', 'jewelry', 'jewellery', 'pendientes', 'pulsera', 'anillo', 'bague', 'boucles', 'collier', 'kette', 'orecchini' ), 'accessory' ),
			'scarf'    => self::cat( 'Scarf', 'accessory', 'accessory', array( 'scarf', 'scarves', 'shawl', 'bufanda', 'echarpe', 'foulard', 'schal' ), 'accessory' ),
			'belt'     => self::cat( 'Belt', 'accessory', 'accessory', array( 'belt', 'cinturon', 'ceinture', 'gurtel', 'cintura' ), 'accessory' ),

			// Recognised but not offered for try-on (sensitive, or not visible when worn).
			'swimwear' => self::cat( 'Swimwear', 'upper', 'inner', array( 'swimsuit', 'bikini', 'swimwear', 'swim shorts', 'bathing suit', 'banador', 'maillot de bain', 'badeanzug' ), 'none' ),
			'lingerie' => self::cat( 'Lingerie / underwear', 'upper', 'inner', array( 'lingerie', 'underwear', 'bra', 'briefs', 'panties', 'boxers', 'thong', 'ropa interior', 'sous-vetements', 'unterwasche' ), 'none' ),
			'socks'    => self::cat( 'Socks', 'footwear', 'base', array( 'sock', 'socks', 'calcetines', 'chaussettes', 'socken' ), 'none' ),
		);
	}

	/**
	 * Phrases that mean "not a wearable" even when a garment word appears.
	 *
	 * @return string[]
	 */
	public static function exclusions(): array {
		return array( 'gift card', 'gift voucher', 'hanger', 'shoe polish', 'shoe care', 'shoe rack', 'lint roller', 'garment bag', 'sewing pattern', 'fabric by the metre', 'fabric by the yard', 'mannequin', 'poster', 'sticker', 'mug', 'phone case' );
	}

	/**
	 * Gender presentation keywords.
	 *
	 * @return array<string,string[]>
	 */
	public static function genders(): array {
		return array(
			'men'    => array( 'men', 'mens', "men's", 'man', 'male', 'hombre', 'hombres', 'homme', 'hommes', 'herren', 'uomo', 'masculino' ),
			'women'  => array( 'women', 'womens', "women's", 'woman', 'ladies', 'female', 'mujer', 'mujeres', 'femme', 'femmes', 'damen', 'donna', 'feminino' ),
			'kids'   => array( 'kids', 'kid', 'children', 'child', 'boys', 'girls', 'baby', 'ninos', 'enfant', 'enfants', 'kinder', 'bambini' ),
			'unisex' => array( 'unisex' ),
		);
	}

	/**
	 * Canonical colour => synonyms.
	 *
	 * @return array<string,string[]>
	 */
	public static function colors(): array {
		return array(
			'black'  => array( 'black', 'jet', 'onyx', 'negro', 'noir', 'schwarz', 'nero', 'preto' ),
			'white'  => array( 'white', 'ivory', 'off-white', 'off white', 'blanco', 'blanc', 'weiss', 'bianco', 'branco' ),
			'grey'   => array( 'grey', 'gray', 'charcoal', 'heather', 'slate', 'gris', 'grau', 'grigio', 'cinza' ),
			'red'    => array( 'red', 'burgundy', 'maroon', 'wine', 'crimson', 'rojo', 'rouge', 'rot', 'rosso', 'vermelho' ),
			'blue'   => array( 'blue', 'sky', 'royal', 'cobalt', 'azul', 'bleu', 'blau', 'blu' ),
			'navy'   => array( 'navy', 'marine', 'marino', 'dunkelblau' ),
			'green'  => array( 'green', 'olive', 'khaki green', 'sage', 'emerald', 'mint', 'verde', 'vert', 'grun' ),
			'yellow' => array( 'yellow', 'mustard', 'lemon', 'amarillo', 'jaune', 'gelb', 'giallo', 'amarelo' ),
			'orange' => array( 'orange', 'rust', 'coral', 'naranja', 'arancione', 'laranja' ),
			'pink'   => array( 'pink', 'blush', 'rose', 'fuchsia', 'magenta', 'rosa', 'rosado' ),
			'purple' => array( 'purple', 'violet', 'lilac', 'lavender', 'plum', 'morado', 'violeta', 'lila', 'viola', 'roxo' ),
			'brown'  => array( 'brown', 'chocolate', 'coffee', 'cognac', 'marron', 'braun', 'marrone' ),
			'beige'  => array( 'beige', 'tan', 'camel', 'khaki', 'sand', 'nude', 'taupe', 'cream', 'ecru', 'crema' ),
			'gold'   => array( 'gold', 'golden', 'dorado', 'oro' ),
			'silver' => array( 'silver', 'plata', 'argent', 'silber', 'argento' ),
		);
	}

	/**
	 * Pattern => keywords.
	 *
	 * @return array<string,string[]>
	 */
	public static function patterns(): array {
		return array(
			'striped' => array( 'striped', 'stripe', 'stripes', 'pinstripe', 'rayas', 'raye' ),
			'checked' => array( 'plaid', 'check', 'checked', 'checkered', 'gingham', 'tartan', 'cuadros', 'carreaux' ),
			'floral'  => array( 'floral', 'flower', 'flowers', 'flores', 'fleuri', 'blumen' ),
			'dotted'  => array( 'polka dot', 'polka', 'dots', 'dotted', 'lunares' ),
			'graphic' => array( 'graphic', 'print', 'printed', 'logo', 'slogan' ),
			'camo'    => array( 'camo', 'camouflage' ),
			'animal'  => array( 'leopard', 'zebra', 'snake print', 'animal print' ),
		);
	}

	/**
	 * Build one category entry.
	 *
	 * @param string   $label    English label (translated at display time).
	 * @param string   $region   Garment region.
	 * @param string   $layer    inner | outer | base | accessory.
	 * @param string[] $keywords Keywords.
	 * @param string   $mode     Try-on mode: fashion_vto | accessory | none.
	 * @return array{label:string,region:string,layer:string,mode:string,keywords:string[]}
	 */
	private static function cat( string $label, string $region, string $layer, array $keywords, string $mode = 'fashion_vto' ): array {
		return array(
			'label'    => $label,
			'region'   => $region,
			'layer'    => $layer,
			'mode'     => $mode,
			'keywords' => $keywords,
		);
	}
}
