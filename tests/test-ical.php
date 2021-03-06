<?php
use WordPressdotorg\Meeting_Calendar;

/**
 * Class MeetingiCalTest
 *
 * @package Meeting_Calendar
 */

/**
 * Sample test case.
 */
class MeetingiCalTest extends WP_UnitTestCase {
	protected $server;
	protected $meeting_ids;

	function setUp() {
		parent::setUp();

		// Initialize a REST server
		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server;
		do_action( 'rest_api_init' );

		// Install test data
		$this->meeting_ids = Meeting_Calendar\wporg_meeting_install();

		// Make sure the meta keys are registered - setUp/tearDown nukes these
		Meeting_Post_Type::getInstance()->register_meta();

	}


	/**
	 * A single example test.
	 */
	public function test_sample() {
		// Replace this with some actual testing code.
		$this->assertTrue( true );
	}


	public function test_get_meetings() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/meeting' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3, count( $response->get_data() ) );
	}

	public function test_get_posts() {
		$posts = WordPressdotorg\Meeting_Calendar\ICS\get_meeting_posts();

		// Should be a numerical array
		$this->assertArrayHasKey( 0, $posts );
		// With one post per meeting
		$this->assertGreaterThan( 2, count($posts) );
	}

	public function test_get_ical() {
		$posts = WordPressdotorg\Meeting_Calendar\ICS\get_meeting_posts();
		$ical_feed = WordPressdotorg\Meeting_Calendar\ICS\Generator\generate( $posts );

		$expected_posts = '';
		Meeting_Post_Type::getInstance()->meeting_set_next_meeting( $posts, new WP_Query( array('post_type' => 'meeting', 'nopaging' => true ) ) );	
		foreach ( $posts as $i => $post ) {
			$post->start_datetime = strftime( '%Y%m%dT%H%M%SZ', strtotime( "{$post->start_date} {$post->time} GMT" ) );
			$post->end_datetime = strftime( '%Y%m%dT%H%M%SZ', strtotime( "{$post->start_date} {$post->time} GMT +1 hour" ) );
			if ( $post->ID === $this->meeting_ids[0] )
				$post->rrule = 'FREQ=WEEKLY';
			elseif ( $post->ID === $this->meeting_ids[1] )
				$post->rrule = 'FREQ=MONTHLY';
			elseif ( $post->ID === $this->meeting_ids[2] )
				$post->rrule = 'FREQ=MONTHLY;BYDAY=3WE';
			else
				$post->rrule = 'FIX THE TESTS ALEX';

			$expected_posts .= <<<EOF
BEGIN:VEVENT
UID:{$post->ID}
DTSTAMP:{$post->start_datetime}
DTSTART:{$post->start_datetime}
DTEND:{$post->end_datetime}
CATEGORIES:WordPress
ORGANIZER;CN=WordPress {$post->team} Team:mailto:mail@example.com
SUMMARY:{$post->team}: {$post->post_title}
SEQUENCE:0
STATUS:CONFIRMED
TRANSP:OPAQUE
LOCATION:#meta channel on Slack
DESCRIPTION:Slack channel link: https://wordpress.slack.com/messages/#meta\\nFor more information visit wordpress.org
RRULE:{$post->rrule}
END:VEVENT

EOF;
		}

		$expected = <<<EOF
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Make WordPress//Meeting Events Calendar//EN
METHOD:PUBLISH
CALSCALE:GREGORIAN
{$expected_posts}END:VCALENDAR
EOF;

		$this->assertEquals( preg_split('/\r\n|\r|\n/',$expected), preg_split('/\r\n|\r|\n/',$ical_feed) );
	}

	public function test_get_ical_with_cancellation() {
		$posts = WordPressdotorg\Meeting_Calendar\ICS\get_meeting_posts( 'Team-A' );
		// Cancel the second occurrence of the weekly meeting
		$occurrences = Meeting_Post_Type::getInstance()->get_future_occurrences( get_post( $posts[0]->ID ), null, null );
		$this->assertGreaterThan( 0, Meeting_Post_Type::getInstance()->cancel_meeting( array( 
			'meeting_id' => $posts[0]->ID, 
			'date'       => $occurrences[1] 
		) ) );

		$ical_feed = WordPressdotorg\Meeting_Calendar\ICS\Generator\generate( $posts );

		Meeting_Post_Type::getInstance()->meeting_set_next_meeting( $posts, new WP_Query( array('post_type' => 'meeting', 'nopaging' => true ) ) );	
		foreach ( $posts as $i => $post ) {
			$post->start_datetime = strftime( '%Y%m%dT%H%M%SZ', strtotime( "{$post->start_date} {$post->time} GMT" ) );
			$post->end_datetime = strftime( '%Y%m%dT%H%M%SZ', strtotime( "{$post->start_date} {$post->time} GMT +1 hour" ) );
			$post->exdate = str_replace( '-', '', $occurrences[1] );
		}

		$expected = <<<EOF
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Make WordPress//Meeting Events Calendar//EN
METHOD:PUBLISH
CALSCALE:GREGORIAN
BEGIN:VEVENT
UID:49
DTSTAMP:{$posts[0]->start_datetime}
DTSTART:{$posts[0]->start_datetime}
DTEND:{$posts[0]->end_datetime}
CATEGORIES:WordPress
ORGANIZER;CN=WordPress Team-A Team:mailto:mail@example.com
SUMMARY:Team-A: A weekly meeting
SEQUENCE:0
STATUS:CONFIRMED
TRANSP:OPAQUE
LOCATION:#meta channel on Slack
DESCRIPTION:Slack channel link: https://wordpress.slack.com/messages/#meta\\nFor more information visit wordpress.org
RRULE:FREQ=WEEKLY
EXDATE:{$posts[0]->exdate}
END:VEVENT
END:VCALENDAR
EOF;

		$this->assertEquals( preg_split('/\r\n|\r|\n/',$expected), preg_split('/\r\n|\r|\n/',$ical_feed) );

	}
}
