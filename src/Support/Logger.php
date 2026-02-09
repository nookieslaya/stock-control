<?php

namespace StockControl\Support;

final class Logger {
	/**
	 * Log a stock-control event.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context data.
	 */
	public function log( string $message, array $context = array() ): void {
		$line = '[stock-control] ' . $message;

		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );

			if ( false !== $encoded ) {
				$line .= ' ' . $encoded;
			}
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

