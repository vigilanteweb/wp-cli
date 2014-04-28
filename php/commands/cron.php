<?php

/**
 * Manage WP-Cron events.
 *
 */
class Cron_Event_Command extends WP_CLI_Command {

	private $fields = array(
		'hook',
		'next_run_gmt',
		'next_run_relative',
		'recurrence',
	);
	private static $time_format = 'Y-m-d H:i:s';

	/**
	 * List scheduled cron events.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific object fields. Available fields: hook, next_run, next_run_gmt, next_run_relative, recurrence.
	 *
	 * [--format=<format>]
	 * : Accepted values: table, json, csv, ids. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cron event list
	 *
	 *     wp cron event list --fields=hook,next_run --format=json
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		$formatter = $this->get_formatter( $assoc_args );

		$events = self::get_cron_events();

		if ( is_wp_error( $events ) ) {
			$events = array();
		}

		if ( 'ids' == $formatter->format ) {
			echo implode( ' ', wp_list_pluck( $events, 'hook' ) );
		} else {
			$formatter->display_items( $events );
		}

	}

	/**
	 * Schedule a new cron event.
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : The hook name
	 *
	 * [--next_run=<value>]
	 * : A Unix timestamp or an English textual datetime description compatible with `strtotime()`. Defaults to now.
	 *
	 * [--recurrence=<value>]
	 * : How often the event should recur. See `wp cron schedule list` for available schedule names. Defaults to no recurrence.
	 *
	 * [--<field>=<value>]
	 * : Associative args for the event.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cron event schedule cron_test
	 *
	 *     wp cron event schedule cron_test --next_run='+1 hour' --recurrence=hourly
	 *
	 *     wp cron event schedule cron_test --recurrence=daily --foo=1 --bar=2
	 */
	public function schedule( $args, $assoc_args ) {

		list( $hook ) = $args;

		if ( !isset( $assoc_args['next_run'] ) ) {
			$timestamp = time();
		} else if ( is_numeric( $assoc_args['next_run'] ) ) {
			$timestamp = absint( $assoc_args['next_run'] );
		} else {
			$timestamp = strtotime( $assoc_args['next_run'] );
		}

		if ( ! $timestamp ) {
			WP_CLI::error( sprintf( "'%s' is not a valid datetime.", $assoc_args['next_run'] ) );
		}

		$event_args = array_diff_key( $assoc_args, array_flip( array(
			'next_run', 'recurrence'
		) ) );

		if ( isset( $assoc_args['recurrence'] ) ) {

			$recurrence = $assoc_args['recurrence'];
			$schedules  = wp_get_schedules();

			if ( ! isset( $schedules[$recurrence] ) ) {
				WP_CLI::error( sprintf( "'%s' is not a valid schedule name for recurrence.", $recurrence ) );
			}

			$event = wp_schedule_event( $timestamp, $recurrence, $hook, $event_args );

		} else {

			$event = wp_schedule_single_event( $timestamp, $hook, $event_args );

		}

		if ( false !== $event ) {
			WP_CLI::success( sprintf( "Scheduled event with hook '%s' for %s.", $hook, date( self::$time_format, $timestamp ) ) );
		} else {
			WP_CLI::error( 'Event not scheduled' );
		}

	}

	/**
	 * Run the next scheduled cron event for the given hook.
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : The hook name
	 */
	public function run( $args, $assoc_args ) {

		$hook   = $args[0];
		$result = false;
		$events = self::get_cron_events();

		if ( is_wp_error( $events ) ) {
			WP_CLI::error( $events );
		}

		foreach ( $events as $id => $event ) {
			if ( $event->hook == $hook ) {
				$result = self::run_event( $event );
				break;
			}
		}

		if ( $result ) {
			WP_CLI::success( sprintf( "Successfully executed the cron event '%s'", $hook ) );
		} else {
			WP_CLI::error( sprintf( "Failed to the execute the cron event '%s'", $hook ) );
		}

	}

	/**
	 * Executes an event immediately by scheduling a new single event with the same arguments.
	 *
	 * @param stdClass $event The event
	 * @return bool Whether the event was successfully executed or not.
	 */
	protected static function run_event( stdClass $event ) {

		delete_transient( 'doing_cron' );
		$scheduled = wp_schedule_single_event( time()-1, $event->hook, $event->args );

		if ( false === $scheduled ) {
			return false;
		}

		spawn_cron();

		return true;

	}

	/**
	 * Delete the next scheduled cron event for the given hook.
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : The hook name
	 */
	public function delete( $args, $assoc_args ) {

		$hook   = $args[0];
		$result = false;
		$events = self::get_cron_events();

		if ( is_wp_error( $events ) ) {
			WP_CLI::error( $events );
		}

		foreach ( $events as $id => $event ) {
			if ( $event->hook == $hook ) {
				$result = self::delete_event( $event );
				break;
			}
		}

		if ( $result ) {
			WP_CLI::success( sprintf( "Successfully deleted the cron event '%s'", $hook ) );
		} else {
			WP_CLI::error( sprintf( "Failed to the delete the cron event '%s'", $hook ) );
		}

	}

	/**
	 * Deletes a cron event.
	 *
	 * @param stdClass $event The event
	 * @return bool Whether the event was successfully deleted or not.
	 */
	protected static function delete_event( stdClass $event ) {
		$crons = _get_cron_array();

		if ( ! isset( $crons[$event->time][$event->hook][$event->sig] ) ) {
			return false;
		}

		wp_unschedule_event( $event->time, $event->hook, $event->args );
		return true;
	}

	/**
	 * Callback function to format a cron event.
	 *
	 * @param stdClass $event The event.
	 * @return stdClass The formatted event object.
	 */
	protected static function format_event( stdClass $event ) {

		$event->next_run          = get_date_from_gmt( date( 'Y-m-d H:i:s', $event->time ), self::$time_format );
		$event->next_run_gmt      = date( self::$time_format, $event->time );
		$event->next_run_relative = self::interval( $event->time - time() );
		$event->recurrence        = ( $event->schedule ) ? self::interval( $event->interval ) : 'Non-repeating';

		return $event;
	}

	/**
	 * Fetch an array of scheduled cron events.
	 *
	 * @return array|WP_Error An array of event objects, or a WP_Error object if there are no events scheduled.
	 */
	protected static function get_cron_events() {

		$crons  = _get_cron_array();
		$events = array();

		if ( empty( $crons ) ) {
			return new WP_Error(
				'no_events',
				'You currently have no scheduled cron events.'
			);
		}

		// @TODO rename these vars a bit more better nicely nicer:
		foreach ( $crons as $time => $cron ) {
			foreach ( $cron as $hook => $dings ) {
				foreach ( $dings as $sig => $data ) {

					$events["$hook-$sig"] = (object) array(
						'hook'     => $hook,
						'time'     => $time,
						'sig'      => $sig,
						'args'     => $data['args'],
						'schedule' => $data['schedule'],
						'interval' => isset( $data['interval'] ) ? $data['interval'] : null,
					);

				}
			}
		}

		$events = array_map( 'Cron_Event_Command::format_event', $events );

		return $events;

	}

	/**
	 * Convert a time interval into human-readable format.
	 *
	 * Similar to WordPress' built-in `human_time_diff()` but returns two time period chunks instead of just one.
	 *
	 * @param int $since An interval of time in seconds
	 * @return string The interval in human readable format
	 */
	private static function interval( $since ) {
		if ( $since <= 0 ) {
			return 'now';
		}

		$since = absint( $since );

		// array of time period chunks
		$chunks = array(
			array( 60 * 60 * 24 * 365 , \_n_noop( '%s year', '%s years' ) ),
			array( 60 * 60 * 24 * 30 , \_n_noop( '%s month', '%s months' ) ),
			array( 60 * 60 * 24 * 7, \_n_noop( '%s week', '%s weeks' ) ),
			array( 60 * 60 * 24 , \_n_noop( '%s day', '%s days' ) ),
			array( 60 * 60 , \_n_noop( '%s hour', '%s hours' ) ),
			array( 60 , \_n_noop( '%s minute', '%s minutes' ) ),
			array(  1 , \_n_noop( '%s second', '%s seconds' ) ),
		);

		// we only want to output two chunks of time here, eg:
		// x years, xx months
		// x days, xx hours
		// so there's only two bits of calculation below:

		// step one: the first chunk
		for ( $i = 0, $j = count( $chunks ); $i < $j; $i++ ) {
			$seconds = $chunks[$i][0];
			$name = $chunks[$i][1];

			// finding the biggest chunk (if the chunk fits, break)
			if ( ( $count = floor( $since / $seconds ) ) != 0 ){
				break;
			}
		}

		// set output var
		$output = sprintf( \_n( $name[0], $name[1], $count ), $count );

		// step two: the second chunk
		if ( $i + 1 < $j ) {
			$seconds2 = $chunks[$i + 1][0];
			$name2    = $chunks[$i + 1][1];

			if ( ( $count2 = floor( ( $since - ( $seconds * $count ) ) / $seconds2 ) ) != 0 ) {
				// add to output var
				$output .= ' ' . sprintf( \_n( $name2[0], $name2[1], $count2 ), $count2 );
			}
		}

		return $output;
	}

	private function get_formatter( &$assoc_args ) {
		return new \WP_CLI\Formatter( $assoc_args, $this->fields, 'event' );
	}

}

/**
 * Manage WP-Cron schedules.
 */
class Cron_Schedule_Command extends WP_CLI_Command {

	private $fields = array(
		'name',
		'display',
		'interval',
	);

	/**
	 * List available cron schedules.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific object fields. Available fields: name, display, interval.
	 *
	 * [--format=<format>]
	 * : Accepted values: table, json, csv, ids. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cron schedule list
	 *
	 *     wp cron schedule list --fields=name --format=ids
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		$formatter = $this->get_formatter( $assoc_args );

		$schedules = self::get_schedules();

		if ( 'ids' == $formatter->format ) {
			echo implode( ' ', wp_list_pluck( $schedules, 'name' ) );
		} else {
			$formatter->display_items( $schedules );
		}

	}

	/**
	 * Callback function to format a cron schedule.
	 *
	 * @param array $schedule The schedule.
	 * @param string $name The schedule name.
	 * @return array The formatted schedule.
	 */
	protected static function format_schedule( array $schedule, $name ) {
		$schedule['name'] = $name;
		return $schedule;
	}

	/**
	* Return a list of the cron schedules sorted according to interval.
	*
	* @return array The array of cron schedules. Each schedule is itself an array.
	*/
	protected static function get_schedules() {
		$schedules = wp_get_schedules();
		if ( !empty( $schedules ) ) {
			uasort( $schedules, 'Cron_Schedule_Command::sort' );
			$schedules = array_map( 'Cron_Schedule_Command::format_schedule', $schedules, array_keys( $schedules ) );
		}
		return $schedules;
	}

	/**
	 * Callback function to sort the cron schedule array by interval.
	 *
	 */
	protected static function sort( array $a, array $b ) {
		return $a['interval'] - $b['interval'];
	}

	private function get_formatter( &$assoc_args ) {
		return new \WP_CLI\Formatter( $assoc_args, $this->fields, 'schedule' );
	}

}

/**
 * Manage WP-Cron events and schedules.
 */
class Cron_Command extends WP_CLI_Command {

	/**
	 * Test the WP Cron spawning system and report back any errors.
	 */
	public function test() {

		$status = self::test_cron_spawn();

		if ( is_wp_error( $status ) ) {
			WP_CLI::error( $status );
		} else {
			WP_CLI::success( 'WP-Cron is working as expected.' );
		}

	}

	/**
	 * Gets the status of WP-Cron functionality on the site by performing a test spawn.
	 *
	 * This function is designed to mimic the functionality in `spawn_cron()` with the addition of checking
	 * the return value of the call to `wp_remote_post()`.
	 *
	 * @return bool|WP_Error Boolean true if the cron spawn test is successful, WP_Error object if not.
	 */
	protected static function test_cron_spawn() {

		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return true;
		}

		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );

		$cron_request = apply_filters( 'cron_request', array(
			'url'  => site_url( 'wp-cron.php?doing_wp_cron=' . $doing_wp_cron ),
			'key'  => $doing_wp_cron,
			'args' => array(
				'timeout'   => 3,
				'blocking'  => true,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true )
			)
		) );

		# Enforce a blocking request in case something that's hooked onto the 'cron_request' filter sets it to false
		$cron_request['args']['blocking'] = true;

		$result = wp_remote_post( $cron_request['url'], $cron_request['args'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		} else {
			return true;
		}

	}

}

WP_CLI::add_command( 'cron',          'Cron_Command' );
WP_CLI::add_command( 'cron event',    'Cron_Event_Command' );
WP_CLI::add_command( 'cron schedule', 'Cron_Schedule_Command' );
