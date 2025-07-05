<?php
/**
 * Class for the Handle Mappings.
 *
 * @package migrate-ct
 */

/**
 * Encapsulates all mapping arrays for market, fuel/fertilizer, strategy handles,
 * and strategy page members.
 */
class Content_Mappings {

	/**
     * Market route handle to slug mapping.
     *
     * @var array<string, string>
     */
	public $market_mappings = array(
		'/api-content/ct/en/market_/early_word'       		 => 'early-word',
		'/api-content/ct/en/market_/before_the'       		 => 'before-the-bell',
		'/api-content/ct/en/market_/midday'           		 => 'midday',
	    '/api-content/ct/en/market_/close'            		 => 'close',
		'/api-content/ct/en/market_/livestock_early_word'    => 'livestock-early-word',
		'/api-content/ct/en/market_/livestock_midday'        => 'livestock-midday',
		'/api-content/ct/en/market_/livestock_close'         => 'livestock-close',
		'/api-content/ct/en/market_/dairy_open'              => 'dairy-open',
		'/api-content/ct/en/market_/dairy_midday'            => 'dairy-midday',
		'/api-content/ct/en/market_/dairy_close'             => 'dairy-close',
		'/api-content/ct/en/ethanol_folder/comments'         => 'comments',
		'/api-content/ct/en/columns/feed_daily'              => 'feed-daily',
		'/api-content/ct/en/columns/distillers_grain_weekly' => 'distillers-grain-weekly',
		'/api-content/ct/en/columns/fertilizer_weekly'       => 'fertilizer-weekly',
		'/api-content/ct/en/columns/fertilizer_outlook'      => 'fertilizer-outlook',
		'/api-content/ct/en/market_/quick_takes_all'         => 'quick-takes-all',
		'/api-content/ct/en/market_/quick_takes'             => 'quick-takes',
		'/api-content/ct/en/market_/quick_takes_livestock'   => 'quick-takes-livestock',
		'/api-content/ct/en/market_/northern_open'    		 => 'northern-open',
		'/api-content/ct/en/market_/northern_close'   		 => 'northern-close',
		'/api-content/ct/en/columns/basis_daily'             => 'basis-daily',
	);

	/**
	 * Mapping of fuel/fertilizer member IDs to slugs.
	 *
	 * @var array<string, string>
	 */
	public $fuel_fertilizer_mappings = array(
		'0702EC82' => 'cost-of-n/lb',
		'080545F9' => 'cost-of-n/lb',
		'0702EC83' => 'dap-chart',
		'080545FA' => 'dap-chart',
		'0702EC84' => 'map-chart',
		'080545FB' => 'map-chart',
		'0702EC85' => 'potash-chart',
		'080545FC' => 'potash-chart',
		'0702EC86' => 'urea-chart',
		'080545FD' => 'urea-chart',
		'0702EC87' => '10-34-0-chart',
		'080545FE' => '10-34-0-chart',
		'0702EC88' => 'anhydrous-chart',
		'080545FF' => 'anhydrous-chart',
		'0702EC89' => 'uan28-chart',
		'08054600' => 'uan28-chart',
		'0702EC8A' => 'uan32-chart',
		'08054601' => 'uan32-chart',
	);

	/**
	 * Strategy route handle to slug mapping.
	 *
	 * @var array<string, string>
	 */
	public $strategy_handles = array(
		'/api-content/ct/en/strategies/and_oilseeds/ct_canola_analysis'        => 'canola-recommendations',
		'/api-content/ct/en/strategies/and_oilseeds/ct_canola_strategies'      => 'canola-snapshot',
		'/api-content/ct/en/strategies/and_oilseeds/ct_canola_how_it'          => 'canola-recap',
		'/api-content/ct/en/strategies/and_oilseeds/ct_canola_alert'           => 'canola-alert',
		'/api-content/ct/en/strategies/and_oilseeds/ct_corn_analysis'          => 'corn-recommendations',
		'/api-content/ct/en/strategies/and_oilseeds/ct_corn_strategies'        => 'corn-strategies-snapshot',
		'/api-content/ct/en/strategies/and_oilseeds/ct_corn_how_it_works'      => 'corn-historical-recap',
		'/api-content/ct/en/strategies/and_oilseeds/ct_corn_alert'             => 'corn-alert',
		'/api-content/ct/en/strategies/and_oilseeds/ct_feed_corn_analysis'     => 'feed-corn-recommendations',
		'/api-content/ct/en/strategies/and_oilseeds/ct_feed_corn_strategies'   => 'feed-corn-strategies-snapshot',
		'/api-content/ct/en/strategies/and_oilseeds/ct_feed_corn_how'          => 'feed-corn-historical-recap',
		'/api-content/ct/en/strategies/and_oilseeds/ct_feed_corn_alert'        => 'feed-corn-alert',
		'/api-content/ct/en/strategies/and_oilseeds/ct_soybean_analysis'       => 'soybean-recommendations',
		'/api-content/ct/en/strategies/and_oilseeds/ct_soybean_how_it'         => 'soybean-historical-recap',
		'/api-content/ct/en/strategies/and_oilseeds/ct_soybean_strategies'     => 'soybean-strategies-snapshot',
		'/api-content/ct/en/strategies/and_oilseeds/ct_soybean_alert'          => 'soybean-alert',
		'/api-content/ct/en/strategies/and_oilseeds/ct_soybean_meal_analysis'  => 'soybean-meal-recommendations',
		'/api-content/ct/en/strategies/and_oilseeds/ct_soybean_meal_strategies' => 'soybean-meal-strategies-snapshot',
		'/api-content/ct/en/strategies/and_oilseeds/ct_soybean_meal_how'       => 'soybean-meal-historical-recap',
		'/api-content/ct/en/strategies/and_oilseeds/ct_soybean_meal_alert'     => 'soybean-meal-alert',
		'/api-content/ct/en/strategies/and_oilseeds/ct_wheat_analysis'         => 'wheat-recommendations',
		'/api-content/ct/en/strategies/and_oilseeds/ct_wheat_strategies'       => 'wheat-strategies-snapshot',
		'/api-content/ct/en/strategies/and_oilseeds/ct_wheat_how_it_works'     => 'wheat-historical-recap',
		'/api-content/ct/en/strategies/and_oilseeds/ct_wheat_alert'            => 'wheat-alert',
		'/api-content/ct/en/strategies/and_oilseeds/ct_hrs_analysis_and'       => 'hrs-recommendations',
		'/api-content/ct/en/strategies/and_oilseeds/ct_hrs_strategies'         => 'hrs-strategies-snapshot',
		'/api-content/ct/en/strategies/and_oilseeds/ct_hrs_how_it_works'       => 'hrs-historical-recap',
		'/api-content/ct/en/strategies/and_oilseeds/ct_spring_wheat_alert'     => 'spring-wheat-alert',
		'/api-content/ct/en/strategies/livestock/ct_cattle_analysis'           => 'cattle-recommendations',
		'/api-content/ct/en/strategies/livestock/ct_cattle_strategies'         => 'cattle-strategies-snapshot',
		'/api-content/ct/en/strategies/livestock/ct_cattle_how_it'             => 'cattle-historical-recap',
		'/api-content/ct/en/strategies/livestock/ct_cattle_alert'              => 'cattle-alert',
	);

	/**
	 * Page member ID to strategy sub-slug mapping.
	 *
	 * @var array<string, string>
	 */
	public $strategy_page_members = array(
		'0702B4D0' => 'ct-canola-trend',
		'08050AB0' => 'ct-canola-trend',
		'0702B4D1' => 'ct-canola-commercial-outlook',
		'08050AB3' => 'ct-canola-commercial-outlook',
		'0702B4D2' => 'ct-canola-noncommercial-outlook',
		'08050AB6' => 'ct-canola-noncommercial-outlook',
		'0702E738' => 'ct-canola-seasonal-index',
		'0805409D' => 'ct-canola-seasonal-index',
		'0702E739' => 'ct-canola-price-probability',
		'080540A0' => 'ct-canola-price-probability',
		'0702E73A' => 'ct-canola-volatility',
		'080540A3' => 'ct-canola-volatility',
		'0702B494' => 'ct-corn-trend',
		'080509FC' => 'ct-corn-trend',
		'0702B495' => 'ct-corn-commercial-outlook',
		'080509FF' => 'ct-corn-commercial-outlook',
		'0702B496' => 'ct-corn-noncommercial-outlook',
		'08050A02' => 'ct-corn-noncommercial-outlook',
		'0702E707' => 'ct-corn-seasonal-index',
		'0805403C' => 'ct-corn-seasonal-index',
		'0702E708' => 'ct-corn-price-probability',
		'0805403F' => 'ct-corn-price-probability',
		'0702E709' => 'ct-corn-volatility',
		'08054042' => 'ct-corn-volatility',
		'0702CB42' => 'ct-feed-corn-trend',
		'080522CA' => 'ct-feed-corn-trend',
		'0702CB43' => 'ct-feed-corn-commercial-outlook',
		'080522CD' => 'ct-feed-corn-commercial-outlook',
		'0702CB44' => 'ct-feed-corn-noncommercial-outlook',
		'080522D0' => 'ct-feed-corn-noncommercial-outlook',
		'0702E72B' => 'ct-feed-corn-seasonal-index',
		'08054084' => 'ct-feed-corn-seasonal-index',
		'0702E72C' => 'ct-feed-corn-price-probability',
		'08054087' => 'ct-feed-corn-price-probability',
		'0702E72D' => 'ct-feed-corn-volatility',
		'0805408A' => 'ct-feed-corn-volatility',
		'0702B4A8' => 'ct-soybean-trend',
		'08050A38' => 'ct-soybean-trend',
		'0702B4A9' => 'ct-soybean-commercial-outlook',
		'08050A3B' => 'ct-soybean-commercial-outlook',
		'0702B4AA' => 'ct-soybean-noncommercial-outlook',
		'08050A3E' => 'ct-soybean-noncommercial-outlook',
		'0702E70B' => 'ct-soybean-seasonal-index',
		'08054044' => 'ct-soybean-seasonal-index',
		'0702E70C' => 'ct-soybean-price-probability',
		'08054047' => 'ct-soybean-price-probability',
		'0702E70D' => 'ct-soybean-volatility',
		'0805404A' => 'ct-soybean-volatility',
		'0702CB48' => 'ct-soybean-meal-trend',
		'080522DC' => 'ct-soybean-meal-trend',
		'0702CB49' => 'ct-soybean-meal-commercial-outlook',
		'080522DF' => 'ct-soybean-meal-commercial-outlook',
		'0702CB4A' => 'ct-soybean-meal-noncommercial-outlook',
		'080522E2' => 'ct-soybean-meal-noncommercial-outlook',
		'0702E72F' => 'ct-soybean-meal-seasonal-index',
		'0805408C' => 'ct-soybean-meal-seasonal-index',
		'0702E730' => 'ct-soybean-meal-price-probability',
		'0805408F' => 'ct-soybean-meal-price-probability',
		'0702E731' => 'ct-soybean-meal-volatility',
		'08054092' => 'ct-soybean-meal-volatility',
		'0702B4BC' => 'ct-srw-trend',
		'08050A74' => 'ct-srw-trend',
		'0702B4BD' => 'ct-srw-commercial-outlook',
		'08050A77' => 'ct-srw-commercial-outlook',
		'0702B4BE' => 'ct-srw-noncommercial-outlook',
		'08050A7A' => 'ct-srw-noncommercial-outlook',
		'0702E70F' => 'ct-srw-seasonal-index',
		'0805404C' => 'ct-srw-seasonal-index',
		'0702E710' => 'ct-srw-price-probability',
		'0805404F' => 'ct-srw-price-probability',
		'0702E711' => 'ct-srw-volatility',
		'08054052' => 'ct-srw-volatility',
		'0702ECCD' => 'ct-hrs-trend',
		'08054737' => 'ct-hrs-trend',
		'0702ECCE' => 'ct-hrs-commercial-outlook',
		'08054738' => 'ct-hrs-commercial-outlook',
		'0702ECCF' => 'ct-hrs-noncommercial-outlook',
		'08054739' => 'ct-hrs-noncommercial-outlook',
		'0702ECD0' => 'ct-hrs-seasonal-index',
		'0805473A' => 'ct-hrs-seasonal-index',
		'0702ECD1' => 'ct-hrs-price-probability',
		'0805473B' => 'ct-hrs-price-probability',
		'0702ECD2' => 'ct-hrs-volatility',
		'0805473C' => 'ct-hrs-volatility',
		'0702B508' => 'ct-cattle-trend',
		'08050B22' => 'ct-cattle-trend',
		'0702B509' => 'ct-cattle-commercial-outlook',
		'08050B25' => 'ct-cattle-commercial-outlook',
		'0702B50A' => 'ct-cattle-noncommercial-outlook',
		'08050B28' => 'ct-cattle-noncommercial-outlook',
		'0702E723' => 'ct-cattle-seasonal-index',
		'08054074' => 'ct-cattle-seasonal-index',
		'0702E724' => 'ct-cattle-price-probability',
		'08054077' => 'ct-cattle-price-probability',
		'0702E725' => 'ct-cattle-volatility',
		'0805407A' => 'ct-cattle-volatility',
	);

	/**
     * Magazine sections (any page_* under PFMag goes to CPT 'magazine').
     *
     * @var string[]
     */
    public $magazine_sections = [
        'farm',
        'land',
        'life',
        'columns',
    ];
}