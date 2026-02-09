<?php

namespace StockControl\Rest;

use StockControl\Service\Stock_Updater;
use StockControl\Support\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Stock_Controller {
	private const ROUTE_NAMESPACE = 'stock-control/v1';
	private const ROUTE_PATH      = '/stock';

	private Stock_Updater $stock_updater;
	private Logger $logger;

	public function __construct( Stock_Updater $stock_updater, Logger $logger ) {
		$this->stock_updater = $stock_updater;
		$this->logger        = $logger;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_stock_request' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * Check if the current authenticated user can update stock.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permission_check( WP_REST_Request $request ): bool {
		unset( $request );

		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	public function handle_stock_request( WP_REST_Request $request ): WP_REST_Response {
		try {
			$payload = $request->get_json_params();
			if ( ! is_array( $payload ) ) {
				$payload = $request->get_body_params();
			}

			$items = $this->extract_items( $payload );

			if ( is_wp_error( $items ) ) {
				$response = array(
					'success' => false,
					'results' => array(),
					'errors'  => array(
						array(
							'index'      => null,
							'identifier' => null,
							'code'       => $items->get_error_code(),
							'message'    => $items->get_error_message(),
						),
					),
				);

				$this->log_summary( $request, 0, $response['results'], $response['errors'] );

				return new WP_REST_Response( $response, 400 );
			}

			$mode = 'set';
			if ( isset( $payload['mode'] ) ) {
				$mode = strtolower( sanitize_text_field( (string) $payload['mode'] ) );
			}

			$result = $this->stock_updater->process_items( $items, $mode );

			$this->log_summary( $request, count( $items ), $result['results'], $result['errors'] );

			$status_code = $result['success'] ? 200 : 207;
			return new WP_REST_Response( $result, $status_code );
		} catch ( \Throwable $exception ) {
			$this->logger->log(
				'Unhandled request exception',
				array(
					'message' => $exception->getMessage(),
				)
			);

			$response = array(
				'success' => false,
				'results' => array(),
				'errors'  => array(
					array(
						'index'      => null,
						'identifier' => null,
						'code'       => 'internal_error',
						'message'    => __( 'Unexpected server error while processing the request.', 'stock-control' ),
					),
				),
			);

			$this->log_summary( $request, 0, $response['results'], $response['errors'] );

			return new WP_REST_Response( $response, 200 );
		}
	}

	/**
	 * Extract items from payload.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array|WP_Error
	 */
	private function extract_items( $payload ) {
		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Request body must be a JSON object.', 'stock-control' )
			);
		}

		if ( isset( $payload['items'] ) ) {
			if ( ! is_array( $payload['items'] ) ) {
				return new WP_Error(
					'invalid_items',
					__( 'items must be an array.', 'stock-control' )
				);
			}

			$items = array_values( $payload['items'] );
		} else {
			$items = array( $payload );
		}

		if ( empty( $items ) ) {
			return new WP_Error(
				'empty_items',
				__( 'At least one stock update item is required.', 'stock-control' )
			);
		}

		return $items;
	}

	/**
	 * Log request summary and result totals.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $items   Item count.
	 * @param array           $results Success results.
	 * @param array           $errors  Error results.
	 */
	private function log_summary( WP_REST_Request $request, int $items, array $results, array $errors ): void {
		$this->logger->log(
			'Stock request summary',
			array(
				'user_id'     => get_current_user_id(),
				'method'      => $request->get_method(),
				'route'       => $request->get_route(),
				'items'       => $items,
				'result_count' => count( $results ),
				'error_count' => count( $errors ),
				'success'     => empty( $errors ),
			)
		);
	}
}
