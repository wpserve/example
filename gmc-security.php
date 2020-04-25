<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GMC_Security {
	const TOKEN_EXPIRATION_IN_MINUTES = 5;
	const GMC_ACCESS_TOKEN_QUERY_VAR = 'gmc_access_token';

	public static function tokenize($value) {
		$encoded = Base58_Encoding::encode((string)$value);
		$alphabet = str_split(Base58_Encoding::ALPHABET);
		$random = array_rand($alphabet, 1);

		return substr($encoded, 0, 1) . $alphabet[$random] . substr($encoded, 1);
	}

	public static function untokenize($code) {
		$original = substr_replace($code, '', 1, 1);

		return Base58_Encoding::decode($original);
	}

	public static function impersonate_system_request() {
		$access_token = self::create_access_token(GMC::ROLE_ADMINISTRATOR);
		set_query_var(self::GMC_ACCESS_TOKEN_QUERY_VAR, $access_token['token']);
	}

	public static function create_access_token($role) {
		$now = new DateTime();
		$now->modify('+' . self::TOKEN_EXPIRATION_IN_MINUTES . ' minutes');
		$expiration_date = $now->format('Y-m-d H:i:s');
		$token = $role . '|' . $expiration_date;

		return [
			'token'             => self::tokenize($token),
			'expiration_date'   => $expiration_date
		];
	}

	public static function get_current_user_roles() {
		$user = wp_get_current_user();
		$roles = [];
		if($user && $user->ID) {
			$roles = $user->roles;
		} else {
			$access_token = self::untokenize(get_query_var( self::GMC_ACCESS_TOKEN_QUERY_VAR ));
			$parts = explode('|', $access_token);
			if(count($parts) === 2) {
				$expiration_date = DateTime::createFromFormat('Y-m-d H:i:s', $parts[1]);
				$now = new DateTime();
				if($expiration_date >= $now) {
					$roles = [$parts[0]];
				}
			}
		}

		return $roles;
	}

	public static function is_access_token_valid($token) {
		$access_token = self::untokenize($token);
		$parts = explode('|', $access_token);
		$is_valid = false;
		if(count($parts) === 2) {
			$expiration_date = DateTime::createFromFormat('Y-m-d H:i:s', $parts[1]);
			$now = new DateTime();
			if($expiration_date >= $now) {
				$is_valid = true;
			}
		}

		return $is_valid;
	}



	public static function validate_shopify_request() {
		// TODO - find a better place for them
		$shopify_app_secrets = [
			'scotiabank-gmc.myshopify.com'  => 'xxxxx_8ad0ce20eacfa6d055fb306702386fe6',
			'gmc-store-1.myshopify.com' => 'xxxxx_2ec80b753f12dcd38b22fc4964ccffe6'
		];

		$shopify_domain = $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'];
		$shared_secret = '';
		if (array_key_exists($shopify_domain, $shopify_app_secrets)) {
			$shared_secret = $shopify_app_secrets[$shopify_domain];
		}

		$hmac_header = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'];
		$data = file_get_contents('php://input');
		$calculated_hmac = base64_encode(hash_hmac('sha256', $data, $shared_secret, true));

		return hash_equals($hmac_header, $calculated_hmac);
	}
}
