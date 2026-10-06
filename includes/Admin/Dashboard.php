<?php
/**
 * WearGraph → Dashboard: usage, sales after try-on and AI cost (blueprint §20).
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Database\Repositories\EventsRepository;
use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Database\Repositories\UsageLedgerRepository;
use WearGraph\Providers\ProviderRegistry;

/**
 * Server-rendered, no script. Counts that need distinct shoppers or "today" come
 * from raw events; per-product and per-day series come from the daily rollups.
 */
final class Dashboard {

	private const CHART_DAYS = 14;

	/**
	 * The only markup icon() produces, for wp_kses() when it is printed.
	 */
	private const SVG_KSES = array(
		'svg'    => array(
			'viewbox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'focusable'       => true,
		),
		'path'   => array( 'd' => true ),
		'circle' => array(
			'cx' => true,
			'cy' => true,
			'r'  => true,
		),
		'rect'   => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
		),
	);

	/**
	 * Events.
	 *
	 * @var EventsRepository
	 */
	private $events;

	/**
	 * Ledger.
	 *
	 * @var UsageLedgerRepository
	 */
	private $ledger;

	/**
	 * Generations.
	 *
	 * @var GenerationsRepository
	 */
	private $generations;

	/**
	 * Catalog.
	 *
	 * @var CatalogRepository
	 */
	private $catalog;

	/**
	 * Providers.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param EventsRepository      $events      Events.
	 * @param UsageLedgerRepository $ledger      Ledger.
	 * @param GenerationsRepository $generations Generations.
	 * @param CatalogRepository     $catalog     Catalog.
	 * @param ProviderRegistry      $providers   Providers.
	 */
	public function __construct( EventsRepository $events, UsageLedgerRepository $ledger, GenerationsRepository $generations, CatalogRepository $catalog, ProviderRegistry $providers ) {
		$this->events      = $events;
		$this->ledger      = $ledger;
		$this->generations = $generations;
		$this->catalog     = $catalog;
		$this->providers   = $providers;
	}

	/**
	 * Numbers for the cards.
	 *
	 * @return array<string,int|float>
	 */
	public function stats(): array {
		$tz       = wp_timezone();
		$midnight = ( new \DateTimeImmutable( 'today', $tz ) )->getTimestamp();
		$utc      = static function ( int $ts ): string {
			return gmdate( 'Y-m-d H:i:s', $ts );
		};
		$days_7   = $utc( $midnight - 6 * DAY_IN_SECONDS );
		$days_30  = $utc( $midnight - 29 * DAY_IN_SECONDS );
		$totals   = $this->ledger->totals_since( $days_30 );

		return array(
			'tryons_today' => $this->events->count( array( 'tryon_completed' ), $utc( $midnight ) ),
			'tryon_users'  => $this->events->distinct_sessions( 'tryon_requested', $days_7 ),
			'add_to_cart'  => $this->events->count( array( 'add_to_cart_after_tryon' ), $days_30 ),
			'checkouts'    => $this->events->distinct_sessions( 'checkout_started_after_tryon', $days_30 ),
			'orders'       => $this->events->distinct_orders( 'purchase_after_tryon', $days_30 ),
			'revenue'      => $this->events->sum_property( 'purchase_after_tryon', 'line_total', self::currency(), $days_30 ),
			'images'       => $totals['images'],
			'cost'         => $totals['cost'],
			'failed'       => $this->events->count( array( 'tryon_failed' ), $days_7 ) + $this->generations->failed_since( $days_7, 'storefront' ),
		);
	}

	/**
	 * Cards to show.
	 *
	 * @param array<string,int|float> $stats From stats().
	 * @return array<string,array{label:string,value:string,hint?:string}>
	 */
	public function cards( array $stats ): array {
		$cards = array(
			'tryons_today' => array(
				'label' => __( 'Try-ons today', 'weargraph' ),
				'value' => number_format_i18n( (int) $stats['tryons_today'] ),
				'hint'  => __( 'Previews shown to shoppers', 'weargraph' ),
			),
			'tryon_users'  => array(
				'label' => __( 'Shoppers who tried on', 'weargraph' ),
				'value' => number_format_i18n( (int) $stats['tryon_users'] ),
				'hint'  => __( 'Last 7 days', 'weargraph' ),
			),
			'add_to_cart'  => array(
				'label' => __( 'Added to cart after try-on', 'weargraph' ),
				'value' => number_format_i18n( (int) $stats['add_to_cart'] ),
				'hint'  => __( 'Last 30 days', 'weargraph' ),
			),
			'checkouts'    => array(
				'label' => __( 'Checkouts after try-on', 'weargraph' ),
				'value' => number_format_i18n( (int) $stats['checkouts'] ),
				'hint'  => __( 'Shoppers who reached checkout, last 30 days', 'weargraph' ),
			),
			'orders'       => array(
				'label' => __( 'Orders after try-on', 'weargraph' ),
				'value' => number_format_i18n( (int) $stats['orders'] ),
				'hint'  => __( 'Paid orders with a tried-on product, last 30 days', 'weargraph' ),
			),
			'revenue'      => array(
				'label' => __( 'Sales after try-on', 'weargraph' ),
				'value' => trim( html_entity_decode( wp_strip_all_tags( function_exists( 'wc_price' ) ? wc_price( (float) $stats['revenue'] ) : number_format_i18n( (float) $stats['revenue'], 2 ) ), ENT_QUOTES, 'UTF-8' ), " \xC2\xA0" ),
				'hint'  => __( 'Tried-on items in paid orders, last 30 days', 'weargraph' ),
			),
			'images'       => array(
				'label' => __( 'Images generated', 'weargraph' ),
				'value' => number_format_i18n( (int) $stats['images'] ),
				'hint'  => __( 'Studio and try-on, last 30 days', 'weargraph' ),
			),
			'cost'         => array(
				'label' => __( 'Estimated AI cost', 'weargraph' ),
				/* translators: %s: amount in US dollars */
				'value' => sprintf( __( '$%s', 'weargraph' ), number_format_i18n( (float) $stats['cost'], 2 ) ),
				'hint'  => __( 'Paid to your AI provider, last 30 days (USD)', 'weargraph' ),
			),
			'failed'       => array(
				'label' => __( 'Failed generations', 'weargraph' ),
				'value' => number_format_i18n( (int) $stats['failed'] ),
				'hint'  => __( 'Last 7 days. Failed attempts do not use your quota.', 'weargraph' ),
			),
		);

		/**
		 * Dashboard cards.
		 *
		 * @param array<string,array{label:string,value:string,hint?:string}> $cards Cards.
		 * @param array<string,int|float>                                     $stats Free stats.
		 */
		return (array) apply_filters( 'weargraph_dashboard_cards', $cards, $stats );
	}

	/**
	 * Try-ons per local day for the chart; today comes from raw events (the rollup
	 * can be up to an hour behind).
	 *
	 * @param int $today_count Today's live count.
	 * @return array<string,int> Y-m-d => count, oldest first.
	 */
	public function series( int $today_count ): array {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$first = $today->modify( '-' . ( self::CHART_DAYS - 1 ) . ' days' );
		$rows  = $this->events->daily( 'tryon_completed', $first->format( 'Y-m-d' ) );

		$series = array();
		for ( $i = 0; $i < self::CHART_DAYS; $i++ ) {
			$day            = $first->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$series[ $day ] = (int) ( $rows[ $day ] ?? 0 );
		}
		$series[ $today->format( 'Y-m-d' ) ] = $today_count;

		return $series;
	}

	/**
	 * Cards placed in the dashboard's groups (the rest are shown in a row below).
	 */
	private const GROUPS = array(
		'shoppers'   => array( 'tryon_users', 'tryons_today' ),
		'conversion' => array( 'add_to_cart', 'checkouts' ),
		'health'     => array( 'images', 'failed' ),
		'efficiency' => array( 'orders', 'pro_roi' ),
	);

	/**
	 * Icon per card (see icon()).
	 */
	private const ICONS = array(
		'tryon_users'  => 'users',
		'tryons_today' => 'clock',
		'add_to_cart'  => 'cart',
		'checkouts'    => 'bag',
		'images'       => 'image',
		'failed'       => 'alert',
		'orders'       => 'receipt',
		'pro_roi'      => 'coin',
		'pro_direct'   => 'tag',
		'pro_returns'  => 'return',
	);

	/**
	 * Visits through the fitting room, last 30 days (shoppers, not events).
	 *
	 * @param array<string,int|float> $stats From stats().
	 * @return array<string,int> opened, tried, cart, ordered.
	 */
	public function funnel( array $stats ): array {
		$since = gmdate( 'Y-m-d H:i:s', ( new \DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp() - 29 * DAY_IN_SECONDS );

		return array(
			'opened'  => $this->events->distinct_sessions( 'fitting_room_opened', $since ),
			'tried'   => $this->events->distinct_sessions( 'tryon_completed', $since ),
			'cart'    => $this->events->distinct_sessions( 'add_to_cart_after_tryon', $since ),
			'ordered' => (int) ( $stats['orders'] ?? 0 ),
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		$stats   = $this->stats();
		$summary = $this->catalog->summary();
		$cards   = $this->cards( $stats );

		AdminMenu::open_shell( __( 'WearGraph Dashboard', 'weargraph' ) );
		AdminMenu::render_page_header( __( 'Dashboard', 'weargraph' ), __( 'How shoppers use your fitting room, and what your AI provider costs.', 'weargraph' ) );

		$provider = $this->providers->configured();
		if ( ! $provider || ! $provider->is_configured() ) {
			$this->render_getting_started();
		}
		?>
		<div class="weargraph-dash">
			<p class="weargraph-dash__readiness">
				<span class="weargraph-dash__readiness-icon" aria-hidden="true"><?php echo wp_kses( self::icon( 'info' ), self::SVG_KSES ); ?></span>
				<span>
					<?php
					printf(
						/* translators: 1: products ready, 2: all products */
						esc_html__( '%1$s of %2$s products are ready for try-on.', 'weargraph' ),
						'<strong>' . esc_html( number_format_i18n( $summary['ready'] ) ) . '</strong>',
						esc_html( number_format_i18n( $summary['total'] ) )
					);
					?>
				</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . CatalogPage::SLUG ) ); ?>"><?php esc_html_e( 'Open Catalog', 'weargraph' ); ?></a>
			</p>

			<div class="weargraph-dash__top">
				<?php
				$this->render_hero( $cards['revenue'] ?? ( $cards['pro_influenced'] ?? null ) );
				$this->render_funnel( $this->funnel( $stats ), $cards['pro_funnel'] ?? null );
				$this->render_single( 'cost', $cards['cost'] ?? null );
				?>
			</div>

			<div class="weargraph-dash__groups">
				<?php
				$titles = array(
					'shoppers'   => __( 'Engaged shoppers', 'weargraph' ),
					'conversion' => __( 'Conversion', 'weargraph' ),
					'health'     => __( 'System health', 'weargraph' ),
					'efficiency' => __( 'Efficiency', 'weargraph' ),
				);
				foreach ( self::GROUPS as $group => $keys ) {
					$in = array();
					foreach ( $keys as $key ) {
						if ( isset( $cards[ $key ] ) ) {
							$in[ $key ] = $cards[ $key ];
						}
					}
					if ( 'efficiency' === $group && ! isset( $in['pro_roi'] ) ) {
						$in['cost_per_image'] = $this->cost_per_image( $stats );
					}
					$this->render_group( $group, $titles[ $group ], $in );
				}
				?>
			</div>

			<?php
			// Cards an extension added that have no place above.
			$placed = array_merge( array( 'revenue', 'pro_influenced', 'pro_funnel', 'cost' ), ...array_values( self::GROUPS ) );
			$more   = array_diff_key( $cards, array_flip( $placed ) );
			if ( $more ) :
				?>
				<div class="weargraph-dash__more">
					<?php foreach ( $more as $key => $card ) : ?>
						<?php $this->render_single( (string) $key, $card ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="weargraph-dash__grid">
				<?php
				$this->render_chart( $this->series( (int) $stats['tryons_today'] ) );
				$this->render_top_products();
				?>
			</div>
			<?php $this->render_recent(); ?>
		</div>
		<?php
		AdminMenu::close_shell();
	}

	/**
	 * Free's own efficiency figure when Pro's sales-per-dollar is absent.
	 *
	 * @param array<string,int|float> $stats Stats.
	 * @return array{label:string,value:string,hint:string}
	 */
	private function cost_per_image( array $stats ): array {
		$images = (int) $stats['images'];

		return array(
			'label' => __( 'AI cost per image', 'weargraph' ),
			/* translators: %s: amount in US dollars */
			'value' => $images > 0 ? sprintf( __( '$%s', 'weargraph' ), number_format_i18n( (float) $stats['cost'] / $images, 3 ) ) : '—',
			'hint'  => __( 'Last 30 days (USD)', 'weargraph' ),
		);
	}

	/**
	 * The sales card that leads the page.
	 *
	 * @param array|null $card Card.
	 */
	private function render_hero( $card ): void {
		if ( ! is_array( $card ) ) {
			return;
		}
		?>
		<div class="weargraph-stat weargraph-hero" data-card="revenue">
			<span class="weargraph-hero__glyph" aria-hidden="true"><?php echo wp_kses( self::icon( 'trend' ), self::SVG_KSES ); ?></span>
			<span class="weargraph-stat__label"><?php echo esc_html( (string) $card['label'] ); ?></span>
			<strong class="weargraph-stat__value"><?php echo esc_html( (string) $card['value'] ); ?></strong>
			<?php if ( ! empty( $card['hint'] ) ) : ?>
				<span class="weargraph-stat__hint"><?php echo esc_html( (string) $card['hint'] ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One figure in a card of its own.
	 *
	 * @param string     $key  Card key.
	 * @param array|null $card Card.
	 */
	private function render_single( string $key, $card ): void {
		if ( ! is_array( $card ) ) {
			return;
		}
		?>
		<div class="weargraph-stat weargraph-single" data-card="<?php echo esc_attr( $key ); ?>">
			<span class="weargraph-stat__label"><?php echo esc_html( (string) $card['label'] ); ?></span>
			<strong class="weargraph-stat__value"><?php echo esc_html( (string) $card['value'] ); ?></strong>
			<?php if ( ! empty( $card['hint'] ) ) : ?>
				<span class="weargraph-stat__hint"><?php echo esc_html( (string) $card['hint'] ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The fitting-room funnel: visits at each step, with the share of opened visits.
	 *
	 * @param array<string,int> $funnel From funnel().
	 * @param array|null        $pro    Pro's funnel card (its label and rate win when present).
	 */
	private function render_funnel( array $funnel, $pro ): void {
		$steps  = array(
			'opened'  => __( 'Fitting room opened', 'weargraph' ),
			'tried'   => __( 'Tried on', 'weargraph' ),
			'cart'    => __( 'Added to cart', 'weargraph' ),
			'ordered' => __( 'Ordered', 'weargraph' ),
		);
		$opened = max( 0, (int) $funnel['opened'] );
		$rate   = is_array( $pro ) ? (string) $pro['value'] : ( $opened > 0 ? number_format_i18n( 100 * $funnel['ordered'] / $opened, 1 ) . '%' : '—' );
		?>
		<div class="weargraph-card weargraph-funnel" data-card="funnel">
			<div class="weargraph-funnel__head">
				<h3><?php echo esc_html( is_array( $pro ) ? (string) $pro['label'] : __( 'Try-on funnel', 'weargraph' ) ); ?></h3>
				<div class="weargraph-funnel__rate">
					<strong><?php echo esc_html( $rate ); ?></strong>
					<span><?php esc_html_e( 'opened → ordered', 'weargraph' ); ?></span>
				</div>
			</div>
			<ol class="weargraph-funnel__steps">
				<?php foreach ( $steps as $key => $label ) : ?>
					<?php
					$count = max( 0, (int) $funnel[ $key ] );
					$share = $opened > 0 ? min( 100, 100 * $count / $opened ) : 0;
					?>
					<li class="weargraph-funnel__step is-<?php echo esc_attr( $key ); ?>">
						<span class="weargraph-funnel__label"><?php echo esc_html( $label ); ?></span>
						<span class="weargraph-funnel__track"><span class="weargraph-funnel__fill" style="width:<?php echo esc_attr( (string) max( 2, round( $share ) ) ); ?>%"></span></span>
						<span class="weargraph-funnel__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
			<p class="weargraph-stat__hint"><?php esc_html_e( 'Shopper visits, last 30 days', 'weargraph' ); ?></p>
		</div>
		<?php
	}

	/**
	 * A titled card holding two related figures, each with an icon.
	 *
	 * @param string              $group Group key.
	 * @param string              $title Title.
	 * @param array<string,array> $cards Cards.
	 */
	private function render_group( string $group, string $title, array $cards ): void {
		if ( ! $cards ) {
			return;
		}
		?>
		<div class="weargraph-card weargraph-group is-<?php echo esc_attr( $group ); ?>">
			<h3><?php echo esc_html( $title ); ?></h3>
			<?php foreach ( $cards as $key => $card ) : ?>
				<div class="weargraph-stat weargraph-metric<?php echo 'failed' === $key && '0' !== (string) $card['value'] ? ' is-alert' : ''; ?>" data-card="<?php echo esc_attr( (string) $key ); ?>">
					<span class="weargraph-metric__icon" aria-hidden="true"><?php echo wp_kses( self::icon( self::ICONS[ $key ] ?? 'coin' ), self::SVG_KSES ); ?></span>
					<span class="weargraph-metric__body">
						<span class="weargraph-stat__label"><?php echo esc_html( (string) $card['label'] ); ?></span>
						<strong class="weargraph-stat__value"><?php echo esc_html( (string) $card['value'] ); ?></strong>
						<?php if ( ! empty( $card['hint'] ) ) : ?>
							<span class="weargraph-stat__hint"><?php echo esc_html( (string) $card['hint'] ); ?></span>
						<?php endif; ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * First-run card.
	 */
	private function render_getting_started(): void {
		?>
		<div class="weargraph-card weargraph-dash__welcome">
			<h3><?php esc_html_e( 'Welcome to WearGraph', 'weargraph' ); ?></h3>
			<p><?php esc_html_e( 'Connect your AI provider key and scan your catalog to see which products are ready for virtual try-on.', 'weargraph' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=provider' ) ); ?>"><?php esc_html_e( 'Connect your AI provider', 'weargraph' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Area chart of try-ons per day: an SVG drawn on the server (no script), with the same
	 * figures as a list for screen readers.
	 *
	 * @param array<string,int> $series Day => count.
	 */
	private function render_chart( array $series ): void {
		$total = array_sum( $series );
		?>
		<div class="weargraph-card weargraph-chart">
			<div class="weargraph-chart__head">
				<h3><?php esc_html_e( 'Try-ons, last 14 days', 'weargraph' ); ?></h3>
				<span class="weargraph-chart__total">
					<?php
					/* translators: %s: number of try-ons */
					echo esc_html( sprintf( _n( '%s try-on', '%s try-ons', $total, 'weargraph' ), number_format_i18n( $total ) ) );
					?>
				</span>
			</div>
			<?php if ( 0 === $total ) : ?>
				<p class="weargraph-dash__none"><?php esc_html_e( 'No try-ons yet. Once shoppers use the fitting room, you will see them here.', 'weargraph' ); ?></p>
			<?php else : ?>
				<?php $this->chart_svg( $series ); ?>
				<ol class="screen-reader-text">
					<?php foreach ( $series as $day => $count ) : ?>
						<li>
							<?php
							$ts = ( new \DateTimeImmutable( $day . ' 12:00:00', wp_timezone() ) )->getTimestamp();
							/* translators: 1: date, 2: number of try-ons */
							echo esc_html( sprintf( _n( '%1$s: %2$s try-on', '%1$s: %2$s try-ons', $count, 'weargraph' ), wp_date( get_option( 'date_format' ), $ts ), number_format_i18n( $count ) ) );
							?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The chart itself: gridlines on round numbers, a smooth line, a soft area, a dot per day.
	 *
	 * @param array<string,int> $series Day => count.
	 */
	private function chart_svg( array $series ): void {
		$w     = 640;
		$h     = 220;
		$left  = 34;
		$right = 12;
		$top   = 12;
		$base  = $h - 28;
		// At least 0–4 on the axis, so a quiet fortnight is not drawn as a mountain.
		$step = self::nice_step( max( 4, max( $series ) ) );
		$max  = max( 4, (int) ( ceil( max( $series ) / $step ) * $step ) );
		$n    = count( $series );
		$pts  = array();
		$i    = 0;
		foreach ( $series as $day => $count ) {
			$x     = $left + ( $n > 1 ? $i * ( $w - $left - $right ) / ( $n - 1 ) : 0 );
			$y     = $base - ( $base - $top ) * $count / $max;
			$pts[] = array( round( $x, 1 ), round( $y, 1 ), $day, $count );
			++$i;
		}
		// Smooth line (Catmull-Rom as cubic Béziers), flattened a little so it never overshoots far.
		$line = 'M' . $pts[0][0] . ' ' . $pts[0][1];
		for ( $k = 0; $k < $n - 1; $k++ ) {
			$p0    = $pts[ max( 0, $k - 1 ) ];
			$p1    = $pts[ $k ];
			$p2    = $pts[ $k + 1 ];
			$p3    = $pts[ min( $n - 1, $k + 2 ) ];
			$c1x   = $p1[0] + ( $p2[0] - $p0[0] ) / 6;
			$c1y   = min( $base, $p1[1] + ( $p2[1] - $p0[1] ) / 6 );
			$c2x   = $p2[0] - ( $p3[0] - $p1[0] ) / 6;
			$c2y   = min( $base, $p2[1] - ( $p3[1] - $p1[1] ) / 6 );
			$line .= sprintf( ' C%s %s %s %s %s %s', round( $c1x, 1 ), round( $c1y, 1 ), round( $c2x, 1 ), round( $c2y, 1 ), $p2[0], $p2[1] );
		}
		$area = $line . ' L' . $pts[ $n - 1 ][0] . ' ' . $base . ' L' . $pts[0][0] . ' ' . $base . ' Z';
		?>
		<svg class="weargraph-chart__svg" viewBox="0 0 <?php echo esc_attr( "$w $h" ); ?>" aria-hidden="true" focusable="false">
			<defs>
				<linearGradient id="weargraph-area" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0" stop-color="currentColor" stop-opacity=".28"/>
					<stop offset="1" stop-color="currentColor" stop-opacity="0"/>
				</linearGradient>
			</defs>
			<?php for ( $v = 0; $v <= $max; $v += $step ) : ?>
				<?php $gy = round( $base - ( $base - $top ) * $v / $max, 1 ); ?>
				<line class="weargraph-chart__grid" x1="<?php echo esc_attr( (string) $left ); ?>" x2="<?php echo esc_attr( (string) ( $w - $right ) ); ?>" y1="<?php echo esc_attr( (string) $gy ); ?>" y2="<?php echo esc_attr( (string) $gy ); ?>"/>
				<text class="weargraph-chart__axis" x="<?php echo esc_attr( (string) ( $left - 8 ) ); ?>" y="<?php echo esc_attr( (string) ( $gy + 4 ) ); ?>" text-anchor="end"><?php echo esc_html( number_format_i18n( $v ) ); ?></text>
			<?php endfor; ?>
			<path class="weargraph-chart__area" d="<?php echo esc_attr( $area ); ?>" fill="url(#weargraph-area)"/>
			<path class="weargraph-chart__line" d="<?php echo esc_attr( $line ); ?>" fill="none"/>
			<?php foreach ( $pts as $k => $p ) : ?>
				<?php $ts = ( new \DateTimeImmutable( $p[2] . ' 12:00:00', wp_timezone() ) )->getTimestamp(); ?>
				<circle class="weargraph-chart__dot<?php echo $k === $n - 1 ? ' is-today' : ''; ?>" cx="<?php echo esc_attr( (string) $p[0] ); ?>" cy="<?php echo esc_attr( (string) $p[1] ); ?>" r="<?php echo $k === $n - 1 ? '5' : '3.5'; ?>"><title><?php echo esc_html( wp_date( 'M j', $ts ) . ': ' . number_format_i18n( $p[3] ) ); ?></title></circle>
				<?php if ( 0 === ( $n - 1 - $k ) % 3 ) : // Every third day back from today: never crowded. ?>
					<text class="weargraph-chart__axis" x="<?php echo esc_attr( (string) $p[0] ); ?>" y="<?php echo esc_attr( (string) ( $h - 8 ) ); ?>" text-anchor="middle"><?php echo esc_html( wp_date( 'M j', $ts ) ); ?></text>
				<?php endif; ?>
			<?php endforeach; ?>
		</svg>
		<?php
	}

	/**
	 * Gridline step: 1, 2 or 5 times a power of ten, giving about four lines.
	 *
	 * @param int $max Largest value.
	 */
	private static function nice_step( int $max ): int {
		$raw  = max( 1, $max / 4 );
		$pow  = 10 ** (int) floor( log10( $raw ) );
		$step = $raw / $pow;

		return (int) max( 1, ( $step <= 1 ? 1 : ( $step <= 2 ? 2 : ( $step <= 5 ? 5 : 10 ) ) ) * $pow );
	}

	/**
	 * Most tried products, last 30 days.
	 */
	private function render_top_products(): void {
		$since = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-29 days' )->format( 'Y-m-d' );
		$top   = $this->events->top_products( 'tryon_completed', $since, 5 );
		?>
		<div class="weargraph-card">
			<h3><?php esc_html_e( 'Most tried products', 'weargraph' ); ?></h3>
			<?php if ( ! $top ) : ?>
				<p class="weargraph-dash__none"><?php esc_html_e( 'Nothing tried on in the last 30 days.', 'weargraph' ); ?></p>
			<?php else : ?>
				<ol class="weargraph-dash__list weargraph-dash__list--ranked">
					<?php foreach ( $top as $product_id => $count ) : ?>
						<li>
							<?php $this->product_link( (int) $product_id, true ); ?>
							<span class="weargraph-dash__count">
								<?php
								/* translators: %s: number of try-ons */
								echo esc_html( sprintf( _n( '%s try-on', '%s try-ons', $count, 'weargraph' ), number_format_i18n( $count ) ) );
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Latest events.
	 */
	private function render_recent(): void {
		$labels = self::event_labels();
		$rows   = $this->events->recent( 10 );
		?>
		<div class="weargraph-card weargraph-dash__recent">
			<h3><?php esc_html_e( 'Recent activity', 'weargraph' ); ?></h3>
			<?php if ( ! $rows ) : ?>
				<p class="weargraph-dash__none"><?php esc_html_e( 'No activity yet.', 'weargraph' ); ?></p>
			<?php else : ?>
				<ul class="weargraph-dash__list weargraph-activity">
					<?php foreach ( $rows as $row ) : ?>
						<li>
							<span class="weargraph-event is-<?php echo esc_attr( self::event_tone( (string) $row['name'] ) ); ?>"><?php echo esc_html( $labels[ $row['name'] ] ?? (string) $row['name'] ); ?></span>
							<span class="weargraph-activity__product">
								<?php
								if ( $row['product_id'] ) {
									$this->product_link( (int) $row['product_id'], true );
								}
								?>
							</span>
							<span class="weargraph-dash__count">
								<?php
								/* translators: %s: human time difference, e.g. "5 mins" */
								echo esc_html( sprintf( __( '%s ago', 'weargraph' ), human_time_diff( (int) strtotime( $row['created_at'] . ' UTC' ) ) ) );
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Colour family of an event in the activity list.
	 *
	 * @param string $name Event name.
	 */
	private static function event_tone( string $name ): string {
		if ( in_array( $name, array( 'purchase_after_tryon', 'add_to_cart_after_tryon', 'checkout_started_after_tryon' ), true ) ) {
			return 'money';
		}
		if ( in_array( $name, array( 'tryon_completed', 'look_generated' ), true ) ) {
			return 'ok';
		}
		if ( in_array( $name, array( 'tryon_failed', 'return_after_tryon' ), true ) ) {
			return 'bad';
		}
		if ( in_array( $name, array( 'tryon_requested', 'tryon_regenerated' ), true ) ) {
			return 'wait';
		}

		return 'plain';
	}

	/**
	 * Small line icons (24×24, stroked with the text colour).
	 *
	 * @param string $name Icon name.
	 */
	private static function icon( string $name ): string {
		$paths = array(
			'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
			'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'cart'    => '<path d="M3 4h2l2.2 11h11l2-8H6.2"/><circle cx="9" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/>',
			'bag'     => '<path d="M5 8h14l-1 12H6z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
			'image'   => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="1.8"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
			'alert'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.01"/>',
			'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
			'coin'    => '<circle cx="12" cy="12" r="9"/><path d="M14.8 9.2A2.6 2.6 0 0 0 12.4 8h-.9a2 2 0 0 0 0 4h1a2 2 0 0 1 0 4h-.9a2.6 2.6 0 0 1-2.4-1.2M12 6.5V8M12 16v1.5"/>',
			'tag'     => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
			'return'  => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
			'trend'   => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
			'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>',
		);

		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . ( $paths[ $name ] ?? $paths['info'] ) . '</svg>';
	}

	/**
	 * Product name linked to its edit screen (or plain text if it is gone), optionally with
	 * its thumbnail.
	 *
	 * @param int  $product_id Product.
	 * @param bool $thumb      Show the product image.
	 */
	private function product_link( int $product_id, bool $thumb = false ): void {
		$title = get_the_title( $product_id );
		$image = $thumb ? get_the_post_thumbnail(
			$product_id,
			array( 40, 40 ),
			array(
				'class' => 'weargraph-thumb',
				'alt'   => '',
			)
		) : '';
		if ( $thumb && '' === $image ) {
			$image = '<span class="weargraph-thumb weargraph-thumb--empty" aria-hidden="true"></span>';
		}
		if ( '' === $title ) {
			/* translators: %d: product id */
			echo '<span class="weargraph-product">' . wp_kses_post( $image ) . '<span>' . esc_html( sprintf( __( 'Product #%d', 'weargraph' ), $product_id ) ) . '</span></span>';
			return;
		}
		printf( '<a class="weargraph-product" href="%s">%s<span>%s</span></a>', esc_url( (string) get_edit_post_link( $product_id ) ), wp_kses_post( $image ), esc_html( $title ) );
	}

	/**
	 * Store currency; sales in other currencies are left out of the total.
	 */
	private static function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}

	/**
	 * Human labels for event names.
	 *
	 * @return array<string,string>
	 */
	public static function event_labels(): array {
		return array(
			'fitting_room_opened'          => __( 'Fitting room opened', 'weargraph' ),
			'photo_uploaded'               => __( 'Photo uploaded', 'weargraph' ),
			'photo_validated'              => __( 'Photo accepted', 'weargraph' ),
			'tryon_requested'              => __( 'Try-on requested', 'weargraph' ),
			'tryon_completed'              => __( 'Try-on shown', 'weargraph' ),
			'tryon_failed'                 => __( 'Try-on failed', 'weargraph' ),
			'tryon_regenerated'            => __( 'Try-on regenerated', 'weargraph' ),
			'variant_changed'              => __( 'Option changed', 'weargraph' ),
			'look_item_added'              => __( 'Added to a look', 'weargraph' ),
			'look_generated'               => __( 'Look generated', 'weargraph' ),
			'add_to_cart_after_tryon'      => __( 'Added to cart after try-on', 'weargraph' ),
			'checkout_started_after_tryon' => __( 'Checkout started after try-on', 'weargraph' ),
			'purchase_after_tryon'         => __( 'Bought after try-on', 'weargraph' ),
			'return_after_tryon'           => __( 'Returned after try-on', 'weargraph' ),
		);
	}
}
