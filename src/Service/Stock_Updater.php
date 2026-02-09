<?php

namespace StockControl\Service;

use StockControl\Support\Logger;
use WC_Product;

final class Stock_Updater {
	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Process all stock update items.
	 *
	 * @param array  $items        Payload items.
	 * @param string $default_mode Default mode.
	 * @return array{success:bool,results:array,errors:array}
	 */
	public function process_items( array $items, string $default_mode = 'set' ): array {
		$response = array(
			'success' => true,
			'results' => array(),
			'errors'  => array(),
		);

		if ( ! function_exists( 'wc_get_product' ) ) {
			return array(
				'success' => false,
				'results' => array(),
				'errors'  => array(
					array(
						'index'      => null,
						'identifier' => null,
						'code'       => 'woocommerce_not_available',
						'message'    => __( 'WooCommerce is required to update stock.', 'stock-control' ),
					),
				),
			);
		}

		foreach ( $items as $index => $item ) {
			$normalized_input = is_array( $item ) ? $this->normalize_input( $item ) : array();

			try {
				if ( ! is_array( $item ) ) {
					$this->append_error(
						$response,
						$index,
						null,
						'invalid_item',
						__( 'Each item must be an object.', 'stock-control' ),
						$normalized_input
					);
					continue;
				}

				$mode = $this->resolve_mode( $item, $default_mode );
				if ( is_wp_error( $mode ) ) {
					$this->append_error(
						$response,
						$index,
						$this->resolve_identifier_label( $item ),
						$mode->get_error_code(),
						$mode->get_error_message(),
						$normalized_input
					);
					continue;
				}

				$qty = $this->resolve_qty( $item );
				if ( is_wp_error( $qty ) ) {
					$this->append_error(
						$response,
						$index,
						$this->resolve_identifier_label( $item ),
						$qty->get_error_code(),
						$qty->get_error_message(),
						$normalized_input
					);
					continue;
				}

				$product_context = $this->resolve_product( $item );
				if ( is_wp_error( $product_context ) ) {
					$this->append_error(
						$response,
						$index,
						$this->resolve_identifier_label( $item ),
						$product_context->get_error_code(),
						$product_context->get_error_message(),
						$normalized_input
					);
					continue;
				}

				/** @var WC_Product $product */
				$product = $product_context['product'];
				$old_qty = $product->get_stock_quantity();

				if ( ! $product->managing_stock() ) {
					$product->set_manage_stock( true );
					$product->save();
				}

				$updated_qty = wc_update_product_stock( $product, $qty, 'set' );
				if ( false === $updated_qty ) {
					$this->append_error(
						$response,
						$index,
						$this->resolve_identifier_label( $item ),
						'stock_update_failed',
						__( 'Stock update failed for this product.', 'stock-control' ),
						$normalized_input
					);
					continue;
				}

				wc_update_product_stock_status( $product->get_id(), $qty > 0 ? 'instock' : 'outofstock' );

				$refreshed_product = wc_get_product( $product->get_id() );
				$new_qty           = $refreshed_product ? $refreshed_product->get_stock_quantity() : $qty;

				$response['results'][] = array(
					'index'               => $index,
					'input'               => $normalized_input,
					'resolved_product_id' => $product->get_id(),
					'status'              => 'updated',
					'identifier'          => $product_context['identifier'],
					'mode'                => 'set',
					'old_stock'           => $old_qty,
					'new_stock'           => $new_qty,
				);

				$this->logger->log(
					'Stock update item updated',
					array(
						'index'      => $index,
						'input'      => $normalized_input,
						'product_id' => $product->get_id(),
						'old_stock'  => $old_qty,
						'new_stock'  => $new_qty,
						'mode'       => 'set',
					)
				);
			} catch ( \Throwable $exception ) {
				$this->logger->log(
					'Item processing exception',
					array(
						'index'   => $index,
						'message' => $exception->getMessage(),
						'input'   => $normalized_input,
					)
				);

				$this->append_error(
					$response,
					$index,
					$this->resolve_identifier_label( is_array( $item ) ? $item : array() ),
					'internal_error',
					__( 'Unexpected error while processing this item.', 'stock-control' ),
					$normalized_input
				);
			}
		}

		$response['success'] = empty( $response['errors'] );
		return $response;
	}

	/**
	 * Resolve operation mode.
	 *
	 * @param array  $item         Item payload.
	 * @param string $default_mode Default mode.
	 * @return string|\WP_Error
	 */
	private function resolve_mode( array $item, string $default_mode ) {
		$mode = $default_mode;

		if ( isset( $item['mode'] ) ) {
			$mode = strtolower( sanitize_text_field( (string) $item['mode'] ) );
		}

		if ( 'set' !== $mode ) {
			return new \WP_Error(
				'invalid_mode',
				__( 'Only "set" mode is supported.', 'stock-control' )
			);
		}

		return $mode;
	}

	/**
	 * Resolve and validate quantity.
	 *
	 * @param array $item Item payload.
	 * @return int|\WP_Error
	 */
	private function resolve_qty( array $item ) {
		if ( ! array_key_exists( 'qty', $item ) ) {
			return new \WP_Error(
				'missing_qty',
				__( 'qty is required.', 'stock-control' )
			);
		}

		$raw_qty = $item['qty'];
		$qty     = null;

		if ( is_int( $raw_qty ) ) {
			$qty = $raw_qty;
		} elseif ( is_string( $raw_qty ) && preg_match( '/^\d+$/', trim( $raw_qty ) ) ) {
			$qty = (int) trim( $raw_qty );
		}

		if ( null === $qty || $qty < 0 ) {
			return new \WP_Error(
				'invalid_qty',
				__( 'qty must be an integer greater than or equal to 0.', 'stock-control' )
			);
		}

		return $qty;
	}

	/**
	 * Resolve and validate target product.
	 *
	 * @param array $item Item payload.
	 * @return array|\WP_Error
	 */
	private function resolve_product( array $item ) {
		$has_sku = isset( $item['sku'] ) && '' !== trim( (string) $item['sku'] );
		$has_id  = isset( $item['product_id'] ) && '' !== trim( (string) $item['product_id'] );

		if ( ! $has_sku && ! $has_id ) {
			return new \WP_Error(
				'missing_identifier',
				__( 'Provide either sku or product_id.', 'stock-control' )
			);
		}

		if ( $has_sku && $has_id ) {
			return new \WP_Error(
				'ambiguous_identifier',
				__( 'Provide only one identifier: sku or product_id.', 'stock-control' )
			);
		}

		if ( $has_sku ) {
			$sku = sanitize_text_field( (string) $item['sku'] );

			if ( '' === $sku ) {
				return new \WP_Error(
					'invalid_sku',
					__( 'sku must be a non-empty string.', 'stock-control' )
				);
			}

			$matching_ids = $this->find_product_ids_by_sku( $sku );
			if ( empty( $matching_ids ) ) {
				return new \WP_Error(
					'sku_not_found',
					__( 'No product or variation found for the provided SKU.', 'stock-control' )
				);
			}

			if ( count( $matching_ids ) > 1 ) {
				return new \WP_Error(
					'ambiguous_sku',
					__( 'Provided SKU is ambiguous and matches multiple products. Use product_id instead.', 'stock-control' )
				);
			}

			$id      = (int) $matching_ids[0];
			$product = wc_get_product( $id );
			if ( ! $product ) {
				return new \WP_Error(
					'product_not_found',
					__( 'Product not found for the provided SKU.', 'stock-control' )
				);
			}

			if ( $product->is_type( 'variable' ) ) {
				return new \WP_Error(
					'variable_parent_sku_not_allowed',
					__( 'Parent variable product SKU is not allowed. Update a specific variation SKU instead.', 'stock-control' )
				);
			}

			return array(
				'product'    => $product,
				'identifier' => array(
					'type'  => 'sku',
					'value' => $sku,
				),
			);
		}

		$product_id = absint( $item['product_id'] );
		if ( $product_id <= 0 ) {
			return new \WP_Error(
				'invalid_product_id',
				__( 'product_id must be a positive integer.', 'stock-control' )
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error(
				'product_not_found',
				__( 'No product or variation found for the provided product_id.', 'stock-control' )
			);
		}

		if ( $product->is_type( 'variable' ) ) {
			return new \WP_Error(
				'variable_parent_product_id_not_allowed',
				__( 'Parent variable product ID is not allowed. Update a specific variation ID instead.', 'stock-control' )
			);
		}

		return array(
			'product'    => $product,
			'identifier' => array(
				'type'  => 'product_id',
				'value' => $product_id,
			),
		);
	}

	/**
	 * Build a normalized error object.
	 *
	 * @param int|null    $index      Item index.
	 * @param string|null $identifier Item identifier.
	 * @param string      $code       Error code.
	 * @param string      $message    Error message.
	 * @return array
	 */
	private function build_error( ?int $index, ?string $identifier, string $code, string $message ): array {
		return array(
			'index'      => $index,
			'identifier' => $identifier,
			'code'       => $code,
			'message'    => $message,
		);
	}

	/**
	 * Append item-level error to response and log it.
	 *
	 * @param array       $response   API response (by reference).
	 * @param int|null    $index      Item index.
	 * @param string|null $identifier Item identifier.
	 * @param string      $code       Error code.
	 * @param string      $message    Error message.
	 * @param array       $input      Sanitized input payload.
	 */
	private function append_error( array &$response, ?int $index, ?string $identifier, string $code, string $message, array $input = array() ): void {
		$error = $this->build_error( $index, $identifier, $code, $message );

		if ( ! empty( $input ) ) {
			$error['input'] = $input;
		}

		$response['errors'][] = $error;

		$this->logger->log(
			'Stock update item failed',
			array(
				'index'      => $index,
				'identifier' => $identifier,
				'code'       => $code,
				'message'    => $message,
				'input'      => $input,
			)
		);
	}

	/**
	 * Normalize item payload for response and logging.
	 *
	 * @param array $item Item payload.
	 * @return array
	 */
	private function normalize_input( array $item ): array {
		$normalized = array();

		if ( isset( $item['product_id'] ) && '' !== trim( (string) $item['product_id'] ) ) {
			$normalized['product_id'] = absint( $item['product_id'] );
		}

		if ( isset( $item['sku'] ) && '' !== trim( (string) $item['sku'] ) ) {
			$normalized['sku'] = sanitize_text_field( (string) $item['sku'] );
		}

		if ( array_key_exists( 'qty', $item ) ) {
			if ( is_int( $item['qty'] ) ) {
				$normalized['qty'] = $item['qty'];
			} elseif ( is_string( $item['qty'] ) ) {
				$normalized['qty'] = trim( $item['qty'] );
			} else {
				$normalized['qty'] = $item['qty'];
			}
		}

		if ( isset( $item['mode'] ) && '' !== trim( (string) $item['mode'] ) ) {
			$normalized['mode'] = strtolower( sanitize_text_field( (string) $item['mode'] ) );
		}

		return $normalized;
	}

	/**
	 * Find product/variation IDs matching SKU.
	 *
	 * @param string $sku SKU value.
	 * @return int[]
	 */
	private function find_product_ids_by_sku( string $sku ): array {
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_key'       => '_sku',
				'meta_value'     => $sku,
				'numberposts'    => 3,
				'suppress_filters' => true,
			)
		);

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

		return array_values(
			array_filter(
				$ids,
				static function( int $id ): bool {
					return $id > 0;
				}
			)
		);
	}

	/**
	 * Produce a readable identifier label for logs and errors.
	 *
	 * @param array $item Item payload.
	 * @return string|null
	 */
	private function resolve_identifier_label( array $item ): ?string {
		if ( isset( $item['sku'] ) && '' !== trim( (string) $item['sku'] ) ) {
			return 'sku:' . sanitize_text_field( (string) $item['sku'] );
		}

		if ( isset( $item['product_id'] ) && '' !== trim( (string) $item['product_id'] ) ) {
			return 'product_id:' . absint( $item['product_id'] );
		}

		return null;
	}
}
