<?php

namespace StockControl;

use StockControl\Rest\Stock_Controller;
use StockControl\Service\Stock_Updater;
use StockControl\Support\Logger;

final class Plugin {
	private static ?self $instance = null;

	private Stock_Controller $stock_controller;

	private function __construct() {
		$logger              = new Logger();
		$stock_updater       = new Stock_Updater( $logger );
		$this->stock_controller = new Stock_Controller( $stock_updater, $logger );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function register(): void {
		$this->stock_controller->register();
	}
}

