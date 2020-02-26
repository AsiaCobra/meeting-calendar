<?php
namespace WordPressdotorg\Meeting_Calendar;

class Plugin {

	const QUERY_KEY      = 'meeting_ical';
	const QUERY_TEAM_KEY = 'meeting_team';

	/**
	 * @var Plugin The singleton instance.
	 */
	private static $instance;

	/**
	 * Returns always the same instance of this plugin.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( ! ( self::$instance instanceof Plugin ) ) {
			self::$instance = new Plugin();
		}
		return self::$instance;
	}

	/**
	 * Instantiates a new Plugin object.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
	}

	public function plugins_loaded() {
		// Stop loading if "Meeting Post Type" plugin not available.
		if ( ! class_exists( '\WordPressdotorg\Meetings\PostType\Plugin' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="error"><p><strong>' . 'The Meetings iCalendar API requires the "Meetings Post Type" plugin to be installed and active' . '</strong></p></div>';
				}
			);

			return;
		}

		register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );

		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ) );

		add_filter( 'query_vars', array( $this, 'query_vars' ) );
	}

	public function on_activate() {
		$this->add_rewrite_rules();
		flush_rewrite_rules();
	}

	public function on_deactivate() {
		flush_rewrite_rules(); // remove custom rewrite rule
		delete_option( self::QUERY_KEY ); // remove cache
	}

	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^meetings/?([a-zA-Z\d\s_-]+)?/calendar\.ics$',
			array(
				self::QUERY_KEY      => 1,
				self::QUERY_TEAM_KEY => '$matches[1]',
			),
			'top'
		);
	}

	public function parse_request( $request ) {
		if ( ! array_key_exists( self::QUERY_KEY, $request->query_vars ) ) {
			return;
		}

		$team = strtolower( $request->query_vars[ self::QUERY_TEAM_KEY ] );

		// Generate a calendar if such a team exists
		$ical = $this->get_ical_contents( $team );

		if ( null !== $ical ) {
			/**
			 * If the calendar has a 'method' property, the 'Content-Type' header must also specify it
			 */
			header( 'Content-Type: text/calendar; charset=utf-8; method=publish' );
			header( 'Content-Disposition: inline; filename=calendar.ics' );
			echo $ical;
			exit;
		}

		return;
	}

	public function query_vars( $query_vars ) {
		array_push( $query_vars, self::QUERY_KEY );
		array_push( $query_vars, self::QUERY_TEAM_KEY );
		return $query_vars;
	}

	private function get_ical_contents( $team ) {
		$ttl    = 1; // in seconds
		$option = $team ? self::QUERY_KEY . "_{$team}" : self::QUERY_KEY;
		$cache  = get_option( $option, false );

		if ( is_array( $cache ) && $cache['timestamp'] > time() - $ttl ) {
			return $cache['contents'];
		}

		$contents = $this->generate_ical_contents( $team );

		if ( null !== $contents ) {
			$cache = array(
				'contents'  => $contents,
				'timestamp' => time(),
			);
			delete_option( $option );
			add_option( $option, $cache, false, false );

			return $cache['contents'];
		}

		return null;
	}

	private function generate_ical_contents( $team ) {
		$posts = $this->get_meeting_posts( $team );

		// Don't generate a calendar if there are no meetings for that team
		if ( empty( $posts ) ) {
			return null;
		}

		$ical_generator = new ICAL_Generator();
		return $ical_generator->generate( $posts );
	}

	/**
	 * Get all meetings for a team. If the 'team' parameter is empty, all meetings are returned.
	 *
	 * @param string $team Name of the team to fetch meetings for.
	 * @return array
	 */
	private function get_meeting_posts( $team = '' ) {
		$query = new Meeting_Query( $team );

		return $query->get_posts();
	}
}
