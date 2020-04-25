<?php

class GMC_GoDirect_Rest_API {

	/**
	 * Register the REST API routes.
	 */
	public static function init() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			// The REST API wasn't integrated into core until 4.4, and we support 4.0+ (for now).
			return false;
		}

		register_rest_route( 'gmc_infrastructure_go_direct/v1', '/shipment_event', array(
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( 'GMC_GoDirect_Rest_API', 'shipment_event' ),
			)
		) );

	}

	/**
	 * GoDirect shipment event WebHook.
	 *
	 * @see https://contacservices.atlassian.net/wiki/spaces/GDP/pages/577372354/GD+Shipment+event
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public static function shipment_event( $request ) {
		try {
			// TODO add token to all requests from UI

			GMC_Security::impersonate_system_request();
			GMC_Operations_Log::add( "Shipment Event WebHook", GMC::OP_Debug, "GoDirect", "Starting...", $request->get_body() );

			$response_code = 200;

			$data = $request->get_json_params();

			if ( $data && $data['Notifications'] && is_array($data['Notifications'])) {
				$shipped_orders = [];
				$error_messages= [];
				foreach ($data['Notifications'] as $notification) {
					if($notification['Action'] === 'GD_Shipment' && $notification['WMSOrder']) {
						$order_name      = $notification['WMSOrder']['CustomerPoNo'];
						$carrier_code    = $notification['WMSOrder']['Carrier'];
						$tracking_number = $notification['WMSOrder']['TrackingNumber'];

						$result = GMC_GoDirect::fulfill_woo_commerce_order( $order_name, $carrier_code, $tracking_number );

						if($result['success']) {
							array_push($shipped_orders, $order_name);
						} else {
							array_push($error_messages, $result['message']);
						}
					}
				}

				if ( count($shipped_orders) > 0 ) {
					$response = array(
						'status'  => 'success',
						'message' => 'WooCommerce order(s) #: [' . implode(',', $shipped_orders) . '] successfully fulfilled.',
					);

					GMC_Operations_Log::add( "Shipment WebHook", GMC::OP_Info, "GoDirect", "WooCommerce order(s) [" . implode(',', $shipped_orders) . "] were fulfilled." );
				} else {
					$response      = array(
						'status'  => 'failure',
						'errors' => $error_messages
					);
					$response_code = 500;

					GMC_Operations_Log::add( "Shipment WebHook", GMC::OP_Error, "GoDirect", "Failed processing GoDirect Shipment WebHook for WooCommerce order(s) failed: [" . implode(',', $error_messages) . "]." );
				}
			} else {
				$response      = [
					'status'  => 'failure',
					'errors' => 'No data to process.'
				];
				$response_code = 400;

				GMC_Operations_Log::add( "Shipment WebHook", GMC::OP_Debug, "GoDirect", "Empty request." );
			}

			return new WP_REST_Response( $response, $response_code );
		} catch (Exception $e) {
			$error = $e->getMessage();
			$details = $e->getTrace();

			GMC_Operations_Log::add( "Shipment WebHook", GMC::OP_Error, "GoDirect", "Error occurred [" . $error . "]", $details );

			return new WP_REST_Response( [
				'status' => 'failure',
				'errors' => [ $error ]
			], 500 );
		}
	}
}