<?php
/**
 * API Class
 *
 * Handles all communication with the Optti backend API.
 * Migrated from API_Client_V2 class.
 *
 * @package Optti\Framework
 */

namespace Optti\Framework;

use Optti\Framework\Traits\Singleton;
use Optti\Framework\Traits\ApiResponse;
use Optti\Framework\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ApiClient
 *
 * Centralized API client for Optti backend.
 */
class ApiClient {

	use Singleton;
	use ApiResponse;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	protected $api_url;

	/**
	 * Get API base URL.
	 *
	 * @return string API base URL.
	 */
	protected function get_api_url() {
		if ( ! $this->api_url ) {
			// Check for constant first.
			if ( defined( 'OPTTI_BACKEND_URL' ) ) {
				$this->api_url = OPTTI_BACKEND_URL;
			} else {
				// Check filter.
				$this->api_url = apply_filters( 'optti_backend_url', 'https://alttext-ai-backend.onrender.com' );
			}
		}
		return $this->api_url;
	}

	/**
	 * Option keys.
	 *
	 * @var array
	 */
	protected $option_keys = [
		'token'      => 'optti_jwt_token',
		'user'       => 'optti_user_data',
		'site_id'    => 'optti_site_id',
		'license_key' => 'optti_license_key',
		'license_data' => 'optti_license_data',
	];

	/**
	 * Encryption prefix.
	 *
	 * @var string
	 */
	protected $encryption_prefix = 'enc:';

	/**
	 * Initialize the API.
	 */
	protected function __construct() {
		$this->migrate_legacy_options();
	}

	/**
	 * Migrate legacy option keys to new format.
	 *
	 * @return void
	 */
	protected function migrate_legacy_options() {
		// Migrate token.
		$legacy_token = get_option( 'beepbeepai_jwt_token', '' );
		if ( ! empty( $legacy_token ) && empty( get_option( $this->option_keys['token'], '' ) ) ) {
			update_option( $this->option_keys['token'], $legacy_token );
		}

		// Migrate user data.
		$legacy_user = get_option( 'beepbeepai_user_data', null );
		if ( null !== $legacy_user && null === get_option( $this->option_keys['user'], null ) ) {
			update_option( $this->option_keys['user'], $legacy_user );
		}

		// Migrate license key.
		$legacy_license = get_option( 'beepbeepai_license_key', '' );
		if ( ! empty( $legacy_license ) && empty( get_option( $this->option_keys['license_key'], '' ) ) ) {
			update_option( $this->option_keys['license_key'], $legacy_license );
		}

		// Migrate license data.
		$legacy_license_data = get_option( 'beepbeepai_license_data', null );
		if ( null !== $legacy_license_data && null === get_option( $this->option_keys['license_data'], null ) ) {
			update_option( $this->option_keys['license_data'], $legacy_license_data );
		}
	}

	/**
	 * Get stored JWT token.
	 *
	 * @return string Token.
	 */
	public function get_token() {
		$token = get_option( $this->option_keys['token'], '' );
		if ( empty( $token ) ) {
			return '';
		}
		return $this->maybe_decrypt_secret( $token );
	}

	/**
	 * Set JWT token.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	public function set_token( $token ) {
		if ( empty( $token ) ) {
			$this->clear_token();
			return;
		}

		$stored = $this->encrypt_secret( $token );
		if ( empty( $stored ) ) {
			$stored = $token;
		}
		update_option( $this->option_keys['token'], $stored, false );
	}

	/**
	 * Clear stored token.
	 *
	 * @return void
	 */
	public function clear_token() {
		delete_option( $this->option_keys['token'] );
		delete_option( $this->option_keys['user'] );
	}

	/**
	 * Get stored user data.
	 *
	 * @return array|null User data.
	 */
	public function get_user_data() {
		$data = get_option( $this->option_keys['user'], null );
		return ( $data !== false && $data !== null ) ? $data : null;
	}

	/**
	 * Set user data.
	 *
	 * @param array $user_data User data.
	 * @return void
	 */
	public function set_user_data( $user_data ) {
		update_option( $this->option_keys['user'], $user_data, false );
	}

	/**
	 * Get license key.
	 *
	 * @return string License key.
	 */
	public function get_license_key() {
		$key = get_option( $this->option_keys['license_key'], '' );
		if ( empty( $key ) ) {
			return '';
		}
		return $this->maybe_decrypt_secret( $key );
	}

	/**
	 * Set license key.
	 *
	 * @param string $license_key License key.
	 * @return void
	 */
	public function set_license_key( $license_key ) {
		if ( empty( $license_key ) ) {
			delete_option( $this->option_keys['license_key'] );
			delete_option( $this->option_keys['license_data'] );
			return;
		}

		$stored = $this->encrypt_secret( $license_key );
		if ( empty( $stored ) ) {
			$stored = $license_key;
		}
		update_option( $this->option_keys['license_key'], $stored, false );
	}

	/**
	 * Get license data.
	 *
	 * @return array|null License data.
	 */
	public function get_license_data() {
		$data = get_option( $this->option_keys['license_data'], null );
		return ( $data !== false && $data !== null ) ? $data : null;
	}

	/**
	 * Set license data.
	 *
	 * @param array $license_data License data.
	 * @return void
	 */
	public function set_license_data( $license_data ) {
		update_option( $this->option_keys['license_data'], $license_data, false );
	}

	/**
	 * Check if user is authenticated.
	 *
	 * @return bool True if authenticated.
	 */
	public function is_authenticated() {
		// Check license key first.
		if ( ! empty( $this->get_license_key() ) && ! empty( $this->get_license_data() ) ) {
			return true;
		}

		// Check JWT token.
		$token = $this->get_token();
		return ! empty( $token );
	}

	/**
	 * Get site ID.
	 *
	 * @return string Site identifier.
	 */
	public function get_site_id() {
		$site_id = get_option( $this->option_keys['site_id'], '' );
		if ( empty( $site_id ) ) {
			// Generate site ID from site URL.
			$site_url = get_site_url();
			$site_id  = md5( $site_url );
			update_option( $this->option_keys['site_id'], $site_id, false );
		}
		return $site_id;
	}

	/**
	 * Make API request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param string $method HTTP method.
	 * @param array  $data Request data.
	 * @param array  $args Additional arguments.
	 * @return array|\WP_Error Response data or error.
	 */
	public function request( $endpoint, $method = 'GET', $data = null, $args = [] ) {
		$url      = trailingslashit( $this->get_api_url() ) . ltrim( $endpoint, '/' );
		$headers  = $this->get_auth_headers( $args );
		$timeout  = $this->get_timeout_for_endpoint( $endpoint, $args['timeout'] ?? null );
		$retries  = $args['retries'] ?? 3;

		$request_args = [
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		];

		if ( $data && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$request_args['body'] = wp_json_encode( $data );
		}

		// Add extra headers if provided.
		if ( ! empty( $args['extra_headers'] ) && is_array( $args['extra_headers'] ) ) {
			$request_args['headers'] = array_merge( $request_args['headers'], $args['extra_headers'] );
		}

		// Log request.
		Logger::log( 'debug', 'API request', [
			'endpoint' => $endpoint,
			'method'   => $method,
		], 'api' );

		// Make request with retry logic.
		$attempt = 0;
		$last_error = null;

		while ( $attempt < $retries ) {
			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				$error_code = $response->get_error_code();
				$error_message = $response->get_error_message();

				// Normalize error.
				$normalized_error = $this->normalize_error( $error_code, $error_message, $endpoint );

				// Check if should retry.
				if ( ! $this->should_retry_error( $normalized_error, $attempt, $retries ) ) {
					Logger::log( 'error', 'API request failed (non-retryable)', [
						'endpoint' => $endpoint,
						'error'    => $error_message,
						'code'     => $error_code,
					], 'api' );
					return $normalized_error;
				}

				$attempt++;
				$last_error = $normalized_error;

				if ( $attempt < $retries ) {
					// Exponential backoff (wait 1s, 2s, 4s).
					$delay = min( 4, pow( 2, $attempt ) );
					Logger::log( 'warning', 'Retrying API request', [
						'endpoint' => $endpoint,
						'attempt'  => $attempt + 1,
						'delay'    => $delay,
					], 'api' );
					usleep( $delay * 1000000 );
				}
				continue;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );
			$response_data = json_decode( $body, true );

			// Handle token refresh on 401.
			if ( 401 === $status_code && $this->should_refresh_token( $response_data ) ) {
				if ( $this->refresh_token() ) {
					// Retry with new token.
					$request_args['headers'] = $this->get_auth_headers( $args );
					if ( ! empty( $args['extra_headers'] ) ) {
						$request_args['headers'] = array_merge( $request_args['headers'], $args['extra_headers'] );
					}
					Logger::log( 'info', 'Token refreshed, retrying request', [
						'endpoint' => $endpoint,
					], 'api' );
					continue;
				} else {
					// Token refresh failed, clear token.
					$this->clear_token();
					return new \WP_Error(
						'auth_required',
						__( 'Your session has expired. Please log in again.', 'beepbeep-ai-alt-text-generator' ),
						[ 'requires_auth' => true, 'status' => 401 ]
					);
				}
			}

			// Log response.
			Logger::log( $status_code >= 400 ? 'warning' : 'debug', 'API response', [
				'endpoint' => $endpoint,
				'status'   => $status_code,
			], 'api' );

			// Handle errors.
			if ( $status_code >= 400 ) {
				$error = $this->normalize_api_error( $response_data, $status_code, $endpoint );
				if ( $this->should_retry_error( $error, $attempt, $retries ) && $attempt < $retries - 1 ) {
					$attempt++;
					$last_error = $error;
					$delay = min( 4, pow( 2, $attempt ) );
					usleep( $delay * 1000000 );
					continue;
				}
				return $error;
			}

			// Success.
			if ( $attempt > 0 ) {
				Logger::log( 'info', 'API request recovered after retry', [
					'endpoint' => $endpoint,
					'attempt'  => $attempt + 1,
				], 'api' );
			}

			return $response_data;
		}

		return $last_error ?: new \WP_Error( 'api_error', __( 'API request failed after retries', 'beepbeep-ai-alt-text-generator' ) );
	}

	/**
	 * Get authentication headers.
	 *
	 * @param array $args Additional arguments.
	 * @return array Headers.
	 */
	protected function get_auth_headers( $args = [] ) {
		$headers = [
			'Content-Type' => 'application/json',
		];

		// Skip auth headers if requested.
		if ( isset( $args['include_auth_headers'] ) && false === $args['include_auth_headers'] ) {
			return $headers;
		}

		$headers['X-Site-Hash'] = $this->get_site_id();
		$headers['X-Site-URL']  = get_site_url();

		// Add site fingerprint if available.
		if ( class_exists( '\Optti\Framework\LicenseManager' ) ) {
			$license = LicenseManager::instance();
			$fingerprint = $license->get_fingerprint();
			if ( ! empty( $fingerprint ) ) {
				$headers['X-Site-Fingerprint'] = $fingerprint;
			}
		}

		// Include user ID if requested.
		if ( ! empty( $args['include_user_id'] ) ) {
			$user_id = get_current_user_id();
			if ( $user_id > 0 ) {
				$headers['X-WP-User-ID'] = (string) $user_id;
			}
		}

		// Priority: License key > JWT token.
		$license_key = $this->get_license_key();
		$token       = $this->get_token();

		if ( ! empty( $license_key ) ) {
			$headers['X-License-Key'] = $license_key;
		} elseif ( ! empty( $token ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Check if token should be refreshed.
	 *
	 * @param array $response_data Response data.
	 * @return bool True if should refresh.
	 */
	protected function should_refresh_token( $response_data ) {
		if ( ! is_array( $response_data ) ) {
			return false;
		}

		$error_code = $response_data['code'] ?? '';
		return in_array( $error_code, [ 'token_expired', 'invalid_token' ], true );
	}

	/**
	 * Refresh JWT token.
	 *
	 * @return bool True if refreshed.
	 */
	protected function refresh_token() {
		$response = $this->request( '/auth/refresh', 'POST', [], [ 'retries' => 1 ] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( isset( $response['token'] ) ) {
			$this->set_token( $response['token'] );
			return true;
		}

		return false;
	}

	/**
	 * Encrypt secret value.
	 *
	 * @param string $value Value to encrypt.
	 * @return string Encrypted value.
	 */
	protected function encrypt_secret( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}

		$key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$iv  = function_exists( 'random_bytes' ) ? @random_bytes( 16 ) : openssl_random_pseudo_bytes( 16 );
		if ( false === $iv ) {
			return $value;
		}

		$cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $value;
		}

		return $this->encryption_prefix . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt secret value.
	 *
	 * @param string $value Encrypted value.
	 * @return string Decrypted value.
	 */
	protected function maybe_decrypt_secret( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}

		if ( strpos( $value, $this->encryption_prefix ) !== 0 ) {
			return $value;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return substr( $value, strlen( $this->encryption_prefix ) );
		}

		$payload = base64_decode( substr( $value, strlen( $this->encryption_prefix ) ), true );
		if ( false === $payload || strlen( $payload ) < 17 ) {
			return '';
		}

		$iv     = substr( $payload, 0, 16 );
		$cipher = substr( $payload, 16 );
		$key    = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return $plain !== false ? $plain : '';
	}

	// Convenience methods for common endpoints.

	/**
	 * Login user.
	 *
	 * @param string $email Email.
	 * @param string $password Password.
	 * @return array|\WP_Error Response.
	 */
	public function login( $email, $password ) {
		$response = $this->request( '/auth/login', 'POST', [
			'email'    => $email,
			'password' => $password,
		] );

		if ( ! is_wp_error( $response ) && isset( $response['token'] ) ) {
			$this->set_token( $response['token'] );
			if ( isset( $response['user'] ) ) {
				$this->set_user_data( $response['user'] );
				// Save license key from user data for easy access
				if ( isset( $response['user']['license_key'] ) ) {
					$this->set_license_key( $response['user']['license_key'] );
				}
			}
		}

		return $response;
	}

	/**
	 * Register user.
	 *
	 * @param string $email Email.
	 * @param string $password Password.
	 * @return array|\WP_Error Response.
	 */
	public function register( $email, $password ) {
		$response = $this->request( '/auth/register', 'POST', [
			'email'    => $email,
			'password' => $password,
		] );

		if ( ! is_wp_error( $response ) && isset( $response['token'] ) ) {
			$this->set_token( $response['token'] );
			if ( isset( $response['user'] ) ) {
				$this->set_user_data( $response['user'] );
				// Save license key from user data for easy access
				if ( isset( $response['user']['license_key'] ) ) {
					$this->set_license_key( $response['user']['license_key'] );
				}
			}
		}

		return $response;
	}

	/**
	 * Get usage data.
	 *
	 * @return array|\WP_Error Usage data.
	 */
	public function get_usage() {
		return $this->request( '/usage', 'GET' );
	}

	/**
	 * Validate license.
	 *
	 * @param string $license_key License key.
	 * @return array|\WP_Error Validation result.
	 */
	public function validate_license( $license_key ) {
		return $this->request( '/license/validate', 'POST', [
			'license_key' => $license_key,
			'site_id'     => $this->get_site_id(),
			'site_url'    => get_site_url(),
		] );
	}

	/**
	 * Activate license.
	 *
	 * @param string $license_key License key.
	 * @param string $fingerprint Site fingerprint.
	 * @return array|\WP_Error Activation result.
	 */
	public function activate_license( $license_key, $fingerprint = '' ) {
		return $this->request( '/license/activate', 'POST', [
			'license_key' => $license_key,
			'site_id'     => $this->get_site_id(),
			'site_url'    => get_site_url(),
			'fingerprint' => $fingerprint,
		] );
	}

	/**
	 * Deactivate license.
	 *
	 * @param string $license_key License key.
	 * @return array|\WP_Error Deactivation result.
	 */
	public function deactivate_license( $license_key ) {
		return $this->request( '/license/deactivate', 'POST', [
			'license_key' => $license_key,
			'site_id'     => $this->get_site_id(),
		] );
	}

	/**
	 * Get user info.
	 *
	 * @param bool $force_refresh Force refresh cache.
	 * @return array|\WP_Error User info.
	 */
	public function get_user_info( $force_refresh = false ) {
		$cache_key = 'optti_user_info';
		$cache = Cache::instance();

		// Return cached data if available and not forcing refresh.
		if ( ! $force_refresh ) {
			$cached = $cache->get( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = $this->request( '/auth/user', 'GET' );

		// Cache successful responses for 10 minutes.
		if ( ! is_wp_error( $result ) ) {
			$cache->set( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Forgot password.
	 *
	 * @param string $email Email address.
	 * @return array|\WP_Error Response.
	 */
	public function forgot_password( $email ) {
		return $this->request( '/auth/forgot-password', 'POST', [
			'email' => $email,
		], [ 'include_auth_headers' => false ] );
	}

	/**
	 * Reset password.
	 *
	 * @param string $email Email address.
	 * @param string $token Reset token.
	 * @param string $new_password New password.
	 * @return array|\WP_Error Response.
	 */
	public function reset_password( $email, $token, $new_password ) {
		return $this->request( '/auth/reset-password', 'POST', [
			'email'        => $email,
			'token'        => $token,
			'new_password' => $new_password,
		], [ 'include_auth_headers' => false ] );
	}

	/**
	 * Get subscription info.
	 *
	 * @param bool $force_refresh Force refresh cache.
	 * @return array|\WP_Error Subscription info.
	 */
	public function get_subscription_info( $force_refresh = false ) {
		$cache_key = 'optti_subscription_info';
		$cache = Cache::instance();

		// Return cached data if available and not forcing refresh.
		if ( ! $force_refresh ) {
			$cached = $cache->get( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = $this->request( '/billing/subscription', 'GET' );

		// Cache successful responses for 5 minutes.
		if ( ! is_wp_error( $result ) ) {
			$cache->set( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Get billing info.
	 *
	 * @return array|\WP_Error Billing info.
	 */
	public function get_billing_info() {
		return $this->request( '/billing/info', 'GET' );
	}

	/**
	 * Get plans.
	 *
	 * @param bool $force_refresh Force refresh cache.
	 * @return array|\WP_Error Plans data.
	 */
	public function get_plans( $force_refresh = false ) {
		$cache_key = 'optti_plans';
		$cache = Cache::instance();

		// Return cached data if available and not forcing refresh.
		// Plans don't change frequently, so cache longer.
		if ( ! $force_refresh ) {
			$cached = $cache->get( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = $this->request( '/billing/plans', 'GET', null, [ 'include_auth_headers' => false ] );

		// Cache successful responses for 1 hour (plans change infrequently).
		if ( ! is_wp_error( $result ) ) {
			$cache->set( $cache_key, $result, HOUR_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Create checkout session.
	 *
	 * @param string $price_id Price ID.
	 * @param string $success_url Success URL.
	 * @param string $cancel_url Cancel URL.
	 * @return array|\WP_Error Checkout session.
	 */
	public function create_checkout_session( $price_id, $success_url, $cancel_url ) {
		return $this->request( '/billing/checkout', 'POST', [
			'price_id'    => $price_id,
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
		] );
	}

	/**
	 * Create customer portal session.
	 *
	 * @param string $return_url Return URL.
	 * @return array|\WP_Error Portal session.
	 */
	public function create_customer_portal_session( $return_url ) {
		return $this->request( '/billing/portal', 'POST', [
			'return_url' => $return_url,
		] );
	}

	/**
	 * Get timeout for endpoint.
	 *
	 * @param string $endpoint Endpoint.
	 * @param int|null $default_timeout Default timeout.
	 * @return int Timeout in seconds.
	 */
	protected function get_timeout_for_endpoint( $endpoint, $default_timeout = null ) {
		if ( null !== $default_timeout ) {
			return $default_timeout;
		}

		// Longer timeout for generation endpoints.
		if ( strpos( $endpoint, '/generate' ) !== false || strpos( $endpoint, 'api/generate' ) !== false ) {
			return 90;
		}

		// Default timeout.
		return 30;
	}

	/**
	 * Normalize network error.
	 *
	 * @param string $error_code Error code.
	 * @param string $error_message Error message.
	 * @param string $endpoint Endpoint.
	 * @return \WP_Error Normalized error.
	 */
	protected function normalize_error( $error_code, $error_message, $endpoint ) {
		$error_message_lower = strtolower( $error_message );

		// Timeout errors.
		if ( strpos( $error_message_lower, 'timeout' ) !== false ) {
			$is_generate = strpos( $endpoint, '/generate' ) !== false;
			$message = $is_generate
				? __( 'The image generation is taking longer than expected. This may happen with large images or during high server load. Please try again.', 'beepbeep-ai-alt-text-generator' )
				: __( 'The server is taking too long to respond. Please try again in a few minutes.', 'beepbeep-ai-alt-text-generator' );
			return new \WP_Error( 'api_timeout', $message );
		}

		// Network errors.
		if ( strpos( $error_message_lower, 'could not resolve' ) !== false || strpos( $error_message_lower, 'resolve host' ) !== false ) {
			return new \WP_Error( 'api_unreachable', __( 'Unable to reach authentication server. Please check your internet connection and try again.', 'beepbeep-ai-alt-text-generator' ) );
		}

		// Generic error.
		return new \WP_Error( 'api_error', $error_message ?: __( 'API request failed', 'beepbeep-ai-alt-text-generator' ) );
	}

	/**
	 * Normalize API error response.
	 *
	 * @param array  $response_data Response data.
	 * @param int    $status_code HTTP status code.
	 * @param string $endpoint Endpoint.
	 * @return \WP_Error Normalized error.
	 */
	protected function normalize_api_error( $response_data, $status_code, $endpoint ) {
		$error_code = $response_data['code'] ?? '';
		$error_message = $response_data['error'] ?? $response_data['message'] ?? __( 'API request failed', 'beepbeep-ai-alt-text-generator' );

		// Handle 404 - endpoint not found.
		if ( 404 === $status_code ) {
			$body_str = is_string( $response_data ) ? $response_data : '';
			if ( ! empty( $body_str ) && ( strpos( $body_str, '<html' ) !== false || strpos( $body_str, 'Cannot POST' ) !== false ) ) {
				$message = __( 'This feature is not yet available. Please contact support for assistance or try again later.', 'beepbeep-ai-alt-text-generator' );
				if ( strpos( $endpoint, '/auth/forgot-password' ) !== false || strpos( $endpoint, '/auth/reset-password' ) !== false ) {
					$message = __( 'Password reset functionality is currently being set up on our backend. Please contact support for assistance or try again later.', 'beepbeep-ai-alt-text-generator' );
				}
				return new \WP_Error( 'endpoint_not_found', $message );
			}
		}

		// Handle 500+ server errors.
		if ( $status_code >= 500 ) {
			Logger::log( 'error', 'API server error', [
				'endpoint' => $endpoint,
				'status'   => $status_code,
				'error'    => $error_message,
			], 'api' );
		}

		return new \WP_Error( $error_code ?: 'api_error', $error_message, [ 'status' => $status_code ] );
	}

	/**
	 * Check if error should be retried.
	 *
	 * @param \WP_Error $error Error object.
	 * @param int       $attempt Current attempt.
	 * @param int       $max_attempts Max attempts.
	 * @return bool True if should retry.
	 */
	protected function should_retry_error( $error, $attempt, $max_attempts ) {
		if ( $attempt >= $max_attempts - 1 ) {
			return false;
		}

		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$retryable_codes = [ 'api_timeout', 'api_unreachable', 'server_error' ];
		$code = $error->get_error_code();

		if ( in_array( $code, $retryable_codes, true ) ) {
			return true;
		}

		// Retry 5xx errors.
		$data = $error->get_error_data();
		$status = isset( $data['status'] ) ? intval( $data['status'] ) : 0;
		if ( $status >= 500 && $status < 600 ) {
			return true;
		}

		return false;
	}

	/**
	 * Generate alt text for an image.
	 *
	 * @param int    $image_id WordPress attachment ID.
	 * @param array  $context Additional context for generation.
	 * @param bool   $regenerate Whether to regenerate existing alt text.
	 * @return array|\WP_Error Generated alt text or error.
	 */
	public function generate_alt_text( $image_id, $context = [], $regenerate = false ) {
		// Get image URL.
		$image_url = wp_get_attachment_url( $image_id );
		if ( ! $image_url ) {
			return new \WP_Error(
				'missing_image_url',
				__( 'Image URL not found.', 'beepbeep-ai-alt-text-generator' )
			);
		}

		// Prepare image payload.
		$image_payload = $this->prepare_image_payload( $image_id, $image_url, $context );

		if ( is_wp_error( $image_payload ) ) {
			return $image_payload;
		}

		$site_hash = $this->get_site_id();

		// Build request body.
		$body = [
			'image_data'    => $image_payload,
			'context'       => $context,
			'regenerate'    => $regenerate,
			'timestamp'     => time(),
			'image_id'      => (string) $image_id,
			'attachment_id' => (string) $image_id,
		];

		if ( ! empty( $site_hash ) ) {
			$body['siteHash'] = $site_hash;
			$body['site_id']  = $site_hash;
		}

		$extra_headers = [
			'X-Image-ID'      => (string) $image_id,
			'X-Attachment-ID' => (string) $image_id,
		];

		if ( ! empty( $site_hash ) ) {
			$extra_headers['X-Site-Hash'] = $site_hash;
		}

		// Debug: Log what we are about to send (no raw base64)
		if ( class_exists( '\BeepBeepAI\AltTextGenerator\Debug_Log' ) ) {
			\BeepBeepAI\AltTextGenerator\Debug_Log::log(
				'info',
				'Framework API Request Payload (ready to send)',
				array(
					'image_id'             => $image_id,
					'has_image_url'        => ! empty( $image_payload['image_url'] ),
					'image_url_preview'    => ! empty( $image_payload['image_url'] ) ? substr( $image_payload['image_url'], 0, 120 ) . '...' : 'none',
					'has_image_base64'     => ! empty( $image_payload['image_base64'] ),
					'base64_length'        => ! empty( $image_payload['image_base64'] ) ? strlen( $image_payload['image_base64'] ) : 0,
					'dimensions'           => ( $image_payload['width'] ?? 'missing' ) . 'x' . ( $image_payload['height'] ?? 'missing' ),
					'image_source'         => $image_payload['image_source'] ?? 'missing',
					'image_mime_type'      => $image_payload['mime_type'] ?? 'missing',
					'body_has_site_hash'   => ! empty( $body['siteHash'] ),
					'header_has_site_hash' => ! empty( $extra_headers['X-Site-Hash'] ),
					'body_keys'            => array_keys( $body ),
					'header_keys'          => array_keys( $extra_headers ),
					'license_key_in_header'=> ! empty( $extra_headers['X-License-Key'] ),
					'license_key_in_body'  => ! empty( $body['licenseKey'] ),
					'has_base64'           => ! empty( $image_payload['image_base64'] ),
					'base64_size_kb'       => ! empty( $image_payload['image_base64'] ) ? round( strlen( $image_payload['image_base64'] ) / 1024, 2 ) : 0,
					'has_public_url'       => ! empty( $image_payload['image_url'] ) && $this->is_public_https_url( $image_payload['image_url'] ?? '' ),
					'bytes_per_pixel'      => ( ! empty( $image_payload['image_base64'] ) && ! empty( $image_payload['width'] ) && ! empty( $image_payload['height'] ) )
						? ( ( strlen( $image_payload['image_base64'] ) * 3 / 4 ) / max( 1, $image_payload['width'] * $image_payload['height'] ) )
						: 'n/a',
				),
				'api'
			);
		}

		// Make request.
		return $this->request( '/api/generate', 'POST', $body, [
			'timeout'          => 90,
			'include_user_id'  => true,
			'extra_headers'    => $extra_headers,
		] );
	}

	/**
	 * Prepare image payload for API.
	 *
	 * @param int    $image_id Attachment ID.
	 * @param string $image_url Image URL.
	 * @param array  $context Context data.
	 * @return array|\WP_Error Image payload or error.
	 */
	protected function prepare_image_payload( $image_id, $image_url, $context = [] ) {
		$parsed_image_url = $image_url ? wp_parse_url( $image_url ) : null;
		$filename         = $parsed_image_url && isset( $parsed_image_url['path'] ) ? wp_basename( $parsed_image_url['path'] ) : '';
		$metadata         = wp_get_attachment_metadata( $image_id );
		$file_path        = get_attached_file( $image_id );

		$is_public = $this->is_public_https_url( $image_url );
		if ( $is_public && ! empty( $image_url ) ) {
			$payload = [
				'image_id'      => (string) $image_id,
				'attachment_id' => (string) $image_id,
				'image_source'  => 'url',
				'image_url'     => $image_url,
				'url'           => $image_url,
				'filename'      => $filename,
			];
			if ( $metadata && isset( $metadata['width'], $metadata['height'] ) ) {
				$payload['width']  = (int) $metadata['width'];
				$payload['height'] = (int) $metadata['height'];
			}
			return $payload;
		}

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error( 'missing_image_data', __( 'Image file not found for encoding.', 'beepbeep-ai-alt-text-generator' ) );
		}

		$encoded = $this->encode_image_for_api( $file_path, $image_id );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		return [
			'image_id'      => (string) $image_id,
			'attachment_id' => (string) $image_id,
			'image_source'  => 'base64',
			'image_base64'  => $encoded['base64'],
			'base64'        => $encoded['base64'],
			'mime_type'     => $encoded['mime'],
			'width'         => $encoded['width'],
			'height'        => $encoded['height'],
			'filename'      => $filename,
			'inline'        => [
				'data_url' => 'data:' . $encoded['mime'] . ';base64,' . $encoded['base64'],
			],
		];
	}

	private function encode_image_for_api( $file_path, $image_id ) {
		return $this->encode_image_simple( $file_path );
	}

	/**
	 * Simple resize + base64 with fallback to GD.
	 * Dynamically adjusts dimension/quality to land bytes-per-pixel in a safe band.
	 */
	private function encode_image_simple( $file_path ) {
		$info   = getimagesize( $file_path );
		$orig_w = $info ? (int) $info[0] : 0;
		$orig_h = $info ? (int) $info[1] : 0;
		if ( $orig_w <= 0 || $orig_h <= 0 ) {
			return new \WP_Error( 'invalid_image_size', __( 'Could not read image dimensions.', 'beepbeep-ai-alt-text-generator' ) );
		}

		$min_bpp   = 0.03;
		$max_bpp   = 0.09;
		$mid_bpp   = ( $min_bpp + $max_bpp ) / 2;
		$best      = null;
		$max_side  = min( 512, max( $orig_w, $orig_h ) );
		$targetdim = max( 96, (int) $max_side );
		$quality   = 70;

		for ( $i = 0; $i < 6; $i++ ) {
			$scale    = min( 1, $targetdim / max( $orig_w, $orig_h ) );
			$target_w = max( 1, (int) floor( $orig_w * $scale ) );
			$target_h = max( 1, (int) floor( $orig_h * $scale ) );

			$result = $this->encode_with_quality( $file_path, $target_w, $target_h, $orig_w, $orig_h, $quality );
			if ( $result ) {
				$bytes       = strlen( $result['base64'] ) * 3 / 4;
				$pixel_count = max( 1, $result['width'] * $result['height'] );
				$bpp         = $bytes / $pixel_count;

				$score = abs( $bpp - $mid_bpp );
				if ( ! $best || $score < $best['score'] ) {
					$best = array_merge( $result, [ 'score' => $score ] );
				}

				if ( $bpp >= $min_bpp && $bpp <= $max_bpp ) {
					unset( $result['score'] );
					return $result;
				}

				if ( $bpp > $max_bpp ) {
					$targetdim = max( 96, (int) floor( $targetdim * 0.72 ) );
					$quality   = max( 40, $quality - 10 );
				} else {
					$quality   = min( 90, $quality + 10 );
					$targetdim = min( $max_side, (int) floor( $targetdim * 1.1 ) );
				}
			} else {
				$quality   = max( 40, $quality - 10 );
				$targetdim = max( 96, (int) floor( $targetdim * 0.85 ) );
			}
		}

		if ( $best ) {
			unset( $best['score'] );
			return $best;
		}

		return new \WP_Error(
			'invalid_image_size',
			__( 'Failed to save resized image.', 'beepbeep-ai-alt-text-generator' )
		);
	}

	private function encode_with_quality( $file_path, $target_w, $target_h, $orig_w, $orig_h, $quality ) {
		// WP editor path.
		if ( function_exists( 'wp_get_image_editor' ) ) {
			$editor = wp_get_image_editor( $file_path );
			if ( ! is_wp_error( $editor ) ) {
				$editor->resize( $target_w, $target_h, false );
				if ( method_exists( $editor, 'set_quality' ) ) {
					$editor->set_quality( $quality );
				}

				$upload_dir = wp_upload_dir();
				if ( ! empty( $upload_dir['path'] ) && ! is_dir( $upload_dir['path'] ) ) {
					wp_mkdir_p( $upload_dir['path'] );
				}
				$temp_filename = 'bbai-inline-' . $target_w . 'x' . $target_h . '-' . $quality . '-' . time() . '.jpg';
				$temp_path     = ! empty( $upload_dir['path'] )
					? trailingslashit( $upload_dir['path'] ) . $temp_filename
					: trailingslashit( sys_get_temp_dir() ) . $temp_filename;

				$saved = $editor->save( $temp_path, 'image/jpeg' );
				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
					$contents = file_get_contents( $saved['path'] );
					@unlink( $saved['path'] );
					if ( $contents !== false ) {
						$base64     = base64_encode( $contents );
						$size_after = getimagesizefromstring( $contents );
						return [
							'base64' => $base64,
							'width'  => $size_after ? (int) $size_after[0] : $target_w,
							'height' => $size_after ? (int) $size_after[1] : $target_h,
							'mime'   => 'image/jpeg',
						];
					}
				}
			}
		}

		// GD fallback.
		if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagecreatetruecolor' ) ) {
			$raw = file_get_contents( $file_path );
			if ( $raw !== false ) {
				$src = @imagecreatefromstring( $raw );
				if ( $src !== false ) {
					$dst = imagecreatetruecolor( $target_w, $target_h );
					imagecopyresampled( $dst, $src, 0, 0, 0, 0, $target_w, $target_h, $orig_w, $orig_h );
					ob_start();
					imagejpeg( $dst, null, $quality );
					$jpeg = ob_get_clean();
					imagedestroy( $dst );
					imagedestroy( $src );
					if ( $jpeg !== false ) {
						$base64     = base64_encode( $jpeg );
						$size_after = getimagesizefromstring( $jpeg );
						return [
							'base64' => $base64,
							'width'  => $size_after ? (int) $size_after[0] : $target_w,
							'height' => $size_after ? (int) $size_after[1] : $target_h,
							'mime'   => 'image/jpeg',
						];
					}
				}
			}
		}

		return null;
	}


	/**
	 * Determine if a URL is publicly reachable over HTTPS (not localhost or private IPs)
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function is_public_https_url( $url ) {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || strtolower( $parsed['scheme'] ) !== 'https' || empty( $parsed['host'] ) ) {
			return false;
		}

		$host = strtolower( $parsed['host'] );
		if ( $host === 'localhost' || $host === '127.0.0.1' || substr( $host, -6 ) === '.local' ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( substr( $host, 0, 4 ) === '10.' ) {
				return false;
			}
			if ( substr( $host, 0, 8 ) === '192.168.' ) {
				return false;
			}
			if ( preg_match( '#^172\\.(1[6-9]|2[0-9]|3[0-1])\\.#', $host ) ) {
				return false;
			}
		}

		return true;
	}
	/**
	 * Get user subscriptions.
	 *
	 * @param string $email User email.
	 * @return array|\WP_Error Subscriptions data or error.
	 */
	public function get_subscriptions( $email ) {
		return $this->post( '/billing/subscriptions', [ 'email' => $email ] );
	}

	/**
	 * Get checkout URL.
	 *
	 * @param string $email User email.
	 * @param string $price_id Stripe price ID.
	 * @return array|\WP_Error Checkout session data or error.
	 */
	public function get_checkout_url( $email, $price_id ) {
		$plugin_slug = defined( 'OPTTI_PLUGIN_SLUG' ) ? OPTTI_PLUGIN_SLUG : 'beepbeep-ai';
		return $this->post( '/billing/create-checkout', [
			'email'    => $email,
			'plugin'   => $plugin_slug,
			'priceId'  => $price_id,
		] );
	}

	/**
	 * Get portal URL.
	 *
	 * @param string $email User email.
	 * @return array|\WP_Error Portal session data or error.
	 */
	public function get_portal_url( $email ) {
		return $this->post( '/billing/create-portal', [ 'email' => $email ] );
	}
}
